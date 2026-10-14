<?php
declare(strict_types=1);

/** CLI: повернути processing → queued після падіння exec worker. */
$root = dirname(__DIR__);
require $root . '/engine_v2/bootstrap.php';
require $root . '/engine_v2/signal_queue.php';

$minutes = isset($argv[1]) ? (int)$argv[1] : 5;
signal_queue_reap_stale($minutes);
echo date('c') . " reaped processing older than {$minutes} min\n";

$db = db();
$r = $db->query(
    "SELECT status, COUNT(*) c FROM signal_queue WHERE status IN ('queued','processing') GROUP BY status"
);
while ($r && ($row = $r->fetch_assoc())) {
    echo ($row['status'] ?? '') . '=' . ($row['c'] ?? 0) . "\n";
}
