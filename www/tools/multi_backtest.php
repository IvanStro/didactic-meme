<?php
declare(strict_types=1);

/**
 * Multi-symbol paper backtest (1h) із СПІЛЬНИМ пулом USDT.
 * - Стартовий баланс USDT: 10 000 (shared across ADA,DOGE,SUSHI,DOT)
 * - L1..L4: "головна позиція", TP whole = 1.4% (продаємо всі саби L<=4 разом)
 * - L5..70: кожна сабпозиція має власний TP; продаємо індивідуально
 * - BUY: FBA=10 USDT * mult рівня; для L<=4 anchor = середня ціна по L<=4 (якщо пусто — lastBuy/price),
 *        для L>=5 anchor = остання покупка
 * - Реалізований PnL: сума (продаж - собівартість проданої кількості)
 * - Нереалізований PnL: (поточна вартість відкритих сабів - їх собівартість)
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

// --- конфіг ---
$SYMS = ['ADAUSDT','DOGEUSDT','SUSHIUSDT','DOTUSDT'];
$CASH_START = 10000.0;       // СПІЛЬНИЙ кеш
$FBA = 10.0;                 // базова сума покупки
$TP_WHOLE = 1.4;             // L<=4
$DAYS = 365;
$INTERVAL = '1h';
$sinceMs = (int)((microtime(true) - $DAYS*86400) * 1000);
$endMs   = (int)(microtime(true) * 1000);

// --- рівні з БД ---
$master = 0;
if ($r = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1")){
  if ($row = $r->fetch_assoc()) $master = (int)$row['v'];
}
if ($master<=0){ fwrite(STDERR,"NO master_strategy_id\n"); exit(3); }

$levels = []; // idx => ['drop'=>..,'mult'=>..,'tp'=>..]
$lv = $mysqli->query("SELECT lvl_idx, drop_pct, mult, COALESCE(tp_pct,1.6) tp FROM strategy_levels WHERE strategy_id=$master ORDER BY lvl_idx");
if ($lv) while($x=$lv->fetch_assoc()){
  $levels[(int)$x['lvl_idx']] = ['drop'=>(float)$x['drop_pct'], 'mult'=>(float)$x['mult'], 'tp'=>(float)$x['tp']];
}

// --- завантажуємо klines для всіх монет ---
function fetch_klines(string $symbol, string $interval, int $startMs, int $endMs): array {
  $out=[]; $from=$startMs;
  while ($from < $endMs) {
    $url = bn_base()."/api/v3/klines?symbol={$symbol}&interval={$interval}&startTime={$from}&endTime={$endMs}&limit=1000";
    $chunk = http_get_json($url);
    if (!$chunk) break;
    foreach ($chunk as $k){
      $ts=(int)$k[0]; $close=(float)$k[4];
      $out[] = ['ts'=>$ts,'c'=>$close];
      $from = $ts + 1;
    }
    if (count($chunk)<1000) break;
  }
  return $out;
}
$hist = [];        // symbol => [ [ts,c], ... ]
$allTs = [];       // уніон часових міток
foreach ($SYMS as $sym){
  $kl = fetch_klines($sym, $INTERVAL, $sinceMs, $endMs);
  $hist[$sym] = $kl;
  foreach ($kl as $k) $allTs[$k['ts']] = true;
}
$timeline = array_keys($allTs);
sort($timeline);

// --- стани по кожній монеті ---
$state = []; // sym => assoc
foreach ($SYMS as $sym){
  $state[$sym] = [
    'subs' => [],         // відкриті саби: [ ['lvl'=>, 'qty'=>, 'buy'=>, 'tp'=>], ... ]
    'posQty' => 0.0,
    'posCost'=> 0.0,      // сума купівель (cost) по відкритих сабах
    'lastBuy'=> 0.0,
    'ptr'    => 1,
    'buys'=>0, 'sells'=>0,
    'lastP'=>0.0
  ];
}

// --- глобальний кеш і реалізований PnL ---
$cash = $CASH_START;
$realized = 0.0;

// допоміжні
function basis_main(array $subs): array {
  $q=0.0; $cost=0.0;
  foreach ($subs as $sp) if ($sp['lvl']<=4 && $sp['qty']>0){ $q += $sp['qty']; $cost += $sp['qty']*$sp['buy']; }
  return [$q, $cost, $q>0 ? $cost/$q : 0.0];
}

// --- ітерація по часу ---
foreach ($timeline as $ts){
  foreach ($SYMS as $sym){
    // ціна на ts (якщо нема — скіп)
    $kl = $hist[$sym];
    // бінпоіск/ітеративний буфер — простий пошук назад:
    $p = null;
    // кеш last price
    if (!empty($state[$sym]['lastP'])) $p = $state[$sym]['lastP'];
    // знайдемо найближчу свічку <= ts
    for ($i=count($kl)-1; $i>=0; $i--){
      if ($kl[$i]['ts'] <= $ts){ $p = $kl[$i]['c']; break; }
    }
    if ($p===null) continue;
    $state[$sym]['lastP'] = $p;

    // 1) SUB sells (L>=5)
    foreach ($state[$sym]['subs'] as $i=>$sp){
      if ($sp['qty']>0 && $sp['lvl']>=5 && $p >= $sp['buy']*(1.0+$sp['tp']/100.0)){
        // продаємо цю сабу повністю
        $got = $sp['qty'] * $p;
        $cash += $got;
        // realized = дохід - собівартість
        $realized += $sp['qty']*$p - $sp['qty']*$sp['buy'];
        // зменшуємо відкриту позицію
        $state[$sym]['posQty']  -= $sp['qty'];
        $state[$sym]['posCost'] -= $sp['qty']*$sp['buy'];
        $state[$sym]['subs'][$i]['qty'] = 0.0;
        $state[$sym]['sells']++;
      }
    }

    // 2) MAIN sell (L<=4)
    [$mainQ, $mainCost, $basis] = basis_main($state[$sym]['subs']);
    if ($mainQ>0 && $basis>0 && $p >= $basis*(1.0+$TP_WHOLE/100.0)){
      // продаємо весь main
      $got = $mainQ * $p;
      $cash += $got;
      $realized += $mainQ*$p - $mainCost;
      // зменшуємо відкриту позицію
      $state[$sym]['posQty']  -= $mainQ;
      $state[$sym]['posCost'] -= $mainCost;
      // закриваємо саби L<=4
      foreach ($state[$sym]['subs'] as $i=>$sp) if ($sp['lvl']<=4) $state[$sym]['subs'][$i]['qty']=0.0;
      // ресет вказівника рівня
      $state[$sym]['ptr'] = 1;
      $state[$sym]['sells']++;
    }

    // 3) BUY
    // якщо позиція порожня — стартова покупка
    if ($state[$sym]['posQty'] <= 1e-12){
      if ($cash >= $FBA){
        $qty = $FBA / $p;
        $cash -= $FBA;
        $state[$sym]['posQty']  += $qty;
        $state[$sym]['posCost'] += $FBA;
        $state[$sym]['lastBuy'] = $p;
        $state[$sym]['ptr'] = 1;
        $state[$sym]['subs'][] = ['lvl'=>1,'qty'=>$qty,'buy'=>$p,'tp'=>$TP_WHOLE];
        $state[$sym]['buys']++;
      }
      continue;
    }

    $usePtr = max(1, (int)$state[$sym]['ptr']);
    if (!isset($levels[$usePtr])) continue;

    $drop = (float)$levels[$usePtr]['drop'];
    $mult = max(0.0,(float)$levels[$usePtr]['mult']);
    [$mainQ, $mainCost, $basis] = basis_main($state[$sym]['subs']);
    $anchor = ($usePtr<=4)
      ? ($mainQ>0 ? $basis : ($state[$sym]['lastBuy']>0 ? $state[$sym]['lastBuy'] : $p))
      : ($state[$sym]['lastBuy']>0 ? $state[$sym]['lastBuy'] : $p);

    if ($anchor>0 && $p <= $anchor*(1.0 - $drop/100.0)){
      $quote = $FBA * ($mult>0 ? $mult : 1.0);
      if ($cash >= $quote){
        $qty = $quote / $p;
        $cash -= $quote;
        $state[$sym]['posQty']  += $qty;
        $state[$sym]['posCost'] += $quote;
        $state[$sym]['lastBuy']  = $p;
        $tpFor = ($usePtr<=4) ? $TP_WHOLE : (float)$levels[$usePtr]['tp'];
        $state[$sym]['subs'][] = ['lvl'=>$usePtr,'qty'=>$qty,'buy'=>$p,'tp'=>$tpFor];
        $state[$sym]['ptr']++;
        $state[$sym]['buys']++;
      }
    }
  }
}

// --- фінальний зріз по монетах та загалом ---
$sumValue=0.0; $sumCostOpen=0.0;
echo "=== BREAKDOWN ===\n";
foreach ($SYMS as $sym){
  $lastP = $state[$sym]['lastP'];
  // вартість відкритих сабів і їх cost
  $v=0.0; $cost=0.0; $openQty=0.0;
  foreach ($state[$sym]['subs'] as $sp){
    if ($sp['qty']>0){
      $v   += $sp['qty'] * $lastP;
      $cost+= $sp['qty'] * $sp['buy'];
      $openQty += $sp['qty'];
    }
  }
  $sumValue += $v; $sumCostOpen += $cost;
  $unrl = $v - $cost;
  printf("%-9s buys=%3d sells=%3d  openQty=%.6f  invested(open)=%.2f  value(open)=%.2f  unrealized=%.2f\n",
    $sym, $state[$sym]['buys'], $state[$sym]['sells'], $openQty, $cost, $v, $unrl);
}

$totalFinal = $cash + $sumValue;
$totalPnl   = ($cash + $sumValue) - $CASH_START;
$unrealized = $sumValue - $sumCostOpen;
$realizedPnL= $realized;
$pct        = $CASH_START>0 ? ($totalPnl/$CASH_START*100.0) : 0.0;

echo "\n=== SUMMARY ===\n";
printf("Start cash : %.2f USDT\n", $CASH_START);
printf("Cash free  : %.2f USDT\n", $cash);
printf("Invested(open) value : %.2f USDT\n", $sumValue);
printf("Realized PnL         : %.2f USDT\n", $realizedPnL);
printf("Unrealized PnL       : %.2f USDT\n", $unrealized);
printf("TOTAL PnL            : %.2f USDT (%.2f%%)\n", $totalPnl, $pct);
