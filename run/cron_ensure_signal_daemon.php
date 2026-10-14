<?php
declare(strict_types=1);

/**
 * Cron (~1 min): signal daemon для V2 (shadow/hybrid/v2).
 *   php run/cron_ensure_signal_daemon.php
 * При STRIM_SUPERVISOR_MANAGED=1 — лише моніторинг (spawn робить ADM Supervisor).
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once __DIR__ . '/cron_supervisor_guard.php';
cron_singleton_acquire('cron_ensure_signal_daemon');

require_once $root . '/engine_v2/engine_mode.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_ensure_signal_daemon');

if (!function_exists('engine_signal_daemon_enabled')) {
    echo date('c') . " skip: engine_mode.php missing\n";
    exit(0);
}

if (!engine_signal_daemon_enabled()) {
    echo date('c') . ' skip: ENGINE_MODE=' . engine_mode() . " (daemon not needed)\n";
    exit(0);
}

$pidPath = $root . '/services/signal/signal_daemon.pid';
$startSh = $root . '/run/start_signal_daemon.sh';
$logPath = $root . '/logs/signal_daemon.log';

function signal_daemon_pid_alive(int $pid): bool
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
$pid = 0;
if (is_readable($pidPath)) {
    $pid = (int) trim((string) file_get_contents($pidPath));
    $alive = signal_daemon_pid_alive($pid);
}
if (!$alive) {
    $out = [];
    @exec('pgrep -f ' . escapeshellarg($root . '/engine_v2/signal_daemon.php') . ' 2>/dev/null', $out);
    foreach ($out as $line) {
        $cand = (int) trim((string) $line);
        if ($cand > 0 && signal_daemon_pid_alive($cand)) {
            $pid = $cand;
            $alive = true;
            @file_put_contents($pidPath, (string) $pid);
            break;
        }
    }
}

if ($alive) {
    echo date('c') . ' signal_daemon ok pid=' . $pid . PHP_EOL;
    exit(0);
}

if (strim_supervisor_managed()) {
    echo date('c') . " signal_daemon down — Supervisor managed, skip spawn\n";
    exit(0);
}

@unlink($pidPath);
if (!is_executable($startSh)) {
    @chmod($startSh, 0755);
}
if (is_executable($startSh)) {
    @exec('bash ' . escapeshellarg($startSh) . ' 2>&1', $out, $code);
    $line = date('c') . ' cron_ensure_signal_daemon: start code=' . $code . ' ' . trim(implode(' ', $out)) . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
    echo $line;
    exit($code === 0 ? 0 : 1);
}
fwrite(STDERR, "start script missing: {$startSh}\n");
exit(1);
