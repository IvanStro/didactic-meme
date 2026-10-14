<?php
require_once __DIR__.'/_binance_utils.php';

header('Content-Type: application/json; charset=utf-8');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 400;
if ($limit < 50) $limit = 50;
if ($limit > 2000) $limit = 2000;

[$h,$r,$e,$er] = http_json(bn_base().'/api/v3/exchangeInfo');
if ($e || $h >= 400) {
  echo json_encode(['ok'=>false,'error'=>"exchangeInfo http=$h"], JSON_UNESCAPED_UNICODE);
  exit;
}

$j = json_decode((string)$r, true);
$syms = $j['symbols'] ?? [];
$out = [];

foreach ($syms as $s) {
  $symbol = $s['symbol'] ?? '';
  $status = $s['status'] ?? '';
  $quote  = $s['quoteAsset'] ?? '';
  $isSpotAllowed = $s['isSpotTradingAllowed'] ?? true;

  if (!$symbol || $quote !== 'USDT') continue;
  if ($status !== 'TRADING') continue;
  if ($isSpotAllowed === false) continue;

  $out[] = $symbol;
  if (count($out) >= $limit) break;
}

echo json_encode(['ok'=>true,'items'=>$out,'ts'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
