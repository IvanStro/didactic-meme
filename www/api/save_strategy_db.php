<?php
declare(strict_types=1);
// Robust save for strategy levels: fills 1..70, handles commas, mirrors to strategy_levels (master template only)
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_binance_utils.php';
require_once __DIR__.'/../auth/_session_user.php';
require_once __DIR__ . '/_billing_access.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

function num($v, $def=null){
  if ($v===null) return $def;
  if (is_string($v)) { $v = str_replace([' ', ','], ['', '.'], trim($v)); }
  if ($v==='' || !is_numeric($v)) return $def;
  return (float)$v;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { echo json_encode(['ok'=>false,'error'=>'bad_json']); exit; }

$strategy_id = isset($in['strategy_id']) ? (int)$in['strategy_id'] :
               (isset($in['rid']) ? (int)$in['rid'] : 0);

$tp_whole = num($in['tp_whole'] ?? ($in['whole']['tp'] ?? null), 1.4);
$cb_whole = num($in['cb_whole'] ?? ($in['whole']['cb'] ?? null), 0.1);
$fba      = num($in['fba'] ?? null, null);
$global_cb= num($in['global_cb'] ?? ($in['globalCallback'] ?? null), 0.1);

$levels_in = $in['levels'] ?? $in['buy_levels'] ?? $in['martingale'] ?? [];
$levels_map = [];
foreach ((array)$levels_in as $lv){
  $idx = (int)($lv['idx'] ?? $lv['i'] ?? 0);
  if ($idx<1 || $idx>70) continue;
  $levels_map[$idx] = [
    'drop'=> num($lv['drop'] ?? $lv['drop_pct'] ?? $lv['d'] ?? null, null),
    'mult'=> num($lv['mult'] ?? $lv['m'] ?? null, null),
    'tp'  => num($lv['tp_sub'] ?? $lv['tp'] ?? $lv['tp_pct'] ?? null, null),
    'cb'  => num($lv['cb_sub'] ?? $lv['cb'] ?? $lv['cb_pct'] ?? null, null),
  ];
}

$subs_in = $in['sub'] ?? $in['sub_sells'] ?? $in['sell_levels'] ?? [];
$sub_map = [];
foreach ((array)$subs_in as $sv){
  $idx = (int)($sv['idx'] ?? $sv['i'] ?? 0);
  if ($idx<5 || $idx>70) continue;
  $sub_map[$idx] = [
    'tp'=> num($sv['tp'] ?? $sv['tp_pct'] ?? null, 1.6),
    'cb'=> num($sv['cb'] ?? $sv['cb_pct'] ?? null, $global_cb),
  ];
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

billing_emit_trading_denied(billing_require_trading_access($mysqli, $uid));

if ($strategy_id<=0){
  $r = $mysqli->query("SELECT id FROM strategies WHERE owner_id=$uid ORDER BY id DESC LIMIT 1");
  if ($r && ($row=$r->fetch_assoc())) {
    $strategy_id = (int)$row['id'];
  }
}
if ($strategy_id<=0){ echo json_encode(['ok'=>false,'error'=>'no_strategy_id']); exit; }

$own = $mysqli->query("SELECT id FROM strategies WHERE id=".$strategy_id." AND owner_id=$uid LIMIT 1");
if (!$own || !$own->fetch_assoc()) {
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

$master_id = 0;
$r = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
if ($r && ($row=$r->fetch_assoc())) { $master_id = (int)$row['v']; }

$minFba = 7.0;
if (is_file(__DIR__ . '/_filters_utils.php')) {
  require_once __DIR__ . '/_filters_utils.php';
  if (function_exists('strim_min_fba_usdt')) {
    $minFba = (float)strim_min_fba_usdt();
  }
}
if ($fba !== null && $fba > 0 && $fba < $minFba) {
  echo json_encode([
    'ok' => false,
    'error' => 'fba_too_small',
    'message' => 'Початкова покупка має бути не менше '
      . number_format($minFba, 0, '.', '')
      . ' USDT. Менша сума часто блокується біржею при продажу (мінімум ~5 USDT після округлення).',
    'min_fba' => $minFba,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$maxTrialFba = billing_trial_max_fba_usdt($mysqli, $uid);
if ($maxTrialFba > 0 && $fba !== null && $fba > $maxTrialFba) {
  echo json_encode([
    'ok' => false,
    'error' => 'fba_trial_cap',
    'message' => 'У тестовому доступі FBA не більше ' . number_format($maxTrialFba, 0, '.', '') . ' USDT. Поповніть від 500 грн.',
    'max_fba' => $maxTrialFba,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$mysqli->begin_transaction();
try {
  if ($fba!==null) {
    $mysqli->query("UPDATE strategies SET fba=".number_format($fba,8,'.','')." WHERE id=".$strategy_id." AND owner_id=$uid");
  }
  $mysqli->query("UPDATE strategies SET tp_whole=".number_format($tp_whole,6,'.','').",
                                       cb_whole=".number_format($cb_whole,6,'.','')." WHERE id=".$strategy_id." AND owner_id=$uid");

  $mysqli->query("DELETE FROM martingale_levels WHERE strategy_id=".$strategy_id);

  $ins = $mysqli->prepare("INSERT INTO martingale_levels (strategy_id, idx, drop_pct, mult, tp_sub, cb_sub) VALUES (?,?,?,?,?,?)");
  $rows = 0;
  for ($i=1; $i<=70; $i++){
    $drop = $levels_map[$i]['drop'] ?? null;
    $mult = $levels_map[$i]['mult'] ?? null;
    $tp   = $levels_map[$i]['tp']   ?? null;
    $cb   = $levels_map[$i]['cb']   ?? null;

    if ($drop===null){ $drop = [1=>0.8,2=>1.0,3=>1.1,4=>1.7][$i] ?? 1.6; }
    if ($mult===null){ $mult = [1=>2.0,2=>1.0,3=>1.0,4=>3.0][$i] ?? 1.0; }

    if ($i<=4){
      $tp = $tp ?? $tp_whole;
      $cb = $cb ?? $cb_whole;
    } else {
      if (!isset($sub_map[$i])) $sub_map[$i]=['tp'=>1.6,'cb'=>$global_cb];
      $tp = $tp ?? $sub_map[$i]['tp'];
      $cb = $cb ?? $sub_map[$i]['cb'];
    }

    $ins->bind_param('iidddd', $strategy_id, $i, $drop, $mult, $tp, $cb);
    $ins->execute();
    $rows += $ins->affected_rows>0 ? 1 : 0;
  }
  $ins->close();

  // Mirror global template only when editing that template strategy (prevents cross-tenant overwrites)
  if ($master_id>0 && (int)$strategy_id === (int)$master_id){
    $mysqli->query("DELETE FROM strategy_levels WHERE strategy_id=".$master_id);
    $ins2 = $mysqli->prepare("INSERT INTO strategy_levels (strategy_id,lvl_idx,drop_pct,mult,tp_pct,cb_pct) VALUES (?,?,?,?,?,?)");
    for ($i=1; $i<=70; $i++){
      $row = $mysqli->query("SELECT drop_pct,mult,tp_sub,cb_sub FROM martingale_levels WHERE strategy_id=".$strategy_id." AND idx=".$i." LIMIT 1")->fetch_assoc();
      $d = (float)$row['drop_pct']; $m=(float)$row['mult']; $tp=(float)$row['tp_sub']; $cb=(float)$row['cb_sub'];
      $ins2->bind_param('iidddd', $master_id, $i, $d, $m, $tp, $cb);
      $ins2->execute();
    }
    $ins2->close();
  }

  $mysqli->commit();
  echo json_encode([
    'ok'=>true,
    'saved_rows'=>$rows,
    'strategy_id'=>$strategy_id,
    'mirrored_to'=>($master_id>0 && (int)$strategy_id === (int)$master_id) ? $master_id : 0,
  ]);
} catch (Throwable $e){
  $mysqli->rollback();
  echo json_encode(['ok'=>false,'error'=>'tx_fail','msg'=>$e->getMessage()]);
}
