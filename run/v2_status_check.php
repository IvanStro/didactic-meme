<?php
declare(strict_types=1);

/** CLI: швидкий статус V2 / RoyalQ path на проді. */
$root = dirname(__DIR__);
require $root . '/engine_v2/bootstrap.php';
require $root . '/engine_v2/engine_mode.php';
require_once $root . '/engine_v3/queue_config.php';

$db = db();

echo '=== V2 status ' . date('c') . " ===\n";
echo 'ENGINE_MODE=' . engine_mode() . "\n";
echo 'QUEUE_BACKEND=' . queue_backend() . "\n";
if (function_exists('redis_available')) {
    require_once $root . '/engine_v3/redis_client.php';
    echo 'redis_ok=' . (redis_available() ? '1' : '0') . "\n";
}
if (function_exists('engine_v2_live_enabled') && engine_v2_live_enabled()) {
    echo "path=all running → V2-signal (RoyalQ live)\n";
} else {
    echo 'canary=' . implode(',', engine_v2_canary_strategy_ids()) . "\n";
    echo 'hybrid_extra=' . implode(',', engine_hybrid_extra_strategy_ids()) . "\n";
    echo 'hybrid_pct=' . engine_hybrid_exec_percent() . "\n";
}
echo 'signal_exec=' . (engine_signal_exec_enabled() ? '1' : '0') . "\n\n";

echo "--- running strategies ---\n";
$r = $db->query("SELECT id, symbol, user_id FROM strategies WHERE status='running' ORDER BY id");
while ($s = $r->fetch_assoc()) {
    $sid = (int) $s['id'];
    $path = engine_strategy_uses_signal_exec($sid) ? 'V2-signal' : 'V1-poll';
    echo "{$sid} {$s['symbol']} uid={$s['user_id']} {$path}\n";
}

echo "\n--- signal_queue last 1h ---\n";
if (queue_use_redis_pop() || queue_use_redis_push()) {
    require_once $root . '/engine_v3/redis_signal_queue.php';
    echo 'redis_queue ' . json_encode(redis_signal_queue_counts(), JSON_UNESCAPED_UNICODE) . "\n";
}
$q = $db->query(
    "SELECT status, COUNT(*) c FROM signal_queue WHERE created_at >= NOW() - INTERVAL 1 HOUR GROUP BY status"
);
while ($q && ($x = $q->fetch_assoc())) {
    echo "{$x['status']}={$x['c']}\n";
}
$q2 = $db->query("SELECT COUNT(*) c FROM signal_queue WHERE created_at >= NOW() - INTERVAL 10 MINUTE");
echo 'last10min=' . (int) (($q2->fetch_assoc()['c'] ?? 0)) . "\n";

$q3 = $db->query(
    "SELECT COUNT(*) c FROM signal_queue WHERE status='processing'"
);
echo 'processing_now=' . (int) (($q3->fetch_assoc()['c'] ?? 0)) . "\n";

echo "\n--- signal_queue done/24h by sid ---\n";
$r = $db->query(
    "SELECT strategy_id, COUNT(*) c FROM signal_queue
     WHERE status='done' AND updated_at >= NOW() - INTERVAL 24 HOUR
     GROUP BY strategy_id ORDER BY c DESC"
);
while ($r && ($x = $r->fetch_assoc())) {
    echo "sid={$x['strategy_id']} done={$x['c']}\n";
}

echo "\n--- trade_ledger 7d (V2 sids) ---\n";
$r2 = $db->query(
    "SELECT strategy_id, side, COUNT(*) c, MAX(created_at) last_at
     FROM trade_ledger
     WHERE strategy_id IN (15,16,17,22,18) AND created_at >= NOW() - INTERVAL 7 DAY
     GROUP BY strategy_id, side ORDER BY strategy_id, side"
);
while ($r2 && ($x = $r2->fetch_assoc())) {
    echo "sid={$x['strategy_id']} {$x['side']} n={$x['c']} last={$x['last_at']}\n";
}

echo "\n--- recent exec picks (log tail) ---\n";
$log = $root . '/logs/signal_exec_worker.log';
if (is_readable($log)) {
    $lines = [];
    $fh = fopen($log, 'rb');
    if ($fh) {
        fseek($fh, -12000, SEEK_END);
        while (($line = fgets($fh)) !== false) {
            if (str_contains($line, 'picked') || str_contains($line, 'done') || str_contains($line, 'failed')) {
                $lines[] = trim($line);
            }
        }
        fclose($fh);
    }
    foreach (array_slice($lines, -8) as $ln) {
        echo $ln . "\n";
    }
}
