<?php
require __DIR__ . '/_binance_utils.php';
require_once __DIR__ . '/_free_only_shim.php';

function exi_symbol_filters(string $symbol): array {
  // symbol-specific exchangeInfo (швидше і дешевше ніж весь довідник)
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/exchangeInfo?symbol='.$symbol);
  $out = ['stepSize'=>null,'minQty'=>null,'tickSize'=>null,'minNotional'=>null];
  if ($e || $h>=400) return $out;
  $j = json_decode((string)$r,true);
  $sy = $j['symbols'][0] ?? [];
  foreach (($sy['filters'] ?? []) as $f) {
    $t = $f['filterType'] ?? '';
    if ($t==='LOT_SIZE') {
      $out['stepSize'] = (float)$f['stepSize'];
      $out['minQty']   = (float)$f['minQty'];
    } elseif ($t==='MARKET_LOT_SIZE' && empty($out['stepSize'])) {
      // іноді біржа задає окремий step для маркетів
      $out['stepSize'] = (float)$f['stepSize'];
      $out['minQty']   = max((float)$out['minQty'], (float)$f['minQty']);
    } elseif ($t==='PRICE_FILTER') {
      $out['tickSize'] = (float)$f['tickSize'];
    } elseif ($t === 'NOTIONAL') {
      $mn = (float)($f['minNotional'] ?? $f['notional'] ?? 0);
      $out['minNotional'] = max(0.0, $mn);
    } elseif ($t === 'MIN_NOTIONAL' && (($out['minNotional'] ?? null) === null || (float)($out['minNotional'] ?? 0) <= 0.0)) {
      $mn = (float)($f['minNotional'] ?? $f['notional'] ?? 0);
      $out['minNotional'] = max(0.0, $mn);
    }
  }
  if (isset($out['minNotional']) && $out['minNotional'] !== null) {
    $out['minNotional'] = max(0.0, (float)$out['minNotional']);
  }
  return $out;
}

function exi_round_step_floor(float $value, ?float $step): float {
  if (!$step || $step<=0) return $value;
  $n = floor($value / $step) * $step;
  // уникаємо -0.0000000 через FP
  return $n>0 ? (float)number_format($n, 12, '.', '') : 0.0;
}

function exi_round_step_ceil(float $value, ?float $step): float {
  if (!$step || $step <= 0) {
    return $value > 0 ? $value : 0.0;
  }
  if ($value <= 0) {
    return 0.0;
  }
  $n = ceil($value / $step - 1e-12) * $step;
  return $n > 0 ? (float)number_format($n, 12, '.', '') : 0.0;
}

/**
 * Мін. початкова покупка (FBA), USDT — запас над Binance minNotional (~5),
 * щоб після комісії/LOT_SIZE продаж не падав на Filter failure: NOTIONAL.
 */
if (!function_exists('strim_min_fba_usdt')) {
  function strim_min_fba_usdt(): float
  {
    return 7.0;
  }
}

function exi_get_price(string $symbol): float {
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/ticker/price?symbol='.$symbol);
  if ($e || $h>=400) return 0.0;
  $p = (float)(json_decode((string)$r,true)['price'] ?? 0);
  return $p>0 ? $p : 0.0;
}

function exi_get_account(string $apiKey, string $secret): array {
  $ts = bn_server_time();
  $q  = bn_sign_query(['timestamp'=>$ts,'recvWindow'=>5000], $secret);
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/account?'.$q, ['X-MBX-APIKEY: '.$apiKey], 'GET');
  return (!$e && $h<400) ? (json_decode((string)$r,true) ?: []) : [];
}

function exi_get_free_asset(array $account, string $asset): float {
  foreach (($account['balances'] ?? []) as $b) {
    if (($b['asset'] ?? '') === $asset) {
      return (float)$b['free'] + (float)$b['locked'];
    }
  }
  return 0.0;
}

/**
 * Нормалізує quoteOrderQty для MARKET BUY:
 * - не більше вільного USDT,
 * - не менше безпечного мінімуму (max(minNotional, StrIM FBA floor)),
 * - округлює до 2х знаків (USDT).
 * Купівля < ~7 USDT навмисно пропускається — інакше продаж часто падає на NOTIONAL.
 */
