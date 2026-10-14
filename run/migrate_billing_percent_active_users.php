<?php
declare(strict_types=1);

/**
 * Migrate active/paid users → +500 UAH wallet credit @ Binance FX.
 *   php run/migrate_billing_percent_active_users.php [--dry-run]
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_billing_wallet.php';
require_once $root . '/www/api/_billing_access.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$dry = in_array('--dry-run', $argv ?? [], true);

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

if (!billing_users_has_wallet_cols($db)) {
    fwrite(STDERR, "Run apply_billing_percent_migration.php first\n");
    exit(1);
}

$fx = billing_binance_usdt_uah_rate();
if (empty($fx['ok'])) {
    fwrite(STDERR, 'FX fail: ' . ($fx['error'] ?? '?') . PHP_EOL);
    exit(1);
}
$rate = (float)$fx['rate'];
$usdt = billing_uah_to_usdt(BILLING_MIN_DEPOSIT_UAH, $rate);
echo "FX USDTUAH={$rate} → 500 UAH = {$usdt} USDT\n";
echo $dry ? "DRY-RUN\n" : "LIVE\n";

// Active/paid: billing_status=active OR paid_until in future
$q = $db->query("
  SELECT id, email, billing_status, paid_until, wallet_activated_at, balance_usdt
  FROM users
  WHERE billing_status='active'
     OR (paid_until IS NOT NULL AND paid_until > NOW())
  ORDER BY id ASC
");

$n = 0;
$skipped = 0;
while ($q && ($u = $q->fetch_assoc())) {
    $uid = (int)$u['id'];
    $email = (string)$u['email'];
    // Skip if already has migration_credit
    $ex = $db->query(
        "SELECT id FROM billing_ledger WHERE user_id={$uid} AND entry_type='migration_credit' LIMIT 1"
    );
    if ($ex && $ex->fetch_assoc()) {
        echo "SKIP {$uid} {$email} (already migrated)\n";
        $skipped++;
        continue;
    }
    if ($dry) {
        echo "WOULD {$uid} {$email}\n";
        $n++;
        continue;
    }
    $ref = 'migration_500uah_' . $uid . '_' . date('Ymd');
    $res = billing_wallet_credit(
        $db,
        $uid,
        'migration_credit',
        $usdt,
        BILLING_MIN_DEPOSIT_UAH,
        $rate,
        $ref,
        null,
        null,
        ['source' => 'cutover_active_paid']
    );
    if (empty($res['ok'])) {
        echo "FAIL {$uid} {$email} " . ($res['error'] ?? '') . "\n";
        continue;
    }
    billing_resume_strategies_after_renewal($db, $uid);
    echo "OK {$uid} {$email} +{$usdt} USDT ledger=" . ($res['ledger_id'] ?? 0) . "\n";
    $n++;
}

echo "done credited={$n} skipped={$skipped}\n";
