<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/scaling_schedule.php';
require_once __DIR__ . '/scaling_binance_cache.php';
require_once __DIR__ . '/scaling_tick_mode.php';
require_once __DIR__ . '/engine_mode.php';
require_once __DIR__ . '/../www/api/_billing_access.php';
require_once __DIR__ . '/../engine/engine_helpers.php';
require_once __DIR__ . '/../engine/engine_logic.php';
require_once __DIR__ . '/../engine/signal/eval_strategy.php';

function handle_job(array $job): void {
    $jobType = (string)($job['job_type'] ?? '');
    $userId = (int)($job['user_id'] ?? 0);
    $strategyId = (int)($job['strategy_id'] ?? 0);
    $symbol = (string)($job['symbol'] ?? '');

    if ($jobType !== 'PROCESS_STRATEGY') {
        throw new RuntimeException("unsupported job_type: {$jobType}");
    }

    if ($userId <= 0 || $strategyId <= 0 || $symbol === '') {
        throw new RuntimeException('invalid job payload');
    }

    refresh_env_from_disk();

    $db = db();

    $stmt = $db->prepare("
        SELECT id, user_id, symbol, fba, tp_whole, cb_whole, status
        FROM strategies
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $strategyId);
    $stmt->execute();
    $res = $stmt->get_result();
    $st = $res ? $res->fetch_assoc() : null;

    if (!$st) {
        throw new RuntimeException("strategy not found: {$strategyId}");
    }

    if ((int)$st['user_id'] !== $userId) {
        throw new RuntimeException("strategy {$strategyId} does not belong to user {$userId}");
    }

    $status = (string)($st['status'] ?? '');
    $hasBilling = billing_user_has_access($db, $userId);

    if ($status === 'running' && !$hasBilling) {
        $db->query("UPDATE strategies SET status='paused' WHERE id={$strategyId} AND status='running' LIMIT 1");
        return;
    }

    if ($status !== 'running' && $status !== 'paused') {
        return;
    }

    [$apiKey, $secret] = user_keys($db, $userId);
    if (!$apiKey || !$secret) {
        throw new RuntimeException("binance keys missing for user {$userId}");
    }

    $sid = (int)$st['id'];
    $tickMode = scaling_tick_mode_for_strategy($db, $sid);

    $priceOverride = null;
    if (isset($job['tick_price'])) {
        $tp = (float)$job['tick_price'];
        if ($tp > 0.0) {
            $priceOverride = $tp;
        }
    }

    $ran = process_strategy(
        $db,
        $sid,
        (int)$st['user_id'],
        (string)$st['symbol'],
        (float)($st['fba'] ?? 0),
        (float)($st['tp_whole'] ?? 0),
        (float)($st['cb_whole'] ?? 0),
        $apiKey,
        $secret,
        $tickMode,
        $priceOverride
    );

    if (!$ran) {
        // Lock busy: do not advance heartbeat / schedule as if work completed.
        throw new RuntimeException('strategy_lock_busy');
    }

    $db->query("UPDATE strategies SET last_tick_at=NOW() WHERE id={$sid} LIMIT 1");
    if ($tickMode === 'full') {
        scaling_mark_full_tick($sid);
    }
    scaling_schedule_next_tick($db, $sid, scaling_tick_interval_for_strategy($db, $sid));
}
