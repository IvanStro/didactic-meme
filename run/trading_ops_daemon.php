<?php
declare(strict_types=1);

/**
 * Єдиний довгоживучий процес: enqueue running strategies + підтримка N воркерів.
 * Синглтон через flock (logs/trading_ops_daemon.lock) — другий екземпляр одразу виходить.
 *
 * Запуск (на сервері, з кореня проєкту на хостингу):
 *   cd /home/bot20/strim.in.ua
 *   nohup "$(command -v php)" run/trading_ops_daemon.php >> logs/trading_ops_daemon.log 2>&1 &
 *
 * Інтервал циклу: TRADING_OPS_INTERVAL_SEC (за замовчуванням 10).
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_worker_manager.php';
require_once $root . '/engine_v2/engine_mode.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

worker_ensure_paths();
$lockPath = worker_trading_ops_lock_path();
$sup = strtolower(trim((string) (getenv('STRIM_SUPERVISOR_MANAGED') ?: ($_ENV['STRIM_SUPERVISOR_MANAGED'] ?? ''))));
$supervisorManaged = in_array($sup, ['1', 'true', 'yes', 'on'], true);

$lockFp = null;
if (!$supervisorManaged) {
    if (!worker_trading_ops_daemon_alive()) {
        worker_trading_ops_break_stale_lock();
    }
    $lockFp = fopen($lockPath, 'c+');
    if ($lockFp === false) {
        fwrite(STDERR, "trading_ops_daemon: cannot open lock file\n");
        exit(1);
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "trading_ops_daemon: already running (lock held)\n");
        exit(0);
    }
}

$pidPath = worker_trading_ops_pid_path();
@file_put_contents($pidPath, (string)getmypid() . "\n");

$interval = (int)(getenv('TRADING_OPS_INTERVAL_SEC') ?: ($_ENV['TRADING_OPS_INTERVAL_SEC'] ?? 10));
$interval = max(3, min(300, $interval));

$php = worker_php_binary();
$enqueueScript = $root . '/engine_v2/enqueue_running_strategies.php';

fwrite(STDOUT, '[' . date('c') . "] trading_ops_daemon start interval={$interval}s php={$php}\n");
fflush(STDOUT);

while (true) {
    try {
        $ts = date('c');
        $cmd = $php . ' ' . escapeshellarg($enqueueScript) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        $line = trim(implode("\n", $out));
        $queuedN = 0;
        if (preg_match('/queued=(\d+)/', $line, $qm)) {
            $queuedN = (int) $qm[1];
        }
        $verboseEnqueue = ($code !== 0 || $queuedN > 0 || !function_exists('engine_v2_live_enabled') || !engine_v2_live_enabled());
        if ($verboseEnqueue) {
            fwrite(STDOUT, "[{$ts}] enqueue exit={$code} {$line}\n");
            fflush(STDOUT);
        }

        $m = @new mysqli(
            $_ENV['DB_HOST'] ?? '',
            $_ENV['DB_USER'] ?? '',
            $_ENV['DB_PASS'] ?? '',
            $_ENV['DB_NAME'] ?? ''
        );
        if (!$m->connect_errno) {
            if ($code === 0) {
                worker_touch_heartbeat($m, 'trading_ops_last_enqueue_utc');
            }
            $s = worker_get_settings($m);
            if ((int)$s['auto'] === 1) {
                $running = worker_running_pids();
                $target = (int)$s['target'];
                if (count($running) < $target) {
                    $res = worker_start_missing($target);
                    fwrite(STDOUT, "[{$ts}] workers started=" . count($res['started'])
                        . ' running=' . count($res['running']) . " target={$target}\n");
                    fflush(STDOUT);
                }
            }
            $m->close();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] trading_ops_daemon loop: ' . $e->getMessage() . "\n");
    }
    sleep($interval);
}
