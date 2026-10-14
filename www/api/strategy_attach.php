<?php
declare(strict_types=1);
/**
 * POST: symbol=ZECUSDT & action=start|pause & fba=10(optional)
 * owner-scoped (owner_id, symbol); гарантує positions;
 * start: running + start_ts + sync myTrades (qty/avg) + SEED L1 (якщо net_q>0)
 */
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_binance_utils.php';
require_once __DIR__.'/_user_keys.php';
require_once __DIR__.'/../auth/_session_user.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_csrf.php';
use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');
function fail($m){ echo json_encode(['ok'=>false,'error'=>$m]); exit; }

try{$dotenv = Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();}catch(Throwable $e){ fail('env'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  fail('method');
}
ensure_csrf_token();
require_csrf_or_403();

$symbol = strtoupper(trim($_POST['symbol'] ?? ''));
$action = strtolower(trim($_POST['action'] ?? ''));
$fba    = isset($_POST['fba']) ? (float)$_POST['fba'] : null;

if ($symbol==='' || !preg_match('~^[A-Z0-9]{3,}USDT$~',$symbol)) fail('bad symbol');
if (!in_array($action,['start','pause'],true)) fail('bad action');

require_once __DIR__ . '/_filters_utils.php';
$minFba = function_exists('strim_min_fba_usdt') ? (float)strim_min_fba_usdt() : 7.0;
if ($action === 'start' && $fba !== null && $fba > 0 && $fba < $minFba) {
  echo json_encode([
    'ok' => false,
    'error' => 'fba_too_small',
    'message' => 'Початкова покупка має бути не менше ' . number_format($minFba, 0, '.', '') . ' USDT.',
    'min_fba' => $minFba,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$mysqli = @new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);
if ($mysqli->connect_errno) fail('db');
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid<=0) fail('unauthorized');

billing_emit_trading_denied(billing_require_trading_access($mysqli, $uid));

/* 1) знайти/створити СВОЮ стратегію */
$escSym = $mysqli->real_escape_string($symbol);
$ss = $mysqli->query("SELECT id, status FROM strategies WHERE symbol='$escSym' AND owner_id=$uid LIMIT 1");
if ($ss && ($row=$ss->fetch_assoc())) {
  $sid = (int)$row['id'];
  $curStatus = (string)($row['status'] ?? '');
} else {
  $fbaval = ($fba!==null ? $fba : 10.0);
  $mysqli->query("INSERT INTO strategies(symbol,status,mode,fba,owner_id,user_id) VALUES('$escSym','stopped','subbin',".number_format($fbaval,8,'.','').",$uid,$uid)");
  if ($mysqli->errno) fail('db-insert-strategy');
  $sid = (int)$mysqli->insert_id;
  $curStatus = 'stopped';
}

/* 2) гарантуємо позицію */
$mysqli->query("INSERT IGNORE INTO positions(strategy_id) VALUES($sid)");
if ($mysqli->errno) fail('db-ensure-position');

/* 3) оновити fba, якщо прийшов */
if ($fba!==null){ $mysqli->query("UPDATE strategies SET fba=".number_format($fba,8,'.','')." WHERE id=$sid LIMIT 1"); }

/* pause */
if ($action==='pause'){
  require_once __DIR__ . '/_strategy_status.php';
  $apply = strategy_apply_status($mysqli, $uid, $sid, 'pause');
  if (!$apply['ok']) {
    echo json_encode(['ok'=>false,'error'=>$apply['error'] ?? 'pause_failed']); exit;
  }
  echo json_encode(['ok'=>true,'strategy_id'=>$sid,'status'=>'paused','changed'=>!empty($apply['changed'])]); exit;
}

/* 4) start: running + start_ts */
if ($curStatus !== 'running') {
  $maxRun = billing_max_running_strategies($mysqli, $uid);
  $runCnt = billing_count_running_strategies($mysqli, $uid);
  if ($runCnt >= $maxRun) {
    echo json_encode([
      'ok'=>false,
      'error'=>'running_limit',
      'max'=>$maxRun,
      'running'=>$runCnt,
      'message'=>"Ліміт одночасних ботів: {$maxRun}.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
$nowms = (int)(microtime(true)*1000);
$mysqli->query("UPDATE strategies SET status='running', start_ts=$nowms WHERE id=$sid LIMIT 1");

/* 5) швидка sync з myTrades під ключами користувача */
list($K,$S) = user_keys($mysqli,$uid);
$net_q=0.0; $net_c=0.0; $avg=0.0; $last_buy=0.0; $lbt=0;
if ($K && $S){
  $ts = bn_server_time();
  $q = bn_sign_query(['symbol'=>$symbol,'timestamp'=>$ts,'limit'=>1000,'recvWindow'=>5000], $S);
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/myTrades?'.$q, ['X-MBX-APIKEY: '.$K], 'GET');
  if (!$e && $h<400){
    $tr = json_decode((string)$r,true) ?: [];
    foreach($tr as $t){
      $p=(float)$t['price']; $q=(float)$t['qty']; $isB=!empty($t['isBuyer']); $tm=(int)$t['time'];
      if ($isB){ $net_q+=$q; $net_c+=$p*$q; if($tm>=$lbt){$lbt=$tm; $last_buy=$p;} }
      else     { $net_q-=$q; $net_c-=$p*$q; }
    }
    if ($net_q>0) $avg=$net_c/$net_q;
  }
}

/* 6) записати позицію */
$pidRow = $mysqli->query("SELECT id FROM positions WHERE strategy_id=$sid LIMIT 1")->fetch_assoc();
$pid = (int)$pidRow['id'];
$mysqli->query("UPDATE positions SET
  qty=".number_format(max(0.0,$net_q),18,'.','').",
  invested_usdt=".number_format(max(0.0,$net_c),18,'.','').",
  avg_price=".number_format(max(0.0,$avg),18,'.','').",
  last_buy=".number_format(max(0.0,$last_buy),18,'.','').",
  updated_at=NOW()
  WHERE id=$pid LIMIT 1");

/* 7) SEED L1: якщо є net_q>0 і ще немає відкритих L<=4 — створюємо L1 і піднімаємо sub_assigned_qty */
$seed=false;
if ($net_q>0){
  $chk = $mysqli->query("SELECT COALESCE(SUM(qty),0) q FROM sub_positions WHERE position_id=$pid AND closed=0 AND level_idx<=4");
  $haveMain = 0.0; if ($chk && ($row=$chk->fetch_assoc())) $haveMain = (float)$row['q'];
  if ($haveMain<=1e-12){
    // tp_whole/cb_whole з strategies (дефолт 1.4% / 0.1%)
    $tpw=1.4; $cbw=0.1;
    $tpr=$mysqli->query("SELECT COALESCE(tp_whole,1.4) t, COALESCE(cb_whole,0.1) c FROM strategies WHERE id=$sid");
    if ($tpr && ($x=$tpr->fetch_assoc())){ $tpw=(float)$x['t']; $cbw=(float)$x['c']; }
    $buyPrice = $avg>0 ? $avg : ($last_buy>0 ? $last_buy : 0.0);
    if ($buyPrice<=0){
      // резервний якір — публічна ціна
      [$hh,$rr,$ee,$er2] = http_json(bn_base().'/api/v3/ticker/price?symbol='.$symbol);
      if (!$ee && $hh===200){ $buyPrice = (float)(json_decode($rr,true)['price'] ?? 0.0); }
    }
    $mysqli->query("INSERT INTO sub_positions(position_id,level_idx,qty,buy_price,tp,cb,closed,created_at)
                    VALUES($pid,1,".number_format($net_q,18,'.','').",".number_format($buyPrice,18,'.','').",".number_format($tpw,6,'.','').",".number_format($cbw,6,'.','').",0,NOW())");
    $mysqli->query("UPDATE positions SET sub_assigned_qty=".number_format($net_q,18,'.','')." WHERE id=$pid");
    $seed=true;
  }
}

/* done */
echo json_encode(['ok'=>true,'strategy_id'=>$sid,'status'=>'running','synced_qty'=>$net_q,'avg'=>$avg,'seeded_l1'=>$seed]);
