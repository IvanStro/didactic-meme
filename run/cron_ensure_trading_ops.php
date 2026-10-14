<?php
declare(strict_types=1);

/**
 * Cron (cPanel / панель): раз на хвилину.
 * Якщо trading_ops_daemon мертвий — знімає stale lock і піднімає один екземпляр.
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
cron_singleton_acquire('cron_ensure_trading_ops');
set_time_limit(45);

require_once $root . '/www/api/_worker_manager.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
require_once __DIR__ . '/cron_supervisor_guard.php';
cron_host_guard_or_exit('cron_ensure_trading_ops');
worker_ensure_paths();

$logPath = worker_trading_ops_log_path();

$m = @new mysqli(
    $_ENV['DB_HOST'] ?? '',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? ''
);
if ($m->connect_errno) {
    fwrite(STDERR, "cron_ensure: db " . $m->connect_error . "\n");
    exit(1);
}

$got = 0;
if ($res = $m->query("SELECT GET_LOCK('luckydrop_cron_ensure_trading_ops', 2) AS g")) {
    $row = $res->fetch_assoc();
    $got = (int)($row['g'] ?? 0);
}
if ($got !== 1) {
    echo date('c') . " cron_ensure: skip (lock busy)\n";
    $m->close();
    exit(0);
}

$alive = worker_trading_ops_daemon_alive();

if (!$alive) {
    if (strim_supervisor_managed()) {
        $sr = worker_trading_ops_supervisor_restart();
        $line = date('c') . ' trading_ops down — supervisor_restart ' . ($sr['ok'] ? 'ok' : 'fail') . ' ' . $sr['detail'] . "\n";
        file_put_contents($logPath, $line, FILE_APPEND);
        echo $line;
        $m->query("SELECT RELEASE_LOCK('luckydrop_cron_ensure_trading_ops')");
        $m->close();
        exit($sr['ok'] ? 0 : 1);
    }
    worker_trading_ops_break_stale_lock();
    $code = worker_trading_ops_spawn();

    $line = date('c') . " cron_ensure: spawned trading_ops_daemon (bash_exec_code={$code})\n";
    file_put_contents($logPath, $line, FILE_APPEND);
    echo $line;
}

$m->query("SELECT RELEASE_LOCK('luckydrop_cron_ensure_trading_ops')");
$m->close();
