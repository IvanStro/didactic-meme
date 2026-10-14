<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function queue_push(
    string $jobType,
    int $userId,
    int $strategyId,
    string $symbol,
    array $payload = [],
    ?string $availableAt = null
): int {
    $db = db();

    $jobType = trim($jobType);
    $symbol = strtoupper(trim($symbol));
    $payloadJson = $payload ? json_encode($payload, 
JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $availableAt = $availableAt ?: date('Y-m-d H:i:s');

    $db->begin_transaction();
    try {
        // uniq_active_job is (user_id, strategy_id, symbol), so we must reuse an existing row
        // when it is already done/failed instead of inserting a new one.
        $sel = $db->prepare("
            SELECT id, status
            FROM job_queue
            WHERE user_id = ? AND strategy_id = ? AND symbol = ?
            LIMIT 1
            FOR UPDATE
        ");
        $sel->bind_param('iis', $userId, $strategyId, $symbol);
        $sel->execute();
        $res = $sel->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        if ($row) {
            $jobId = (int)$row['id'];
            $status = (string)$row['status'];

            if ($status === 'queued' || $status === 'processing') {
                $db->commit();
                return $jobId;
            }

            // Re-arm completed/failed row as a fresh queued job.
            $upd = $db->prepare("
                UPDATE job_queue
                SET job_type = ?,
                    payload_json = ?,
                    status = 'queued',
                    attempts = 0,
                    available_at = ?,
                    locked_at = NULL,
                    worker_id = NULL,
                    last_error = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd->bind_param('sssi', $jobType, $payloadJson, $availableAt, $jobId);
            $upd->execute();

            $db->commit();
            return $jobId;
        }

        $ins = $db->prepare("INSERT INTO job_queue
            (job_type, user_id, strategy_id, symbol, payload_json, status, attempts, available_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'queued', 0, ?, NOW(), NOW())");
        $ins->bind_param(
            'siisss',
            $jobType,
            $userId,
            $strategyId,
            $symbol,
            $payloadJson,
            $availableAt
        );
        $ins->execute();

        $newId = (int)$db->insert_id;
        $db->commit();
        return $newId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
