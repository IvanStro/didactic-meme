<?php
declare(strict_types=1);

require __DIR__ . '/../auth/api_gate_json.php'; // дає $auth_user + $mysqli (і JSON unauthorized якщо нема сесії)

header('Content-Type: application/json; charset=utf-8');

$user_id = (int)($auth_user['id'] ?? 0);
if ($user_id <= 0) {
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$res = $mysqli->query("
  SELECT id, symbol, status, fba, last_tick_at
  FROM strategies
  WHERE user_id = $user_id
  ORDER BY id ASC
");

$items = [];
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $items[] = [
      'sid' => (int)$r['id'],
      'symbol' => (string)$r['symbol'],
      'status' => (string)$r['status'],
      'fba' => (float)$r['fba'],
      'last_tick_at' => $r['last_tick_at'],
    ];
  }
}

echo json_encode(['ok'=>true,'items'=>$items,'ts'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
