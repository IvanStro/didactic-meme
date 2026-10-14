<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/signal_queue_dedupe.php';
require_once __DIR__ . '/../engine_v3/queue_config.php';
require_once __DIR__ . '/../engine_v3/redis_signal_queue.php';

/** @return list<int> */
function signal_actionable_from_list(array $signals): array
{
    $out = [];
    foreach ($signals as $s) {
        $s = (string)$s;
        if ($s === '' || $s === 'none') {
            continue;
        }
        if (
            $s === 'MAIN_SELL_FIRE'
            || $s === 'BUY0_ELIGIBLE'
            || $s === 'MAIN_ARM'
            || $s === 'MAIN_PEAK_UPDATE'
            || $s === 'RECONCILE_TICK'
            || str_starts_with($s, 'LADDER_L')
            || str_starts_with($s, 'SUB_SELL_L')
        ) {
            $out[] = $s;
        }
    }
    return $out;
}

/** Лише сигнали виходу (для paused / EXPIRED wind-down). */
function signal_actionable_exits_only(array $signals): array
{
    $out = [];
    foreach ($signals as $s) {
        $s = (string)$s;
        if (
            $s === 'MAIN_SELL_FIRE'
            || $s === 'MAIN_ARM'
            || str_starts_with($s, 'SUB_SELL_L')
            || $s === 'RECONCILE_TICK'
        ) {
            $out[] = $s;
        }
    }
    return $out;
}

/** Skip enqueue when the same strategy+signal is already active or recently finished. */
function signal_queue_should_skip_enqueue(int $strategyId, string $signal): bool
{
    $db = db();
    $strategyId = (int)$strategyId;
    $signal = trim($signal);
    if ($strategyId <= 0 || $signal === '') {
        return true;
    }
    $sigEsc = $db->real_escape_string($signal);

    $pending = $db->query(
        "SELECT id FROM signal_queue
         WHERE strategy_id = {$strategyId}
           AND signal_name = '{$sigEsc}'
           AND status IN ('queued','processing')
         LIMIT 1"
    );
    if ($pending && $pending->fetch_assoc()) {
        return true;
    }

    // After success, eval should clear the signal; long window avoids tick spam.
    // SUB_SELL: retry quickly if spike was missed at exec time (sub still open).
    if (str_starts_with($signal, 'SUB_SELL_L')) {
        if (preg_match('/^SUB_SELL_L(\d+)$/', $signal, $m)) {
            $lvl = (int) $m[1];
            $open = $db->query(
                "SELECT sp.id FROM sub_positions sp
                 INNER JOIN positions p ON p.id = sp.position_id
                 WHERE p.strategy_id = {$strategyId}
                   AND sp.level_idx = {$lvl}
                   AND sp.closed = 0
                   AND sp.qty > 0
                 LIMIT 1"
            );
            if (!$open || !$open->fetch_assoc()) {
                return true;
            }
            $recentSell = $db->query(
                "SELECT id FROM trade_ledger
                 WHERE strategy_id = {$strategyId}
                   AND event_type = 'SELL_SUB'
                   AND level_idx = {$lvl}
                   AND created_at >= (NOW() - INTERVAL 120 SECOND)
                 LIMIT 1"
            );
            if ($recentSell && $recentSell->fetch_assoc()) {
                return true;
            }
        }
        return false;
    }
    if ($signal === 'MAIN_PEAK_UPDATE') {
        // Пік оновлює signal_daemon напряму; у черзі лише fallback — коротке вікно.
        $doneWindow = 20;
    } else {
        $doneWindow = str_starts_with($signal, 'LADDER_L') ? 900 : ($signal === 'RECONCILE_TICK' ? 45 : 300);
    }
    $done = $db->query(
        "SELECT id FROM signal_queue
         WHERE strategy_id = {$strategyId}
           AND signal_name = '{$sigEsc}'
           AND status = 'done'
           AND updated_at >= (NOW() - INTERVAL {$doneWindow} SECOND)
         LIMIT 1"
    );
    if ($done && $done->fetch_assoc()) {
        return true;
    }

    // Back off after failed exec (SSL, no USDT, etc.).
    $failed = $db->query(
        "SELECT id FROM signal_queue
         WHERE strategy_id = {$strategyId}
           AND signal_name = '{$sigEsc}'
           AND status = 'failed'
           AND updated_at >= (NOW() - INTERVAL 180 SECOND)
         LIMIT 1"
    );
    return (bool)($failed && $failed->fetch_assoc());
}

