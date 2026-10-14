<?php
declare(strict_types=1);
/**
 * Перебудова symbol_index.json з БД (фаза 3 V2).
 *   php run/rebuild_symbol_index.php
 */

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine/signal/symbol_index.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$data = symbol_index_rebuild($m);
$stats = symbol_index_stats();
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
