<?php
declare(strict_types=1);

/**
 * Звіт SHADOW vs SIGNAL_DAEMON vs угоди (trade_ledger) + canary queue.
 *   php run/shadow_compare_report.php [hours]
 *
 * Логи: worker-slot-*.log ([SHADOW]), signal_daemon.log ([SIGNAL_DAEMON]),
 *       signal_exec_worker.log (picked/done), БД signal_queue.
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$hours = isset($argv[1]) ? max(1, min(168, (int)$argv[1])) : 24;
$logDir = $root . '/logs';
$sinceTs = time() - $hours * 3600;
$since = date('Y-m-d H:i:s', $sinceTs);

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$canaryIds = [];
$modeFile = $root . '/engine_v2/engine_mode.php';
if (is_file($modeFile)) {
    require_once $modeFile;
    if (function_exists('engine_v2_canary_strategy_ids')) {
        $canaryIds = engine_v2_canary_strategy_ids();
    }
}

/** @param list<string> $patterns */
function shadow_parse_log_lines(string $file, array $patterns, int $sinceTs): array
{
    $rows = [];
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return $rows;
    }
    while (($line = fgets($fh)) !== false) {
        $matched = false;
        foreach ($patterns as $p) {
            if (str_contains($line, $p)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            continue;
        }
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $tm)) {
            $ts = strtotime($tm[1]);
            if ($ts !== false && $ts < $sinceTs) {
                continue;
            }
        }
        if (!preg_match('/\[([A-Z0-9]+)\]\s+sid=(\d+)\s+signals=([^\s]+)/', $line, $mch)
            && !preg_match('/sid=(\d+)\s+signal=([^\s]+)/', $line, $mch2)) {
            if (preg_match('/picked #(\d+)\s+sid=(\d+)\s+signal=([^\s]+)/', $line, $ex)) {
                $rows[] = [
                    'kind' => 'exec_pick',
                    'sid' => (int)$ex[2],
                    'signals' => $ex[3],
                    'symbol' => '',
                    'line' => trim($line),
                ];
            }
            continue;
        }
        if (isset($mch[1])) {
            $rows[] = [
                'kind' => str_contains($line, '[SIGNAL_DAEMON]') ? 'daemon' : 'shadow',
                'symbol' => $mch[1],
                'sid' => (int)$mch[2],
                'signals' => $mch[3],
                'line' => trim($line),
            ];
        }
    }
    fclose($fh);
    return $rows;
}

$shadowRows = [];
foreach (glob($logDir . '/worker-slot-*.log') ?: [] as $file) {
    $shadowRows = array_merge($shadowRows, shadow_parse_log_lines($file, ['[SHADOW]'], $sinceTs));
}

$daemonRows = shadow_parse_log_lines(
    $logDir . '/signal_daemon.log',
    ['[SIGNAL_DAEMON]', '[CANARY_ENQUEUE]', '[SIGNAL_ENQUEUE]'],
    $sinceTs
);

$execRows = shadow_parse_log_lines(
    $logDir . '/signal_exec_worker.log',
    ['picked #', 'done #', 'failed #'],
    $sinceTs
);

