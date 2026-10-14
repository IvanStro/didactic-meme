<?php
declare(strict_types=1);

require_once __DIR__ . '/redis_client.php';
require_once __DIR__ . '/queue_config.php';

const REDIS_PRICES_HASH = 'strim:prices';

function redis_price_bus_write(string $symbol, float $price, ?int $tsMs = null): bool
{
    if (!redis_price_bus_enabled()) {
        return false;
    }
    $r = redis_client();
    if (!$r) {
        return false;
    }
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '' || $price <= 0) {
        return false;
    }
    $tsMs = $tsMs ?? (int)round(microtime(true) * 1000);
    $payload = json_encode(['price' => $price, 'ts' => $tsMs], JSON_UNESCAPED_UNICODE);
    $r->hset(REDIS_PRICES_HASH, $symbol, $payload);
    return true;
}

/** @return array{price:float,ts:int}|null */
function redis_price_bus_read(string $symbol): ?array
{
    $r = redis_client();
    if (!$r) {
        return null;
    }
    $symbol = strtoupper(trim($symbol));
    $raw = $r->hget(REDIS_PRICES_HASH, $symbol);
    if ($raw === null || $raw === '') {
        return null;
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'price' => (float)($data['price'] ?? 0),
        'ts' => (int)($data['ts'] ?? 0),
    ];
}

/** @return array<string,array{price:float,ts:int}> */
function redis_price_bus_read_all(): array
{
    $r = redis_client();
    if (!$r) {
        return [];
    }
    $all = $r->hgetall(REDIS_PRICES_HASH);
    if (!is_array($all)) {
        return [];
    }
    $out = [];
    foreach ($all as $sym => $raw) {
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            continue;
        }
        $out[(string)$sym] = [
            'price' => (float)($data['price'] ?? 0),
            'ts' => (int)($data['ts'] ?? 0),
        ];
    }
    return $out;
}
