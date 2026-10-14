<?php
declare(strict_types=1);

/**
 * Stage 3: конфіг черги (MySQL / Redis / dual) та роль ноди.
 *
 * dual: push → MySQL + Redis; pop за замовчуванням → MySQL (безпечно).
 * redis: push → Redis (+ MySQL safety при збої); pop → Redis з fallback на MySQL.
 */

function queue_backend(): string
{
    $v = strtolower(trim((string)(getenv('QUEUE_BACKEND') ?: ($_ENV['QUEUE_BACKEND'] ?? 'mysql'))));
    if (!in_array($v, ['mysql', 'redis', 'dual'], true)) {
        return 'mysql';
    }
    return $v;
}

/** Джерело pop: mysql | redis (QUEUE_POP перевизначає). */
function queue_pop_backend(): string
{
    $v = strtolower(trim((string)(getenv('QUEUE_POP') ?: ($_ENV['QUEUE_POP'] ?? ''))));
    if (in_array($v, ['mysql', 'redis'], true)) {
        return $v;
    }
    return queue_backend() === 'redis' ? 'redis' : 'mysql';
}

function queue_use_redis_push(): bool
{
    $b = queue_backend();
    return $b === 'redis' || $b === 'dual';
}

function queue_use_redis_pop(): bool
{
    return queue_pop_backend() === 'redis';
}

/** При pop з Redis — дозволити MySQL fallback, якщо Redis порожній/недоступний. */
function queue_mysql_pop_fallback(): bool
{
    $raw = getenv('QUEUE_MYSQL_FALLBACK') ?: ($_ENV['QUEUE_MYSQL_FALLBACK'] ?? '1');
    return (string)$raw === '1' || strtolower((string)$raw) === 'true';
}

/** Дублювати enqueue в MySQL, якщо Redis push не вдався (orphan dedupe / down). */
function queue_mysql_push_safety(): bool
{
    $raw = getenv('QUEUE_MYSQL_PUSH_SAFETY') ?: ($_ENV['QUEUE_MYSQL_PUSH_SAFETY'] ?? '1');
    return (string)$raw === '1' || strtolower((string)$raw) === 'true';
}

function strim_node_role(): string
{
    $v = strtolower(trim((string)(getenv('STRIM_NODE_ROLE') ?: ($_ENV['STRIM_NODE_ROLE'] ?? 'control'))));
    return in_array($v, ['control', 'worker'], true) ? $v : 'control';
}

function strim_exec_on_control(): bool
{
    $raw = getenv('STRIM_EXEC_ON_CONTROL') ?: ($_ENV['STRIM_EXEC_ON_CONTROL'] ?? '1');
    return (string)$raw === '1' || strtolower((string)$raw) === 'true';
}

function redis_signal_shard_count(): int
{
    $n = (int)(getenv('REDIS_SIGNAL_SHARDS') ?: ($_ENV['REDIS_SIGNAL_SHARDS'] ?? 16));
    return max(1, min(64, $n));
}

function redis_signal_shard_for_strategy(int $strategyId): int
{
    $shards = redis_signal_shard_count();
    return $strategyId % $shards;
}

function redis_signal_stream_key(int $shard): string
{
    return 'strim:signal:s' . $shard;
}

/** @return list<int> */
function worker_assigned_shard_ids(): array
{
    $raw = trim((string)(getenv('WORKER_SHARD_IDS') ?: ($_ENV['WORKER_SHARD_IDS'] ?? '')));
    if ($raw === '') {
        $out = [];
        for ($i = 0, $n = redis_signal_shard_count(); $i < $n; $i++) {
            $out[] = $i;
        }
        return $out;
    }
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) as $p) {
        if ($p === '') {
            continue;
        }
        $out[] = (int)$p;
    }
    return array_values(array_unique($out));
}

function redis_price_bus_enabled(): bool
{
    $raw = getenv('REDIS_PRICE_BUS') ?: ($_ENV['REDIS_PRICE_BUS'] ?? '0');
    return (string)$raw === '1' || strtolower((string)$raw) === 'true';
}

/** Чи можна запускати exec_worker на цій ноді. */
function engine_exec_allowed_on_this_node(): bool
{
    if (strim_node_role() === 'worker') {
        return true;
    }
    return strim_exec_on_control();
}
