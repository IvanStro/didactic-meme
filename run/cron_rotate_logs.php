<?php
declare(strict_types=1);

/**
 * Обрізання великих логів (shared hosting, без logrotate).
 * CLI: php run/cron_rotate_logs.php [max_mb=5]
 *
 * Тримує всі logs/*.log понад max_mb (лишає хвіст ~512 KiB),
 * окрім skip-списку (rotate_logs.log, thin_exec.log — shadow/canary evidence).
 */
$root = dirname(__DIR__);
require_once __DIR__ . '/cron_singleton.php';

cron_singleton_acquire('cron_rotate_logs');
set_time_limit(120);

$maxMb = isset($argv[1]) ? max(1, min(50, (int) $argv[1])) : 5;
$maxBytes = $maxMb * 1024 * 1024;
$logDir = $root . '/logs';
$keep = (int) min($maxBytes, 512 * 1024);

$skip = [
    'rotate_logs.log' => true, // не ріжемо свій stdout-лог під час запису
    // Thin-exec shadow/canary evidence: truncate без архіву знищує B1/C gate (72h+).
    // Не обрізати; при потребі місце — окремий архів вручну / інший cron.
    'thin_exec.log' => true,
];

$report = [];
foreach (glob($logDir . '/*.log') ?: [] as $file) {
    $base = basename($file);
    if (isset($skip[$base])) {
        continue;
    }
    if (!is_file($file) || !is_writable($file)) {
        continue;
    }
    $sz = (int) filesize($file);
    if ($sz <= $maxBytes) {
        continue;
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        continue;
    }
    if ($sz > $keep) {
        fseek($fp, -$keep, SEEK_END);
    }
    $tail = stream_get_contents($fp) ?: '';
    fclose($fp);
    // Відкинути обірваний перший рядок після seek
    $nl = strpos($tail, "\n");
    if ($nl !== false && $nl < 4096) {
        $tail = substr($tail, $nl + 1);
    }
    $line = '[' . date('c') . "] log_rotate truncated was={$sz} bytes kept~=" . strlen($tail) . "\n";
    if (@file_put_contents($file, $line . $tail) === false) {
        continue;
    }
    $report[$base] = $sz;
}

echo date('c') . ' rotate_logs max_mb=' . $maxMb
    . ' files=' . count($report)
    . ' ' . json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
