<?php
declare(strict_types=1);

/**
 * Режим рушія V2 (feature flags). За замовчуванням v1 — без змін торгівлі.
 */
function engine_mode(): string
{
    static $mode = null;
    if ($mode !== null) {
        return $mode;
    }

    $raw = getenv('ENGINE_MODE') ?: ($_ENV['ENGINE_MODE'] ?? 'v1');
    $m = strtolower(trim((string)$raw));
    if (!in_array($m, ['v1', 'shadow', 'hybrid', 'v2'], true)) {
        $m = 'v1';
    }

    $master = getenv('SIGNAL_ENGINE_ENABLED') ?: ($_ENV['SIGNAL_ENGINE_ENABLED'] ?? '');
    if ($master === '0' || strcasecmp((string)$master, 'false') === 0) {
        $m = 'v1';
    }

    $mode = $m;
    return $mode;
}

function engine_shadow_enabled(): bool
{
    return engine_mode() === 'shadow';
}

function engine_hybrid_enabled(): bool
{
    return engine_mode() === 'hybrid';
}

function engine_v2_live_enabled(): bool
{
    return engine_mode() === 'v2';
}

/** Signal daemon (eval на зміну ціни) — shadow / hybrid / v2. */
function engine_signal_daemon_enabled(): bool
{
    return in_array(engine_mode(), ['shadow', 'hybrid', 'v2'], true);
}

/** Master switch: signal_queue → exec_worker (V2_CANARY_EXEC=1). */
function engine_signal_exec_enabled(): bool
{
    $raw = getenv('V2_CANARY_EXEC') ?: ($_ENV['V2_CANARY_EXEC'] ?? '');
    if ($raw !== '1' && strcasecmp((string)$raw, 'true') !== 0) {
        return false;
    }
    if (engine_v2_live_enabled()) {
        return true;
    }
    if (engine_v2_canary_strategy_ids() !== []) {
        return true;
    }
    if (engine_hybrid_extra_strategy_ids() !== []) {
        return true;
    }
    return engine_hybrid_enabled() && engine_hybrid_exec_percent() > 0;
}

/** @deprecated use engine_signal_exec_enabled */
function engine_v2_canary_exec_enabled(): bool
{
    return engine_signal_exec_enabled();
}

/**
 * % running стратегій на signal-exec (hybrid rollout). 0 = лише explicit canary IDs.
 */
function engine_hybrid_exec_percent(): int
{
    static $pct = null;
    if ($pct !== null) {
        return $pct;
    }
    if (!engine_hybrid_enabled() && !engine_v2_live_enabled()) {
        $pct = 0;
        return 0;
    }
    $raw = getenv('V2_HYBRID_EXEC_PCT') ?: ($_ENV['V2_HYBRID_EXEC_PCT'] ?? '0');
    $pct = max(0, min(100, (int)$raw));
    return $pct;
}

/** @return list<int> */
function engine_hybrid_extra_strategy_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $raw = getenv('V2_HYBRID_EXTRA_STRATEGY_IDS') ?: ($_ENV['V2_HYBRID_EXTRA_STRATEGY_IDS'] ?? '');
    $ids = [];
    foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
        $v = (int)$p;
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }
    $ids = array_values($ids);
    return $ids;
}

/**
 * Стратегія торгує через signal_queue (без V1 poll enqueue).
 * Canary IDs + hybrid % + v2 live (усі running).
 */
function engine_strategy_uses_signal_exec(int $strategyId): bool
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0 || !engine_signal_exec_enabled()) {
        return false;
    }
    if (engine_v2_live_enabled()) {
        return true;
    }
    if (engine_strategy_is_v2_canary($strategyId)) {
        return true;
    }
    if (in_array($strategyId, engine_hybrid_extra_strategy_ids(), true)) {
        return true;
    }
    $pct = engine_hybrid_exec_percent();
    if ($pct <= 0) {
        return false;
    }
    if ($pct >= 100) {
        return true;
    }
    return ($strategyId % 100) < $pct;
}

/** @return list<int> */
function engine_v2_canary_strategy_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $raw = getenv('V2_CANARY_STRATEGY_IDS') ?: ($_ENV['V2_CANARY_STRATEGY_IDS'] ?? '');
    $ids = [];
    foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
        $v = (int)$p;
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }
    $ids = array_values($ids);
    return $ids;
}

function engine_strategy_is_v2_canary(int $strategyId): bool
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0) {
        return false;
    }
    return in_array($strategyId, engine_v2_canary_strategy_ids(), true);
}

/** V1 poll enqueue пропускається — exec через signal_queue. */
function engine_strategy_skip_v1_poll(int $strategyId): bool
{
    return engine_strategy_uses_signal_exec($strategyId);
}

/** @deprecated use engine_strategy_skip_v1_poll */
function engine_v2_canary_skip_v1_poll(int $strategyId): bool
{
    return engine_strategy_skip_v1_poll($strategyId);
}
