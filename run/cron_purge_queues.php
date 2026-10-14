<?php
declare(strict_types=1);

/**
 * Purge старих рядків job_queue / signal_queue (done, failed).
 * CLI: php run/cron_purge_queues.php [days]
 * Cron (daily): php run/cron_purge_queues.php 14
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';

cron_singleton_acquire('cron_purge_queues');
set_time_limit(120);

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$days = isset($argv[1]) ? max(3, min(90, (int) $argv[1])) : 14;

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'purge_queues: db ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$interval = "INTERVAL {$days} DAY";
$report = ['days' => $days, 'tables' => []];

foreach (['job_queue', 'signal_queue'] as $table) {
    $chk = $m->query("SHOW TABLES LIKE '{$table}'");
    if (!$chk || $chk->num_rows === 0) {
        continue;
    }
    foreach (['done', 'failed'] as $status) {
        $dateCol = $table === 'signal_queue' ? 'created_at' : 'updated_at';
        $m->query(
            "DELETE FROM {$table}
             WHERE status = '{$status}'
               AND {$dateCol} < (NOW() - {$interval})"
        );
        $report['tables'][$table][$status] = (int) $m->affected_rows;
    }
}

echo date('c') . ' purge_queues ' . json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
