<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_ws_price.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

require_once __DIR__ . '/_health_access.php';
if (!health_access_allowed()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function health_exec_worker_target(): int
{
    $n = (int)(getenv('SIGNAL_EXEC_WORKERS') ?: ($_ENV['SIGNAL_EXEC_WORKERS'] ?? 1));

    return max(1, min(32, $n));
}

function health_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return is_dir('/proc/' . $pid);
}

/** Legacy single PID або sharded signal_exec_worker_s{N}.pid (Phase C Redis). */
function health_signal_exec_status(string $signalDir): array
{
    $target = health_exec_worker_target();
    $pids = [];
    $aliveCount = 0;

    if ($target <= 1) {
        $legacy = $signalDir . '/signal_exec_worker.pid';
        if (is_readable($legacy)) {
            $pid = (int)trim((string)file_get_contents($legacy));
            if ($pid > 0) {
                $pids[] = $pid;
                if (health_pid_alive($pid)) {
                    $aliveCount++;
                }
            }
        }
    } else {
        for ($i = 1; $i <= $target; $i++) {
            $pidf = $signalDir . '/signal_exec_worker_s' . $i . '.pid';
            if (!is_readable($pidf)) {
                continue;
            }
            $pid = (int)trim((string)file_get_contents($pidf));
            if ($pid > 0) {
                $pids[] = $pid;
                if (health_pid_alive($pid)) {
                    $aliveCount++;
                }
            }
        }
    }

    return [
        'workers_target' => $target,
        'workers_alive' => $aliveCount,
        'pid' => $pids[0] ?? null,
        'pids' => $pids,
        'alive' => $aliveCount > 0,
    ];
}

header('Content-Type: application/json; charset=utf-8');

$host = $_ENV['DB_HOST'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
  echo json_encode(['ok'=>false,'error'=>'db_connect_failed','db_ok'=>false], JSON_UNESCAPED_UNICODE);
  exit;
}
$mysqli->set_charset('utf8mb4');

$res = $mysqli->query("SELECT MAX(last_tick_at) AS last_tick FROM strategies");
$row = $res ? $res->fetch_assoc() : null;

$last = $row && $row['last_tick'] ? strtotime($row['last_tick']) : null;
$now  = time();
$lag  = $last ? ($now - $last) : null;

$alive = ($last !== null && $lag !== null && $lag <= 120);

$modeFileEarly = __DIR__ . '/../../engine_v2/engine_mode.php';
if (is_file($modeFileEarly)) {
  require_once $modeFileEarly;
  if (function_exists('engine_v2_live_enabled') && engine_v2_live_enabled()) {
    $signalDirEarly = dirname(__DIR__, 2) . '/services/signal';
    $sigPidFileEarly = $signalDirEarly . '/signal_daemon.pid';
    $sigAlive = false;
    if (is_readable($sigPidFileEarly)) {
      $spid = (int)trim((string)file_get_contents($sigPidFileEarly));
      if ($spid > 0) {
        $sigAlive = health_pid_alive($spid);
      }
    }
    $execStatusEarly = health_signal_exec_status($signalDirEarly);
    $execRequired = function_exists('engine_signal_exec_enabled') && engine_signal_exec_enabled();
    $alive = $sigAlive && (!$execRequired || $execStatusEarly['alive']);
  }
}

$out = [
  'ok' => true,
  'db_ok' => true,
  'engine_alive' => $alive,
  'last_tick_at' => $row['last_tick'] ?? null,
  'lag_seconds' => $lag,
  'checked_at' => date('Y-m-d H:i:s'),
];

$tq = $mysqli->query("SHOW TABLES LIKE 'job_queue'");
if ($tq && $tq->num_rows > 0) {
  $counts = ['queued'=>0,'processing'=>0,'done'=>0,'failed'=>0];
  $rc = $mysqli->query("SELECT status, COUNT(*) AS c FROM job_queue GROUP BY status");
  if ($rc) {
    while ($c = $rc->fetch_assoc()) {
      $st = (string)$c['status'];
      if (isset($counts[$st])) {
        $counts[$st] = (int)$c['c'];
      }
    }
  }
  $failed1h = 0;
  $rf = $mysqli->query(
    "SELECT COUNT(*) AS c FROM job_queue WHERE status='failed' AND updated_at >= (NOW() - INTERVAL 1 HOUR)"
  );
  if ($rf && ($f = $rf->fetch_assoc())) {
    $failed1h = (int)$f['c'];
  }
  $oldestSec = null;
  $ro = $mysqli->query(
    "SELECT TIMESTAMPDIFF(SECOND, MIN(available_at), NOW()) AS d FROM job_queue WHERE status='queued'"
  );
  if ($ro && ($o = $ro->fetch_assoc()) && $o['d'] !== null) {
    $oldestSec = (int)$o['d'];
  }
  $queued = (int)($counts['queued'] ?? 0);
  $queuePressure = 'ok';
  if ($queued > 300) {
    $queuePressure = 'crit';
  } elseif ($queued > 100) {
    $queuePressure = 'warn';
  }
  $out['job_queue'] = array_merge($counts, [
    'failed_last_hour' => $failed1h,
    'oldest_queued_age_seconds' => $oldestSec,
    'pressure' => $queuePressure,
  ]);
}

