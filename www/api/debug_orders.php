<?php
declare(strict_types=1); header('Content-Type: application/json; charset=UTF-8');
require __DIR__.'/../auth/api_gate_json.php';
$dotenv=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();
$m=$mysqli;
$m->set_charset('utf8mb4');
$reqSid = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;
if ($reqSid > 0) {
  $st=$m->query("SELECT id,symbol FROM strategies WHERE id=$reqSid AND owner_id=$uid LIMIT 1");
} else {
  $st=$m->query("SELECT id,symbol FROM strategies WHERE owner_id=$uid ORDER BY id DESC LIMIT 1");
}
$s=$st?$st->fetch_assoc():null; if(!$s){ echo json_encode(['ok'=>false,'error'=>'no strategy']); exit; }
$sid=(int)$s['id']; $sym=$s['symbol'];
$out=['ok'=>true,'strategy_id'=>$sid,'symbol'=>$sym,'orders'=>[]];
$r=$m->query("SELECT id,symbol,side,type,status,executed_qty,cumm_quote FROM orders WHERE strategy_id=$sid AND user_id=$uid ORDER BY id DESC LIMIT 20");
if($r){ while($o=$r->fetch_assoc()){
  $oid=(int)$o['id'];
  $fills=[]; $fr=$m->query("SELECT price,qty,commission,commission_asset FROM fills WHERE order_id=$oid");
  if($fr){ while($f=$fr->fetch_assoc()) $fills[]=$f; }
  $o['fills']=$fills;
  $out['orders'][]=$o;
}}
echo json_encode($out,JSON_UNESCAPED_UNICODE);
