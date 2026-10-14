<?php
declare(strict_types=1);
/**
 * Backfill missing trade_ledger + engine_journal from Binance order id.
 *   php run/backfill_binance_sell.php <strategy_id> <order_id> [level_idx]
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine_v2/bootstrap.php';
require_once __DIR__ . '/../www/api/_user_keys.php';
require_once __DIR__ . '/../www/api/_binance_utils.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$sid = (int) ($argv[1] ?? 0);
$orderId = (int) ($argv[2] ?? 0);
$levelIdx = isset($argv[3]) ? (int) $argv[3] : 7;

if ($sid <= 0 || $orderId <= 0) {
    fwrite(STDERR, "Usage: php run/backfill_binance_sell.php <strategy_id> <order_id> [level_idx]\n");
    exit(1);
}

$db = db();
$st = $db->query("SELECT id, user_id, symbol FROM strategies WHERE id={$sid} LIMIT 1")->fetch_assoc();
if (!$st) {
    fwrite(STDERR, "strategy not found\n");
    exit(1);
}
$uid = (int) $st['user_id'];
$symbol = (string) $st['symbol'];

$exists = $db->query("SELECT id FROM trade_ledger WHERE strategy_id={$sid} AND order_id={$orderId} LIMIT 1");
if ($exists && $exists->fetch_assoc()) {
    echo "ledger already has order_id={$orderId}\n";
    exit(0);
}

[$apiKey, $secret] = user_keys($db, $uid);
if (!$apiKey || !$secret) {
    fwrite(STDERR, "no api keys\n");
    exit(1);
}

$params = [
    'symbol' => $symbol,
    'orderId' => $orderId,
    'timestamp' => bn_server_time(),
    'recvWindow' => 10000,
];
$q = bn_sign_query($params, $secret);
[$http, $body, $err] = http_json(
    bn_base() . '/api/v3/order?' . $q,
    ['X-MBX-APIKEY: ' . $apiKey],
    'GET'
);
$ord = json_decode((string) $body, true);
if ($err || $http >= 400 || !is_array($ord)) {
    fwrite(STDERR, "binance order fetch failed http={$http} err={$err} body={$body}\n");
    exit(1);
}

$execQty = (float) ($ord['executedQty'] ?? 0);
$quoteQ = (float) ($ord['cummulativeQuoteQty'] ?? 0);
$pSell = ($execQty > 0 && $quoteQ > 0) ? ($quoteQ / $execQty) : 0.0;

$pid = (int) ($db->query("SELECT id FROM positions WHERE strategy_id={$sid} LIMIT 1")->fetch_assoc()['id'] ?? 0);
$basis = 0.0;
if ($pid > 0 && $levelIdx > 0) {
    $br = $db->query("SELECT buy_price FROM sub_positions WHERE position_id={$pid} AND level_idx={$levelIdx} ORDER BY id DESC LIMIT 1");
    if ($br && ($brow = $br->fetch_assoc())) {
        $basis = (float) ($brow['buy_price'] ?? 0);
    }
}
if ($basis <= 0) {
    fwrite(STDERR, "cannot resolve basis buy_price for L{$levelIdx}\n");
    exit(1);
}

$pnlU = ($pSell - $basis) * $execQty;
$pnlP = (($pSell / $basis) - 1.0) * 100.0;
$ts = isset($ord['transactTime']) ? (int) $ord['transactTime'] : (int) (microtime(true) * 1000);
$createdAt = gmdate('Y-m-d H:i:s', (int) ($ts / 1000));

$clientOrderId = (string) ($ord['clientOrderId'] ?? '');
$cidSql = $clientOrderId !== '' ? "'" . $db->real_escape_string($clientOrderId) . "'" : 'NULL';
$metaEsc = $db->real_escape_string(json_encode(['src' => 'backfill_binance_sell', 'note' => 'orphan sell recovered from Binance'], JSON_UNESCAPED_UNICODE));

$db->query("
    INSERT INTO trade_ledger
      (created_at, user_id, strategy_id, symbol, event_type, level_idx, side, order_id, client_order_id,
       price, qty, quote_usdt, fee, fee_asset, basis_price, pnl_usdt, pnl_pct, meta)
    VALUES
      ('{$createdAt}', {$uid}, {$sid}, '" . $db->real_escape_string($symbol) . "', 'SELL_SUB', {$levelIdx}, 'SELL',
       {$orderId}, {$cidSql},
       {$pSell}, {$execQty}, {$quoteQ}, 0, NULL, {$basis}, {$pnlU}, {$pnlP}, '{$metaEsc}')
");
$ledgerId = (int) $db->insert_id;

$msg = sprintf(
    "🟢 %s SUB L%d SELL\nQty: %.8f\nBuy: %.8f\nPrice: %.8f\nPnL: %.2f (%.2f%%)\n[backfill orphan sell order %d]",
    $symbol,
    $levelIdx,
    $execQty,
    $basis,
    $pSell,
    $pnlU,
    $pnlP,
    $orderId
);
$msgEsc = $db->real_escape_string($msg);
$db->query("
    INSERT INTO engine_journal
      (strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message, created_at)
    VALUES
      ({$sid}, '" . $db->real_escape_string($symbol) . "', 'SUB_SELL', {$levelIdx}, 'SELL',
       {$execQty}, {$pSell}, {$pnlU}, {$pnlP}, '{$msgEsc}', '{$createdAt}')
");
$journalId = (int) $db->insert_id;

echo json_encode([
    'ok' => true,
    'ledger_id' => $ledgerId,
    'journal_id' => $journalId,
    'order_id' => $orderId,
    'price' => $pSell,
    'qty' => $execQty,
    'pnl_usdt' => round($pnlU, 4),
    'created_at_utc' => $createdAt,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
