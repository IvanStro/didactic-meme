<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($m->connect_errno) {
  fwrite(STDERR, "DB_CONNECT_FAIL: " . $m->connect_error . PHP_EOL);
  exit(2);
}

$sqlFile = __DIR__ . '/migrations/20260427_create_admin_action_audit.sql';
$sql = @file_get_contents($sqlFile);
if (!is_string($sql) || trim($sql) === '') {
  fwrite(STDERR, "SQL_FILE_FAIL: $sqlFile" . PHP_EOL);
  exit(3);
}

if (!$m->multi_query($sql)) {
  fwrite(STDERR, "MIGRATION_FAIL: " . $m->error . PHP_EOL);
  exit(4);
}
do {
  if ($r = $m->store_result()) $r->free();
} while ($m->more_results() && $m->next_result());

echo "MIGRATION_OK" . PHP_EOL;
