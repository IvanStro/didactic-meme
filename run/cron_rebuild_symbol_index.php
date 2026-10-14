<?php
declare(strict_types=1);

/**
 * Cron (кожні 5 хв): перебудова symbol_index.json з БД (V2 фаза 3).
 *   php run/cron_rebuild_symbol_index.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
cron_singleton_acquire('cron_rebuild_symbol_index');

require_once $root . '/engine/signal/symbol_index.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, date('c') . ' db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

symbol_index_rebuild($m);
$stats = symbol_index_stats();
echo date('c') . ' ok ' . json_encode($stats, JSON_UNESCAPED_UNICODE) . PHP_EOL;
