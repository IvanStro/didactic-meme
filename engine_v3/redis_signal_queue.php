<?php
declare(strict_types=1);

require_once __DIR__ . '/redis_client.php';
require_once __DIR__ . '/queue_config.php';
require_once __DIR__ . '/../engine_v2/bootstrap.php';
require_once __DIR__ . '/../engine_v2/signal_queue_dedupe.php';

const REDIS_SIGNAL_GROUP = 'strim-exec';
const REDIS_SIGNAL_AUTOCLAIM_MS = 60_000;

function redis_signal_ensure_group(\Predis\Client $r, string $stream): void
{
    try {
        $r->executeRaw(['XGROUP', 'CREATE', $stream, REDIS_SIGNAL_GROUP, '0', 'MKSTREAM']);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'BUSYGROUP') === false) {
            throw $e;
        }
    }
}

function redis_signal_dedupe_ttl(string $signal): int
{
    if (str_starts_with($signal, 'SUB_SELL_L')) {
        return 30;
    }
    if (str_starts_with($signal, 'LADDER_L')) {
        return 900;
    }
    if ($signal === 'RECONCILE_TICK') {
        return 45;
    }
    return 300;
}

/** Зняти dedupe, якщо немає busy/PENDING — типовий «завислий» dedupe після втраченого job. */
function redis_signal_reap_orphan_dedupe(int $strategyId, string $signal): void
{
    $r = redis_client();
    if (!$r || $strategyId <= 0 || $signal === '') {
        return;
    }
    if ($r->exists('strim:sq:busy:' . $strategyId)) {
        return;
    }

    $dedupe = signal_queue_dedupe_key($strategyId, $signal, 0.0);
    $dedupeKey = 'strim:sq:dedupe:' . $dedupe;
    if (!$r->exists($dedupeKey)) {
        return;
    }

    $shard = redis_signal_shard_for_strategy($strategyId);
    $stream = redis_signal_stream_key($shard);
    $pendingCount = 0;
    try {
        redis_signal_ensure_group($r, $stream);
        $pending = $r->executeRaw(['XPENDING', $stream, REDIS_SIGNAL_GROUP]);
        if (is_array($pending)) {
            $pendingCount = (int)($pending[0] ?? 0);
        }
    } catch (Throwable $e) {
        // stream may not exist
    }
    if ($pendingCount > 0) {
        return;
    }

    $r->del($dedupeKey);
}

function redis_signal_should_skip_enqueue(int $strategyId, string $signal): bool
{
    $r = redis_client();
    if (!$r) {
        return false;
    }

    if ($r->exists('strim:sq:busy:' . $strategyId)) {
        return true;
    }

    $dedupe = signal_queue_dedupe_key($strategyId, $signal, 0.0);
    if ($r->exists('strim:sq:dedupe:' . $dedupe)) {
        return true;
    }

    if (str_starts_with($signal, 'SUB_SELL_L')) {
        if (preg_match('/^SUB_SELL_L(\d+)$/', $signal, $m)) {
            $lvl = (int) $m[1];
            $db = db();
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
        }
        return false;
    }

    return false;
}

function redis_signal_queue_push(
    int $userId,
    int $strategyId,
    string $symbol,
    string $signal,
    float $price,
    array $meta = []
): int
{
    $r = redis_client();
    if (!$r) {
        return 0;
    }

    $symbol = strtoupper(trim($symbol));
    $signal = trim($signal);
    if ($strategyId <= 0 || $userId <= 0 || $symbol === '' || $signal === '') {
        return 0;
    }

    if (redis_signal_should_skip_enqueue($strategyId, $signal)) {
        return 0;
    }

    $dedupe = signal_queue_dedupe_key($strategyId, $signal, $price);
    $shard = redis_signal_shard_for_strategy($strategyId);
    $stream = redis_signal_stream_key($shard);
    redis_signal_ensure_group($r, $stream);

    $payload = $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    try {
        $entryId = $r->executeRaw([
            'XADD', $stream, '*',
            'user_id', (string)$userId,
            'strategy_id', (string)$strategyId,
            'symbol', $symbol,
            'signal_name', $signal,
            'price', (string)$price,
            'payload_json', $payload,
            'dedupe_key', $dedupe,
        ]);
    } catch (Throwable $e) {
        error_log('redis_signal_queue_push XADD: ' . $e->getMessage());
        return 0;
    }

    if (!is_string($entryId) || $entryId === '') {
        return 0;
    }

    $dedupeKey = 'strim:sq:dedupe:' . $dedupe;
    $ttl = redis_signal_dedupe_ttl($signal);
    $r->setex($dedupeKey, $ttl, '1');

    return 1;
}

