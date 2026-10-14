<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function queue_pop(string $workerId): ?array {
    $db = db();
    $workerId = trim($workerId);
    $staleLockSec = (int)(getenv('WORKER_STALE_LOCK_SEC') ?: 600);
    if ($staleLockSec < 30) {
        $staleLockSec = 30;
    }

    $db->begin_transaction();

    try {
        // Recover jobs stuck in processing by dead workers.
        $db->query(
            "UPDATE job_queue
             SET status='queued',
                 worker_id=NULL,
                 locked_at=NULL,
                 updated_at=NOW(),
                 last_error=CASE
                   WHEN last_error IS NULL OR last_error = '' THEN 'stale_processing_requeued'
                   ELSE last_error
                 END
             WHERE status='processing'
               AND locked_at IS NOT NULL
               AND locked_at < (NOW() - INTERVAL {$staleLockSec} SECOND)"
        );

        $maxPerUser = (int)(getenv('QUEUE_MAX_PROCESSING_PER_USER') ?: ($_ENV['QUEUE_MAX_PROCESSING_PER_USER'] ?? 2));
        if ($maxPerUser < 1) {
            $maxPerUser = 1;
        }
        if ($maxPerUser > 8) {
            $maxPerUser = 8;
        }

        // Fair queue: не більше N processing jobs на одного user_id (етап 2).
        $sql = "SELECT jq.id
                FROM job_queue jq
                INNER JOIN strategies s ON s.id = jq.strategy_id
                WHERE jq.status = 'queued'
                  AND jq.available_at <= NOW()
                  AND (
                    SELECT COUNT(*)
                    FROM job_queue jq2
                    WHERE jq2.user_id = jq.user_id
                      AND jq2.status = 'processing'
                  ) < {$maxPerUser}
                ORDER BY (s.status = 'running') DESC, jq.id ASC
                LIMIT 1
                FOR UPDATE";

        $res = $db->query($sql);
        $row = $res ? $res->fetch_assoc() : null;

        if (!$row) {
            $db->commit();
            return null;
        }

        $jobId = (int)$row['id'];

        $stmt = $db->prepare(
            "UPDATE job_queue
             SET status='processing',
                 attempts=attempts+1,
                 locked_at=NOW(),
                 worker_id=?,
                 updated_at=NOW()
             WHERE id=? AND status='queued'"
        );
        $stmt->bind_param('si', $workerId, $jobId);
        $stmt->execute();
        if ($stmt->affected_rows <= 0) {
            $db->commit();
            return null;
        }
        $stmt->close();

        $res2 = $db->query("SELECT * FROM job_queue WHERE id = {$jobId} 
LIMIT 1");
        $job = $res2 ? $res2->fetch_assoc() : null;

        $db->commit();

        if (!$job) {
            return null;
        }

        if (!empty($job['payload_json'])) {
            $job['payload'] = json_decode((string)$job['payload_json'], 
true) ?: [];
        } else {
            $job['payload'] = [];
        }

        return $job;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
