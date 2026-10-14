<?php
declare(strict_types=1);

/**
 * V2 фаза 5: exec worker для canary (signal_queue → process_strategy).
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engine_mode.php';
require_once __DIR__ . '/signal_queue.php';
require_once __DIR__ . '/signal_exec_handler.php';
require_once __DIR__ . '/../engine_v3/queue_config.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

if (!engine_exec_allowed_on_this_node()) {
    fwrite(STDERR, "exec_worker: STRIM_NODE_ROLE=" . strim_node_role() . " STRIM_EXEC_ON_CONTROL=0 — skip on control\n");
    exit(0);
}

if (!engine_signal_exec_enabled()) {
    fwrite(STDERR, "exec_worker: V2_CANARY_EXEC off or no signal-exec strategies configured\n");
    exit(0);
}

$pidDir = $root . '/services/signal';
$slot = getenv('SIGNAL_EXEC_SLOT');
$slotSuffix = ($slot !== false && $slot !== '') ? ('_s' . preg_replace('/[^0-9]/', '', (string)$slot)) : '';
$pidFile = $pidDir . '/signal_exec_worker' . $slotSuffix . '.pid';
$logFile = $root . '/logs/signal_exec' . ($slotSuffix !== '' ? ('_slot_' . $slot) : '') . '.log';

if (!is_dir($pidDir)) {
    @mkdir($pidDir, 0755, true);
}

function signal_exec_log(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function signal_exec_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

$myPid = getmypid();
if (is_readable($pidFile)) {
    $oldPid = (int)trim((string)file_get_contents($pidFile));
    if ($oldPid > 0 && $oldPid !== $myPid && signal_exec_pid_alive($oldPid)) {
        fwrite(STDERR, "signal_exec_worker already running pid={$oldPid}\n");
        exit(1);
    }
}
file_put_contents($pidFile, (string)$myPid);

register_shutdown_function(static function () use ($pidFile, $myPid): void {
    if (is_readable($pidFile) && (int)trim((string)file_get_contents($pidFile)) === $myPid) {
        @unlink($pidFile);
    }
});

$slotSuffix = ($slot !== false && $slot !== '') ? ('s' . $slot . '-') : '';
$workerId = gethostname() . '-se' . $slotSuffix . $myPid;

$idleSleep = (int)(getenv('SIGNAL_EXEC_IDLE_SLEEP') ?: 1);
$busyUsleep = (int)(getenv('SIGNAL_EXEC_BUSY_USLEEP') ?: 50_000);

signal_exec_log(
    'SIGNAL_EXEC start pid=' . $myPid . ' worker=' . $workerId
    . ' mode=' . engine_mode()
    . ' queue=' . queue_backend() . '/' . queue_pop_backend()
    . ' shards=' . implode(',', worker_assigned_shard_ids())
    . ' canary=' . implode(',', engine_v2_canary_strategy_ids())
    . ' hybrid_pct=' . engine_hybrid_exec_percent()
);

// After daemon restart, jobs can stay in processing if the old worker was killed mid-tick.
signal_queue_reap_stale(5);

$loop = 0;
while (true) {
    $loop++;
    if ($loop % 30 === 0) {
        signal_queue_reap_stale(5);
    }
    if ($loop % 120 === 0) {
        refresh_env_from_disk();
        if (!engine_signal_exec_enabled()) {
            signal_exec_log('SIGNAL_EXEC stop: signal exec disabled');
            break;
        }
    }

    $row = null;
    try {
        $row = signal_queue_pop($workerId);
        if (!$row) {
            usleep(max(200_000, $idleSleep * 1_000_000));
            continue;
        }

        $jobId = $row['id'];
        $sid = (int)$row['strategy_id'];
        $sig = (string)($row['signal_name'] ?? ($row['signal'] ?? ''));

        signal_exec_log("picked #{$jobId} sid={$sid} signal={$sig} worker={$workerId}");

        $jobCtx = [
            'redis_stream' => (string)($row['redis_stream'] ?? ''),
            'dedupe_key' => (string)($row['dedupe_key'] ?? ''),
        ];

        if (!engine_strategy_uses_signal_exec($sid)) {
            signal_queue_mark_failed($jobId, 'strategy not on signal-exec path', $sid, $jobCtx);
            continue;
        }

        try {
            signal_handle_exec_job($row);
            signal_queue_mark_done($jobId, $sid, $jobCtx);
            signal_exec_log("done #{$jobId}");
            if ($busyUsleep > 0) {
                usleep(min($busyUsleep, 2_000_000));
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'strategy_lock_busy')) {
                signal_queue_requeue($jobId, $sid, $jobCtx, 2);
                signal_exec_log("requeue #{$jobId}: strategy_lock_busy");
                usleep(200_000);
            } else {
                signal_queue_mark_failed($jobId, $msg, $sid, $jobCtx);
                signal_exec_log("failed #{$jobId}: " . $msg);
                sleep(max(1, $idleSleep));
            }
        }
    } catch (Throwable $e) {
        signal_exec_log('loop err: ' . $e->getMessage());
        sleep(max(1, $idleSleep));
    }
}

signal_exec_log('SIGNAL_EXEC exit pid=' . $myPid);
