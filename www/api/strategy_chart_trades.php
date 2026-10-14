<?php
declare(strict_types=1);

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_journal_query.php';

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sid = isset($_GET['strategy_id']) ? (int) $_GET['strategy_id'] : 0;
if ($sid <= 0 && isset($_GET['sid'])) {
    $sid = (int) $_GET['sid'];
}
if ($sid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'strategy_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$scope = journal_sql_owner_scope($uid, 's');
$stRes = $mysqli->query(
    "SELECT id, symbol FROM strategies s WHERE s.id=" . (int) $sid . " AND {$scope} LIMIT 1"
);
$stRow = $stRes ? $stRes->fetch_assoc() : null;
if (!$stRow) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$symbol = (string) ($stRow['symbol'] ?? '');
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 400;
$limit = max(1, min(800, $limit));

$items = [];

$tlRes = $mysqli->query("
  SELECT id, created_at, event_type, level_idx, side, qty, price, quote_usdt, pnl_usdt, pnl_pct, order_id
  FROM trade_ledger
  WHERE strategy_id=" . (int) $sid . "
    AND (qty > 0 OR quote_usdt > 0)
  ORDER BY created_at ASC, id ASC
  LIMIT {$limit}
");
while ($tlRes && ($r = $tlRes->fetch_assoc())) {
    $items[] = chart_trade_row_from_db($r, $symbol, 'ledger');
}

$ejRes = $mysqli->query("
  SELECT j.id, j.created_at, j.event_type, j.level_idx, j.side, j.qty, j.price, j.pnl_usdt, j.pnl_pct
  FROM engine_journal j
  WHERE j.strategy_id=" . (int) $sid . "
    AND j.qty IS NOT NULL AND j.qty > 0
    AND j.event_type IN ('BUY0','BUY_L','BUY_EXT','SELL_SUB','SELL_MAIN','CORE_SELL','MAIN_SELL_ALL','SUB_SELL')
  ORDER BY j.created_at ASC, j.id ASC
  LIMIT {$limit}
");
while ($ejRes && ($r = $ejRes->fetch_assoc())) {
    $ts = strtotime((string) ($r['created_at'] ?? ''));
    if ($ts <= 0) {
        continue;
    }
    $dup = false;
    foreach ($items as $ex) {
        if ((string) ($ex['event_type'] ?? '') !== (string) ($r['event_type'] ?? '')) {
            continue;
        }
        $l1 = $ex['level_idx'] ?? null;
        $l2 = $r['level_idx'] === null ? null : (int) $r['level_idx'];
        if ($l1 !== $l2) {
            continue;
        }
        if (abs((int) ($ex['time'] ?? 0) - $ts) <= 120) {
            $dup = true;
            break;
        }
    }
    if ($dup) {
        continue;
    }
    $items[] = chart_trade_row_from_db($r, $symbol, 'journal');
}

usort($items, static function (array $a, array $b): int {
    $ta = (int) ($a['time'] ?? 0);
    $tb = (int) ($b['time'] ?? 0);
    if ($ta !== $tb) {
        return $ta <=> $tb;
    }

    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
});

if (count($items) > $limit) {
    $items = array_slice($items, -$limit);
}

$mysqli->close();

echo json_encode([
    'ok'       => true,
    'symbol'   => $symbol,
    'strategy_id' => $sid,
    'items'    => $items,
    'ts'       => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function chart_trade_row_from_db(array $r, string $symbol, string $src): array
{
    $ts = strtotime((string) ($r['created_at'] ?? ''));
    $eventType = (string) ($r['event_type'] ?? '');
    $sideRaw = strtoupper((string) ($r['side'] ?? ''));
    $isSell = $sideRaw === 'SELL'
        || str_starts_with($eventType, 'SELL')
        || in_array($eventType, ['CORE_SELL', 'MAIN_SELL_ALL', 'SUB_SELL'], true);
    $side = $isSell ? 'sell' : 'buy';

    $qty = (float) ($r['qty'] ?? 0);
    $price = (float) ($r['price'] ?? 0);
    $quote = (float) ($r['quote_usdt'] ?? 0);
    if ($quote <= 0 && $qty > 0 && $price > 0) {
        $quote = $qty * $price;
    }

    $asset = $symbol;
    foreach (['USDT', 'BUSD', 'USDC', 'BTC', 'ETH'] as $q) {
        if (str_ends_with($asset, $q)) {
            $asset = substr($asset, 0, -strlen($q));
            break;
        }
    }

    $lvl = $r['level_idx'] === null ? null : (int) $r['level_idx'];
    $lvlLabel = $lvl !== null && $lvl > 0 ? ('L' . $lvl) : '';

    return [
        'id'          => (int) ($r['id'] ?? 0),
        'time'        => $ts > 0 ? $ts : 0,
        'side'        => $side,
        'event_type'  => $eventType,
        'level_idx'   => $lvl,
        'level_label' => $lvlLabel,
        'qty'         => $qty,
        'price'       => $price,
        'quote_usdt'  => round($quote, 8),
        'pnl_usdt'    => isset($r['pnl_usdt']) ? (float) $r['pnl_usdt'] : null,
        'pnl_pct'     => isset($r['pnl_pct']) ? (float) $r['pnl_pct'] : null,
        'asset'       => $asset,
        'source'      => $src,
    ];
}
