<?php
declare(strict_types=1);
/**
 * Dry-run burst після розморозки для всіх running стратегій користувача.
 * Usage: php run/verify_user_unfreeze_burst_all.php <user_id> [extra_usdt]
 */
$root = dirname(__DIR__);
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine/engine_helpers.php';
require_once $root . '/engine/engine_logic.php';
require_once $root . '/engine/ladder_unfreeze_burst.php';
require_once $root . '/engine/spot_funds_freeze.php';

$uid = isset($argv[1]) ? (int)$argv[1] : 0;
$extraUsdt = isset($argv[2]) ? (float)$argv[2] : 0.0;
if ($uid <= 0) {
    fwrite(STDERR, "Usage: php run/verify_user_unfreeze_burst_all.php <user_id> [extra_usdt]\n");
    exit(1);
}

$db = db();
$out = ladder_user_unfreeze_burst_report($db, $uid, $extraUsdt);
if (empty($out['ok'])) {
    fwrite(STDERR, (string)($out['error'] ?? 'failed') . "\n");
    exit(2);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
