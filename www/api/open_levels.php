<?php
declare(strict_types=1);

require __DIR__ . '/../auth/api_gate_json.php'; // дає $auth_user + $mysqli або JSON unauthorized

header('Content-Type: application/json; charset=utf-8');

$user_id = (int)($auth_user['id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Include-path safe: run_include_json() sets $_GET (sid/strategy_id); REQUEST_URI may be unrelated.
$strategy_id = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;
if ($strategy_id <= 0 && isset($_GET['sid'])) {
    $strategy_id = (int)$_GET['sid'];
}
if ($strategy_id <= 0) {
    $qs = [];
    $q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    parse_str($q ?? '', $qs);
    $strategy_id = isset($qs['strategy_id']) ? (int)$qs['strategy_id'] : 0;
    if ($strategy_id <= 0 && isset($qs['sid'])) {
        $strategy_id = (int)$qs['sid'];
    }
}
if ($strategy_id <= 0) {
    echo json_encode([
        'ok'=>false,
        'error'=>'strategy_id required',
        'qs_raw'=>($_SERVER['QUERY_STRING'] ?? ''),
        'get'=>array_keys($_GET ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// перевірка власника стратегії
$res = $mysqli->query("
    SELECT id, symbol
    FROM strategies
    WHERE id = $strategy_id AND owner_id = $user_id
    LIMIT 1
");
if (!$res || !($st = $res->fetch_assoc())) {
    echo json_encode(['ok'=>false,'error'=>'strategy not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// відкриті рівні через JOIN positions
$res2 = $mysqli->query("
    SELECT
        sp.level_idx,
        sp.qty,
        sp.buy_price,
        sp.tp,
        sp.cb
    FROM sub_positions sp
    JOIN positions p ON p.id = sp.position_id
    WHERE p.strategy_id = $strategy_id
      AND sp.closed = 0
    ORDER BY sp.level_idx ASC
");

$items = [];
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        $buy = (float)$r['buy_price'];
        $tp  = (float)$r['tp'];
        $items[] = [
            'level' => (int)$r['level_idx'],
            'qty' => (float)$r['qty'],
            'buy_price' => $buy,
            'tp_pct' => $tp,
            'tp_price' => ($buy > 0 && $tp > 0) ? round($buy * (1 + $tp/100), 8) : null,
            'cb_pct' => (float)$r['cb'],
        ];
    }
}

echo json_encode([
    'ok' => true,
    'strategy_id' => $strategy_id,
    'symbol' => $st['symbol'],
    'items' => $items,
    'ts' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
