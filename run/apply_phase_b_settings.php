<?php
declare(strict_types=1);
require __DIR__ . '/../engine_v2/bootstrap.php';
$db = db();
$pairs = ['v2_signal_reconcile_sec' => '30', 'worker_target_count' => '2'];
foreach ($pairs as $k => $v) {
    $ke = $db->real_escape_string($k);
    $ve = $db->real_escape_string($v);
    $db->query("INSERT INTO app_settings (k,v) VALUES ('{$ke}','{$ve}') ON DUPLICATE KEY UPDATE v=VALUES(v)");
}
echo "ok settings\n";
