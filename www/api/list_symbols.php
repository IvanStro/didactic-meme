<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require __DIR__.'/_binance_utils.php';
try{
  $base = bn_base();
  [$h,$r,$e,$er] = http_json($base.'/api/v3/exchangeInfo');
  if($e || $h!==200){ throw new RuntimeException('exchangeInfo http='.$h.' err='.$er); }
  $j = json_decode((string)$r,true);
  $out = [];
  foreach($j['symbols']??[] as $s){
    if(($s['status']??'')!=='TRADING') continue;
    if(($s['quoteAsset']??'')!=='USDT') continue;
    // Дозволимо як новий формат (permissions), так і старий (isSpotTradingAllowed)
    $isSpot = false;
    foreach(($s['permissions']??[]) as $p){ if($p==='SPOT'){ $isSpot=true; break; } }
    if(!$isSpot && !empty($s['isSpotTradingAllowed'])) $isSpot = true;
    if(!$isSpot) continue;
    $out[] = [
      'symbol' => $s['symbol'],
      'base'   => $s['baseAsset'],
      'quote'  => $s['quoteAsset'],
    ];
  }
  usort($out, fn($a,$b)=>strcmp($a['base'],$b['base']));
  echo json_encode(['ok'=>true,'items'=>$out], JSON_UNESCAPED_UNICODE);
}catch(Throwable $ex){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$ex->getMessage()]);
}
