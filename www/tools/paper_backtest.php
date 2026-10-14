<?php
declare(strict_types=1);

/**
 * Paper backtest (1h) під твою логіку:
 * - L1..L4: головна позиція, TP whole = 1.4%
 * - L5..70: індивідуальні TP з strategy_levels
 * - anchor: для L<=4 середня по відкритих L<=4, для L>=5 — остання покупка
 * - initial FBA = 10 USDT, стартовий баланс = 10000 USDT
 */

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../api/_binance_utils.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();

function http_get_json(string $url): array {
  [$h,$r,$e,$er] = http_json($url);
  if ($e || $h>=400) return [];
  $j = json_decode((string)$r, true);
  return is_array($j)?$j:[];
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { fwrite(STDERR, "DB ERR ".$mysqli->connect_error."\n"); exit(2); }
$mysqli->set_charset('utf8mb4');

// ---- конфіг ----
$SYMS = ['ADAUSDT','DOGEUSDT','SUSHIUSDT','DOTUSDT'];
$START_BAL = 10000.0;
$FBA = 10.0;                   // стартова покупка і база для мультиплікаторів
$TP_WHOLE = 1.4;               // 1–4 + старт
$CB_WHOLE = 0.1;               // не використовуємо у backtest, лишимо для сумісності
$DAYS = 365;                    // горизонт
$INTERVAL = '1h';               // крок
$sinceMs = (int)( (microtime(true) - $DAYS*86400) * 1000 );
$endMs   = (int)( microtime(true) * 1000 );

// ---- рівні з БД (master strategy) ----
$master = 0;
if ($r = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1")){
  if ($row = $r->fetch_assoc()) $master = (int)$row['v'];
}
if ($master<=0){ fwrite(STDERR,"NO master_strategy_id\n"); exit(3); }

$levels = []; // idx => ['drop'=>..,'mult'=>..,'tp'=>..]
$lv = $mysqli->query("SELECT lvl_idx, drop_pct, mult, COALESCE(tp_pct,1.6) tp FROM strategy_levels WHERE strategy_id=$master ORDER BY lvl_idx");
if ($lv) while($x=$lv->fetch_assoc()) $levels[(int)$x['lvl_idx']] = ['drop'=>(float)$x['drop_pct'], 'mult'=>(float)$x['mult'], 'tp'=>(float)$x['tp']];

// ---- ф-ція для історії klines з пагінацією (limit=1000) ----
function fetch_klines(string $symbol, string $interval, int $startMs, int $endMs): array {
  $out=[]; $from=$startMs;
  while ($from < $endMs){
    $url = bn_base()."/api/v3/klines?symbol={$symbol}&interval={$interval}&startTime={$from}&endTime={$endMs}&limit=1000";
    $chunk = http_get_json($url);
    if (!$chunk) break;
    foreach ($chunk as $k){
      $ts=(int)$k[0]; $open=(float)$k[1]; $high=(float)$k[2]; $low=(float)$k[3]; $close=(float)$k[4];
      $out[] = ['ts'=>$ts,'o'=>$open,'h'=>$high,'l'=>$low,'c'=>$close];
      $from = $ts + 1;
    }
    if (count($chunk)<1000) break;
  }
  return $out;
}

// ---- симулятор однієї монети ----
function simulate_symbol(string $sym, array $klines, array $levels, float $startBal, float $fba, float $tpWhole): array {
  $bal = $startBal;
  $posQty = 0.0;
  $posInvest = 0.0;
  $lastBuy = 0.0;
  $ptr = 1;                             // наступний рівень
  $subs = [];                           // відкриті саби: [['lvl'=>..,'qty'=>..,'buy'=>..,'tp'=>..], ...]
  $logBuys=0; $logSells=0;

  foreach ($klines as $k){
    $p = $k['c'];

    // 1) SUB sells (>=5)
    foreach ($subs as $i=>$sp){
      if ($sp['lvl']>=5 && $sp['qty']>0 && $p >= $sp['buy']*(1.0 + $sp['tp']/100.0)){
        // sell повністю
        $got = $sp['qty'] * $p;
        $bal += $got;
        $posQty -= $sp['qty'];
        // позиційні інвестиції зменшимо пропорційно до qty (selling at profit завершуємо вкладення цієї саби)
        $posInvest -= $sp['qty'] * $sp['buy'];
        $subs[$i]['qty']=0.0;
        $logSells++;
      }
    }
    // 2) MAIN sell (<=4 + старт)
    // basis = середня ціна по відкритих L<=4
    $mainQ=0.0; $mainCost=0.0;
    foreach($subs as $sp){
      if ($sp['lvl']<=4 && $sp['qty']>0){ $mainQ += $sp['qty']; $mainCost += $sp['qty']*$sp['buy']; }
    }
    $basis = $mainQ>0 ? ($mainCost/$mainQ) : 0.0;
    if ($mainQ>0 && $basis>0 && $p >= $basis*(1.0 + $tpWhole/100.0)){
      // sell ALL main
      $got = $mainQ * $p;
      $bal += $got;
      $posQty -= $mainQ;
      $posInvest -= $mainCost;
      // закрити main-саби
      foreach($subs as $i=>$sp){ if ($sp['lvl']<=4) $subs[$i]['qty']=0.0; }
      $ptr = 1; // після повного продажу
      $logSells++;
    }

    // 3) BUY
    if ($posQty<=1e-12){ // пуста позиція -> стартова покупка
      if ($bal >= $fba){
        $qty = $fba / $p;
        $bal -= $fba;
        $posQty += $qty; $posInvest += $fba;
        $lastBuy = $p; $ptr = 1;
        $subs[] = ['lvl'=>1,'qty'=>$qty,'buy'=>$p,'tp'=>$tpWhole];
        $logBuys++;
      }
      continue;
    }

    // маємо позицію -> спроба купити поточний рівень
    $usePtr = max(1,$ptr);
    if (!isset($levels[$usePtr])) continue;
    $drop = (float)$levels[$usePtr]['drop'];
    $mult = max(0.0,(float)$levels[$usePtr]['mult']);
    $anchor = ($usePtr<=4)
      ? ($mainQ>0 ? $basis : ($lastBuy>0 ? $lastBuy : $p))
      : ($lastBuy>0 ? $lastBuy : $p);

    if ($anchor>0 && $p <= $anchor*(1.0 - $drop/100.0)){
      $quote = $fba * ($mult>0 ? $mult : 1.0);
      if ($bal >= $quote){
        $qty = $quote / $p;
        $bal -= $quote;
        $posQty += $qty; $posInvest += $quote;
        $lastBuy = $p;
        $tpFor = ($usePtr<=4) ? $tpWhole : (float)$levels[$usePtr]['tp'];
        $subs[] = ['lvl'=>$usePtr,'qty'=>$qty,'buy'=>$p,'tp'=>$tpFor];
        $ptr++;
        $logBuys++;
      }
    }
  }

  // фінальна вартість відкритої позиції
  $lastP = $klines ? end($klines)['c'] : 0.0;
  $value = $posQty * $lastP;
  $final = $bal + $value;
  $pnl = $final - $startBal;
  $pnlPct = $startBal>0 ? ($pnl/$startBal*100.0) : 0.0;

  return [
    'final'=>$final, 'pnl'=>$pnl, 'pnlPct'=>$pnlPct,
    'bal'=>$bal, 'posQty'=>$posQty, 'posInvest'=>$posInvest, 'lastP'=>$lastP,
    'buys'=>$logBuys,'sells'=>$logSells
  ];
}

// ---- main backtest ----
printf("Backtest (paper, %dd, %s)\n", $DAYS, $INTERVAL);
$totalStart = $START_BAL;
$bySym = [];
foreach ($SYMS as $sym){
  echo ">>> $sym: fetching klines...\n";
  $kl = fetch_klines($sym, $INTERVAL, $sinceMs, $endMs);
  if (!$kl){ echo "   no klines\n"; continue; }
  $res = simulate_symbol($sym, $kl, $levels, $START_BAL, $FBA, $TP_WHOLE);
  $bySym[$sym] = $res;
}
echo "\n=== RESULT ===\n";
$sumFinal=0.0; $sumPnl=0.0;
foreach ($bySym as $s=>$r){
  printf("%-9s final=%.2f  pnl=%.2f (%.2f%%)  buys=%d sells=%d  openQty=%.6f @ last=%.6f\n",
    $s, $r['final'], $r['pnl'], $r['pnlPct'], $r['buys'], $r['sells'], $r['posQty'], $r['lastP']);
  $sumFinal += $r['final']; $sumPnl += $r['pnl'];
}
$sumPct = $totalStart>0 ? ($sumPnl/$totalStart*100.0) : 0.0;
printf("\nTOTAL start=%.2f  final=%.2f  PnL=%.2f (%.2f%%)\n", $totalStart, $sumFinal, $sumPnl, $sumPct);
