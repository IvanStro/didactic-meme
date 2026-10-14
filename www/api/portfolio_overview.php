<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_binance_utils.php';
require_once __DIR__.'/_user_keys.php';
require_once __DIR__ . '/../auth/_session_user.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }
$mysqli->set_charset('utf8mb4');
function f2($v){ return (float)number_format((float)$v,2,'.',''); }
function f8($v){ return (float)number_format((float)$v,8,'.',''); }

$uid = current_user_id($mysqli);
if ($uid<=0){ echo json_encode(['ok'=>true,'usdt_free'=>f2(0),'positions_invested'=>f2(0),'positions_value'=>f2(0),'unrealized_pl'=>f2(0),'unrealized_pl_pct'=>0,'items'=>[],'ts'=>date('Y-m-d H:i:s')]); exit; }

list($K,$S)=user_keys($mysqli,$uid);
$acc=[];
if($K && $S){
  $ts=bn_server_time(); $q=bn_sign_query(['timestamp'=>$ts,'recvWindow'=>5000],$S);
  list($h,$r,$e,$er)=http_json(bn_base().'/api/v3/account?'.$q,['X-MBX-APIKEY: '.$K],'GET');
  if(!$e && $h<400) $acc=json_decode($r,true)?:[];
}
$usdt_free=0.0;
foreach(($acc['balances']??[]) as $b){ if(($b['asset']??'')==='USDT'){ $usdt_free=(float)($b['free']??0)+(float)($b['locked']??0); break; } }

$items=[]; $sum_invested=0.0; $sum_value=0.0;
$res=$mysqli->query("SELECT s.id sid,s.symbol,p.qty,p.invested_usdt,p.avg_price
                     FROM strategies s LEFT JOIN positions p ON p.strategy_id=s.id
                     WHERE s.owner_id={$uid}");
while($row=$res->fetch_assoc()){
  $sid=(int)$row['sid']; $sym=$row['symbol']?:'BTCUSDT';
  $qty=(float)($row['qty']??0.0); $inv=(float)($row['invested_usdt']??0.0); $avg=(float)($row['avg_price']??0.0);
  list($h,$r,$e,$er)=http_json(bn_base().'/api/v3/ticker/price?symbol='.$sym);
  $price= (!$e && $h<400) ? (float)(json_decode($r,true)['price']??0.0) : 0.0;
  $val=$qty*$price;
  $sum_invested+=$inv; $sum_value+=$val;
  $items[]=[
    'strategy_id'=>$sid,'symbol'=>$sym,
    'qty'=>f8($qty),'avg_price'=>f8(($qty>0 && $inv>0)?($inv/$qty):$avg),'price'=>f8($price),
    'invested_usdt'=>f2($inv),'value_usdt'=>f2($val),
  ];
}
$unreal=$sum_value-$sum_invested; $pl_pct=($sum_invested>0)?($unreal/$sum_invested*100.0):0.0;
echo json_encode(['ok'=>true,'usdt_free'=>f2($usdt_free),'positions_invested'=>f2($sum_invested),'positions_value'=>f2($sum_value),
  'unrealized_pl'=>f2($unreal),'unrealized_pl_pct'=>(float)number_format($pl_pct,2,'.',''),
  'items'=>$items,'ts'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
