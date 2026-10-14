<?php
declare(strict_types=1);

/**
 * Cron (~1 min): exec worker для V2 canary.
 *   php run/cron_ensure_signal_exec_worker.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
cron_singleton_acquire('cron_ensure_signal_exec_worker');

require_once $root . '/engine_v2/engine_mode.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_ensure_signal_exec_worker');

if (!engine_signal_exec_enabled()) {
    echo date('c') . ' skip: signal exec off (V2_CANARY_EXEC / canary ids / hybrid pct)' . PHP_EOL;
    exit(0);
}

$pidPath = $root . '/services/signal/signal_exec_worker.pid';
$startSh = $root . '/run/start_signal_exec_worker.sh';
$logPath = $root . '/logs/signal_exec_worker.log';

function signal_exec_worker_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

$alive = false;
if (is_readable($pidPath)) {
    $pid = (int)trim((string)file_get_contents($pidPath));
    $alive = signal_exec_worker_pid_alive($pid);
}

if (!$alive) {
    @unlink($pidPath);
    if (!is_executable($startSh)) {
        @chmod($startSh, 0755);
    }
    if (is_executable($startSh)) {
        @exec('bash ' . escapeshellarg($startSh) . ' 2>&1', $out, $code);
        $line = date('c') . ' cron_ensure_signal_exec_worker: start code=' . $code . ' ' . trim(implode(' ', $out)) . PHP_EOL;
        @file_put_contents($logPath, $line, FILE_APPEND);
        echo $line;
        exit($code === 0 ? 0 : 1);
    }
    fwrite(STDERR, "start script missing: {$startSh}\n");
    exit(1);
}

echo date('c') . ' signal_exec_worker ok pid=' . trim((string)file_get_contents($pidPath)) . PHP_EOL;
