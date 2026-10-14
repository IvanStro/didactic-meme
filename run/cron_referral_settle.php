<?php
declare(strict_types=1);

/**
 * Settle referral deposit accruals on days 01 and 16 (Kyiv).
 * Cron suggestion (twice daily check is fine; script no-ops other days):
 *   5 0 * * * php .../cron_referral_settle.php
 *
 *   php run/cron_referral_settle.php [--force]
 */

$root = dirname(__DIR__);
require_once __DIR__ . '/cron_singleton.php';
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_billing_wallet.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

cron_singleton_acquire('cron_referral_settle');

$force = in_array('--force', $argv ?? [], true);
$day = (int)date('j'); // server local — prod uses Kyiv-ish
if (!$force && $day !== 1 && $day !== 16) {
    echo date('c') . " skip day={$day} (only 01/16; use --force)\n";
    exit(0);
}

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

// Settle accruals created before today 00:00
$before = date('Y-m-d 00:00:00');
$res = billing_referral_settle_pending($db, $before);
echo date('c') . ' settled=' . $res['settled'] . ' usdt=' . $res['amount_usdt'] . " before={$before}\n";