/** @return array<string,mixed>|null */
function redis_signal_parse_stream_message(string $streamName, array $message): ?array
{
    $msgId = (string)($message[0] ?? '');
    $fields = $message[1] ?? [];
    if ($msgId === '' || !is_array($fields)) {
        return null;
    }

    $fieldMap = [];
    for ($i = 0; $i < count($fields); $i += 2) {
        $fieldMap[(string)$fields[$i]] = (string)($fields[$i + 1] ?? '');
    }

    $sid = (int)($fieldMap['strategy_id'] ?? 0);
    return [
        'id' => $msgId,
        'redis_stream' => $streamName,
        'dedupe_key' => (string)($fieldMap['dedupe_key'] ?? ''),
        'user_id' => (int)($fieldMap['user_id'] ?? 0),
        'strategy_id' => $sid,
        'symbol' => (string)($fieldMap['symbol'] ?? ''),
        'signal_name' => (string)($fieldMap['signal_name'] ?? ''),
        'price' => (float)($fieldMap['price'] ?? 0),
        'payload_json' => ($fieldMap['payload_json'] ?? '') !== '' ? $fieldMap['payload_json'] : null,
        'attempts' => 0,
    ];
}

/** @return array<string,mixed>|null */
function redis_signal_claim_one(\Predis\Client $r, string $stream, string $workerId): ?array
{
    try {
        $reply = $r->executeRaw([
            'XAUTOCLAIM', $stream, REDIS_SIGNAL_GROUP, $workerId,
            (string)REDIS_SIGNAL_AUTOCLAIM_MS, '0-0', 'COUNT', '1',
        ]);
    } catch (Throwable $e) {
        return null;
    }

    if (!is_array($reply) || !isset($reply[1]) || !is_array($reply[1]) || $reply[1] === []) {
        return null;
    }

    $messages = $reply[1];
    if (!is_array($messages[0] ?? null)) {
        return null;
    }

    return redis_signal_parse_stream_message($stream, $messages[0]);
}

/** @return array<string,mixed>|null */
function redis_signal_queue_pop(string $workerId): ?array
{
    $r = redis_client();
    if (!$r) {
        return null;
    }

    $shards = worker_assigned_shard_ids();
    if ($shards === []) {
        return null;
    }

    $workerId = substr($workerId, 0, 64);
    $streams = [];
    foreach ($shards as $shard) {
        $key = redis_signal_stream_key((int)$shard);
        redis_signal_ensure_group($r, $key);
        $claimed = redis_signal_claim_one($r, $key, $workerId);
        if ($claimed !== null) {
            $sid = (int)($claimed['strategy_id'] ?? 0);
            if ($sid > 0) {
                $r->setex('strim:sq:busy:' . $sid, 600, $workerId);
            }
            return $claimed;
        }
        $streams[$key] = '>';
    }

    try {
        $reply = $r->executeRaw(array_merge(
            ['XREADGROUP', 'GROUP', REDIS_SIGNAL_GROUP, $workerId, 'COUNT', '1', 'BLOCK', '500', 'STREAMS'],
            array_keys($streams),
            array_values($streams)
        ));
    } catch (Throwable $e) {
        error_log('redis_signal_queue_pop: ' . $e->getMessage());
        return null;
    }

    if (!is_array($reply) || $reply === []) {
        return null;
    }

    $streamName = (string)($reply[0][0] ?? '');
    $messages = $reply[0][1] ?? [];
    if (!is_array($messages) || $messages === []) {
        return null;
    }

    $row = redis_signal_parse_stream_message($streamName, $messages[0]);
    if ($row === null) {
        return null;
    }

    $sid = (int)($row['strategy_id'] ?? 0);
    if ($sid > 0) {
        $r->setex('strim:sq:busy:' . $sid, 600, $workerId);
    }

    return $row;
}

