<?php
declare(strict_types=1);

/**
 * File cache для Binance API (етап 2b): myTrades, account.
 * Ключі — hash(apiKey), без збереження секретів у іменах файлів.
 */

function scaling_binance_cache_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $root = dirname(__DIR__);
    $dir = $root . '/logs/cache/binance';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function scaling_binance_cache_enabled(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    $env = getenv('BINANCE_FILE_CACHE') ?: ($_ENV['BINANCE_FILE_CACHE'] ?? '');
    if ($env === '0' || strcasecmp((string)$env, 'false') === 0) {
        $on = false;
        return false;
    }
    if ($env === '1' || strcasecmp((string)$env, 'true') === 0) {
        $on = true;
        return true;
    }
    // За замовчуванням увімкнено (етап 2b); вимкнути явно BINANCE_FILE_CACHE=0
    $on = true;
    return $on;
}

function scaling_binance_key_hash(string $apiKey): string
{
    return substr(hash('sha256', $apiKey), 0, 16);
}

function scaling_binance_cache_ttl_mytrades(): int
{
    $sec = (int)(getenv('BINANCE_CACHE_MYTRADES_SEC') ?: ($_ENV['BINANCE_CACHE_MYTRADES_SEC'] ?? 3));
    return max(1, min(30, $sec));
}

function scaling_binance_cache_ttl_account(): int
{
    $sec = (int)(getenv('BINANCE_CACHE_ACCOUNT_SEC') ?: ($_ENV['BINANCE_CACHE_ACCOUNT_SEC'] ?? 8));
    return max(2, min(60, $sec));
}

function scaling_binance_cache_path(string $key): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);
    return scaling_binance_cache_dir() . '/' . $safe . '.json';
}

/** @return array{expires:int,data:mixed}|null */
function scaling_binance_cache_read(string $key): ?array
{
    if (!scaling_binance_cache_enabled()) {
        return null;
    }
    $path = scaling_binance_cache_path($key);
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['expires'])) {
        return null;
    }
    if ((int)$decoded['expires'] < time()) {
        @unlink($path);
        return null;
    }
    return $decoded;
}

function scaling_binance_cache_write(string $key, $data, int $ttlSec): void
{
    if (!scaling_binance_cache_enabled() || $ttlSec < 1) {
        return;
    }
    $dir = scaling_binance_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $payload = json_encode(
        ['expires' => time() + $ttlSec, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false) {
        return;
    }
    $path = scaling_binance_cache_path($key);
    @file_put_contents($path, $payload, LOCK_EX);
}

function scaling_binance_invalidate(string $apiKey, ?string $symbol = null): void
{
    if ($apiKey === '') {
        return;
    }
    $h = scaling_binance_key_hash($apiKey);
    @unlink(scaling_binance_cache_path('acct_' . $h));
    if ($symbol !== null && $symbol !== '') {
        @unlink(scaling_binance_cache_path('trades_' . $h . '_' . strtoupper($symbol)));
    } else {
        $dir = scaling_binance_cache_dir();
        $prefix = 'trades_' . $h . '_';
        foreach (glob($dir . '/' . $prefix . '*.json') ?: [] as $f) {
            @unlink($f);
        }
    }
}

/**
 * @return array{ok:bool,http:int,rows:array,err:string,cached:bool}
 */
function scaling_binance_my_trades_rows(
    string $apiKey,
    string $secret,
    string $symbol,
    bool $forceFresh = false
): array {
    $fail = static function (int $http, string $err): array {
        return ['ok' => false, 'http' => $http, 'rows' => [], 'err' => $err, 'cached' => false];
    };

    if ($apiKey === '' || $secret === '' || $symbol === '') {
        return $fail(0, 'missing credentials or symbol');
    }

    $cacheKey = 'trades_' . scaling_binance_key_hash($apiKey) . '_' . strtoupper($symbol);
    if (!$forceFresh) {
        $hit = scaling_binance_cache_read($cacheKey);
        if ($hit !== null && is_array($hit['data'])) {
            return [
                'ok' => true,
                'http' => 200,
                'rows' => $hit['data'],
                'err' => '',
                'cached' => true,
            ];
        }
    }

    if (!function_exists('bn_server_time') || !function_exists('bn_sign_query') || !function_exists('http_json')) {
        return $fail(0, 'binance utils missing');
    }

    $ts = bn_server_time();
    $params = [
        'symbol' => $symbol,
        'timestamp' => $ts,
        'limit' => 1000,
        'recvWindow' => 5000,
    ];
    $q = bn_sign_query($params, $secret);
    [$h, $r, $e, $er] = http_json(
        bn_base() . '/api/v3/myTrades?' . $q,
        ['X-MBX-APIKEY: ' . $apiKey],
        'GET'
    );

    if ($e || $h >= 400) {
        return $fail((int)$h, $e ? (string)$e : (string)$er);
    }

    $tmp = json_decode((string)$r, true);
    $rows = is_array($tmp) ? $tmp : [];
    scaling_binance_cache_write($cacheKey, $rows, scaling_binance_cache_ttl_mytrades());

    return ['ok' => true, 'http' => (int)$h, 'rows' => $rows, 'err' => '', 'cached' => false];
}

/**
 * @return array|null decoded account or null on failure
 */
function scaling_binance_account(string $apiKey, string $secret, bool $forceFresh = false): ?array
{
    if ($apiKey === '' || $secret === '') {
        return null;
    }

    $cacheKey = 'acct_' . scaling_binance_key_hash($apiKey);
    if (!$forceFresh) {
        $hit = scaling_binance_cache_read($cacheKey);
        if ($hit !== null && is_array($hit['data'])) {
            return $hit['data'];
        }
    }

    if (!function_exists('exi_get_account')) {
        return null;
    }

    $acc = exi_get_account($apiKey, $secret);
    if ($acc === [] || !is_array($acc)) {
        return null;
    }
    scaling_binance_cache_write($cacheKey, $acc, scaling_binance_cache_ttl_account());
    return $acc;
}
