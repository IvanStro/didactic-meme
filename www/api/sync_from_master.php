<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__,2));
$dotenv->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect']);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']);
  exit;
}

$strategy_id = isset($_POST['strategy_id']) ? (int)$_POST['strategy_id'] : 0;
if ($strategy_id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad_strategy_id']);
  exit;
}

// verify ownership
$chk = $mysqli->query("SELECT id FROM strategies WHERE id=$strategy_id AND owner_id=$uid LIMIT 1");
if (!$chk || !$chk->fetch_assoc()) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

// master id
$ms = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
$msRow = $ms ? $ms->fetch_assoc() : null;
$masterId = $msRow ? (int)$msRow['v'] : 0;
if ($masterId <= 0) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'master_not_set']);
  exit;
}

try {
  $mysqli->begin_transaction();

  // wipe user levels
  $mysqli->query("DELETE FROM martingale_levels WHERE strategy_id=$strategy_id");
  $mysqli->query("DELETE FROM strategy_levels   WHERE strategy_id=$strategy_id");

  // copy from master
  $q1 = $mysqli->query("
    INSERT INTO martingale_levels (strategy_id, idx, drop_pct, mult, tp_sub, cb_sub)
    SELECT $strategy_id, idx, drop_pct, mult, tp_sub, cb_sub
    FROM martingale_levels
    WHERE strategy_id=$masterId
  ");
  if (!$q1) throw new Exception($mysqli->error);

  $q2 = $mysqli->query("
    INSERT INTO strategy_levels (strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct)
    SELECT $strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct
    FROM strategy_levels
    WHERE strategy_id=$masterId
  ");
  if (!$q2) throw new Exception($mysqli->error);

  $mysqli->commit();

  echo json_encode(['ok'=>true]);

} catch (Throwable $e) {
  try { $mysqli->rollback(); } catch (Throwable $e2) {}
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'tx_fail','msg'=>$e->getMessage()]);
}
