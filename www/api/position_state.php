<?php
declare(strict_types=1); header('Content-Type: application/json; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../auth/_session_user.php';
$dotenv=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();
$m=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);
if($m->connect_errno){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$m->connect_error]); exit; }
$m->set_charset('utf8mb4');

$uid = current_user_id($m, 86400);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$reqSid = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;
if ($reqSid > 0) {
  $r=$m->query("SELECT s.id sid,s.symbol,p.* FROM strategies s LEFT JOIN positions p ON p.strategy_id=s.id AND p.user_id=$uid WHERE s.id=$reqSid AND s.owner_id=$uid LIMIT 1");
} else {
  $r=$m->query("SELECT s.id sid,s.symbol,p.* FROM strategies s LEFT JOIN positions p ON p.strategy_id=s.id AND p.user_id=$uid WHERE s.owner_id=$uid ORDER BY s.id DESC LIMIT 1");
}
if(!$r || !($row=$r->fetch_assoc())){ echo json_encode(['ok'=>false,'error'=>'no strategy']); exit; }

$missedLvls = [];
if (!empty($row['ladder_missed_json'])) {
  $jm = json_decode((string)$row['ladder_missed_json'], true);
  if (is_array($jm) && isset($jm['q']) && is_array($jm['q'])) {
    foreach ($jm['q'] as $qr) {
      if (!is_array($qr)) {
        continue;
      }
      $ln = (int)($qr['lvl'] ?? 0);
      if ($ln > 0) {
        $missedLvls[] = $ln;
      }
    }
    $missedLvls = array_values(array_unique($missedLvls));
    sort($missedLvls, SORT_NUMERIC);
  }
}

$out=['ok'=>true,'symbol'=>$row['symbol'],'position'=>[
  'qty'=>(float)$row['qty'],'avg_price'=>(float)$row['avg_price'],
  'base_price'=>isset($row['base_price']) ? (float)$row['base_price'] : 0.0,
  'invested_usdt'=>(float)$row['invested_usdt'],'next_ptr'=>(int)$row['next_ptr'],
  'last_buy'=>(float)$row['last_buy'],'last_peak'=>(float)$row['last_peak'],
  'state'=>$row['state'],'id'=>(int)$row['id'],'strategy_id'=>(int)$row['strategy_id'],
  'ladder_missed_levels'=>$missedLvls,
]];
$pid=(int)$row['id'];
if($pid){
  $rs=$m->query("SELECT level_idx,qty,buy_price,tp,cb,closed FROM sub_positions WHERE position_id=$pid ORDER BY id ASC");
  $out['sub_positions']=[];
  if($rs){ while($sp=$rs->fetch_assoc()){
    $out['sub_positions'][]=[
      'level_idx'=>(int)$sp['level_idx'],'qty'=>(float)$sp['qty'],
      'buy_price'=>(float)$sp['buy_price'],'tp'=>is_null($sp['tp'])?null:(float)$sp['tp'],
      'cb'=>is_null($sp['cb'])?null:(float)$sp['cb'],'closed'=>(int)$sp['closed']
    ];
  }}
}
echo json_encode($out,JSON_UNESCAPED_UNICODE);
