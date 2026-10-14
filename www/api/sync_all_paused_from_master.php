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
  echo json_encode(['ok'=>false,'error'=>'db_connect']); exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

// only admin
$u = $mysqli->query("SELECT is_admin FROM users WHERE id=$uid LIMIT 1");
$ur = $u ? $u->fetch_assoc() : null;
if (!$ur || (int)$ur['is_admin'] !== 1) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

$ms = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
$msRow = $ms ? $ms->fetch_assoc() : null;
$masterId = $msRow ? (int)$msRow['v'] : 0;
if ($masterId <= 0) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'master_not_set']); exit;
}

try {
  $mysqli->begin_transaction();

  // Wipe levels only for paused strategies (excluding master)
  $q1 = $mysqli->query("
    DELETE ml FROM martingale_levels ml
    JOIN strategies st ON st.id = ml.strategy_id
    WHERE st.status='paused' AND st.id <> $masterId
  ");
  if (!$q1) throw new Exception($mysqli->error);

  $q2 = $mysqli->query("
    DELETE sl FROM strategy_levels sl
    JOIN strategies st ON st.id = sl.strategy_id
    WHERE st.status='paused' AND st.id <> $masterId
  ");
  if (!$q2) throw new Exception($mysqli->error);

  // Copy from master into all paused strategies
  $q3 = $mysqli->query("
    INSERT INTO martingale_levels (strategy_id, idx, drop_pct, mult, tp_sub, cb_sub)
    SELECT st.id, m.idx, m.drop_pct, m.mult, m.tp_sub, m.cb_sub
    FROM strategies st
    JOIN martingale_levels m ON m.strategy_id = $masterId
    WHERE st.status='paused' AND st.id <> $masterId
  ");
  if (!$q3) throw new Exception($mysqli->error);

  $q4 = $mysqli->query("
    INSERT INTO strategy_levels (strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct)
    SELECT st.id, s.lvl_idx, s.drop_pct, s.mult, s.tp_pct, s.cb_pct
    FROM strategies st
    JOIN strategy_levels s ON s.strategy_id = $masterId
    WHERE st.status='paused' AND st.id <> $masterId
  ");
  if (!$q4) throw new Exception($mysqli->error);

  $mysqli->commit();
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  try { $mysqli->rollback(); } catch (Throwable $e2) {}
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'tx_fail','msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