function exi_normalize_quote_buy(string $symbol, float $desiredQuoteUSDT, string $apiKey, string $secret): float {
  [$api, $sec] = [$apiKey, $secret];
  $account = exi_get_account($api, $sec);
  // MARKET quoteOrderQty обмежується лише free USDT, не free+locked (заблоковано в ордерах).
  $freeUSDT = function_exists('exi_get_free_only')
    ? (float)exi_get_free_only($account, 'USDT')
    : (float)exi_get_free_asset($account, 'USDT');

  $filters = exi_symbol_filters($symbol);
  $minNotional = max(0.0, (float)($filters['minNotional'] ?? 0.0));
  $minSafe = max($minNotional, (float)strim_min_fba_usdt());

  // обмежуємо вільним балансом
  $quote = max(0.0, min($desiredQuoteUSDT, $freeUSDT));
  // забезпечуємо безпечний мінімум (не лише біржовий ~5)
  if ($minSafe > 0 && $quote > 0 && $quote < $minSafe) {
    if ($freeUSDT < $minSafe) {
      return 0.0;
    }
    $quote = $minSafe;
  }
  // USDT: 2 знаки достатньо
  $quote = floor($quote * 100.0) / 100.0;
  return $quote >= $minSafe ? (float)$quote : 0.0;
}

/**
 * Нормалізує quantity для MARKET SELL:
 * - не більше фактичної кількості,
 * - під крок LOT_SIZE (stepSize),
 * - виконує minQty і minNotional;
 * - якщо після floor notional < minNotional, але є «пил» (free − desired),
 *   підтягує qty лише з цього надлишку (не чіпає інші відкриті рівні).
 */
function exi_normalize_sell_qty(string $symbol, float $desiredQty, string $apiKey, string $secret): float {
  $asset = str_replace('USDT','', $symbol);
  $account = exi_get_account($apiKey, $secret);
  $freeQty = exi_get_free_asset($account, $asset); // free + locked

  $filters = exi_symbol_filters($symbol);
  $step    = (float)($filters['stepSize'] ?? 0.0);
  $minQty  = (float)($filters['minQty'] ?? 0.0);
  $minNot  = (float)($filters['minNotional'] ?? 0.0);
  $price   = exi_get_price($symbol);

  $qty = max(0.0, min($desiredQty, $freeQty));
  $qty = exi_round_step_floor($qty, $step);

  // Підтягнути dust/fee leftover лише з unallocated free (понад desired цього рівня).
  if ($minNot > 0 && $price > 0 && $qty > 0 && ($qty * $price) < $minNot) {
    $surplus = max(0.0, $freeQty - $desiredQty);
    $needQty = exi_round_step_ceil($minNot / $price, $step);
    if ($needQty <= ($qty + $surplus) + 1e-12 && $needQty <= $freeQty + 1e-12) {
      $qty = $needQty;
    }
  }

  // EXI_SELL_NORM_DEBUG: log normalization inputs when result becomes 0
  $dbgLog = __DIR__ . '/../logs/tick.multi.log';
  if ($qty <= 0.0 || ($minQty>0 && $qty < $minQty) || ($minNot>0 && $price>0 && ($qty*$price) < $minNot)) {
    @file_put_contents($dbgLog,
      sprintf("[EXI_SELL_NORM_DEBUG] %s symbol=%s asset=%s desired=%.12f free=%.12f step=%s minQty=%s minNot=%s price=%s qty_pre=%.12f qty_post=%.12f
",
        date('c'),
        $symbol,
        $asset,
        $desiredQty,
        $freeQty,
        (string)$step,
        (string)$minQty,
        (string)$minNot,
        (string)$price,
        max(0.0, min($desiredQty, $freeQty)),
        $qty
      ),
      FILE_APPEND
    );
  }

  if ($minQty>0 && $qty < $minQty) return 0.0;
  if ($minNot>0 && $price>0 && ($qty*$price) < $minNot) return 0.0;

  return $qty>0 ? (float)$qty : 0.0;
}
