<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Use POST with JSON body']); exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit;
}

# базові перевірки
$levels = $data['levels'] ?? null;
if (!is_array($levels) || count($levels)!==70) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'levels must be array of 70 items']); exit;
}

# нормалізація чисел
function f($v){ return is_numeric($v) ? (float)$v : null; }
$out = [
  'global_cb' => f($data['global_cb'] ?? null),
  'tp_whole'  => f($data['tp_whole']  ?? null),
  'cb_whole'  => f($data['cb_whole']  ?? null),
  'levels'    => []
];

for($i=1;$i<=70;$i++){
  $l = $levels[$i-1] ?? [];
  $out['levels'][] = [
    'idx'   => $i,
    'drop'  => f($l['drop']  ?? null),
    'mult'  => f($l['mult']  ?? null),
    'tp_sub'=> $i>=5 ? f($l['tp_sub'] ?? null) : null,
    'cb_sub'=> $i>=5 ? f($l['cb_sub'] ?? ($out['global_cb'] ?? null)) : null,
  ];
}

# збереження у файл (тимчасово)
$storeDir = dirname(__DIR__) . '/data';
@mkdir($storeDir, 0775, true);
$file = $storeDir . '/strategy.json';
$ok = (bool)file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

if (!$ok) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Failed to write file']); exit;
}

echo json_encode(['ok'=>true,'saved'=>'file','path'=>basename($file)]);
