<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = @new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, "DB connect failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$sql = (string)file_get_contents($root . '/run/migrations/20260528_create_web_analytics_events.sql');
if (trim($sql) === '') {
    fwrite(STDERR, "Migration SQL is empty\n");
    exit(1);
}

if (!$db->multi_query($sql)) {
    fwrite(STDERR, "Migration failed: {$db->error}\n");
    exit(1);
}
do {
    if ($res = $db->store_result()) {
        $res->free();
    }
} while ($db->more_results() && $db->next_result());

echo "OK web_analytics_events migration applied\n";
