<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_binance_utils.php';
require_once __DIR__.'/../auth/_session_user.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB '.$mysqli->connect_error], JSON_UNESCAPED_UNICODE);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

function nf($v, int $d=8){ return (float)number_format((float)$v, $d, '.', ''); }
function now(){ return date('Y-m-d H:i:s'); }

function get_price(string $symbol): float {
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/ticker/price?symbol='.$symbol);
  if ($e || $h!==200) throw new RuntimeException("ticker http=$h err=$er");
  $p = (float)(json_decode((string)$r,true)['price'] ?? 0);
  if ($p<=0) throw new RuntimeException("bad price");
  return $p;
}

$res = $mysqli->query("SELECT s.* FROM strategies s LEFT JOIN positions p ON p.strategy_id=s.id AND p.user_id=".(int)$uid." WHERE s.owner_id=".(int)$uid." AND (s.status='running' OR (p.qty IS NOT NULL AND p.qty>0)) ORDER BY s.id ASC");
$list = [];
if ($res) {
  while ($st = $res->fetch_assoc()) {
    $sid    = (int)$st['id'];
    $symbol = $st['symbol'] ?: 'BTCUSDT';
    $mode   = $st['mode'] ?: 'subbin';
    $fba    = (float)$st['fba'];

    $pos = null;
    $rp = $mysqli->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1");
    if ($rp && ($row=$rp->fetch_assoc())) $pos = $row;

    $qty           = $pos ? (float)$pos['qty'] : 0.0;
    $invested_usdt = $pos ? (float)$pos['invested_usdt'] : 0.0;
    $avg_price     = $pos ? (float)$pos['avg_price'] : 0.0;
    $last_buy      = $pos ? (float)$pos['last_buy'] : 0.0;
    $next_ptr      = $pos ? (int)$pos['next_ptr'] : 0;
    $state         = $pos ? (string)$pos['state'] : 'empty';

    try { $price = get_price($symbol); } catch (Throwable $e) { $price = 0.0; }

    $position_value   = $qty * $price;
    $unrealized_pl    = $position_value - $invested_usdt;
    $pl_pct           = ($avg_price>0 && $qty>0) ? (($price/$avg_price - 1.0)*100.0) : 0.0;

    $list[] = [
      'strategy_id'      => $sid,
      'symbol'           => $symbol,
      'mode'             => $mode,
      'fba'              => nf($fba, 2),
      'qty'              => nf($qty, 8),
      'avg_price'        => nf($avg_price, 8),
      'last_buy'         => nf($last_buy, 8),
      'price'            => nf($price, 8),
      'position_value'   => nf($position_value, 2),
      'invested_usdt'    => nf($invested_usdt, 2),
      'unrealized_pl'    => nf($unrealized_pl, 2),
      'unrealized_pl_pct'=> nf($pl_pct, 2),
      'next_ptr'         => $next_ptr,
      'state'            => $state,
      'ts'               => now(),
    ];
  }
}

echo json_encode(['ok'=>true,'items'=>$list], JSON_UNESCAPED_UNICODE);
