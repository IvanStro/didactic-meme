<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../auth/_session_user.php';
require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();
$mysqli = @new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if($mysqli->connect_errno){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$mysqli->connect_error]); exit; }
$mysqli->set_charset('utf8mb4');
$uid = current_user_id($mysqli);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
$strategyId = (int)($_REQUEST['strategy_id'] ?? ($_REQUEST['sid'] ?? 0));
if ($strategyId > 0) {
  $res = $mysqli->query("SELECT id,status FROM strategies WHERE id=$strategyId AND owner_id=$uid LIMIT 1");
} else {
  $res = $mysqli->query("SELECT id,status FROM strategies WHERE owner_id=$uid ORDER BY id DESC LIMIT 1");
}
if(!$res || !($row=$res->fetch_assoc())){ echo json_encode(['ok'=>false,'error'=>'no strategy']); exit; }
echo json_encode(['ok'=>true,'strategy_id'=>(int)$row['id'],'status'=>$row['status']]);
