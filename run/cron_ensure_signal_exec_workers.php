<?php
declare(strict_types=1);
/**
 * Cron (~1 min): N × exec_worker для Redis signal queue.
 *   SIGNAL_EXEC_WORKERS=4 у .env
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once $root . '/engine_v2/engine_mode.php';
require_once $root . '/engine_v3/queue_config.php';

cron_singleton_acquire('cron_ensure_signal_exec_workers');
Dotenv\Dotenv::createImmutable($root)->safeLoad();
require_once __DIR__ . '/cron_supervisor_guard.php';
cron_host_guard_or_exit('cron_ensure_signal_exec_workers');

if (!engine_signal_exec_enabled()) {
    echo date('c') . " skip: signal exec off\n";
    exit(0);
}

if (!engine_exec_allowed_on_this_node()) {
    echo date('c') . " skip: exec not on this node\n";
    exit(0);
}

$n = max(1, min(32, (int)(getenv('SIGNAL_EXEC_WORKERS') ?: ($_ENV['SIGNAL_EXEC_WORKERS'] ?? 1))));
$startSh = $root . '/run/start_exec_workers_sharded.sh';

if ($n <= 1) {
    $php = '/usr/local/php84/bin/php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/run/cron_ensure_signal_exec_worker.php');
    passthru($cmd, $code);
    exit((int)$code);
}

$alive = 0;
$pidDir = $root . '/services/signal';
for ($i = 1; $i <= $n; $i++) {
    $pidf = $pidDir . '/signal_exec_worker_s' . $i . '.pid';
    if (!is_readable($pidf)) {
        continue;
    }
    $pid = (int)trim((string)file_get_contents($pidf));
    if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
        $alive++;
    } elseif ($pid > 0 && is_dir('/proc/' . $pid)) {
        $alive++;
    }
}

if ($alive < $n) {
    $out = [];
    @exec('pgrep -f ' . escapeshellarg($root . '/engine_v2/exec_worker.php') . ' 2>/dev/null', $out);
    $alive = max($alive, count($out));
}

if ($alive >= $n) {
    echo date('c') . " signal_exec_workers ok {$alive}/{$n}\n";
    exit(0);
}

if (strim_supervisor_managed()) {
    echo date('c') . " signal_exec_workers {$alive}/{$n} — Supervisor managed, skip spawn\n";
    exit(0);
}

if (!is_executable($startSh)) {
    @chmod($startSh, 0755);
}
putenv('SIGNAL_EXEC_WORKERS=' . $n);
@exec('bash ' . escapeshellarg($startSh) . ' 2>&1', $out, $code);
echo date('c') . ' ensure_workers ' . $alive . '/' . $n . ' code=' . $code . ' ' . trim(implode(' ', $out)) . PHP_EOL;
exit($code === 0 ? 0 : 1);
