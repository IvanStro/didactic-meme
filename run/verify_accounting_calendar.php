<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_accounting.php';
require_once __DIR__ . '/../www/api/_accounting_compliance.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, "db error\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$year = (int)($argv[1] ?? date('Y'));
$settings = accounting_compliance_load($db);

$t0 = microtime(true);
$events = accounting_compliance_build_calendar($settings, $year);
$t1 = microtime(true);
$stats = accounting_compliance_calendar_stats($events);
$t2 = microtime(true);
$summary = accounting_monthly_summary($db, date('Y-m'));
$t3 = microtime(true);

echo "year={$year} events=" . count($events) . ' build_ms=' . round(($t1 - $t0) * 1000) . "\n";
echo 'stats file=' . json_encode($stats['file'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
echo 'summary_ms=' . round(($t3 - $t2) * 1000) . ' revenue=' . ($summary['revenue_uah'] ?? 0) . "\n";
echo "ok\n";
