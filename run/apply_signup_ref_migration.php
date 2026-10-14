<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($m->connect_errno) {
  fwrite(STDERR, "DB_CONNECT_FAIL: " . $m->connect_error . PHP_EOL);
  exit(2);
}

$dbName = $_ENV['DB_NAME'];
$colExists = false;
$colQ = $m->query("
  SELECT COUNT(*) AS c
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = '" . $m->real_escape_string($dbName) . "'
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'signup_ref_code_used'
");
if ($colQ && ($row = $colQ->fetch_assoc())) $colExists = ((int)$row['c'] > 0);

if (!$colExists) {
  if (!$m->query("ALTER TABLE users ADD COLUMN signup_ref_code_used varchar(32) DEFAULT NULL AFTER referred_by_user_id")) {
    fwrite(STDERR, "MIGRATION_FAIL_ADD_COLUMN: " . $m->error . PHP_EOL);
    exit(4);
  }
}

$idxExists = false;
$idxQ = $m->query("
  SELECT COUNT(*) AS c
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = '" . $m->real_escape_string($dbName) . "'
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_signup_ref_code_used'
");
if ($idxQ && ($row = $idxQ->fetch_assoc())) $idxExists = ((int)$row['c'] > 0);

if (!$idxExists) {
  if (!$m->query("ALTER TABLE users ADD KEY idx_signup_ref_code_used (signup_ref_code_used)")) {
    fwrite(STDERR, "MIGRATION_FAIL_ADD_INDEX: " . $m->error . PHP_EOL);
    exit(5);
  }
}

if (!$m->query("UPDATE users SET signup_ref_code_used = COALESCE(signup_ref_code_used, ref_code) WHERE signup_ref_code_used IS NULL")) {
  fwrite(STDERR, "MIGRATION_FAIL_BACKFILL: " . $m->error . PHP_EOL);
  exit(6);
}

echo "MIGRATION_OK" . PHP_EOL;