$ledger = [];
$res = $m->query("
    SELECT strategy_id, symbol, event_type, side, level_idx, created_at
    FROM trade_ledger
    WHERE created_at >= '" . $m->real_escape_string($since) . "'
      AND event_type IN ('BUY0','BUY','SELL','SELL_SUB','CORE_SELL')
    ORDER BY created_at ASC
");
while ($res && ($r = $res->fetch_assoc())) {
    $sid = (int)$r['strategy_id'];
    $ledger[$sid][] = $r;
}

/** @param list<array<string,mixed>> $rows */
function shadow_agg_by_sid(array $rows): array
{
    $bySid = [];
    foreach ($rows as $row) {
        $sid = (int)($row['sid'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySid[$sid])) {
            $bySid[$sid] = ['samples' => 0, 'fire' => 0, 'ladder' => 0, 'none' => 0, 'enqueue' => 0];
        }
        $bySid[$sid]['samples']++;
        $sig = (string)($row['signals'] ?? '');
        if ($sig === 'none' || $sig === '') {
            $bySid[$sid]['none']++;
        }
        foreach (['MAIN_SELL_FIRE', 'BUY0_ELIGIBLE', 'MAIN_ARM'] as $fs) {
            if (str_contains($sig, $fs)) {
                $bySid[$sid]['fire']++;
            }
        }
        if (str_contains($sig, 'LADDER_L')) {
            $bySid[$sid]['ladder']++;
        }
        if (preg_match('/\[(?:CANARY|SIGNAL)_ENQUEUE\]/', (string)($row['line'] ?? ''))) {
            $bySid[$sid]['enqueue']++;
        }
    }
    return $bySid;
}

$aggShadow = shadow_agg_by_sid($shadowRows);
$aggDaemon = shadow_agg_by_sid(array_filter($daemonRows, static fn($r) => ($r['kind'] ?? '') === 'daemon'));
$aggEnqueue = shadow_agg_by_sid($daemonRows);

$allSids = array_unique(array_merge(
    array_keys($aggShadow),
    array_keys($aggDaemon),
    array_keys($ledger),
    $canaryIds
));
sort($allSids);

echo "=== V2 shadow / signal compare (since {$since}, hours={$hours}) ===" . PHP_EOL;
echo 'ENGINE_MODE=' . (getenv('ENGINE_MODE') ?: ($_ENV['ENGINE_MODE'] ?? '?')) . PHP_EOL;
echo 'V2_CANARY_STRATEGY_IDS=' . implode(',', $canaryIds) . PHP_EOL;
echo 'log_lines: shadow=' . count($shadowRows) . ' daemon=' . count($daemonRows) . ' exec=' . count($execRows) . PHP_EOL;

$sqStats = [];
$tq = $m->query("SHOW TABLES LIKE 'signal_queue'");
if ($tq && $tq->num_rows > 0) {
    $sq = $m->query("
        SELECT strategy_id, status, COUNT(*) c
        FROM signal_queue
        WHERE created_at >= '" . $m->real_escape_string($since) . "'
        GROUP BY strategy_id, status
    ");
    while ($sq && ($r = $sq->fetch_assoc())) {
        $sid = (int)$r['strategy_id'];
        $st = (string)$r['status'];
        $sqStats[$sid][$st] = (int)$r['c'];
    }
}

echo PHP_EOL . '--- per strategy ---' . PHP_EOL;
foreach ($allSids as $sid) {
    $sid = (int)$sid;
    $sh = $aggShadow[$sid] ?? ['samples' => 0, 'fire' => 0, 'ladder' => 0, 'none' => 0];
    $dm = $aggDaemon[$sid] ?? ['samples' => 0, 'fire' => 0, 'ladder' => 0, 'none' => 0];
    $en = $aggEnqueue[$sid]['enqueue'] ?? 0;
    $trades = count($ledger[$sid] ?? []);
    $canaryMark = in_array($sid, $canaryIds, true) ? ' CANARY' : '';
    $sqLine = '';
    if (isset($sqStats[$sid])) {
        $parts = [];
        foreach ($sqStats[$sid] as $st => $c) {
            $parts[] = "{$st}={$c}";
        }
        $sqLine = ' signal_queue[' . implode(',', $parts) . ']';
    }
    echo sprintf(
        "sid=%d%s | shadow(samples=%d fire=%d ladder=%d) | daemon(samples=%d fire=%d ladder=%d) | canary_enqueue=%d | ledger_trades=%d%s\n",
        $sid,
        $canaryMark,
        $sh['samples'],
        $sh['fire'],
        $sh['ladder'],
        $dm['samples'],
        $dm['fire'],
        $dm['ladder'],
        $en,
        $trades,
        $sqLine
    );
    if ($sh['fire'] > 0 && $dm['fire'] === 0 && $dm['samples'] > 0) {
        echo "  WARN: shadow fire hits but daemon fire=0 (eval drift?)\n";
    }
    if ($dm['fire'] > 0 && $sh['fire'] === 0 && $sh['samples'] > 0) {
        echo "  WARN: daemon fire hits but shadow fire=0\n";
    }
    if (in_array($sid, $canaryIds, true) && $en === 0 && $dm['fire'] > 0) {
        echo "  WARN: canary sid had fire signals but no CANARY_ENQUEUE (check V2_CANARY_EXEC)\n";
    }
}

echo PHP_EOL . '--- canary exec log picks (last 10) ---' . PHP_EOL;
$picks = array_filter($execRows, static fn($r) => ($r['kind'] ?? '') === 'exec_pick');
$picks = array_slice($picks, -10);
foreach ($picks as $p) {
    echo '  ' . ($p['line'] ?? '') . PHP_EOL;
}
if ($picks === []) {
    echo "  (none in window — normal if no actionable price yet)\n";
}

echo PHP_EOL . 'Done. See run/SHADOW_REPORT_PLAN.md for OK criteria.' . PHP_EOL;
