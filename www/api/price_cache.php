<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$symbol = strtoupper(trim($_GET['symbol'] ?? ''));
$file = __DIR__.'/../data/ws_prices.json';
$out = ['ok'=>false,'error'=>'no data'];
if (is_file($file)) {
  $j = json_decode(file_get_contents($file), true) ?: [];
  if ($symbol) {
    if (!empty($j[$symbol])) { $out = ['ok'=>true,'symbol'=>$symbol]+$j[$symbol]; }
    else { $out = ['ok'=>false,'error'=>'symbol not found']; }
  } else {
    $out = ['ok'=>true,'items'=>$j];
  }
} else { $out = ['ok'=>false,'error'=>'cache file not found']; }
echo json_encode($out, JSON_UNESCAPED_UNICODE);
