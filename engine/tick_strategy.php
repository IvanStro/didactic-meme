<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
require __DIR__.'/../api/_binance_utils.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if($mysqli->connect_errno) die("DB ERR ".$mysqli->connect_error."\n");
$mysqli->set_charset('utf8mb4');

$stq = $mysqli->query("SELECT * FROM strategies ORDER BY id DESC LIMIT 1");
if(!$stq || !($strategy = $stq->fetch_assoc())) die("no strategy\n");
if($strategy['status'] !== 'running') die("status=".$strategy['status']."\n");

$strategy_id = (int)$strategy['id'];
$symbol      = $strategy['symbol'] ?: 'BTCUSDT';
$mode        = $strategy['mode'] ?: 'subbin';
$fba         = (float)$strategy['fba'];
$tp_whole    = is_null($strategy['tp_whole'])  ? 1.4 : (float)$strategy['tp_whole'];  // %
$cb_whole    = is_null($strategy['cb_whole'])  ? 0.1 : (float)$strategy['cb_whole'];  // %
$global_cb   = is_null($strategy['global_cb']) ? 0.1 : (float)$strategy['global_cb'];  // %
[$apiKey,$secret] = bn_keys();

$lvq = $mysqli->query("SELECT idx, drop_pct, mult, tp_sub, cb_sub
                       FROM martingale_levels
                       WHERE strategy_id=$strategy_id ORDER BY idx ASC");
$levels = $lvq ? $lvq->fetch_all(MYSQLI_ASSOC) : [];

function db_log(mysqli $db,int $sid,string $type,string $details,?float $price,string $symbol){
  $d=$db->real_escape_string($details);
  $p=is_null($price)?'NULL':(string)$price;
  $sym=$db->real_escape_string($symbol);
  $db->query("INSERT INTO history(strategy_id,event_type,details,price,symbol)VALUES($sid,'$type','$d',$p,'$sym')");
}
function logline(mysqli $db,int $sid,string $symbol,float $price,string $msg){
  $now=date('Y-m-d H:i:s');
  @file_put_contents(dirname(__DIR__).'/logs/tick.log',"$now strategy_id=$sid $symbol price=$price | $msg\n",FILE_APPEND);
  db_log($db,$sid,'INFO',$msg,$price,$symbol);
}

/* price */
$base = bn_base();
[$h,$r,$e,$er] = http_json($base.'/api/v3/ticker/price?symbol='.$symbol);
if($e||$h!==200) die("price http=$h err=$er\n");
$price=(float)(json_decode((string)$r,true)['price']??0.0);

/* HEARTBEAT */
logline($mysqli,$strategy_id,$symbol,$price,'TICK');

/* позиція в БД */
function load_pos(mysqli $db,int $sid){ $r=$db->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1"); return $r&&($row=$r->fetch_assoc())?$row:null; }
function create_empty_pos(mysqli $db,int $sid){ $db->query("INSERT INTO positions(strategy_id)VALUES($sid)"); return load_pos($db,$sid); }
function save_pos(mysqli $db,array $p){
  $sid=(int)$p['strategy_id']; $id=(int)$p['id'];
  $sql="UPDATE positions SET qty={$p['qty']},invested_usdt={$p['invested_usdt']},avg_price={$p['avg_price']},last_buy={$p['last_buy']},next_ptr={$p['next_ptr']},last_peak={$p['last_peak']},state='{$p['state']}' WHERE id=$id AND strategy_id=$sid";
  $db->query($sql);
}
function load_open_subs(mysqli $db,int $pid){ $r=$db->query("SELECT * FROM sub_positions WHERE position_id=$pid AND closed=0 ORDER BY id ASC"); return $r?$r->fetch_all(MYSQLI_ASSOC):[]; }
function add_sub(mysqli $db,int $pid,int $lvl,float $qty,float $bp,?float $tp,?float $cb){
  $tpv=is_null($tp)?'NULL':(string)$tp; $cbv=is_null($cb)?'NULL':(string)$cb;
  $db->query("INSERT INTO sub_positions(position_id,level_idx,qty,buy_price,tp,cb)VALUES($pid,$lvl,$qty,$bp,$tpv,$cbv)");
}
function close_sub_idx(mysqli $db,array $sub){ $id=(int)$sub['id']; $db->query("UPDATE sub_positions SET closed=1 WHERE id=$id"); }

/* перерахунок позиції з orders/fills (правда з біржі) */
function sync_now(): void {
  $ctx = stream_context_create([
    'http' => [
      'method'  => 'GET',
      'timeout' => 2,
    ],
    'ssl' => [
      'verify_peer'      => false,
      'verify_peer_name' => false,
    ],
  ]);
  $base = rtrim((string)($_ENV['PUBLIC_BASE_URL'] ?? 'https://strim.in.ua'), '/');
  @file_get_contents($base . '/api/sync_positions_from_orders.php', false, $ctx);
}

/* трейл/утиліти */
function drop_from(float $ref,float $p){return $ref<=0?0.0:($ref-$p)/$ref;}
function pulled_back(float $price,array &$pos,float $trigger,float $cb){
  if($price>(float)$pos['last_peak']) $pos['last_peak']=$price;
  if((float)$pos['last_peak']>=$trigger){
    $dd=((float)$pos['last_peak']-$price)/(float)$pos['last_peak']*100.0;
    return $dd>=$cb;
  }
  return false;
}

/* load/create pos */
$pos = load_pos($mysqli,$strategy_id); if(!$pos) $pos=create_empty_pos($mysqli,$strategy_id);
if($price > (float)$pos['last_peak']){ $pos['last_peak']=$price; save_pos($mysqli,$pos); }

/* === ENTRY (REAL BUY MARKET) === */
if((float)$pos['qty']<=0.0){
  // купуємо на fba USDT → конвертуємо в qty по фільтрах
  try{
    // або через quoteOrderQty, або самі рахуємо qty. Більш стабільно — через quoteOrderQty:
    $resp = bn_place_market($symbol,'BUY',null,$fba,$apiKey,$secret);
    db_log($mysqli,$strategy_id,'ENTRY', 'BUY FBA='.$fba.'USDT', $price,$symbol);
    sync_now();
    logline($mysqli,$strategy_id,$symbol,$price,"ENTRY REAL BUY FBA={$fba}USDT");
  }catch(Throwable $ex){
    db_log($mysqli,$strategy_id,'ERROR','ENTRY '.$ex->getMessage(),$price,$symbol);
    die("ENTRY ERROR ".$ex->getMessage()."\n");
  }
  echo date('Y-m-d H:i:s')." strategy_id=$strategy_id $symbol price=$price\n";
  exit;
}

/* === LEVEL BUY (REAL) === */
$next_ptr=(int)$pos['next_ptr'];
if($next_ptr < count($levels)){
  $ref = (float)$pos['avg_price']>0 ? (float)$pos['avg_price'] : (float)$pos['last_buy'];
  $level=$levels[$next_ptr];
  $need=isset($level['drop_pct'])?(float)$level['drop_pct']:0.0; if($need>1.0)$need/=100.0;
  $cur = drop_from($ref,$price);
  if($cur >= $need && $ref>0){
    $mult = isset($level['mult'])?(float)$level['mult']:1.0;
    $buy_usdt = $fba * max($mult,0.000001);
    try{
      $resp = bn_place_market($symbol,'BUY',null,$buy_usdt,$apiKey,$secret);
      // додаємо sub у БД для відстеження TP
      $pid=(int)$pos['id'];
      $tp_s=isset($level['tp_sub'])?(float)$level['tp_sub']:null;
      $cb_s=isset($level['cb_sub'])?(float)$level['cb_sub']:$global_cb;
      add_sub($mysqli,$pid,(int)$level['idx'],0.0,$price,$tp_s,$cb_s); // qty уточнить sync
      db_log($mysqli,$strategy_id,'BUY',"L{$level['idx']} +{$buy_usdt}USDT",$price,$symbol);
      sync_now();
      $pos = load_pos($mysqli,$strategy_id); // оновили з ордерів
      $pos['next_ptr']=$next_ptr+1; save_pos($mysqli,$pos);
      logline($mysqli,$strategy_id,$symbol,$price,"BUY REAL L{$level['idx']} USDT={$buy_usdt}");
    }catch(Throwable $ex){
      db_log($mysqli,$strategy_id,'ERROR','BUY L'.$level['idx'].' '.$ex->getMessage(),$price,$symbol);
    }
  }
}

/* === TAKE PROFIT WHOLE (REAL SELL) === */
if($mode==='whole' && (float)$pos['qty']>0.0){
  $tp_p=(float)$pos['avg_price']*(1.0+$tp_whole/100.0);
  // CB_DEBUG_V1 (WHOLE)
  if($price>=$tp_p){
    $peak=(float)$pos['last_peak'];
    $dd=$peak>0?($peak-$price)/$peak*100.0:0.0;
    logline($mysqli,$strategy_id,$symbol,$price,
      sprintf("DBG WHOLE tp_p=%.8f peak=%.8f dd=%.4f%% cb=%.4f%% avg=%.8f qty=%.8f",
        $tp_p,$peak,$dd,$cb_whole,(float)$pos['avg_price'],(float)$pos['qty']
      )
    );
  }
if($price>=$tp_p && pulled_back($price,$pos,$tp_p,$cb_whole)){
    try{
      $qty=(float)$pos['qty'];
      // продаємо все:
      $resp = bn_place_market($symbol,'SELL',$qty,null,$apiKey,$secret);
      db_log($mysqli,$strategy_id,'TP_WHOLE',"SELL qty=$qty",$price,$symbol);
      sync_now();
      logline($mysqli,$strategy_id,$symbol,$price,"TP WHOLE REAL SELL qty=$qty");
    }catch(Throwable $ex){
      db_log($mysqli,$strategy_id,'ERROR','TP WHOLE '.$ex->getMessage(),$price,$symbol);
    }
  }
}

/* === TAKE PROFIT SUB (часткові, REAL SELL) === */
if($mode==='subbin' && (float)$pos['qty']>0.0){
  $subs = load_open_subs($mysqli,(int)$pos['id']);
  for($k=count($subs)-1;$k>=0;$k--){
    $sp=$subs[$k];
    $tp_p = is_null($sp['tp'])?null:(float)$sp['tp'];
    $cb_p = is_null($sp['cb'])?$global_cb:(float)$sp['cb'];
    if(is_null($tp_p)) continue;
    $trigger=(float)$sp['buy_price']*(1.0+$tp_p/100.0);
    // CB_DEBUG_V1 (SUB)
    if($price>=$trigger){
      $peak=(float)$pos['last_peak'];
      $dd=$peak>0?($peak-$price)/$peak*100.0:0.0;
      logline($mysqli,$strategy_id,$symbol,$price,
        sprintf("DBG SUB lvl=%d trigger=%.8f peak=%.8f dd=%.4f%% cb=%.4f%% tp=%.4f%% buy=%.8f",
          (int)$sp['level_idx'],$trigger,$peak,$dd,$cb_p,$tp_p,(float)$sp['buy_price']
        )
      );
    }
if($price>=$trigger && pulled_back($price,$pos,$trigger,$cb_p)){
      try{
        // продаємо пропорційно (реальна кількість уже в позиції після sync)
        // Щоб не зчитувати qty субпозиції з fills — віддамо частку: напр., 10% портфеля (спрощено).
        // У prod краще зберігати qty підпозиції під час sync.
        $sell_qty = max(0.0, min((float)$pos['qty']*0.1, (float)$pos['qty'])); // 10% як заглушка
        if($sell_qty>0){
          $resp = bn_place_market($symbol,'SELL',$sell_qty,null,$apiKey,$secret);
          db_log($mysqli,$strategy_id,'TP_SUB',"SELL est_qty=$sell_qty L{$sp['level_idx']}",$price,$symbol);
          sync_now();
          logline($mysqli,$strategy_id,$symbol,$price,"TP SUB REAL SELL ~qty=$sell_qty L{$sp['level_idx']}");
          close_sub_idx($mysqli,$sp);
        }
      }catch(Throwable $ex){
        db_log($mysqli,$strategy_id,'ERROR','TP SUB '.$ex->getMessage(),$price,$symbol);
      }
    }
  }
}

echo date('Y-m-d H:i:s')." strategy_id=$strategy_id $symbol price=$price\n";
