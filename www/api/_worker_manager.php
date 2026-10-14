<?php
declare(strict_types=1);

function worker_root_dir(): string {
  return dirname(__DIR__, 2);
}

function worker_pid_file(): string {
  return worker_root_dir() . '/logs/worker_pids.txt';
}

function worker_log_dir(): string {
  return worker_root_dir() . '/logs';
}

function worker_script_path(): string {
  return worker_root_dir() . '/engine_v2/worker.php';
}

/**
 * PHP CLI для spawn воркерів і підпроцесів enqueue (cron / daemon часто мають порожній PATH).
 */
function worker_php_binary(): string {
  $env = getenv('WORKER_PHP_BIN');
  if (is_string($env) && $env !== '' && is_executable($env)) {
    return $env;
  }
  if (PHP_BINARY !== '' && @is_executable(PHP_BINARY)) {
    return PHP_BINARY;
  }
  return 'php';
}

function worker_ensure_paths(): void {
  $logDir = worker_log_dir();
  if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
  if (!is_file(worker_pid_file())) @file_put_contents(worker_pid_file(), '');
}

function worker_exec(string $cmd): array {
  $out = [];
  $code = 0;
  @exec($cmd . ' 2>&1', $out, $code);
  return ['code' => $code, 'out' => implode("\n", $out)];
}

function worker_running_pids(): array {
  // Шаблон без "php" на початку — інакше pgrep ловить сторонні bash-рядки з цим шляхом у argv.
  $needle = worker_script_path();
  $res = worker_exec('pgrep -f ' . escapeshellarg($needle));
  if ($res['code'] !== 0 || trim($res['out']) === '') {
    return [];
  }
  $pids = [];
  foreach (preg_split('/\R+/', trim($res['out'])) as $line) {
    $pid = (int)trim($line);
    if ($pid > 0) {
      $pids[] = $pid;
    }
  }
  $pids = array_values(array_unique($pids));
  sort($pids);
  return $pids;
}

function worker_uptime_sec(int $pid): ?int {
  $res = worker_exec("ps -p " . (int)$pid . " -o etimes=");
  if ($res['code'] !== 0) return null;
  $v = (int)trim($res['out']);
  return $v > 0 ? $v : 0;
}

function worker_is_pid_alive(int $pid): bool {
  if ($pid <= 0) return false;
  if (function_exists('posix_kill')) {
    return @posix_kill($pid, 0);
  }
  $res = worker_exec("kill -0 " . (int)$pid);
  return $res['code'] === 0;
}

function worker_start_missing(int $targetCount): array {
  worker_ensure_paths();
  $targetCount = max(1, min(32, $targetCount));
  $running = worker_running_pids();
  $need = $targetCount - count($running);
  $started = [];
  if ($need <= 0) {
    return ['started' => [], 'running' => $running];
  }

  $script = worker_script_path();
  $logDir = worker_log_dir();
  $pidFile = worker_pid_file();
  $phpBin = worker_php_binary();
  for ($i = 1; $i <= $need; $i++) {
    $slot = count($running) + $i;
    $cmd = "nohup env WORKER_SLOT=" . (int)$slot .
      ' ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) .
      " >> " . escapeshellarg($logDir . "/worker-slot-" . $slot . ".log") .
      " 2>&1 < /dev/null & echo $!";
    $res = worker_exec($cmd);
    $pid = (int)trim($res['out']);
    if ($pid > 0) {
      $started[] = $pid;
      @file_put_contents($pidFile, $pid . PHP_EOL, FILE_APPEND);
    }
  }
  return ['started' => $started, 'running' => worker_running_pids()];
}

function worker_stop_all(): array {
  $running = worker_running_pids();
  $stopped = [];
  foreach ($running as $pid) {
    if (function_exists('posix_kill')) {
      @posix_kill($pid, SIGTERM);
    } else {
      worker_exec("kill -TERM " . (int)$pid);
    }
    $stopped[] = $pid;
  }
  usleep(250000);
  return ['stopped' => $stopped, 'running' => worker_running_pids()];
}

function worker_restart(int $targetCount): array {
  $a = worker_stop_all();
  usleep(250000);
  $b = worker_start_missing($targetCount);
  return ['stopped' => $a['stopped'], 'started' => $b['started'], 'running' => $b['running']];
}

function worker_get_settings(mysqli $db): array {
  $target = 4;
  $auto = 1;
  $q = $db->query("SELECT k,v FROM app_settings WHERE k IN ('worker_target_count','worker_auto_enabled')");
  while ($q && ($r = $q->fetch_assoc())) {
    $k = (string)($r['k'] ?? '');
    $v = (string)($r['v'] ?? '');
    if ($k === 'worker_target_count') $target = max(1, min(32, (int)$v));
    if ($k === 'worker_auto_enabled') $auto = ((int)$v) ? 1 : 0;
  }
  return ['target' => $target, 'auto' => $auto];
}

