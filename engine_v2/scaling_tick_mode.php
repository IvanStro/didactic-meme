<?php
declare(strict_types=1);

require_once __DIR__ . '/scaling_schedule.php';

/**
 * Light vs full tick (етап 2d).
 * full — sync_from_trades + post-tick external check (~кожні 60 с).
 * light — price, TP/CB, ladder; позиція з БД.
 */

function scaling_tick_state_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = dirname(__DIR__) . '/logs/cache/scaling';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function scaling_tick_state_path(int $strategyId): string
{
    return scaling_tick_state_dir() . '/tick_' . max(0, $strategyId) . '.json';
}

/** @return array{last_full_at?:int,pending_full?:bool} */
function scaling_tick_state_read(int $strategyId): array
{
    $path = scaling_tick_state_path($strategyId);
    if (!is_readable($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function scaling_tick_state_write(int $strategyId, array $state): void
{
    $dir = scaling_tick_state_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload !== false) {
        @file_put_contents(scaling_tick_state_path($strategyId), $payload, LOCK_EX);
    }
}

function scaling_light_tick_enabled(mysqli $db): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    $env = getenv('SCALING_LIGHT_TICK') ?: ($_ENV['SCALING_LIGHT_TICK'] ?? '');
    if ($env === '0' || strcasecmp((string)$env, 'false') === 0) {
        $on = false;
        return false;
    }
    if ($env === '1' || strcasecmp((string)$env, 'true') === 0) {
        $on = true;
        return true;
    }
    // Увімкнено разом з етапом 2a (next_tick_at); вимкнути: SCALING_LIGHT_TICK=0
    $on = scaling_has_next_tick_column($db);
    return $on;
}

function scaling_full_tick_interval_sec(mysqli $db): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $sec = (int)(getenv('SCALING_FULL_TICK_INTERVAL_SEC') ?: ($_ENV['SCALING_FULL_TICK_INTERVAL_SEC'] ?? 60));
    $res = $db->query("SELECT v FROM app_settings WHERE k='scaling_full_tick_interval_sec' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $fromDb = (int)($row['v'] ?? 0);
        if ($fromDb >= 15 && $fromDb <= 600) {
            $sec = $fromDb;
        }
    }

    $cached = max(15, min(600, $sec));
    return $cached;
}

/** @return 'full'|'light' */
function scaling_tick_mode_for_strategy(mysqli $db, int $strategyId): string
{
    if ($strategyId <= 0 || !scaling_light_tick_enabled($db)) {
        return 'full';
    }

    $state = scaling_tick_state_read($strategyId);
    if (!empty($state['pending_full'])) {
        return 'full';
    }

    $lastFull = isset($state['last_full_at']) ? (int)$state['last_full_at'] : 0;
    if ($lastFull <= 0) {
        return 'full';
    }

    $interval = scaling_full_tick_interval_sec($db);
    if ((time() - $lastFull) >= $interval) {
        return 'full';
    }

    return 'light';
}

function scaling_mark_full_tick(int $strategyId): void
{
    if ($strategyId <= 0) {
        return;
    }
    $state = scaling_tick_state_read($strategyId);
    $state['last_full_at'] = time();
    $state['pending_full'] = false;
    scaling_tick_state_write($strategyId, $state);
}

/** Наступний тік — повний sync (після угоди на біржі). */
function scaling_request_full_tick(int $strategyId): void
{
    if ($strategyId <= 0) {
        return;
    }
    $state = scaling_tick_state_read($strategyId);
    $state['pending_full'] = true;
    scaling_tick_state_write($strategyId, $state);
}

function scaling_after_trade(mysqli $db, int $strategyId, string $apiKey, string $symbol = ''): void
{
    if (!scaling_light_tick_enabled($db)) {
        return;
    }
    scaling_request_full_tick($strategyId);
    if ($apiKey !== '' && function_exists('scaling_binance_invalidate')) {
        scaling_binance_invalidate($apiKey, $symbol !== '' ? $symbol : null);
    }
}
