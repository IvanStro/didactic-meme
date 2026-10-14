<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__.'/../api/_binance_utils.php';
require_once __DIR__.'/../api/_filters_utils.php';
require_once __DIR__.'/../api/_free_only_shim.php';
require_once __DIR__.'/../api/_tg_notify.php';
require_once __DIR__.'/../api/_ws_price.php';
require_once __DIR__.'/../api/_buy_eps.php';
require_once __DIR__.'/../api/_skip_utils.php';
require_once __DIR__.'/../api/_skip_format.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();
date_default_timezone_set('UTC');

function logx(string $s): void {
  $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $s);
  @file_put_contents(dirname(__DIR__).'/logs/tick.multi.log', $line, FILE_APPEND);
  echo $line;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { logx('DB ERR '.$mysqli->connect_error); exit; }
$mysqli->set_charset('utf8mb4');

function qstr(mysqli $db, string $s): string { return "'".$db->real_escape_string($s)."'"; }
function nf($v,int $d=8){ return (float)number_format((float)$v,$d,'.',''); }

[$apiKey,$secret] = bn_keys();

/** helpers */

function place_quote_buy(string $symbol, float $quote, string $ak,string $sk): array {
  $ts=bn_server_time();
  $p=['symbol'=>$symbol,'side'=>'BUY','type'=>'MARKET','quoteOrderQty'=>sprintf('%.2f',$quote),'timestamp'=>$ts,'recvWindow'=>5000];
  $q=bn_sign_query($p,$sk);
  return http_json(bn_base().'/api/v3/order',['X-MBX-APIKEY: '.$ak],'POST',$q);
}
function place_sell_qty(string $symbol,float $qty,string $ak,string $sk): array {
  $ts=bn_server_time();
  $p=['symbol'=>$symbol,'side'=>'SELL','type'=>'MARKET','quantity'=>sprintf('%.8f',$qty),'timestamp'=>$ts,'recvWindow'=>5000];
  $q=bn_sign_query($p,$sk);
  return http_json(bn_base().'/api/v3/order',['X-MBX-APIKEY: '.$ak],'POST',$q);
}
function ensure_position(mysqli $db,int $sid): array {
  $r=$db->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1");
  if($r&&($row=$r->fetch_assoc())) return $row;
  $db->query("INSERT INTO positions(strategy_id)VALUES($sid)");
  $r2=$db->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1");
  return ($r2&&($row2=$r2->fetch_assoc()))?$row2:[];
}
function sync_from_trades(mysqli $db,int $sid,string $symbol,string $ak,string $sk): array{
  $ts=bn_server_time();
  $params=['symbol'=>$symbol,'timestamp'=>$ts,'limit'=>1000,'recvWindow'=>5000];
  $q=bn_sign_query($params,$sk);
  [$h,$r,$e,$er]=http_json(bn_base().'/api/v3/myTrades?'.$q,['X-MBX-APIKEY: '.$ak],'GET');
  $data=(!$e&&$h<400)?json_decode((string)$r,true):[];
  // filter trades by start_ts (ignore pre-strategy history)

  $startTs = 0;

  $__rs = $db->query("SELECT start_ts FROM strategies WHERE id=$sid LIMIT 1");

  if ($__rs && ($__row = $__rs->fetch_assoc())) { $startTs = (int) ($__row["start_ts"] ?? 0); }

  if ($startTs > 0 && is_array($data)) {

    $tmp = [];

    foreach ($data as $t) {

      $tt = (int) ($t["time"] ?? 0);

      if ($tt >= $startTs) { $tmp[] = $t; }

    }

    $data = $tmp;

  }
  $bq=$sq=$bc=$sc=0.0; $lb=0.0; $lbt=0;
  foreach($data as $t){
    $p=(float)$t['price']; $q=(float)$t['qty']; $isB=!empty($t['isBuyer']); $tm=(int)$t['time'];
    if($isB){ $bq+=$q; $bc+=$p*$q; if($tm>=$lbt){ $lbt=$tm; $lb=$p; } }
    else    { $sq+=$q; $sc+=$p*$q; }
  }
  $net_q=$bq-$sq; $net_c=$bc-$sc; $avg=$net_q>0?($net_c/$net_q):0.0;

  $pos=ensure_position($db,$sid);
  if($pos){
    $id=(int)$pos['id'];
    $db->query("UPDATE positions SET
      qty=".number_format($net_q,18,'.','').",
      invested_usdt=".number_format($net_c,18,'.','').",
      avg_price=".number_format($avg,18,'.','').",
      last_buy=".number_format($lb,18,'.','').",
      updated_at=NOW()
      WHERE id=$id AND strategy_id=$sid");
  }
  $r3=$db->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1");
  return ($r3&&($row3=$r3->fetch_assoc()))?$row3:[];
}
function insert_subpos(mysqli $db,int $position_id,int $lvl_idx,float $qty,float $buy_price,float $tp,float $cb): void{
  $sql="INSERT INTO sub_positions(position_id,level_idx,qty,buy_price,tp,cb,closed,created_at)
        VALUES($position_id,$lvl_idx,".number_format($qty,18,'.','').",".number_format($buy_price,18,'.','').",".number_format($tp,6,'.','').",".number_format($cb,6,'.','').",0,NOW())";
  $db->query($sql);
}
function open_subpos(mysqli $db,int $position_id,?int $from_level=null): array{
  $where=$from_level===null?"":"AND level_idx ".($from_level>=5?">=5":"<=4");
  $rows=[]; $r=$db->query("SELECT * FROM sub_positions WHERE position_id=$position_id AND closed=0 $where ORDER BY id ASC");
  if($r) while($x=$r->fetch_assoc()) $rows[]=$x;
  return $rows;
}

/** settings & levels **/
$master_id=0;
if($r=$mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1")){
  if($row=$r->fetch_assoc()) $master_id=(int)$row['v'];
}
if($master_id<=0){ logx('NO master_strategy_id'); exit; }

$dust_usd=1.0;
$eps_buy = 0.0001; /* 0.01% trigger tolerance for BUY levels */
if($r=$mysqli->query("SELECT v FROM app_settings WHERE k='DUST_NOTIONAL_USD' LIMIT 1")){
if($r=$mysqli->query("SELECT v FROM app_settings WHERE k='BUY_EPS_PCT' LIMIT 1")){ if($row=$r->fetch_assoc()) $buy_eps_pct=(float)$row['v']; } else { $buy_eps_pct=0.01; }
$buy_eps_pct = ($buy_eps_pct>0? $buy_eps_pct:0.0);
$eps_mul = 1.0 + ($buy_eps_pct/100.0); // множник допуску для BUY
  if($row=$r->fetch_assoc()) $dust_usd=(float)$row['v'];
}

$levels=[];
$rl=$mysqli->query("SELECT lvl_idx,drop_pct,mult,COALESCE(tp_pct,1.6) AS tp_pct, COALESCE(cb_pct,0.1) AS cb_pct
                    FROM strategy_levels
                    WHERE strategy_id={$master_id}
                    ORDER BY lvl_idx");
if($rl) while($lv=$rl->fetch_assoc()){
  $levels[(int)$lv['lvl_idx']] = [
    'drop'=>(float)$lv['drop_pct'],
    'mult'=>(float)$lv['mult'],
    'tp'  =>(float)$lv['tp_pct'],
    'cb'  =>(float)$lv['cb_pct'],
  ];
}
if(!$levels){ logx("Master strategy {$master_id} has no levels"); exit; }

/** main loop **/
$got=$mysqli->query("SELECT s.*, COALESCE(s.tp_whole,1.4) AS tp_whole_v, COALESCE(s.cb_whole,0.1) AS cb_whole_v
                     FROM strategies s
                     WHERE s.status='running'
                     ORDER BY s.id");
if(!$got){ logx('No strategies running'); exit; }

while($st=$got->fetch_assoc()){
  $sid=(int)$st['id'];
  $symbol=$st['symbol']?:'BTCUSDT';
  $asset=str_replace('USDT','',$symbol);
  $fba=(float)$st['fba']; if($fba<=0) $fba=5.0;
  $tp_whole=(float)$st['tp_whole_v'];
  $cb_whole=(float)$st['cb_whole_v'];

  // 1) sync із трейдів
  $pos = sync_from_trades($mysqli,$sid,$symbol,$apiKey,$secret);
  $pos = ensure_position($mysqli,$sid);
  $pid=(int)$pos['id'];
  $qty=(float)$pos['qty'];
  $avg=(float)$pos['avg_price'];
  $last_buy=(float)$pos['last_buy'];
  $ptr=max(1,(int)$pos['next_ptr']);
  $assigned=(float)($pos['sub_assigned_qty'] ?? 0.0);
  $price=ws_ticker_price($symbol);

  // 3) AUTO-ASSIGN subpos, якщо надійшла нова к-сть
  $delta = $qty - $assigned;
  if ($delta > 1e-10) {
    $autoLvl = ($ptr<=1) ? 1 : $ptr;
    $anchor = $avg>0 ? $avg : ($last_buy>0 ? $last_buy : $price);
    $tp_for = ($autoLvl<=4)?$tp_whole:(float)($levels[$autoLvl]['tp'] ?? $tp_whole);
    $cb_for = ($autoLvl<=4)?$cb_whole:(float)($levels[$autoLvl]['cb'] ?? $cb_whole);
    insert_subpos($mysqli,$pid,$autoLvl,$delta,$anchor,$tp_for,$cb_for);
    $mysqli->query("UPDATE positions SET sub_assigned_qty=sub_assigned_qty+".number_format($delta,18,'.','')." WHERE id=$pid");
    logx("[$symbol] AUTO-SUB L$autoLvl qty=$delta buy@$anchor tp=$tp_for%");
  }

  // 4) SUB sells (5..70)
  $subs=open_subpos($mysqli,$pid,5);
  foreach($subs as $sp){
    $lvl=(int)$sp['level_idx']; $sp_qty=(float)$sp['qty']; $sp_buy=(float)$sp['buy_price']; $sp_tp=(float)$sp['tp'];
    if($sp_qty<=0) continue;
    if($sp_buy>0 && $price >= $sp_buy*(1.0+$sp_tp/100.0)){
      $sellQty=exi_normalize_sell_qty($symbol,$sp_qty,$apiKey,$secret);
      if($sellQty<=0){ logx("[$symbol] SUB L$lvl sell SKIP qty norm=0"); continue; }
      [$h,$r,$e,$er]=place_sell_qty($symbol,$sellQty,$apiKey,$secret);
      if($e||$h>=400){
        logx("[$symbol] SUB L$lvl SELL ERR http=$h body=$r");
        $txt = function_exists('tg_user_sell_error_message')
            ? tg_user_sell_error_message($symbol, (string)$r, (int)$h, 'sub', $lvl)
            : sprintf("⚠️ <b>%s</b> — продаж рівня L%d не виконано.", $symbol, $lvl);
        if (function_exists('tg_notify_for_strategy')) {
            tg_notify_for_strategy($mysqli, $sid, $txt);
        } else {
            tg_notify($txt);
        }
      } else {
        $mysqli->query("UPDATE sub_positions SET closed=1 WHERE id=".(int)$sp['id']);
        logx("[$symbol] SUB L$lvl SELL ok qty=$sellQty @ $price");
        $rp = ($sellQty*$price) - ($sp_buy*$sellQty);
        $rpPct = $sp_buy>0 ? (($price/$sp_buy - 1.0)*100.0) : 0.0;
        $txt = sprintf("\xF0\x9F\x94\x94 <b>%s</b> SUB L%d SELL\nQty: %.8f\nPrice: %.8f\nTP: %.2f%%\nPnL: %.2f USDT (%.2f%%)",
                       $symbol, $lvl, $sellQty, $price, $sp_tp, $rp, $rpPct);
        tg_notify($txt);
        sync_from_trades($mysqli,$sid,$symbol,$apiKey,$secret);
      }
    }
  }

  // 5) MAIN SELL (1..4) — trailing sell: arm on TP, execute on CB pullback
  {
    $basis = $avg;
    if ($basis <= 0) {
      $rbs = $mysqli->query("SELECT buy_price FROM sub_positions WHERE position_id=$pid AND closed=0 AND level_idx<=4 ORDER BY id ASC LIMIT 1");
      if ($rbs && ($rb = $rbs->fetch_assoc())) { $basis = (float)$rb['buy_price']; }
    }
    if ($basis <= 0) { $basis = ($last_buy>0 ? $last_buy : $price); }

    if ($qty > 0 && $basis > 0) {
      $tpTarget = $basis * (1.0 + $tp_whole / 100.0);
      $cbPct = max(0.0, $cb_whole);
      $lastPeak = isset($pos['last_peak']) ? (float)$pos['last_peak'] : 0.0;

      // Arm trailing only once TP is touched; then keep updating peak.
      if ($price >= $tpTarget) {
        if ($price > $lastPeak) {
          $lastPeak = $price;
          $mysqli->query("UPDATE positions SET last_peak=".number_format($lastPeak,18,'.','')." WHERE id=$pid");
          logx("[$symbol] MAIN SELL ARM/PEAK tp=".number_format($tpTarget,8,'.','')." peak=".number_format($lastPeak,8,'.','')." cb=".$cbPct."%");
        }
      } elseif ($lastPeak > 0.0) {
        // Disarm if price dropped below TP zone before callback was reached.
        $lastPeak = 0.0;
        $mysqli->query("UPDATE positions SET last_peak=0 WHERE id=$pid");
        logx("[$symbol] MAIN SELL DISARM price=".number_format($price,8,'.','')." tp=".number_format($tpTarget,8,'.',''));
      }

      if ($lastPeak > 0.0) {
        $cbTrigger = $cbPct > 0.0 ? ($lastPeak * (1.0 - $cbPct / 100.0)) : $lastPeak;
        if ($price <= $cbTrigger) {
          $sellAll = exi_normalize_sell_qty($symbol, $qty, $apiKey, $secret);
          if ($sellAll > 0) {
            [$h,$r,$e,$er] = place_sell_qty($symbol, $sellAll, $apiKey, $secret);
            if ($e || $h>=400) {
              logx("[$symbol] MAIN SELL ERR http=$h body=$r");
              $txt = function_exists('tg_user_sell_error_message')
                  ? tg_user_sell_error_message($symbol, (string)$r, (int)$h, 'main', null)
                  : sprintf("⚠️ <b>%s</b> — продаж основної частини позиції не виконано.", $symbol);
              if (function_exists('tg_notify_for_strategy')) {
                  tg_notify_for_strategy($mysqli, $sid, $txt);
              } else {
                  tg_notify($txt);
              }
            } else {
              $mysqli->query("UPDATE positions SET next_ptr=1, state='idle', sub_assigned_qty=0, last_peak=0 WHERE id=$pid");
              $mysqli->query("UPDATE sub_positions SET closed=1 WHERE position_id=$pid AND closed=0");
              logx("[$symbol] MAIN SELL ALL ok qty=$sellAll avg=$basis price=$price trigger=".number_format($cbTrigger,8,'.','')." peak=".number_format($lastPeak,8,'.',''));
              $rp = ($sellAll*$price) - ($sellAll*$basis);
              $rpPct = $basis>0 ? (($price/$basis - 1.0)*100.0) : 0.0;
              $txt = sprintf("\xF0\x9F\x94\x94 <b>%s</b> MAIN SELL ALL\nQty: %.8f\nPrice: %.8f\nBasis: %.8f\nPnL: %.2f USDT (%.2f%%)",
                             $symbol, $sellAll, $price, $basis, $rp, $rpPct);
              tg_notify($txt);
              sync_from_trades($mysqli, $sid, $symbol, $apiKey, $secret);
              continue; // до наступної монети
            }
          } else {
            logx("[$symbol] MAIN SELL SKIP qty norm=0 qty=$qty");
          }
        } else {
          logx("[$symbol] MAIN SELL WAIT cb price=".number_format($price,8,'.','')." trigger=".number_format($cbTrigger,8,'.','')." peak=".number_format($lastPeak,8,'.',''));
        }
      }
    }
  }

  // 6) BUY ladder
  if ($qty<=0) {
    $qNorm = exi_normalize_quote_buy($symbol,$fba,$apiKey,$secret);
    if ($qNorm<=0){ logx("[$symbol] BUY1 SKIP quote norm=0"); continue; }
      // TG_SKIP_BUY1
      $reason = explain_buy_skip($mysqli, $symbol, $fba, $apiKey, $secret);
      list($ok,$msg) = tg_notify("INFO: ".$symbol." BUY1 SKIP\n".$reason);
      logx("[".$symbol."] TG SKIP BUY1 ".($ok?"OK":"ERR")." ".$msg);
      $reason = explain_buy_skip($mysqli, $symbol, $fba, $apiKey, $secret);
      tg_notify("INFO: " . $symbol . " BUY1 SKIP
" . $reason);

    [$h,$r,$e,$er]=place_quote_buy($symbol,$qNorm,$apiKey,$secret);
    if($e||$h>=400){
      logx("[$symbol] BUY1 ERR http=$h body=$r");
      $txt = sprintf("❌ <b>%s</b> BUY1 ERR\nhttp=%d\nResp=%s", $symbol, $h, $r);
      tg_notify($txt);
    } else {
      $p2=sync_from_trades($mysqli,$sid,$symbol,$apiKey,$secret);
      $mysqli->query("UPDATE positions SET next_ptr=1, last_buy=".number_format($price,18,'.','').", state='active' WHERE id=".$p2['id']);
      insert_subpos($mysqli,(int)$p2['id'],1,(float)$p2['qty'],$price,$tp_whole,$cb_whole);
      $mysqli->query("UPDATE positions SET sub_assigned_qty=".(float)$p2['qty']." WHERE id=".$p2['id']);
      logx("[$symbol] BUY1 ok quote=$qNorm @ $price");
      $txt = sprintf("\xF0\x9F\x9F\xA2 <b>%s</b> BUY1\nQuote: %.2f USDT\nPrice: %.8f", $symbol, $qNorm, $price);
      tg_notify($txt);
    }
  } else {
    $usePtr=max(1,(int)$pos['next_ptr']);
    $lv=$levels[$usePtr]??null;
    if($lv){
      $drop=(float)$lv['drop'];
      $mult=max(0.0,(float)$lv['mult']);
      $anchor = $pos['last_buy']>0 ? (float)$pos['last_buy'] : ($avg>0?$avg:$price);
      if($anchor>0 && $price <= $anchor*(1.0-$drop/100.0)){
        $quote = $fba * ($mult>0 ? $mult : 1.0);
        $qNorm = exi_normalize_quote_buy($symbol,$quote,$apiKey,$secret);
        if($qNorm<=0){
          logx("[$symbol] BUY(L$usePtr) SKIP quote norm=0");
      // TG_SKIP_LX
      $reason = explain_buy_skip($mysqli, $symbol, $quote, $apiKey, $secret);
      $extra  = skip_extra($mysqli,$anchor,$drop,$price);
      list($ok,$msg) = tg_notify("INFO: ".$symbol." BUY L".$usePtr." SKIP\n".$reason."\n".$extra, ["parse_mode"=>""]);
      logx("[".$symbol."] TG SKIP L".$usePtr." ".($ok?"OK":"ERR")." ".$msg);
        $reason = explain_buy_skip($mysqli, $symbol, $quote, $apiKey, $secret);
        tg_notify("INFO: " . $symbol . " BUY L".$usePtr." SKIP
" . $reason);

        } else {
          [$h,$r,$e,$er]=place_quote_buy($symbol,$qNorm,$apiKey,$secret);
          if($e||$h>=400){
            logx("[$symbol] BUY(L$usePtr) ERR http=$h body=$r");
            $txt = sprintf("❌ <b>%s</b> BUY L%d ERR\nhttp=%d\nResp=%s", $symbol, $usePtr, $h, $r);
            tg_notify($txt);
          } else {
            $p2=sync_from_trades($mysqli,$sid,$symbol,$apiKey,$secret);
            $acq=max(0.0,(float)$p2['qty'] - (float)$pos['qty']);
            $tp_for = ($usePtr<=4)?$tp_whole:(float)$lv['tp'];
            $cb_for = ($usePtr<=4)?$cb_whole:(float)($lv['cb'] ?? $cb_whole);
            $mysqli->query("UPDATE positions SET next_ptr=next_ptr+1, last_buy=".number_format($price,18,'.','').", state='active' WHERE id=".(int)$p2['id']);
            insert_subpos($mysqli,(int)$p2['id'],$usePtr,$acq,$price,$tp_for,$cb_for);
            $mysqli->query("UPDATE positions SET sub_assigned_qty=sub_assigned_qty+".number_format($acq,18,'.','')." WHERE id=".(int)$p2['id']);
            logx("[$symbol] BUY(L$usePtr) ok quote=$qNorm @ $price acq=$acq");
            $txt = sprintf("\xF0\x9F\x9F\xA2 <b>%s</b> BUY L%d\nQuote: %.2f USDT\nPrice: %.8f", $symbol, $usePtr, $qNorm, $price);
            tg_notify($txt);
          }
        }
      } else {
        logx("[$symbol] HOLD L$usePtr price=$price anchor=$anchor drop_need=".$drop.'%');
      }
    } else {
      logx("[$symbol] No level for ptr=$usePtr (max=".max(array_keys($levels)).")");
    }
  }
} // while strategies