function worker_set_setting(mysqli $db, string $key, string $val): bool {
  $k = $db->real_escape_string($key);
  $v = $db->real_escape_string($val);
  return (bool)$db->query("INSERT INTO app_settings(k,v) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE v=VALUES(v)");
}

function worker_trading_ops_pid_path(): string {
  return worker_log_dir() . '/trading_ops_daemon.pid';
}

function worker_trading_ops_lock_path(): string {
  return worker_log_dir() . '/trading_ops_daemon.lock';
}

function worker_trading_ops_log_path(): string {
  return worker_log_dir() . '/trading_ops_daemon.log';
}

function worker_trading_ops_pid(): int {
  $path = worker_trading_ops_pid_path();
  if (!is_readable($path)) {
    return 0;
  }
  return (int)trim((string)file_get_contents($path));
}

/**
 * Живий trading_ops: PID-файл або pgrep (Supervisor може писати PID із wrapper).
 */
function worker_trading_ops_daemon_alive(): bool {
  $pid = worker_trading_ops_pid();
  if ($pid > 0 && worker_is_pid_alive($pid)) {
    return true;
  }
  $root = worker_root_dir();
  $out = [];
  @exec('pgrep -f ' . escapeshellarg($root . '/run/trading_ops_daemon.php') . ' 2>/dev/null', $out);
  foreach ($out as $line) {
    $cand = (int) trim((string) $line);
    if ($cand > 0 && worker_is_pid_alive($cand)) {
      @file_put_contents(worker_trading_ops_pid_path(), (string) $cand . "\n");
      return true;
    }
  }
  return false;
}

/**
 * Рестарт trading_ops через ADM Supervisor (коли STRIM_SUPERVISOR_MANAGED=1).
 * @return array{ok:bool, detail:string}
 */
function worker_trading_ops_supervisor_restart(): array
{
  $client = worker_root_dir() . '/www/api/_adm_tools_client.php';
  if (!is_file($client)) {
    return ['ok' => false, 'detail' => 'adm_client_missing'];
  }
  require_once $client;
  if (!function_exists('strim_adm_tools_hosting_supervisor_list')) {
    return ['ok' => false, 'detail' => 'adm_helpers_missing'];
  }
  $resolved = strim_adm_tools_resolve_account();
  if (empty($resolved['ok'])) {
    return ['ok' => false, 'detail' => 'account:' . (string) ($resolved['error'] ?? '?')];
  }
  $list = strim_adm_tools_hosting_supervisor_list((int) $resolved['account_id']);
  if (empty($list['ok'])) {
    return ['ok' => false, 'detail' => 'list:' . (string) ($list['error'] ?? '?')];
  }
  $taskId = 0;
  foreach (($list['response']['list'] ?? []) as $t) {
    if (!is_array($t)) {
      continue;
    }
    $cmd = (string) ($t['command'] ?? '');
    if (str_contains($cmd, 'supervisor_trading_ops') || str_contains($cmd, 'trading_ops_daemon.php')) {
      $taskId = (int) ($t['task_id'] ?? 0);
      break;
    }
  }
  if ($taskId <= 0) {
    return ['ok' => false, 'detail' => 'task_not_found'];
  }
  $r = strim_adm_tools_supervisor_restart($taskId);
  return [
    'ok' => !empty($r['ok']),
    'detail' => 'task_id=' . $taskId . ' ' . (string) ($r['error'] ?? 'ok'),
  ];
}

/**
 * Якщо flock-файл лишився після мертвого процесу — прибрати, щоб cron міг підняти daemon.
 */
function worker_trading_ops_break_stale_lock(): bool {
  $pid = worker_trading_ops_pid();
  if ($pid > 0 && worker_is_pid_alive($pid)) {
    return false;
  }
  $lockPath = worker_trading_ops_lock_path();
  $pidPath = worker_trading_ops_pid_path();
  if (is_file($lockPath)) {
    @unlink($lockPath);
  }
  if (is_file($pidPath)) {
    @unlink($pidPath);
  }
  return true;
}

function worker_trading_ops_spawn(): int {
  worker_ensure_paths();
  worker_trading_ops_break_stale_lock();

  $root = worker_root_dir();
  $php = worker_php_binary();
  $daemon = $root . '/run/trading_ops_daemon.php';
  $logPath = worker_trading_ops_log_path();
  $inner = sprintf(
    '(cd %s && export WORKER_PHP_BIN=%s && nohup %s %s >> %s 2>&1 </dev/null) &',
    escapeshellarg($root),
    escapeshellarg($php),
    escapeshellarg($php),
    escapeshellarg($daemon),
    escapeshellarg($logPath)
  );
  $code = 0;
  @exec('bash -lc ' . escapeshellarg($inner), $_, $code);
  return (int)$code;
}

