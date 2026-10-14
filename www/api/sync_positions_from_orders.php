<?php
// Unified sync: обновляет таблицу positions на основе реальных трейдов (Binance myTrades)
// для всех стратегий, используя sync_from_trades из cron/engine_helpers.php.

require __DIR__ . '/../../engine/engine_boot.php';
require __DIR__ . '/../../engine/engine_helpers.php';

$out = [
    'ok'     => true,
    'synced' => [],
];

$mysqli->set_charset('utf8mb4');

// Берём все стратегии (можно ограничить running/qty>0 при желании)
// sync тільки поточної стратегії (переданої з order_buy.php)
if (!isset($sid) || !isset($symbol) || !isset($apiKey) || !isset($secret)) {
    throw new RuntimeException('sync context missing');
}

try {
    $pos = sync_from_trades($mysqli, $sid, $symbol, $apiKey, $secret);
    // після sync — прив'язуємо позицію до user
    

    $out['synced'][] = [
        'sid'       => $sid,
        'symbol'    => $symbol,
        'qty'       => isset($pos['qty']) ? (float)$pos['qty'] : null,
        'avg_price' => isset($pos['avg_price']) ? (float)$pos['avg_price'] : null,
    ];
} catch (Throwable $e) {
    $out['synced'][] = [
        'sid'    => $sid,
        'symbol' => $symbol,
        'error'  => $e->getMessage(),
    ];
}


