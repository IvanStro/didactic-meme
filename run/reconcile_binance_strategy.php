<?php
declare(strict_types=1);
/**
 * Binance vs DB reconciliation for one strategy.
 * Usage: php run/reconcile_binance_strategy.php <user_id> <strategy_id>
 */

require __DIR__ . '/../www/vendor/autoload.php';
require __DIR__ . '/../www/api/_user_keys.php';
require __DIR__ . '/../www/api/_binance_utils.php';
require __DIR__ . '/../www/api/_filters_utils.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$uid = (int) ($argv[1] ?? 15);
$sid = (int) ($argv[2] ?? 16);

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

[$apiKey, $secret] = user_keys($m, $uid);
if (!$apiKey || !$secret) {
    fwrite(STDERR, "no keys\n");
    exit(1);
}

$st = $m->query("SELECT * FROM strategies WHERE id={$sid} AND user_id={$uid} LIMIT 1")->fetch_assoc();
$pos = $m->query("SELECT * FROM positions WHERE strategy_id={$sid} LIMIT 1")->fetch_assoc();
if (!$st || !$pos) {
    fwrite(STDERR, "not found\n");
    exit(1);
}

$symbol = (string) $st['symbol'];
$startTs = (int) ($st['start_ts'] ?? 0);

// Base asset
$baseAsset = $symbol;
foreach (['USDT', 'BUSD', 'USDC', 'BTC', 'ETH'] as $q) {
    $qlen = strlen($q);
    if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $q) === 0) {
        $baseAsset = substr($baseAsset, 0, -$qlen);
        break;
    }
}

function bn_get(string $path, array $params, string $apiKey, string $secret): array
{
    $params['timestamp'] = bn_server_time();
    $params['recvWindow'] = 5000;
    $q = bn_sign_query($params, $secret);
    [$h, $r, $e] = http_json(bn_base() . $path . '?' . $q, ['X-MBX-APIKEY: ' . $apiKey], 'GET');
    $j = json_decode((string) $r, true);
    return ['http' => $h, 'err' => $e, 'json' => $j, 'raw' => $r];
}

// Account balance
$acc = bn_get('/api/v3/account', [], $apiKey, $secret);
$freeBase = 0.0;
$lockedBase = 0.0;
$freeUsdt = 0.0;
foreach ($acc['json']['balances'] ?? [] as $b) {
    if (($b['asset'] ?? '') === $baseAsset) {
        $freeBase = (float) ($b['free'] ?? 0);
        $lockedBase = (float) ($b['locked'] ?? 0);
    }
    if (($b['asset'] ?? '') === 'USDT') {
        $freeUsdt = (float) ($b['free'] ?? 0);
    }
}

// myTrades all (paginate)
$allTrades = [];
$fromId = null;
for ($page = 0; $page < 20; $page++) {
    $p = ['symbol' => $symbol, 'limit' => 1000];
    if ($fromId !== null) {
        $p['fromId'] = $fromId;
    }
    $res = bn_get('/api/v3/myTrades', $p, $apiKey, $secret);
    $batch = $res['json'];
    if (!is_array($batch) || $batch === []) {
        break;
    }
    foreach ($batch as $t) {
        $allTrades[] = $t;
    }
    $lastId = (int) ($batch[count($batch) - 1]['id'] ?? 0);
    if (count($batch) < 1000) {
        break;
    }
    $fromId = $lastId + 1;
}

function aggregate_trades(array $trades, string $baseAsset, int $sinceMs = 0): array
{
    $netQty = 0.0;
    $costBuy = 0.0;
    $costSell = 0.0;
    $buys = [];
    $sells = [];
    foreach ($trades as $t) {
        $tm = (int) ($t['time'] ?? 0);
        if ($sinceMs > 0 && $tm < $sinceMs) {
            continue;
        }
        $p = (float) ($t['price'] ?? 0);
        $q = (float) ($t['qty'] ?? 0);
        $qAmt = (float) ($t['quoteQty'] ?? ($p * $q));
        $isB = !empty($t['isBuyer']);
        $comm = (float) ($t['commission'] ?? 0);
        $cAst = (string) ($t['commissionAsset'] ?? '');
        $qEff = $q;
        if ($cAst !== '' && strcasecmp($cAst, $baseAsset) === 0) {
            $qEff -= $comm;
            if ($qEff < 0) {
                $qEff = 0;
            }
        }
        if ($isB) {
            $netQty += $qEff;
            $costBuy += $qAmt;
            $buys[] = $t;
        } else {
            $netQty -= $qEff;
            $costSell += $qAmt;
            $sells[] = $t;
        }
    }
    $netCost = $costBuy - $costSell;
    $avg = $netQty > 0 ? ($netCost / $netQty) : 0;
    return [
        'net_qty' => $netQty,
        'net_cost' => $netCost,
        'avg' => $avg,
        'buy_count' => count($buys),
        'sell_count' => count($sells),
        'buys' => $buys,
        'sells' => $sells,
    ];
}

$sinceMs = $startTs > 0 ? $startTs : 0;
$aggAll = aggregate_trades($allTrades, $baseAsset, 0);
$aggSince = aggregate_trades($allTrades, $baseAsset, $sinceMs);

// DB ledger
$ledger = [];
$lq = $m->query("SELECT id, created_at, event_type, level_idx, side, qty, price, quote_usdt, order_id FROM trade_ledger WHERE strategy_id={$sid} ORDER BY id ASC");
while ($lq && ($r = $lq->fetch_assoc())) {
    $ledger[] = $r;
}
$ledgerBuyQty = 0.0;
$ledgerSellQty = 0.0;
foreach ($ledger as $row) {
    if (($row['side'] ?? '') === 'BUY') {
        $ledgerBuyQty += (float) ($row['qty'] ?? 0);
    } elseif (($row['side'] ?? '') === 'SELL') {
        $ledgerSellQty += (float) ($row['qty'] ?? 0);
    }
}