function worker_touch_heartbeat(mysqli $db, string $key, string $value = ''): void {
  if ($value === '') {
    $value = (string)time();
  }
  worker_set_setting($db, $key, $value);
}

function worker_read_heartbeat(mysqli $db, string $key, int $maxAgeSec = 86400): ?int {
  $keyEsc = $db->real_escape_string($key);
  $res = $db->query("SELECT v FROM app_settings WHERE k='$keyEsc' LIMIT 1");
  if (!$res || !($row = $res->fetch_assoc())) {
    return null;
  }
  $raw = trim((string)($row['v'] ?? ''));
  if ($raw === '') {
    return null;
  }
  $ts = ctype_digit($raw) ? (int)$raw : (int)strtotime($raw);
  if ($ts <= 0) {
    return null;
  }
  $age = time() - $ts;
  if ($age < 0 || $age > $maxAgeSec) {
    return null;
  }
  return $age;
}

function worker_is_template_symbol(string $symbol): bool {
  $symbol = strtoupper(trim($symbol));
  return $symbol === 'TEMPLATE' || str_contains($symbol, 'TEMPLATE');
}

/** Активні jobs у черзі згруповані по стратегії. */
function worker_queue_by_strategy(mysqli $db): array {
  $res = $db->query("
    SELECT
      jq.strategy_id,
      jq.symbol,
      s.user_id,
      s.status AS strategy_status,
      s.last_tick_at,
      TIMESTAMPDIFF(SECOND, s.last_tick_at, NOW()) AS tick_lag_sec,
      SUM(jq.status = 'queued') AS queued,
      SUM(jq.status = 'processing') AS processing
    FROM job_queue jq
    LEFT JOIN strategies s ON s.id = jq.strategy_id
    WHERE jq.status IN ('queued', 'processing')
    GROUP BY jq.strategy_id, jq.symbol, s.user_id, s.status, s.last_tick_at
    ORDER BY processing DESC, queued DESC, jq.strategy_id ASC
    LIMIT 100
  ");
  $rows = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $rows[] = [
      'strategy_id' => (int)($r['strategy_id'] ?? 0),
      'symbol' => (string)($r['symbol'] ?? ''),
      'user_id' => (int)($r['user_id'] ?? 0),
      'strategy_status' => (string)($r['strategy_status'] ?? ''),
      'last_tick_at' => (string)($r['last_tick_at'] ?? ''),
      'tick_lag_sec' => isset($r['tick_lag_sec']) ? (int)$r['tick_lag_sec'] : null,
      'queued' => (int)($r['queued'] ?? 0),
      'processing' => (int)($r['processing'] ?? 0),
      'is_template' => worker_is_template_symbol((string)($r['symbol'] ?? '')),
    ];
  }
  return $rows;
}

/** Dry-run або apply: прибрати TEMPLATE jobs з queued/processing. */
function worker_cleanup_template_queue(mysqli $db, bool $apply = false): array {
  $res = $db->query("
    SELECT jq.id, jq.strategy_id, jq.symbol, jq.status
    FROM job_queue jq
    INNER JOIN strategies s ON s.id = jq.strategy_id
    WHERE jq.status IN ('queued', 'processing')
      AND (s.symbol = 'TEMPLATE' OR s.symbol LIKE '%TEMPLATE%')
    ORDER BY jq.id
  ");
  $jobs = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $jobs[] = [
      'job_id' => (int)($r['id'] ?? 0),
      'strategy_id' => (int)($r['strategy_id'] ?? 0),
      'symbol' => (string)($r['symbol'] ?? ''),
      'status' => (string)($r['status'] ?? ''),
    ];
  }
  $updated = 0;
  if ($apply && $jobs !== []) {
    $db->query("
      UPDATE job_queue jq
      INNER JOIN strategies s ON s.id = jq.strategy_id
      SET jq.status = 'done',
          jq.locked_at = NULL,
          jq.worker_id = NULL,
          jq.updated_at = NOW()
      WHERE jq.status IN ('queued', 'processing')
        AND (s.symbol = 'TEMPLATE' OR s.symbol LIKE '%TEMPLATE%')
    ");
    $updated = (int)$db->affected_rows;
  }
  return [
    'apply' => $apply,
    'found' => count($jobs),
    'updated' => $updated,
    'jobs' => $jobs,
  ];
}
