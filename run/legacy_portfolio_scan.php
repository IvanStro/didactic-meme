<?php
declare(strict_types=1);

/**
 * P0/P1 CLI: аналіз спадку портфеля.
 * Usage: php run/legacy_portfolio_scan.php --uid=10 [--json]
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

require_once $root . '/www/api/_user_keys.php';
require_once $root . '/www/api/_binance_utils.php';
require_once $root . '/www/api/_legacy_portfolio.php';

$db = new mysqli(
    (string)$_ENV['DB_HOST'],
    (string)$_ENV['DB_USER'],
    (string)$_ENV['DB_PASS'],
    (string)$_ENV['DB_NAME']
);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$uid = 0;
$email = '';
$asJson = in_array('--json', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--uid=')) {
        $uid = (int)substr($arg, 6);
    }
    if (str_starts_with($arg, '--email=')) {
        $email = trim(substr($arg, 8));
    }
}
if ($uid <= 0 && $email !== '') {
    $ee = $db->real_escape_string($email);
    $r = $db->query("SELECT id FROM users WHERE email='$ee' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $uid = $row ? (int)$row['id'] : 0;
}
if ($uid <= 0) {
    fwrite(STDERR, "Usage: php run/legacy_portfolio_scan.php --uid=N | --email=...\n");
    exit(1);
}

[$apiKey, $secret] = user_keys($db, $uid);
if (!$apiKey || !$secret) {
    fwrite(STDERR, "No Binance keys for uid=$uid\n");
    exit(1);
}

$out = legacy_portfolio_scan_user($db, $uid, $apiKey, $secret);
if (empty($out['ok'])) {
    fwrite(STDERR, json_encode($out) . "\n");
    exit(1);
}

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cfg = $out['settings'];
echo "=== LEGACY PORTFOLIO SCAN ===\n";
echo "user #{$out['user']['id']} {$out['user']['email']}\n";
echo "free USDT: {$out['free_usdt']}\n";
echo "settings: dust={$cfg['dust_usdt']} dd_ok={$cfg['dd_ok_pct']}% dd_deep={$cfg['dd_deep_pct']}%\n\n";

printf("%-10s %8s %7s %-8s %-14s %s\n", 'SYMBOL', 'NOTIONAL', 'DD%', 'KLASS', 'PRIMARY', 'REASONS');
echo str_repeat('-', 100) . "\n";
foreach ($out['items'] as $it) {
    printf(
        "%-10s %8.2f %7s %-8s %-14s %s\n",
        $it['symbol'],
        $it['notional'],
        $it['dd_pct'] !== null ? number_format((float)$it['dd_pct'], 1, '.', '') : 'n/a',
        $it['klass'],
        $it['primary'],
        implode(',', $it['reasons'])
    );
}
echo "\n=== SUMMARY ===\n";
foreach ($out['summary'] as $k => $v) {
    echo "  $k: $v\n";
}