function signal_queue_strategy_busy(int $strategyId): bool
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0) {
        return false;
    }
    $r = db()->query(
        "SELECT id FROM signal_queue
         WHERE strategy_id = {$strategyId}
           AND status IN ('queued','processing')
         LIMIT 1"
    );
    return (bool)($r && $r->fetch_assoc());
}

function signal_queue_push(
    int $userId,
    int $strategyId,
    string $symbol,
    string $signal,
    float $price,
    array $meta = []
): int {
    $backend = queue_backend();
    $redisId = 0;

    if (queue_use_redis_push()) {
        $redisId = redis_signal_queue_push($userId, $strategyId, $symbol, $signal, $price, $meta);
        if ($backend === 'redis' && queue_pop_backend() === 'redis' && $redisId > 0) {
            return $redisId;
        }
    }

    $mysqlId = signal_queue_push_mysql($userId, $strategyId, $symbol, $signal, $price, $meta);
    if ($mysqlId > 0) {
        return $mysqlId;
    }

    // У dual/mysql-pop Redis — лише дзеркало; qid=1 не означає новий job для daemon.
    if ($backend === 'redis' && queue_pop_backend() === 'redis' && $redisId > 0) {
        return $redisId;
    }

    return 0;
}

function signal_queue_push_mysql(
    int $userId,
    int $strategyId,
    string $symbol,
    string $signal,
    float $price,
    array $meta = []
): int {
    $db = db();
    $symbol = strtoupper(trim($symbol));
    $signal = trim($signal);
    if ($strategyId <= 0 || $userId <= 0 || $symbol === '' || $signal === '') {
        return 0;
    }

    $lockName = 'sq_enq_' . $strategyId . '_' . substr(preg_replace('/[^A-Za-z0-9_]/', '_', $signal), 0, 40);
    $lockEsc = $db->real_escape_string($lockName);
    $got = 0;
    $lr = $db->query("SELECT GET_LOCK('{$lockEsc}', 2) AS g");
    if ($lr && ($lrow = $lr->fetch_assoc())) {
        $got = (int)($lrow['g'] ?? 0);
    }
    if ($got !== 1) {
        return 0;
    }

    try {
        if (signal_queue_should_skip_enqueue($strategyId, $signal)) {
            return 0;
        }

        $dedupe = signal_queue_dedupe_key($strategyId, $signal, $price);
        $dedupeEsc = $db->real_escape_string($dedupe);

        $payload = $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $payloadEsc = $payload !== null ? "'" . $db->real_escape_string($payload) . "'" : 'NULL';
        $priceSql = number_format($price, 12, '.', '');
        $symEsc = $db->real_escape_string($symbol);
        $sigEsc = $db->real_escape_string($signal);

        $db->query(
            "INSERT INTO signal_queue
                (user_id, strategy_id, symbol, signal_name, price, payload_json, dedupe_key, status, attempts, available_at, created_at, updated_at)
             VALUES
                ($userId, $strategyId, '$symEsc', '$sigEsc', $priceSql, $payloadEsc, '$dedupeEsc', 'queued', 0, NOW(), NOW(), NOW())"
        );
        return (int)$db->insert_id;
    } finally {
        $db->query("SELECT RELEASE_LOCK('{$lockEsc}')");
    }
}

function signal_queue_reap_stale(int $minutes = 10): void
{
    if (queue_use_redis_push() || queue_use_redis_pop()) {
        redis_signal_queue_reap_stale($minutes);
    }
    if (!queue_use_redis_pop()) {
        $minutes = max(2, min(60, $minutes));
        db()->query(
            "UPDATE signal_queue
             SET status='queued', worker_id=NULL, locked_at=NULL, updated_at=NOW()
             WHERE status='processing'
               AND locked_at IS NOT NULL
               AND locked_at < (NOW() - INTERVAL {$minutes} MINUTE)"
        );
    }
}

/** @return array<string,mixed>|null */
function signal_queue_pop(string $workerId): ?array
{
    signal_queue_reap_stale(10);
    if (queue_use_redis_pop()) {
        $row = redis_signal_queue_pop($workerId);
        if ($row !== null) {
            return $row;
        }
        if (queue_mysql_pop_fallback()) {
            return signal_queue_pop_mysql($workerId);
        }
        return null;
    }
    return signal_queue_pop_mysql($workerId);
}