// Sub positions open qty sum
$pid = (int) $pos['id'];
$subOpenQty = 0.0;
$subTotalRows = 0;
$sq = $m->query("SELECT level_idx, qty, buy_price, closed FROM sub_positions WHERE position_id={$pid}");
while ($sq && ($r = $sq->fetch_assoc())) {
    $subTotalRows++;
    if ((int) ($r['closed'] ?? 0) === 0) {
        $subOpenQty += (float) ($r['qty'] ?? 0);
    }
}

// SYNC count
$syncN = (int) ($m->query("SELECT COUNT(*) c FROM engine_journal WHERE strategy_id={$sid} AND event_type='SYNC_EXTERNAL_BUY'")->fetch_assoc()['c'] ?? 0);

// Match ledger order_ids to binance trades
$orderIds = [];
foreach ($ledger as $row) {
    if (!empty($row['order_id'])) {
        $orderIds[(string) $row['order_id']] = true;
    }
}
$binanceOrderIds = [];
foreach ($allTrades as $t) {
    $binanceOrderIds[(string) ($t['orderId'] ?? '')] = $t;
}

$ledgerOrdersInBinance = 0;
$ledgerOrdersMissing = [];
foreach (array_keys($orderIds) as $oid) {
    if (isset($binanceOrderIds[$oid])) {
        $ledgerOrdersInBinance++;
    } else {
        $ledgerOrdersMissing[] = $oid;
    }
}

// Recent binance trades not in ledger (by orderId)
$ledgerOidSet = $orderIds;
$untrackedBuys = [];
foreach ($aggSince['buys'] as $t) {
    $oid = (string) ($t['orderId'] ?? '');
    if ($oid !== '' && !isset($ledgerOidSet[$oid])) {
        $untrackedBuys[] = [
            'time' => gmdate('Y-m-d H:i:s', (int) floor(((int) $t['time']) / 1000)),
            'orderId' => $oid,
            'qty' => $t['qty'],
            'price' => $t['price'],
            'quoteQty' => $t['quoteQty'] ?? null,
        ];
    }
}

$out = [
    'symbol' => $symbol,
    'base_asset' => $baseAsset,
    'start_ts' => $startTs,
    'start_ts_human' => $startTs > 0 ? gmdate('Y-m-d H:i:s', (int) floor($startTs / 1000)) . ' UTC' : null,
    'exchange' => [
        'free_base' => $freeBase,
        'locked_base' => $lockedBase,
        'total_base' => $freeBase + $lockedBase,
        'free_usdt' => $freeUsdt,
    ],
    'db_position' => [
        'qty' => (float) $pos['qty'],
        'avg_price' => (float) $pos['avg_price'],
        'base_price' => (float) $pos['base_price'],
        'invested_usdt' => (float) $pos['invested_usdt'],
        'next_ptr' => (int) $pos['next_ptr'],
    ],
    'myTrades_total' => count($allTrades),
    'myTrades_all_time' => [
        'net_qty' => round($aggAll['net_qty'], 8),
        'avg' => round($aggAll['avg'], 8),
        'buys' => $aggAll['buy_count'],
        'sells' => $aggAll['sell_count'],
    ],
    'myTrades_since_start_ts' => [
        'net_qty' => round($aggSince['net_qty'], 8),
        'avg' => round($aggSince['avg'], 8),
        'buys' => $aggSince['buy_count'],
        'sells' => $aggSince['sell_count'],
    ],
    'trade_ledger' => [
        'rows' => count($ledger),
        'buy_qty_sum' => round($ledgerBuyQty, 8),
        'sell_qty_sum' => round($ledgerSellQty, 8),
        'net_qty' => round($ledgerBuyQty - $ledgerSellQty, 8),
    ],
    'sub_positions' => [
        'rows' => $subTotalRows,
        'open_qty_sum' => round($subOpenQty, 8),
    ],
    'reconciliation' => [
        'db_qty_vs_exchange' => round((float) $pos['qty'] - ($freeBase + $lockedBase), 8),
        'myTrades_since_vs_db' => round($aggSince['net_qty'] - (float) $pos['qty'], 8),
        'myTrades_since_vs_exchange' => round($aggSince['net_qty'] - ($freeBase + $lockedBase), 8),
        'ledger_net_vs_db' => round(($ledgerBuyQty - $ledgerSellQty) - (float) $pos['qty'], 8),
    ],
    'sync_external_buy_count' => $syncN,
    'ledger_orders_in_binance' => $ledgerOrdersInBinance,
    'ledger_orders_missing_count' => count($ledgerOrdersMissing),
    'ledger_orders_missing_sample' => array_slice($ledgerOrdersMissing, 0, 10),
    'untracked_buys_since_start_ts' => $untrackedBuys,
    'recent_binance_buys_since_start' => array_map(static function ($t) {
        return [
            'time' => gmdate('Y-m-d H:i:s', (int) floor(((int) $t['time']) / 1000)),
            'orderId' => $t['orderId'] ?? null,
            'qty' => $t['qty'],
            'price' => $t['price'],
        ];
    }, array_slice($aggSince['buys'], -15)),
    'recent_binance_sells_since_start' => array_map(static function ($t) {
        return [
            'time' => gmdate('Y-m-d H:i:s', (int) floor(((int) $t['time']) / 1000)),
            'orderId' => $t['orderId'] ?? null,
            'qty' => $t['qty'],
            'price' => $t['price'],
        ];
    }, array_slice($aggSince['sells'], -15)),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
