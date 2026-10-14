<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../api/_binance_utils.php';
require_once __DIR__.'/../api/_filters_utils.php';
@require_once __DIR__.'/../api/_free_only_shim.php';
require_once __DIR__.'/../api/_user_keys.php';
require_once __DIR__.'/../auth/_session_user.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();
$mysqli = new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$symbol = strtoupper($argv[1] ?? 'ZECUSDT');
$asset  = str_replace('USDT','',$symbol);

$uid = current_user_id($mysqli);
if ($uid<=0) { $r=$mysqli->query("SELECT user_id FROM sessions_auth ORDER BY last_seen DESC LIMIT 1"); $uid=($r&&($x=$r->fetch_assoc()))?(int)$x['user_id']:0; }
if ($uid<=0) { echo "ERR no user\n"; exit(1); }

$ss=$mysqli->query("SELECT id,COALESCE(tp_whole,1.4) tpw,COALESCE(cb_whole,0.1) cbw FROM strategies WHERE symbol='".$mysqli->real_escape_string($symbol)."' AND owner_id=$uid LIMIT 1");
if(!($ss&&($s=$ss->fetch_assoc()))){ echo "NO_STRATEGY\n"; exit(2); }
$sid=(int)$s['id']; $tpw=(float)$s['tpw']; $cbw=(float)$s['cbw'];

$pp=$mysqli->query("SELECT id,qty,avg_price FROM positions WHERE strategy_id=$sid LIMIT 1");
if(!($pp&&($p=$pp->fetch_assoc()))){ echo "NO_POSITION\n"; exit(3); }
$pid=(int)$p['id']; $dbQty=(float)$p['qty']; $dbAvg=(float)$p['avg_price'];

list($K,$S)=user_keys($mysqli,$uid);
if(!$K||!$S){ echo "NO_KEYS\n"; exit(4); }

$acc=exi_get_account($K,$S); $free=0.0;
foreach(($acc['balances']??[]) as $b){ if(($b['asset']??'')===$asset){ $free=(float)($b['free']??0.0); break; } }
echo "EXCHANGE free=$free  DB qty=$dbQty avg=$dbAvg\n";

# закриваємо всі відкриті саби
$mysqli->query("UPDATE sub_positions SET closed=1 WHERE position_id=$pid AND closed=0");

# визначаємо buy для L1: dbAvg -> last_buy -> публічна ціна
$buy = $dbAvg>0 ? $dbAvg : 0.0;
if ($buy<=0){ $lr=$mysqli->query("SELECT last_buy FROM positions WHERE id=$pid"); if($lr&&($lx=$lr->fetch_assoc())) $buy=(float)$lx['last_buy']; }
if ($buy<=0){
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/ticker/price?symbol='.$symbol);
  if(!$e && $h===200){ $buy=(float)(json_decode($r,true)['price']??0.0); }
}

if ($free>0){
  if ($buy<=0){ echo "ERR: no buy price\n"; exit(5); }
  $mysqli->query("INSERT INTO sub_positions(position_id,level_idx,qty,buy_price,tp,cb,closed,created_at)
                  VALUES($pid,1,".number_format($free,18,'.','').",".number_format($buy,18,'.','').",".number_format($tpw,6,'.','').",".number_format($cbw,6,'.','').",0,NOW())");
  $mysqli->query("UPDATE positions SET qty=".number_format($free,18,'.','').",
                                   invested_usdt=".number_format($free*$buy,18,'.','').",
                                   avg_price=".number_format($buy,18,'.','').",
                                   sub_assigned_qty=".number_format($free,18,'.','').",
                                   state='idle', next_ptr=1
                  WHERE id=".$pid);
  echo "SEEDED L1: qty=$free buy=$buy\n";
} else {
  $mysqli->query("UPDATE positions SET qty=0, invested_usdt=0, avg_price=0, sub_assigned_qty=0, state='idle', next_ptr=1 WHERE id=".$pid);
  echo "CLEARED (free==0)\n";
}
