<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function queue_mark_done(int $jobId): void {
    $db = db();
    $stmt = $db->prepare(
        "UPDATE job_queue
         SET status='done',
             locked_at=NULL,
             worker_id=NULL,
             last_error=NULL,
             updated_at=NOW()
         WHERE id=?"
    );
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
}

function queue_mark_failed(int $jobId, string $error, int $delaySeconds = 30, int $maxAttempts = 5): void {
    $db = db();

    $stmt = $db->prepare("SELECT attempts FROM job_queue WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $attempts = (int)($row['attempts'] ?? 0);
    $nextStatus = $attempts >= $maxAttempts ? 'failed' : 'queued';

    $availableAt = date('Y-m-d H:i:s', time() + max(1, $delaySeconds));

    $stmt2 = $db->prepare(
        "UPDATE job_queue
         SET status=?,
             locked_at=NULL,
             worker_id=NULL,
             last_error=?,
             available_at=?,
             updated_at=NOW()
         WHERE id=?"
    );
    $stmt2->bind_param('sssi', $nextStatus, $error, $availableAt, $jobId);
    $stmt2->execute();
}

/** Soft requeue without consuming an attempt (strategy lock busy). */
function queue_mark_requeue(int $jobId, int $delaySeconds = 2): void {
    $db = db();
    $delaySeconds = max(0, min(60, $delaySeconds));
    $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
    $err = 'strategy_lock_busy';
    $stmt = $db->prepare(
        "UPDATE job_queue
         SET status='queued',
             locked_at=NULL,
             worker_id=NULL,
             last_error=?,
             available_at=?,
             updated_at=NOW()
         WHERE id=?"
    );
    $stmt->bind_param('ssi', $err, $availableAt, $jobId);
    $stmt->execute();
}
