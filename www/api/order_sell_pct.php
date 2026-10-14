<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_binance_utils.php';
require __DIR__.'/_filters_utils.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$symbol = $_POST['symbol'] ?? $_GET['symbol'] ?? '';
$pct    = isset($_POST['pct']) ? (float)$_POST['pct'] : (isset($_GET['pct'])?(float)$_GET['pct']:100.0);
if(!$symbol || $pct<=0){ echo json_encode(['ok'=>false,'error'=>'bad params']); exit; }

list($apiKey,$secret) = bn_keys();
list($hP,$rP,$eP,$erP) = http_json(bn_base().'/api/v3/ticker/price?symbol='.$symbol);
$price = (!$eP && $hP<400) ? (float)(json_decode($rP,true)['price'] ?? 0) : 0.0;

// бажана к-сть =% від доступної
$asset = str_replace('USDT','',$symbol);
$acc = exi_get_account($apiKey,$secret);
$freeQty = exi_get_free_asset($acc,$asset);
$desired = max(0.0, $freeQty * ($pct/100.0));

// нормалізація під фільтри і мінімальні значення
$qty = exi_normalize_sell_qty($symbol, $desired, $apiKey, $secret);
if ($qty<=0){ echo json_encode(['ok'=>false,'error'=>'qty too small']); exit; }

// MARKET SELL
$ts = bn_server_time();
$params=['symbol'=>$symbol,'side'=>'SELL','type'=>'MARKET','quantity'=>sprintf('%.8f',$qty),'timestamp'=>$ts,'recvWindow'=>5000];
$q = bn_sign_query($params,$secret);
list($h3,$r3,$e3,$er3)=http_json(bn_base().'/api/v3/order',['X-MBX-APIKEY: '.$apiKey],'POST',$q);
if($e3 || $h3>=400){ echo json_encode(['ok'=>false,'error'=>'order','body'=>$r3]); exit; }
echo json_encode(['ok'=>true,'result'=>json_decode($r3,true)]);
