<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../auth/_session_user.php';
require_once __DIR__.'/_binance_utils.php';
require_once __DIR__.'/_filters_utils.php';
require_once __DIR__ . '/_strategy_levels_ui.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$symbol = isset($_GET['symbol']) ? strtoupper(trim($_GET['symbol'])) : '';
if ($symbol==='') { echo json_encode(['ok'=>false,'error'=>'no symbol']); exit; }

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$master_id = 0;
$r = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
if ($r && ($row=$r->fetch_assoc())) { $master_id = (int)$row['v']; }

$st = $mysqli->query(
  "SELECT id,COALESCE(tp_whole,1.4) AS tp_whole, COALESCE(cb_whole,0.1) AS cb_whole FROM strategies WHERE symbol='".
  $mysqli->real_escape_string($symbol)."' AND owner_id=$uid ORDER BY id DESC LIMIT 1"
);
if (!($st && ($s=$st->fetch_assoc()))) { echo json_encode(['ok'=>false,'error'=>'strategy_not_found']); exit; }
$sid = (int)$s['id'];
$tp_whole = (float)$s['tp_whole'];

$posr = $mysqli->query(
  "SELECT qty,avg_price,last_buy,COALESCE(base_price,0) AS base_price,COALESCE(next_ptr,1) AS next_ptr,id FROM positions WHERE strategy_id=$sid AND user_id=$uid LIMIT 1"
);
$qty = 0.0;
$avg = 0.0;
$last_buy = 0.0;
$base_price = 0.0;
$ptr = 1;
$pid = 0;
if ($posr && ($p = $posr->fetch_assoc())) {
  $qty = (float)$p['qty'];
  $avg = (float)$p['avg_price'];
  $last_buy = (float)$p['last_buy'];
  $base_price = (float)($p['base_price'] ?? 0);
  $ptr = max(1, (int)$p['next_ptr']);
  $pid = (int)($p['id'] ?? 0);
}

$subBuy = [];
if ($pid > 0) {
  $sr = $mysqli->query(
    "SELECT level_idx, buy_price FROM sub_positions WHERE position_id=$pid AND closed=0 ORDER BY level_idx ASC"
  );
  if ($sr) {
    while ($sp = $sr->fetch_assoc()) {
      $subBuy[(int)$sp['level_idx']] = (float)$sp['buy_price'];
    }
  }
}

$price = exi_get_price($symbol);

$levelsRows = strategy_levels_ui_rows($mysqli, $sid, $master_id);
$levels = [];
foreach ($levelsRows as $lr) {
  $idx = (int)$lr['idx'];
  $tpSub = $lr['tp'];
  $levels[$idx] = [
    'drop' => (float)$lr['drop'],
    'mult' => (float)$lr['mult'],
    'tp'   => ($tpSub !== null && $tpSub > 0) ? (float)$tpSub : 1.6,
  ];
}
if (!$levels) { echo json_encode(['ok'=>false,'error'=>'no_levels']); exit; }

$items = [];
$max = 70;
$take = 5;
$projectedFill = [];
$firstRowAnchor = 0.0;
for ($i = $ptr; $i <= min($max, $ptr + $take - 1); $i++) {
  if (!isset($levels[$i])) {
    continue;
  }
  $drop = (float)$levels[$i]['drop'];
  $tp   = ($i <= 4) ? $tp_whole : (float)$levels[$i]['tp'];
  $anchor = 0.0;
  if ($i <= 4) {
    $anchor = $base_price > 0.0 ? $base_price : $avg;
  } elseif ($i === 5) {
    $anchor = $avg;
  } else {
    $prev = $i - 1;
    if (isset($subBuy[$prev])) {
      $anchor = (float)$subBuy[$prev];
    } elseif (isset($projectedFill[$prev])) {
      $anchor = (float)$projectedFill[$prev];
    } elseif ($pid > 0) {
      $pq = $mysqli->query(
        "SELECT buy_price FROM sub_positions WHERE position_id=$pid AND level_idx=$prev AND closed=0 ORDER BY id DESC LIMIT 1"
      );
      $anchor = ($pq && ($pr = $pq->fetch_assoc())) ? (float)$pr['buy_price'] : 0.0;
    }
  }
  if ($anchor <= 0.0) {
    continue;
  }
  if ($firstRowAnchor <= 0.0) {
    $firstRowAnchor = $anchor;
  }
  $buy_price = $anchor * (1.0 - $drop / 100.0);
  $projectedFill[$i] = $buy_price;
  $tp_price  = $buy_price * (1.0 + $tp / 100.0);
  $need_drop = ($anchor > 0) ? max(0.0, $drop - ((1.0 - $price / $anchor) * 100.0)) : $drop;
  $items[] = [
    'level_idx'   => $i,
    'drop_pct'    => number_format($drop, 2, '.', ''),
    'buy_price'   => number_format($buy_price, 8, '.', ''),
    'tp_pct'      => number_format($tp, 2, '.', ''),
    'tp_price'    => number_format($tp_price, 8, '.', ''),
    'need_drop_%' => number_format($need_drop, 2, '.', ''),
  ];
}

$anchorLabel = $base_price > 0.0 ? $base_price : ($firstRowAnchor > 0.0 ? $firstRowAnchor : $avg);
if ($anchorLabel <= 0.0) {
  $anchorLabel = $last_buy > 0.0 ? $last_buy : $price;
}

echo json_encode([
  'ok'          => true,
  'symbol'      => $symbol,
  'from_ptr'    => $ptr,
  'base_price'  => number_format($base_price, 8, '.', ''),
  'anchor'      => number_format($anchorLabel, 8, '.', ''),
  'price'       => number_format($price, 8, '.', ''),
  'next_levels' => $items,
], JSON_UNESCAPED_UNICODE);
