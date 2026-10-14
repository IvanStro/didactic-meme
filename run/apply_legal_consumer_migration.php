<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');
$sql = file_get_contents(__DIR__ . '/migrations/20260923_legal_consumer.sql');
if (!$db->multi_query($sql)) throw new RuntimeException('Migration failed');
while ($db->more_results()) { if (!$db->next_result()) throw new RuntimeException('Migration failed'); }
echo "Legal acceptance and consumer request tables ready\n";
