<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../auth/_session_user.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$m = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($m->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$m->connect_error], JSON_UNESCAPED_UNICODE);
  exit;
}
$m->set_charset('utf8mb4');

$uid = current_user_id($m, 86400);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
$sid = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;

$extra = '';
if ($sid > 0) {
  $extra = ' AND h.strategy_id='.(int)$sid;
}

$sql = "
SELECT h.event_time, h.event_type, h.details, h.price, h.symbol, h.strategy_id
FROM history h
INNER JOIN strategies s ON s.id = h.strategy_id AND s.owner_id=".(int)$uid."
$extra
ORDER BY h.id DESC
LIMIT ".$limit;

$r = $m->query($sql);
$out = ['ok'=>true,'items'=>[]];
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $out['items'][] = $row;
  }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
