<?php
declare(strict_types=1);

require __DIR__ . '/queue_pop.php';
require __DIR__ . '/queue_ack.php';

$slot = getenv('WORKER_SLOT');
$slotSuffix = ($slot !== false && $slot !== '') ? ('s' . $slot . '-') : '';
$workerId = gethostname() . '-w' . $slotSuffix . getmypid();

// Після успішного job не спимо 2s — пропускна здатність; idle backoff лишається.
$idleSleep = (int) (getenv('WORKER_IDLE_SLEEP') ?: 2);
$busyUsleep = (int) (getenv('WORKER_BUSY_USLEEP') ?: 50_000);

while (true) {
    $job = null;
    try {
        $job = queue_pop($workerId);

        if (!$job) {
            echo "[{$workerId}] no jobs\n";
            sleep(max(1, $idleSleep));
            continue;
        }

        $jobId = (int) $job['id'];
        $jobType = (string) $job['job_type'];
        $userId = (int) $job['user_id'];
        $strategyId = (int) $job['strategy_id'];
        $symbol = (string) $job['symbol'];

        echo "[{$workerId}] picked job #{$jobId} type={$jobType} user={$userId} strategy={$strategyId} symbol={$symbol}\n";

        require_once __DIR__ . '/job_handler.php';

        try {
            handle_job($job);
            queue_mark_done($jobId);
            echo "[{$workerId}] done job #{$jobId}\n";
            if ($busyUsleep > 0) {
                usleep(min($busyUsleep, 2_000_000));
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'strategy_lock_busy')) {
                // Put back to queued quickly — do not burn as failed/done.
                queue_mark_requeue($jobId, 2);
                echo "[{$workerId}] requeue job #{$jobId}: strategy_lock_busy\n";
                usleep(200_000);
            } else {
                queue_mark_failed($jobId, $msg);
                fwrite(STDERR, "[{$workerId}] job #{$jobId} failed: " . $msg . "\n");
                sleep(max(1, $idleSleep));
            }
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        fwrite(STDERR, "[{$workerId}] loop error: " . $msg . "\n");
        // Poison the cached mysqli so db() reconnects on the next call.
        try {
            $dbPoison = db();
            @$dbPoison->close();
        } catch (Throwable $ignore) {
            // ignore
        }
        sleep(max(1, $idleSleep));
    }
}
