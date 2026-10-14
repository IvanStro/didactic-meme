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
  $raw = file_get_contents('php://input');
  $d   = json_decode($raw,true) ?: [];
  $symbol = strtoupper(trim($d['symbol'] ?? 'BTCUSDT'));
  $amount_usdt = (float)($d['amount_usdt'] ?? 0);
  if ($amount_usdt <= 0) throw new RuntimeException('amount_usdt required');

  [$apiKey,$secret] = user_keys($mysqli, $uid);
  if (!$apiKey || !$secret) throw new RuntimeException('user binance keys missing');
  $base = bn_base();

  // 1) Ціна
  [$h,$r,$e,$er] = http_json($base.'/api/v3/ticker/price?symbol='.$symbol);
  if($e || $h!==200) throw new RuntimeException('price http='.$h.' err='.$er);
  $price = (float)(json_decode((string)$r,true)['price'] ?? 0);
  if($price <= 0) throw new RuntimeException('bad price');

  // 2) Фільтри символу і кількість
  $info = bn_exchange_info($symbol);
  $qty  = $amount_usdt / $price;
  $qty  = apply_filters_amount($qty, $info);
  check_min_notional($qty, $price, $info);

  // 3) Ордер
  $ts = bn_server_time();
  $params = [
    'symbol'=>$symbol,'side'=>'BUY','type'=>'MARKET',
    'quantity'=>$qty,'timestamp'=>$ts,'recvWindow'=>5000
  ];
  $query = bn_sign_query($params, $secret);
  [$h2,$r2,$e2,$er2] = http_json($base.'/api/v3/order', ['X-MBX-APIKEY: '.$apiKey], 'POST', $query);
  $resp = json_decode((string)$r2, true);
  if($e2 || $h2>=400){ http_response_code(400); echo json_encode(['ok'=>false,'resp'=>$resp,'err'=>$er2]); exit; }

  // 4) Запис у БД + оновлення символу стратегії
  $m = new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);
  if($m->connect_errno) throw new RuntimeException($m->connect_error);
  $m->set_charset('utf8mb4');

  $sid = resolve_trading_strategy_id($m, $uid, $symbol, $d);

  // оновлюємо символ стратегії під реальну пару
  $m->query("UPDATE strategies SET symbol='".$m->real_escape_string($symbol)."' WHERE id=".$sid);

  $boid   = (int)  ($resp['orderId'] ?? 0);
  $status =         $m->real_escape_string($resp['status'] ?? 'NEW');
  $exec   = (float)($resp['executedQty'] ?? 0);
  $cquote = (float)($resp['cummulativeQuoteQty'] ?? 0);
  $oprice = (float)($resp['price'] ?? 0);

  $m->query("INSERT INTO orders(user_id,strategy_id,symbol,side,type,binance_order_id,status,executed_qty,cumm_quote,price,ts)
             VALUES($uid,$sid,'$symbol','BUY','MARKET',$boid,'$status',$exec,$cquote,$oprice,$ts)");
  $oid = (int)$m->insert_id;

  foreach(($resp['fills']??[]) as $f){
    $fp=(float)($f['price']??0); $fq=(float)($f['qty']??0);
    $fc=(float)($f['commission']??0); $fa=$m->real_escape_string($f['commissionAsset']??'');
    $m->query("INSERT INTO fills(order_id,price,qty,commission,commission_asset) VALUES($oid,$fp,$fq,$fc,'$fa')");
  }
  // AUTO_ENTRY_PRICE_FROM_FILLS
  // Save Binance-style entry price (like Binance UI) from order fills: entry = quote / netQty
  $baseAsset = $symbol;
  foreach (['USDT','BUSD','USDC','BTC','ETH'] as $qAst) {
    $qlen = strlen($qAst);
    if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $qAst) === 0) {
      $baseAsset = substr($baseAsset, 0, -$qlen);
      break;
    }
  }

  $grossQty = 0.0;
  $quoteSum = 0.0;
  $feeBase  = 0.0;

  foreach (($resp['fills'] ?? []) as $f) {
    $fp = (float)($f['price'] ?? 0);
    $fq = (float)($f['qty'] ?? 0);
    $fc = (float)($f['commission'] ?? 0);
    $fa = strtoupper((string)($f['commissionAsset'] ?? ''));

    if ($fq > 0 && $fp > 0) {
      $grossQty += $fq;
      $quoteSum += ($fp * $fq);
    }
    if ($fc > 0 && $fa === strtoupper($baseAsset)) {
      $feeBase += $fc; // fee in base asset reduces netQty
    }
  }

  // Fallback: if fills missing, try Binance response fields
  if ($quoteSum <= 0 && $cquote > 0) $quoteSum = $cquote;
  if ($grossQty <= 0 && $exec > 0)   $grossQty = $exec;

  $netQty = max(0.0, $grossQty - $feeBase);
  $entry  = ($netQty > 0 && $quoteSum > 0) ? ($quoteSum / $netQty) : 0.0;

  if ($entry > 0) {
    $entrySql = number_format($entry, 8, '.', '');
    $m->query("UPDATE positions SET entry_price=$entrySql WHERE strategy_id=$sid");
  }



  // 5) Синхронізація позиції з ордерів
  require __DIR__.'/sync_positions_from_orders.php';

  echo json_encode(['ok'=>true,'order_id'=>$oid,'raw'=>$resp]);
}catch(Throwable $ex){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$ex->getMessage()]);
}
