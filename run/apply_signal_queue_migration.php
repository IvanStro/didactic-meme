<?php
declare(strict_types=1);
/**
 *   php run/apply_signal_queue_migration.php
 */

require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$sql = file_get_contents(__DIR__ . '/migrations/20260520_create_signal_queue.sql');
if ($sql === false) {
    fwrite(STDERR, "migration file missing\n");
    exit(1);
}
if (!$m->multi_query($sql)) {
    fwrite(STDERR, $m->error . PHP_EOL);
    exit(1);
}
while ($m->more_results() && $m->next_result()) {
}
echo "signal_queue migration ok\n";
