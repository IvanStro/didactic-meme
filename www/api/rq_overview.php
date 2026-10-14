<?php
// RoyalQ-style overview: поточні позиції на основі таблиці positions + реальних цін з біржі.
// Повертає формат, який очікує фронт dashboard_rq_rows.html.

require __DIR__ . '/../cron/engine_boot.php';
require __DIR__ . '/../cron/engine_helpers.php';

require_once __DIR__ . '/../auth/_session_user.php';
require __DIR__ . '/_user_keys.php';


// UNREALIZED_FROM_BINANCE_TRADES_V1
// Compute cost-basis for current cycle using moving-average method from Binance myTrades.
// Resets when running qty returns to ~0 (cycle boundary).
function bn_cost_basis_cycle(string $symbol, string $apiKey, string $secret): array {
    $ts = bn_server_time();
    // Binance myTrades: signed
    $q  = bn_sign_query(['symbol'=>$symbol, 'timestamp'=>$ts, 'recvWindow'=>5000, 'limit'=>500], $secret);
    [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/myTrades?'.$q, ['X-MBX-APIKEY: '.$apiKey], 'GET');
    if ($e || $h >= 400) {
        return ['ok'=>false,'qty'=>0.0,'cost'=>0.0,'avg'=>0.0,'note'=>"myTrades http=$h"];
    }
    $trades = json_decode((string)$r, true);
    if (!is_array($trades)) $trades = [];

    // Sort by time ascending
    usort($trades, function($a,$b){ return (int)($a['time']??0) <=> (int)($b['time']??0); });

    $qty = 0.0;     // running qty
    $cost = 0.0;    // running cost in USDT for remaining qty
    $eps = 1e-10;

    foreach ($trades as $t) {
        $isBuyer = !empty($t['isBuyer']);
        $qQty  = (float)($t['qty'] ?? 0.0);           // base qty
        $quote = (float)($t['quoteQty'] ?? 0.0);      // USDT for *USDT symbols
        $price = (float)($t['price'] ?? 0.0);

        // Commission handling (best-effort)
        $comm = (float)($t['commission'] ?? 0.0);
        $commAsset = (string)($t['commissionAsset'] ?? '');
        $fee_usdt = 0.0;
        if ($comm > 0) {
            if ($commAsset === 'USDT') {
                $fee_usdt = $comm;
            } else {
                // If fee is in base asset (e.g., DOGE), approximate with trade price
                $base = str_replace('USDT','',$symbol);
                if ($commAsset === $base && $price > 0) {
                    $fee_usdt = $comm * $price;
                }
            }
        }

        if ($qQty <= 0) continue;

        if ($isBuyer) {
            // Buy increases qty and cost
            $qty += $qQty;
            $cost += $quote + $fee_usdt;
        } else {
            // Sell decreases qty; reduce cost by avg_cost * sold_qty (moving average)
            $avg = ($qty > $eps) ? ($cost / $qty) : 0.0;
            $qty -= $qQty;
            $cost -= $avg * $qQty;
            if (abs($qty) < 1e-8) { $qty = 0.0; $cost = 0.0; } // cycle reset
            if ($cost < 0) $cost = 0.0;
        }
    }

    $avg = ($qty > $eps) ? ($cost / $qty) : 0.0;
    return ['ok'=>true,'qty'=>$qty,'cost'=>$cost,'avg'=>$avg,'note'=>''];
}


// RQ_OVERVIEW_USER_SCOPE_V1: scope overview to current user (no cross-user leak)
$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

[$apiKey, $secret] = user_keys($mysqli, (int)$uid);


header('Content-Type: application/json; charset=utf-8');

// Беремо стратегії + позиції
$sql = "
    SELECT
        s.id         AS sid,
        s.symbol     AS symbol,
        COALESCE(s.status,'')      AS status,
        COALESCE(p.qty,0)          AS qty,
        COALESCE(p.avg_price,0)    AS avg_price,
        COALESCE(p.invested_usdt,0) AS invested_usdt
    FROM strategies s
    LEFT JOIN positions p ON p.strategy_id = s.id
    WHERE s.owner_id = $uid
    ORDER BY s.id ASC
";

$res = $mysqli->query($sql);

$items = [];
$ts    = date('Y-m-d H:i:s');

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $sid      = (int)$row['sid'];
        $symbol   = (string)$row['symbol'];
        $status   = (string)$row['status'];
        $qty      = (float)$row['qty'];
        $avg      = (float)$row['avg_price'];
        $invested = (float)$row['invested_usdt'];

        // Пропускаємо порожні позиції (qty=0) – щоб не показувати пил
        if ($qty <= 0) {
            continue;
        }

        // Поточна ціна
        $price = ws_ticker_price($symbol);
        if ($price <= 0) {
            $price = exi_get_price($symbol, $apiKey, $secret);
        }
        // Поточна вартість та нереалізований P/L (Binance fills-based)
        $value = $qty * $price;

        $basis = bn_cost_basis_cycle($symbol, $apiKey, $secret);
        if (!empty($basis['ok']) && (float)$basis['cost'] > 0) {
            $cost_usdt   = (float)$basis['cost'];
            $unr_pl      = $value - $cost_usdt;
            $unr_pl_pct  = ($value / $cost_usdt - 1.0) * 100.0;
            // optional: expose avg from fills if you want later
            // $avg_from_fills = (float)$basis['avg'];
        } else {
            $unr_pl     = 0.0;
            $unr_pl_pct = 0.0;
        }
        // Об'єкт, який очікує фронт (dashboard_rq_rows.html)
        $items[] = [
            'sid'               => $sid,
            'symbol'            => $symbol,
            'status'            => $status,
            'qty'               => $qty,
            'avg_price'         => $avg,
            'price'             => $price,
            'value_usdt'        => $value,
            'unrealized_pl'     => $unr_pl,
            'unrealized_pl_pct' => $unr_pl_pct,
        ];
    }
}

echo json_encode(
    [
        'ok'    => true,
        'items' => $items,
        'ts'    => $ts,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
