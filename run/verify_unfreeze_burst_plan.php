<?php
declare(strict_types=1);
/**
 * Readonly: план burst після розморозки для стратегії.
 * Usage: php run/verify_unfreeze_burst_plan.php <strategy_id> [spot_override]
 */
$root = dirname(__DIR__);
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine/engine_helpers.php';
require_once $root . '/engine/engine_logic.php';
require_once $root . '/engine/ladder_unfreeze_burst.php';

$sid = isset($argv[1]) ? (int)$argv[1] : 0;
if ($sid <= 0) {
    fwrite(STDERR, "Usage: php run/verify_unfreeze_burst_plan.php <strategy_id> [spot]\n");
    exit(1);
}

$db = db();
$rs = $db->query("SELECT id, symbol, user_id, owner_id, fba FROM strategies WHERE id={$sid} LIMIT 1");
if (!$rs || !($st = $rs->fetch_assoc())) {
    fwrite(STDERR, "strategy {$sid} not found\n");
    exit(1);
}
$symbol = strtoupper((string)($st['symbol'] ?? ''));
$uid = (int)($st['user_id'] ?? 0);
if ($uid <= 0) {
    $uid = (int)($st['owner_id'] ?? 0);
}
$fba = (float)($st['fba'] ?? 0);

$spot = isset($argv[2]) ? (float)$argv[2] : 0.0;
if ($spot <= 0.0 && $symbol !== '') {
    if (function_exists('ws_ticker_price')) {
        $spot = (float)ws_ticker_price($symbol);
    }
}
if ($spot <= 0.0) {
    fwrite(STDERR, "spot price unavailable; pass as 2nd arg\n");
    exit(1);
}

$plan = ladder_unfreeze_burst_plan_readonly($db, $sid, $spot, $fba);
echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($plan['ok'] ? 0 : 2);
