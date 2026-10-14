<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require __DIR__.'/../auth/api_gate_json.php';
require_once __DIR__ . '/_billing_access.php';

billing_emit_trading_denied(billing_require_trading_access($mysqli, $uid));

require __DIR__.'/_binance_utils.php';
require __DIR__.'/_user_keys.php';
require_once __DIR__.'/_strategy_scope.php';
require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();

try{
  $raw=file_get_contents('php://input'); $d=json_decode($raw,true)?:[];
  $symbol = strtoupper(trim($d['symbol'] ?? 'BTCUSDT'));
  $qty    = (float)($d['qty'] ?? 0);
  if($qty<=0) throw new RuntimeException('qty required');

  [$apiKey,$secret] = user_keys($mysqli, $uid);
  if (!$apiKey || !$secret) throw new RuntimeException('user binance keys missing');
  $base = bn_base();

  $info = bn_exchange_info($symbol);
  $qty  = apply_filters_amount($qty, $info);

  $ts = bn_server_time();
  $params = ['symbol'=>$symbol,'side'=>'SELL','type'=>'MARKET','quantity'=>$qty,'timestamp'=>$ts,'recvWindow'=>5000];
  $query  = bn_sign_query($params,$secret);
  [$h2,$r2,$e2,$er2] = http_json($base.'/api/v3/order',['X-MBX-APIKEY: '.$apiKey],'POST',$query);
  $resp = json_decode((string)$r2,true);
  if($e2||$h2>=400){ http_response_code(400); echo json_encode(['ok'=>false,'resp'=>$resp,'err'=>$er2]); exit; }

  $m=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']); if($m->connect_errno) throw new RuntimeException($m->connect_error);
  $m->set_charset('utf8mb4');
  $sid = resolve_trading_strategy_id($m, $uid, $symbol, $d);

  $boid  =(int)($resp['orderId']??0);
  $status=$m->real_escape_string($resp['status']??'NEW');
  $exec  =(float)($resp['executedQty']??0);
  $cquote=(float)($resp['cummulativeQuoteQty']??0);
  $oprice=(float)($resp['price']??0);
  $m->query("INSERT INTO orders(user_id,strategy_id,symbol,side,type,binance_order_id,status,executed_qty,cumm_quote,price,ts)
             VALUES($uid,$sid,'$symbol','SELL','MARKET',$boid,'$status',$exec,$cquote,$oprice,$ts)");
  $oid=(int)$m->insert_id;

  foreach(($resp['fills']??[]) as $f){
    $fp=(float)($f['price']??0); $fq=(float)($f['qty']??0);
    $fc=(float)($f['commission']??0); $fa=$m->real_escape_string($f['commissionAsset']??'');
    $m->query("INSERT INTO fills(order_id,price,qty,commission,commission_asset) VALUES($oid,$fp,$fq,$fc,'$fa')");
  }

  // синк позиції після SELL
  require __DIR__.'/sync_positions_from_orders.php';

  
  // AUTO_ENTRY_PRICE_CLEAR_ON_CLOSE
  // After SELL, if position is effectively flat, clear entry_price and mark state closed
  $epsQty = 0.00000001;
  $res = $m->query("SELECT qty FROM positions WHERE strategy_id=$sid AND user_id=$uid");
  $prow = $res ? $res->fetch_assoc() : null;
  $pqty = (float)($prow['qty'] ?? 0);
  if (abs($pqty) <= $epsQty) {
    $m->query("UPDATE positions SET entry_price=0, state='closed' WHERE strategy_id=$sid AND user_id=$uid");
  }

echo json_encode(['ok'=>true,'order_id'=>$oid,'raw'=>$resp]);
}catch(Throwable $ex){
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>$ex->getMessage()]);
}
