<?php
declare(strict_types=1);

require_once __DIR__ . '/_binance_utils.php';

/** Канонічний шлях до WS-кешу (фаза V2.1). */
function ws_prices_file_path(): string
{
    return __DIR__ . '/../data/ws_prices.json';
}

/** Дзеркалити ws_prices.json → Redis HASH strim:prices (якщо REDIS_PRICE_BUS=1). */
function ws_prices_mirror_to_redis(): int
{
    $busFile = dirname(__DIR__, 2) . '/engine_v3/redis_price_bus.php';
    if (!is_file($busFile)) {
        return 0;
    }
    require_once $busFile;
    if (!redis_price_bus_enabled() || !function_exists('redis_price_bus_write')) {
        return 0;
    }
    $n = 0;
    foreach (ws_prices_load_file_only() as $sym => $row) {
        if (!is_array($row)) {
            continue;
        }
        $sym = strtoupper((string)$sym);
        if ($sym === '' || str_contains($sym, 'TEMPLATE')) {
            continue;
        }
        $mid = isset($row['mid']) ? (float)$row['mid'] : (float)($row['price'] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        $ts = isset($row['ts']) ? (int)$row['ts'] : null;
        if (redis_price_bus_write($sym, $mid, $ts)) {
            $n++;
        }
    }
    return $n;
}

/** @return array<string, mixed> symbol => entry (лише ws_prices.json) */
function ws_prices_load_file_only(): array
{
    static $cached = null;
    static $mtime = 0;

    $path = ws_prices_file_path();
    if (!is_file($path)) {
        $cached = [];
        $mtime = 0;
        return [];
    }

    $mt = (int)(filemtime($path) ?: 0);
    if ($cached !== null && $mtime === $mt) {
        return $cached;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        $cached = [];
        $mtime = $mt;
        return [];
    }

    $j = json_decode($raw, true);
    if (!is_array($j)) {
        $cached = [];
        $mtime = $mt;
        return [];
    }

    unset($j['_meta']);
    $cached = $j;
    $mtime = $mt;
    return $cached;
}

/**
 * Усі ціни: файл + (опційно) Redis — для signal_daemon / health.
 *
 * @return array<string, mixed>
 */
function ws_prices_load_all(): array
{
    $j = ws_prices_load_file_only();

    $busFile = dirname(__DIR__, 2) . '/engine_v3/redis_price_bus.php';
    if (!is_file($busFile)) {
        return $j;
    }
    require_once $busFile;
    if (!redis_price_bus_enabled() || !function_exists('redis_price_bus_read_all')) {
        return $j;
    }

    $now = time();
    foreach (redis_price_bus_read_all() as $sym => $row) {
        $tsSec = (int)($row['ts'] ?? 0);
        if ($tsSec > 1_000_000_000_000) {
            $tsSec = (int)round($tsSec / 1000);
        }
        $fileTs = 0;
        if (isset($j[$sym]) && is_array($j[$sym])) {
            $fileTs = (int)($j[$sym]['ts'] ?? 0);
        }
        if ($tsSec >= $fileTs) {
            $j[$sym] = [
                'mid' => (float)($row['price'] ?? 0),
                'bid' => (float)($row['price'] ?? 0),
                'ask' => (float)($row['price'] ?? 0),
                'ts' => $tsSec > 0 ? $tsSec : $now,
            ];
        }
    }

    return $j;
}

/**
 * Нормалізує запис ціни з різних legacy-форматів.
 *
 * @return array{mid:float,bid:float,ask:float,ts:int|null}|null
 */
function ws_price_normalize_entry(string $symbol, mixed $v): ?array
{
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '') {
        return null;
    }

    if (is_numeric($v)) {
        $mid = (float)$v;
        if ($mid <= 0) {
            return null;
        }
        return ['mid' => $mid, 'bid' => $mid, 'ask' => $mid, 'ts' => null];
    }

    if (!is_array($v)) {
        return null;
    }

    $mid = 0.0;
    if (isset($v['mid']) && is_numeric($v['mid'])) {
        $mid = (float)$v['mid'];
    } elseif (isset($v['price']) && is_numeric($v['price'])) {
        $mid = (float)$v['price'];
    }

    $bid = isset($v['bid']) && is_numeric($v['bid']) ? (float)$v['bid'] : 0.0;
    $ask = isset($v['ask']) && is_numeric($v['ask']) ? (float)$v['ask'] : 0.0;
    if ($mid <= 0 && $bid > 0 && $ask > 0) {
        $mid = ($bid + $ask) / 2.0;
    }
    if ($mid <= 0) {
        return null;
    }

    $ts = null;
    if (isset($v['ts']) && is_numeric($v['ts'])) {
        $ts = (int)$v['ts'];
        if ($ts > 1_000_000_000_000) {
            $ts = (int)floor($ts / 1000);
        }
    }

    return [
        'mid' => $mid,
        'bid' => $bid > 0 ? $bid : $mid,
        'ask' => $ask > 0 ? $ask : $mid,
        'ts' => $ts,
    ];
}

