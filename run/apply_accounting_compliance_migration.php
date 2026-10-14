<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, "DB connect error\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$sql = file_get_contents(__DIR__ . '/migrations/20260617_accounting_compliance_settings.sql');
if (!is_string($sql) || $sql === '') {
    fwrite(STDERR, "migration file missing\n");
    exit(1);
}

if (!$db->multi_query($sql)) {
    fwrite(STDERR, 'migration failed: ' . $db->error . "\n");
    exit(1);
}
while ($db->more_results() && $db->next_result()) {
}

echo "ok: accounting_compliance_settings\n";
