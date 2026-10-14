<?php
declare(strict_types=1);
/**
 * Apply: php run/apply_legacy_asset_reviews_migration.php
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_error) {
    fwrite(STDERR, "DB fail\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS legacy_asset_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  symbol VARCHAR(32) NOT NULL,
  notional DECIMAL(18,8) NULL,
  qty DECIMAL(24,12) NULL,
  spot DECIMAL(24,12) NULL,
  avg_cost DECIMAL(24,12) NULL,
  dd_pct DECIMAL(10,4) NULL,
  klass VARCHAR(16) NULL,
  primary_action VARCHAR(32) NULL,
  user_action VARCHAR(32) NULL,
  strategy_id BIGINT NULL,
  payload_json MEDIUMTEXT NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_user_symbol (user_id, symbol),
  KEY idx_legacy_user_resolved (user_id, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

if (!$db->query($sql)) {
    fwrite(STDERR, "CREATE failed: {$db->error}\n");
    exit(1);
}
echo "OK legacy_asset_reviews\n";