/** @return array<string,mixed>|null */
function signal_queue_pop_mysql(string $workerId): ?array
{
    $db = db();
    $workerId = substr($workerId, 0, 64);
    $wEsc = $db->real_escape_string($workerId);

    $db->begin_transaction();
    try {
        $res = $db->query(
            "SELECT id, user_id, strategy_id, symbol, signal_name, price, payload_json, attempts
             FROM signal_queue
             WHERE status = 'queued' AND available_at <= NOW()
             ORDER BY available_at ASC, id ASC
             LIMIT 1
             FOR UPDATE"
        );
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            $db->commit();
            return null;
        }
        $id = (int)$row['id'];
        $db->query(
            "UPDATE signal_queue
             SET status = 'processing', worker_id = '$wEsc', locked_at = NOW(), updated_at = NOW()
             WHERE id = $id AND status = 'queued'"
        );
        if ($db->affected_rows <= 0) {
            $db->commit();
            return null;
        }
        $db->commit();
        return $row;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** @param array{redis_stream?:string,dedupe_key?:string}|null $ctx */
function signal_queue_mark_done($id, int $strategyId = 0, ?array $ctx = null): void
{
    if (queue_use_redis_pop() && is_string($id) && str_contains((string)$id, '-')) {
        redis_signal_queue_mark_done(
            $id,
            $strategyId,
            (string)($ctx['redis_stream'] ?? '')
        );
    }
    $mysqlId = is_int($id) ? $id : (int)(is_numeric($id) ? $id : 0);
    if ($mysqlId > 0) {
        db()->query("UPDATE signal_queue SET status='done', updated_at=NOW() WHERE id=$mysqlId");
    }
}

/**
 * Re-queue a processing job (e.g. strategy lock busy) without counting as failure.
 * @param array{redis_stream?:string,dedupe_key?:string}|null $ctx
 */
function signal_queue_requeue($id, int $strategyId = 0, ?array $ctx = null, int $delaySec = 2): void
{
    $delaySec = max(0, min(60, $delaySec));
    if (queue_use_redis_pop() && is_string($id) && str_contains((string)$id, '-')) {
        // Redis path: mark failed lightly then rely on soft-dedupe windows; prefer MySQL requeue when id numeric.
        if (function_exists('redis_signal_queue_requeue')) {
            redis_signal_queue_requeue(
                $id,
                $strategyId,
                (string)($ctx['redis_stream'] ?? ''),
                $delaySec
            );
        } else {
            redis_signal_queue_mark_failed(
                $id,
                'strategy_lock_busy',
                $strategyId,
                (string)($ctx['redis_stream'] ?? ''),
                (string)($ctx['dedupe_key'] ?? '')
            );
        }
    }
    $mysqlId = is_int($id) ? $id : (int)(is_numeric($id) ? $id : 0);
    if ($mysqlId > 0) {
        db()->query(
            "UPDATE signal_queue
             SET status='queued', worker_id=NULL, locked_at=NULL,
                 available_at=DATE_ADD(NOW(), INTERVAL {$delaySec} SECOND),
                 updated_at=NOW(), last_error='strategy_lock_busy'
             WHERE id={$mysqlId}"
        );
    }
}

/** @param array{redis_stream?:string,dedupe_key?:string}|null $ctx */
function signal_queue_mark_failed($id, string $error, int $strategyId = 0, ?array $ctx = null): void
{
    if (queue_use_redis_pop() && is_string($id) && str_contains((string)$id, '-')) {
        redis_signal_queue_mark_failed(
            $id,
            $error,
            $strategyId,
            (string)($ctx['redis_stream'] ?? ''),
            (string)($ctx['dedupe_key'] ?? '')
        );
    }
    $mysqlId = is_int($id) ? $id : (int)(is_numeric($id) ? $id : 0);
    if ($mysqlId > 0) {
        $db = db();
        $errEsc = $db->real_escape_string(substr($error, 0, 2000));
        $db->query(
            "UPDATE signal_queue
             SET status='failed', attempts=attempts+1, last_error='$errEsc', updated_at=NOW()
             WHERE id=$mysqlId"
        );
    }
}

/** @return array{queued:int,processing:int,done:int,failed:int} */
function signal_queue_counts(): array
{
    $db = db();
    $out = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
    $r = $db->query("SELECT status, COUNT(*) c FROM signal_queue GROUP BY status");
    while ($r && ($row = $r->fetch_assoc())) {
        $st = (string)($row['status'] ?? '');
        if (isset($out[$st])) {
            $out[$st] = (int)($row['c'] ?? 0);
        }
    }
    if (queue_use_redis_push() && queue_backend() !== 'mysql') {
        $redis = redis_signal_queue_counts();
        if (queue_pop_backend() === 'redis') {
            return $redis;
        }
        $out['redis_pending'] = $redis['queued'] ?? 0;
        $out['redis_busy'] = $redis['processing'] ?? 0;
    }
    return $out;
}