/**
 * @return array{mid:float,bid:float,ask:float,ts:int|null}|null
 */
function ws_price_entry(string $symbol): ?array
{
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '') {
        return null;
    }

    $all = ws_prices_load_all();
    if (isset($all[$symbol])) {
        return ws_price_normalize_entry($symbol, $all[$symbol]);
    }

    foreach ($all as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strtoupper((string)($row['symbol'] ?? '')) === $symbol) {
            return ws_price_normalize_entry($symbol, $row);
        }
    }

    return null;
}

/** Вік ціни в секундах (null = немає ts у кеші). */
function ws_price_age_sec(string $symbol): ?int
{
    $entry = ws_price_entry($symbol);
    if (!$entry || $entry['ts'] === null) {
        $path = ws_prices_file_path();
        if (is_file($path)) {
            $age = time() - (int)(filemtime($path) ?: time());
            return max(0, $age);
        }
        return null;
    }
    return max(0, time() - (int)$entry['ts']);
}

function ws_price_max_age_sec(): int
{
    $sec = (int)(getenv('PRICE_MAX_AGE_SEC') ?: ($_ENV['PRICE_MAX_AGE_SEC'] ?? 5));
    return max(1, min(120, $sec));
}

/** Локальний рідер кешу WS (mid). */
function ws_cached_price_php(string $symbol): float
{
    $entry = ws_price_entry($symbol);
    return $entry ? (float)$entry['mid'] : 0.0;
}

/**
 * Ціна для тригерів: WS-кеш, якщо свіжий; інакше REST.
 * Логіка sanity 5% збережена.
 */
function ws_ticker_price(string $symbol): float
{
    $symbol = strtoupper(trim($symbol));
    $ws = ws_cached_price_php($symbol);
    $age = ws_price_age_sec($symbol);
    $maxAge = ws_price_max_age_sec();
    $wsFresh = ($ws > 0 && $age !== null && $age <= $maxAge);

    $rest = 0.0;
    if (function_exists('exi_get_price')) {
        try {
            $rest = (float)exi_get_price($symbol);
        } catch (Throwable $e) {
            $rest = 0.0;
        }
    }

    if (!$wsFresh) {
        if ($rest > 0) {
            if ($ws > 0 && function_exists('logx')) {
                logx(
                    '[PRICE_STALE] %s WS age=%ss (> %ss) -> REST',
                    $symbol,
                    (string)($age ?? '?'),
                    (string)$maxAge
                );
            }
            return $rest;
        }
        return $ws > 0 ? $ws : 0.0;
    }

    if ($rest > 0) {
        $diff = abs($ws - $rest) / $rest;
        if ($diff > 0.05) {
            if (function_exists('logx')) {
                logx('[PRICE_SANITY] %s WS=%.8f REST=%.8f diff=%.2f%% -> use REST', $symbol, $ws, $rest, $diff * 100);
            }
            return $rest;
        }
    }

    return $ws;
}

/**
 * Зведення для health / watchdog: running symbols → age.
 *
 * @param list<string> $symbols
 * @return array{symbols:array<string,array{mid:float,age_sec:int|null}>,worst_age_sec:int|null,all_fresh:bool}
 */
function ws_prices_health_summary(array $symbols): array
{
    $out = [];
    $worst = null;
    $maxAge = ws_price_max_age_sec();
    $allFresh = true;

    foreach ($symbols as $sym) {
        $sym = strtoupper(trim((string)$sym));
        if ($sym === '' || str_contains($sym, 'TEMPLATE')) {
            continue;
        }
        $entry = ws_price_entry($sym);
        $age = ws_price_age_sec($sym);
        $mid = $entry ? (float)$entry['mid'] : 0.0;
        $fresh = ($mid > 0 && $age !== null && $age <= $maxAge);
        if (!$fresh) {
            $allFresh = false;
        }
        if ($age !== null && ($worst === null || $age > $worst)) {
            $worst = $age;
        }
        $out[$sym] = [
            'mid' => $mid > 0 ? $mid : null,
            'age_sec' => $age,
            'fresh' => $fresh,
        ];
    }

    return [
        'symbols' => $out,
        'worst_age_sec' => $worst,
        'max_age_sec' => $maxAge,
        'all_fresh' => $allFresh && $out !== [],
    ];
}