$runningSymbols = [];
$rs = $mysqli->query("
    SELECT DISTINCT symbol FROM strategies
    WHERE status = 'running'
      AND symbol <> 'TEMPLATE'
      AND symbol NOT LIKE '%TEMPLATE%'
");
if ($rs) {
    while ($row = $rs->fetch_assoc()) {
        $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
        if ($sym !== '') {
            $runningSymbols[] = $sym;
        }
    }
}

$priceSummary = ws_prices_health_summary($runningSymbols);
$cachePath = ws_prices_file_path();
$out['prices'] = [
    'cache_file' => is_file($cachePath) ? 'ok' : 'missing',
    'cache_mtime' => is_file($cachePath) ? date('Y-m-d H:i:s', (int)filemtime($cachePath)) : null,
    'running_symbols' => count($runningSymbols),
    'worst_age_sec' => $priceSummary['worst_age_sec'],
    'max_age_sec' => $priceSummary['max_age_sec'],
    'all_fresh' => $priceSummary['all_fresh'],
    'symbols' => $priceSummary['symbols'],
    'ws_daemon_pid' => null,
];
$pidFile = dirname(__DIR__, 2) . '/services/ws/ws_price_cache.pid';
if (is_readable($pidFile)) {
    $pid = (int)trim((string)file_get_contents($pidFile));
    if ($pid > 0) {
        $out['prices']['ws_daemon_pid'] = $pid;
    }
}

$indexFile = __DIR__ . '/../../engine/signal/symbol_index.php';
if (is_file($indexFile)) {
    require_once $indexFile;
    $out['symbol_index'] = symbol_index_stats();
    $out['engine_mode'] = (string)(getenv('ENGINE_MODE') ?: ($_ENV['ENGINE_MODE'] ?? 'v1'));
}
$sigPidFile = dirname(__DIR__, 2) . '/services/signal/signal_daemon.pid';
$out['signal_daemon'] = [
    'enabled' => false,
    'pid' => null,
    'alive' => false,
];
$modeFile = __DIR__ . '/../../engine_v2/engine_mode.php';
if (is_file($modeFile)) {
    require_once $modeFile;
    $out['signal_daemon']['enabled'] = function_exists('engine_signal_daemon_enabled')
        && engine_signal_daemon_enabled();
}
if (is_readable($sigPidFile)) {
    $spid = (int)trim((string)file_get_contents($sigPidFile));
    $out['signal_daemon']['pid'] = $spid > 0 ? $spid : null;
    if ($spid > 0) {
        $alive = false;
        if (function_exists('posix_kill')) {
            $alive = @posix_kill($spid, 0);
        } elseif (is_dir('/proc/' . $spid)) {
            $alive = true;
        }
        $out['signal_daemon']['alive'] = $alive;
    }
}

$signalDir = dirname(__DIR__, 2) . '/services/signal';
$execStatus = health_signal_exec_status($signalDir);
$out['signal_exec'] = [
    'enabled' => false,
    'workers_target' => $execStatus['workers_target'],
    'workers_alive' => $execStatus['workers_alive'],
    'pid' => $execStatus['pid'],
    'pids' => $execStatus['pids'],
    'alive' => $execStatus['alive'],
];
$out['signal_queue'] = ['queued' => null, 'processing' => null];
$out['v2_canary'] = ['strategy_ids' => [], 'exec' => false];
if (is_file($modeFile)) {
    if (function_exists('engine_signal_exec_enabled')) {
        $out['signal_exec']['enabled'] = engine_signal_exec_enabled();
        $out['v2_canary'] = [
            'strategy_ids' => function_exists('engine_v2_canary_strategy_ids')
                ? engine_v2_canary_strategy_ids()
                : [],
            'exec' => engine_signal_exec_enabled(),
            'hybrid_exec_pct' => function_exists('engine_hybrid_exec_percent')
                ? engine_hybrid_exec_percent()
                : 0,
            'hybrid_extra_ids' => function_exists('engine_hybrid_extra_strategy_ids')
                ? engine_hybrid_extra_strategy_ids()
                : [],
        ];
    }
}
$sqTable = $mysqli->query("SHOW TABLES LIKE 'signal_queue'");
if ($sqTable && $sqTable->num_rows > 0) {
    $counts = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
    $rc = $mysqli->query('SELECT status, COUNT(*) AS c FROM signal_queue GROUP BY status');
    if ($rc) {
        while ($c = $rc->fetch_assoc()) {
            $st = (string)($c['status'] ?? '');
            if (isset($counts[$st])) {
                $counts[$st] = (int)($c['c'] ?? 0);
            }
        }
    }
    $out['signal_queue'] = $counts;
}
$out['stage3'] = [
    'queue_backend' => (string)(getenv('QUEUE_BACKEND') ?: ($_ENV['QUEUE_BACKEND'] ?? 'mysql')),
    'queue_pop' => null,
    'node_role' => (string)(getenv('STRIM_NODE_ROLE') ?: ($_ENV['STRIM_NODE_ROLE'] ?? 'control')),
    'redis_ok' => null,
    'redis_price_bus' => (string)(getenv('REDIS_PRICE_BUS') ?: '0'),
];
$redisClientFile = dirname(__DIR__, 2) . '/engine_v3/redis_client.php';
if (is_file($redisClientFile)) {
    require_once $redisClientFile;
    require_once dirname(__DIR__, 2) . '/engine_v3/queue_config.php';
    $out['stage3']['queue_backend'] = queue_backend();
    $out['stage3']['queue_pop'] = queue_pop_backend();
    $out['stage3']['node_role'] = strim_node_role();
    $out['stage3']['redis_ok'] = redis_available();
    if (redis_available() && function_exists('redis_signal_queue_counts')) {
        require_once dirname(__DIR__, 2) . '/engine_v3/redis_signal_queue.php';
        $out['stage3']['redis_signal_queue'] = redis_signal_queue_counts();
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