function redis_signal_clear_busy(int $strategyId): void
{
    $r = redis_client();
    if ($r && $strategyId > 0) {
        $r->del('strim:sq:busy:' . $strategyId);
    }
}

function redis_signal_clear_dedupe(string $dedupeKey): void
{
    if ($dedupeKey === '') {
        return;
    }
    $r = redis_client();
    if ($r) {
        $r->del('strim:sq:dedupe:' . $dedupeKey);
    }
}

/** @param int|string $id */
function redis_signal_queue_mark_done($id, int $strategyId = 0, string $stream = ''): void
{
    $r = redis_client();
    if ($r && is_string($id) && $id !== '' && $stream !== '') {
        try {
            $r->executeRaw(['XACK', $stream, REDIS_SIGNAL_GROUP, $id]);
        } catch (Throwable $e) {
            error_log('redis_signal_queue_mark_done XACK: ' . $e->getMessage());
        }
    }
    if ($strategyId > 0) {
        redis_signal_clear_busy($strategyId);
    }
}

/** @param int|string $id */
function redis_signal_queue_mark_failed(
    $id,
    string $error,
    int $strategyId = 0,
    string $stream = '',
    string $dedupeKey = ''
): void {
    $r = redis_client();
    if ($r && is_string($id) && $id !== '' && $stream !== '') {
        try {
            $r->executeRaw(['XACK', $stream, REDIS_SIGNAL_GROUP, $id]);
        } catch (Throwable $e) {
            error_log('redis_signal_queue_mark_failed XACK: ' . $e->getMessage());
        }
    }
    if ($strategyId > 0) {
        redis_signal_clear_busy($strategyId);
    }
    if ($dedupeKey !== '') {
        redis_signal_clear_dedupe($dedupeKey);
    }
    error_log('redis_signal failed id=' . $id . ' err=' . substr($error, 0, 500));
}

function redis_signal_queue_reap_stale(int $minutes = 10): void
{
    $r = redis_client();
    if (!$r) {
        return;
    }
    $minutes = max(2, min(60, $minutes));
    $idleMs = $minutes * 60 * 1000;

    $shards = worker_assigned_shard_ids();
    if ($shards === []) {
        $shards = range(0, redis_signal_shard_count() - 1);
    }

    foreach ($shards as $shard) {
        $stream = redis_signal_stream_key((int)$shard);
        try {
            redis_signal_ensure_group($r, $stream);
            $r->executeRaw([
                'XAUTOCLAIM', $stream, REDIS_SIGNAL_GROUP, 'reaper',
                (string)$idleMs, '0-0', 'COUNT', '50',
            ]);
        } catch (Throwable $e) {
            // stream may not exist
        }
    }

    $keys = $r->keys('strim:sq:busy:*');
    if (!is_array($keys)) {
        return;
    }
    foreach ($keys as $key) {
        $ttl = (int)$r->ttl($key);
        if ($ttl < 0) {
            $r->del($key);
        }
    }
}

/** @return array{queued:int,processing:int,done:int,failed:int} */
function redis_signal_queue_counts(): array
{
    $r = redis_client();
    $out = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
    if (!$r) {
        return $out;
    }

    $shards = redis_signal_shard_count();
    for ($i = 0; $i < $shards; $i++) {
        try {
            $stream = redis_signal_stream_key($i);
            redis_signal_ensure_group($r, $stream);
            $pending = $r->executeRaw(['XPENDING', $stream, REDIS_SIGNAL_GROUP]);
            $out['queued'] += is_array($pending) ? (int)($pending[0] ?? 0) : 0;
        } catch (Throwable $e) {
            // stream may not exist yet
        }
    }

    $busy = $r->keys('strim:sq:busy:*');
    $out['processing'] = is_array($busy) ? count($busy) : 0;

    return $out;
}
