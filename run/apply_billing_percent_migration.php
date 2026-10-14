<?php
declare(strict_types=1);

/**
 * Apply billing percent wallet columns + tables.
 *   php run/apply_billing_percent_migration.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, 'db: ' . $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

function col_exists(mysqli $db, string $table, string $col): bool
{
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($col);
    $r = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $r && $r->num_rows > 0;
}

function ensure_col(mysqli $db, string $table, string $col, string $ddl): void
{
    if (col_exists($db, $table, $col)) {
        echo "OK column {$table}.{$col}\n";
        return;
    }
    if (!$db->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}")) {
        fwrite(STDERR, "FAIL {$table}.{$col}: " . $db->error . PHP_EOL);
        exit(1);
    }
    echo "ADD {$table}.{$col}\n";
}

ensure_col($db, 'users', 'balance_usdt', 'balance_usdt DECIMAL(18,8) NOT NULL DEFAULT 0');
ensure_col($db, 'users', 'fee_shortfall_usdt', 'fee_shortfall_usdt DECIMAL(18,8) NOT NULL DEFAULT 0');
ensure_col($db, 'users', 'wallet_activated_at', 'wallet_activated_at DATETIME NULL DEFAULT NULL');

$sqlLedger = "
CREATE TABLE IF NOT EXISTS billing_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_type VARCHAR(32) NOT NULL,
  amount_uah DECIMAL(18,2) NULL DEFAULT NULL,
  fx_rate DECIMAL(18,8) NULL DEFAULT NULL,
  amount_usdt DECIMAL(18,8) NOT NULL,
  balance_after_usdt DECIMAL(18,8) NOT NULL,
  trade_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
  payment_ref VARCHAR(191) NULL DEFAULT NULL,
  referral_accrual_id BIGINT UNSIGNED NULL DEFAULT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bl_user_created (user_id, created_at),
  KEY idx_bl_type (entry_type),
  UNIQUE KEY uq_bl_trade_fee (trade_ledger_id, entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";
if (!$db->query($sqlLedger)) {
    fwrite(STDERR, 'billing_ledger: ' . $db->error . PHP_EOL);
    exit(1);
}
echo "OK billing_ledger\n";

$sqlAcc = "
CREATE TABLE IF NOT EXISTS referral_deposit_accruals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_user_id INT UNSIGNED NOT NULL,
  referred_user_id INT UNSIGNED NOT NULL,
  deposit_ledger_id BIGINT UNSIGNED NOT NULL,
  deposit_usdt DECIMAL(18,8) NOT NULL,
  reward_percent DECIMAL(8,2) NOT NULL DEFAULT 20.00,
  reward_usdt DECIMAL(18,8) NOT NULL,
  status ENUM('pending','settled','cancelled') NOT NULL DEFAULT 'pending',
  settled_at DATETIME NULL DEFAULT NULL,
  settle_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rda_deposit (deposit_ledger_id),
  KEY idx_rda_referrer_status (referrer_user_id, status),
  KEY idx_rda_pending (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";
if (!$db->query($sqlAcc)) {
    fwrite(STDERR, 'referral_deposit_accruals: ' . $db->error . PHP_EOL);
    exit(1);
}
echo "OK referral_deposit_accruals\n";

// Feature flag
$db->query(
    "INSERT INTO app_settings (k, v) VALUES ('billing_model', 'percent')
     ON DUPLICATE KEY UPDATE v='percent'"
);
echo "OK app_settings.billing_model=percent\n";
echo "DONE\n";
