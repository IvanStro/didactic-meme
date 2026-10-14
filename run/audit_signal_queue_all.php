<?php
declare(strict_types=1);
/**
 * Audit signal queue coverage for all running/paused strategies.
 *   php run/audit_signal_queue_all.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine_v2/engine_mode.php';
require_once $root . '/engine_v3/queue_config.php';
require_once $root . '/engine_v3/redis_signal_queue.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = db();
echo date('c') . ' ENGINE_MODE=' . engine_mode()
    . ' queue=' . queue_backend() . '/' . queue_pop_backend()
    . ' exec=' . (engine_signal_exec_enabled() ? 'on' : 'off')
    . ' v2_live=' . (engine_v2_live_enabled() ? 'yes' : 'no') . PHP_EOL;

$r = $db->query(
    "SELECT s.id AS sid, s.user_id AS uid, u.email, s.symbol, s.status,
            (SELECT COUNT(*) FROM signal_queue sq
             WHERE sq.strategy_id = s.id AND sq.status IN ('queued','processing')) AS pending,
            (SELECT MAX(sq.updated_at) FROM signal_queue sq
             WHERE sq.strategy_id = s.id AND sq.status = 'done') AS last_done
     FROM strategies s
     LEFT JOIN users u ON u.id = s.user_id
     WHERE s.status IN ('running','paused')
     ORDER BY s.user_id, s.id"
);

printf("%-5s %-5s %-28s %-10s %-8s %-7s %-5s %s\n", 'sid', 'uid', 'email', 'symbol', 'status', 'v2exec', 'pend', 'last_done');
while ($r && ($row = $r->fetch_assoc())) {
    $sid = (int)$row['sid'];
    $v2 = engine_strategy_uses_signal_exec($sid) ? 'YES' : 'no';
    $email = (string)($row['email'] ?? '');
    if (strlen($email) > 28) {
        $email = substr($email, 0, 25) . '...';
    }
    printf(
        "%-5d %-5d %-28s %-10s %-8s %-7s %-5s %s\n",
        $sid,
        (int)$row['uid'],
        $email,
        (string)$row['symbol'],
        (string)$row['status'],
        $v2,
        (string)$row['pending'],
        (string)($row['last_done'] ?? '-')
    );
}

$stuck = $db->query(
    "SELECT id, strategy_id, signal_name, status, created_at, worker_id
     FROM signal_queue WHERE status IN ('queued','processing') ORDER BY id"
);
echo PHP_EOL . 'STUCK jobs: ' . ($stuck ? $stuck->num_rows : 0) . PHP_EOL;
while ($stuck && ($row = $stuck->fetch_assoc())) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$redis = redis_signal_queue_counts();
echo PHP_EOL . 'redis_pending=' . ($redis['queued'] ?? 0) . ' redis_busy=' . ($redis['processing'] ?? 0) . PHP_EOL;

$r2 = redis_client();
if ($r2) {
    $orphanDedupe = 0;
    foreach ($r2->keys('strim:sq:dedupe:*') ?: [] as $key) {
        $orphanDedupe++;
    }
    echo 'redis_dedupe_keys=' . $orphanDedupe . PHP_EOL;
}
