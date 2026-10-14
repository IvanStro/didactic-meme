<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../auth/_session_user.php';
require_once __DIR__.'/_csrf.php';
require_once __DIR__.'/_billing_access.php';
$dotenv=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();
$m=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);
if($m->connect_errno){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$m->connect_error]); exit; }
$m->set_charset('utf8mb4');

$uid = current_user_id($m, 86400);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method']); exit;
}
ensure_csrf_token();
require_csrf_or_403();
billing_emit_trading_denied(billing_require_trading_access($m, $uid));

$symbol=strtoupper(trim((string)($_POST['symbol'] ?? '')));
if($symbol===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'symbol required']); exit; }

$reqSid = isset($_POST['strategy_id']) ? (int)$_POST['strategy_id'] : 0;
if ($reqSid > 0) {
  $r=$m->query("SELECT id FROM strategies WHERE id=$reqSid AND owner_id=$uid LIMIT 1");
} else {
  $r=$m->query("SELECT id FROM strategies WHERE owner_id=$uid ORDER BY id DESC LIMIT 1");
}
$row=$r?$r->fetch_assoc():null; if(!$row){ echo json_encode(['ok'=>false,'error'=>'no strategy']); exit; }
$sid=(int)$row['id'];
$m->query("UPDATE strategies SET symbol='".$m->real_escape_string($symbol)."' WHERE id=".$sid." AND owner_id=".$uid);
try {
  require_once __DIR__ . '/../../engine/signal/symbol_index.php';
  symbol_index_sync_strategy($m, $sid);
} catch (Throwable $e) {
  // index best-effort
}
echo json_encode(['ok'=>true,'strategy_id'=>$sid,'symbol'=>$symbol]);
