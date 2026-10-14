<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../auth/_session_user.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();
require_once __DIR__ . '/_strategy_levels_ui.php';
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$master_id = 0;
$rm = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
if ($rm && ($m = $rm->fetch_assoc())) { $master_id = (int)$m['v']; }

$rid    = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;
$symbol = isset($_GET['symbol']) ? strtoupper(trim($_GET['symbol'])) : '';

if ($rid <= 0 && $symbol !== '') {
  $rs = $mysqli->query(
    "SELECT id FROM strategies WHERE symbol='".$mysqli->real_escape_string($symbol)."' AND owner_id=$uid ORDER BY id DESC LIMIT 1"
  );
  if ($rs && ($r = $rs->fetch_assoc())) {
    $rid = (int)$r['id'];
  }
}
if ($rid <= 0 && $master_id > 0) {
  $rid = $master_id;
}

if ($rid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'strategy_not_found']);
  exit;
}

$access = $mysqli->query(
  "SELECT id FROM strategies WHERE id=".$rid." AND (owner_id=".$uid." OR id=".(int)$master_id.") LIMIT 1"
);
if (!$access || !$access->fetch_assoc()) {
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

$out = ['ok'=>true,'strategy_id'=>$rid,'tp_whole'=>null,'cb_whole'=>null,'global_cb'=>null,'levels'=>[]];

$rh = $mysqli->query("SELECT tp_whole, cb_whole, global_cb FROM strategies WHERE id=".$rid." LIMIT 1");
if ($rh && ($h=$rh->fetch_assoc())) {
  $out['tp_whole']  = isset($h['tp_whole']) ? (float)$h['tp_whole'] : null;
  $out['cb_whole']  = isset($h['cb_whole']) ? (float)$h['cb_whole'] : null;
  $out['global_cb'] = isset($h['global_cb'])? (float)$h['global_cb']: null;
}

$rows = strategy_levels_ui_rows($mysqli, (int)$rid, (int)$master_id);

foreach ($rows as $row) {
  $out['levels'][] = [
    'idx'      => (int)$row['idx'],
    'drop'     => (float)$row['drop'],
    'mult'     => (float)$row['mult'],
    'tp_sub'   => $row['tp'] !== null ? (float)$row['tp'] : null,
    'cb_sub'   => $row['cb'] !== null ? (float)$row['cb'] : null,
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
