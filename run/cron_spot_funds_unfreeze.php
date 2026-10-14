<?php
declare(strict_types=1);

/**
 * Розморозка користувачів з spot_funds_frozen після поповнення Binance.
 * Cron: every 10 minutes
 *   php run/cron_spot_funds_unfreeze.php
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/www/api/_user_keys.php';
require_once $root . '/engine/engine_logic.php';
require_once $root . '/engine/spot_funds_freeze.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

require_once $root . '/run/cron_singleton.php';
cron_singleton_acquire('cron_spot_funds_unfreeze');

$db = db();
$res = $db->query("SELECT k FROM app_settings WHERE k LIKE 'spot_funds_frozen_u%'");
if (!$res) {
    echo date('c') . " no frozen keys\n";
    exit(0);
}
$unfrozen = 0;
$checked = 0;
while ($row = $res->fetch_assoc()) {
    $k = (string)($row['k'] ?? '');
    if (!preg_match('/^spot_funds_frozen_u(\d+)$/', $k, $m)) {
        continue;
    }
    $uid = (int)$m[1];
    if ($uid <= 0 || !spot_funds_user_is_frozen($db, $uid)) {
        continue;
    }
    $checked++;
    [$apiKey, $secret] = user_keys($db, $uid);
    if (!$apiKey || !$secret) {
        continue;
    }
    $st = $db->query(
        "SELECT id, symbol, fba FROM strategies WHERE user_id={$uid} AND status='running' ORDER BY id ASC LIMIT 1"
    );
    if (!$st || !($s = $st->fetch_assoc())) {
        continue;
    }
    $sid = (int)($s['id'] ?? 0);
    $sym = (string)($s['symbol'] ?? '');
    $fba = (float)($s['fba'] ?? 0);
    if ($sym === '' || $sym === 'TEMPLATE') {
        continue;
    }
    if (spot_funds_try_unfreeze_user($db, $uid, $sym, $apiKey, $secret, $fba)) {
        $unfrozen++;
        echo date('c') . " unfrozen uid={$uid} sid={$sid} symbol={$sym}\n";
    }
}
echo date('c') . " done checked={$checked} unfrozen={$unfrozen}\n";
