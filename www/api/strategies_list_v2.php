<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../engine/engine_logic_ladder_cascade.php';
require_once __DIR__ . '/../../engine/engine_helpers.php';
require_once __DIR__ . '/_binance_utils.php';
require_once __DIR__ . '/_user_keys.php';
require_once __DIR__ . '/_ws_price.php';
require_once __DIR__ . '/_legacy_portfolio.php';
require_once __DIR__ . '/../../engine/spot_funds_freeze.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$symbolFilter = isset($_GET['symbol']) ? strtoupper(trim((string)$_GET['symbol'])) : '';

$statusSql = '';
if (in_array($status, ['running','paused','stopped'], true)) {
  $st = $mysqli->real_escape_string($status);
  $statusSql = " AND status='$st' ";
}

$symbolSql = '';
if ($symbolFilter !== '') {
  $sym = $mysqli->real_escape_string($symbolFilter);
  $symbolSql = " AND symbol='$sym' ";
}

/**
 * Binance fills-based cost basis (moving average).
 * Returns remaining qty/cost/avg for the currently held position (cycle resets when qty->0).
 */
function bn_cost_basis_cycle(string $symbol, string $apiKey, string $secret, int $sinceMs = 0): array {
  $ts = bn_server_time();
  $q  = bn_sign_query(['symbol'=>$symbol, 'timestamp'=>$ts, 'recvWindow'=>5000, 'limit'=>500], $secret);
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/myTrades?'.$q, ['X-MBX-APIKEY: '.$apiKey], 'GET');
  if ($e || $h >= 400) {
    return ['ok' => false, 'qty' => 0.0, 'cost' => 0.0, 'avg' => 0.0, 'note' => "myTrades http=$h", 'trade_count' => 0, 'possibly_truncated' => false];
  }

  $trades = json_decode((string)$r, true);
  if (!is_array($trades)) $trades = [];
  usort($trades, fn($a,$b)=> (int)($a['time']??0) <=> (int)($b['time']??0));

  $qty = 0.0;
  $cost = 0.0;
  $eps = 1e-10;

  foreach ($trades as $t) {
    if ($sinceMs > 0 && (int)($t['time'] ?? 0) < $sinceMs) {
      continue;
    }
    $isBuyer = !empty($t['isBuyer']);
    $qQty  = (float)($t['qty'] ?? 0.0);
    $quote = (float)($t['quoteQty'] ?? 0.0);
    $price = (float)($t['price'] ?? 0.0);

    $comm = (float)($t['commission'] ?? 0.0);
    $commAsset = (string)($t['commissionAsset'] ?? '');
    $fee_usdt = 0.0;
    if ($comm > 0) {
      if ($commAsset === 'USDT') {
        $fee_usdt = $comm;
      } else {
        $base = str_replace('USDT','',$symbol);
        if ($commAsset === $base && $price > 0) $fee_usdt = $comm * $price;
      }
    }
    if ($qQty <= 0) continue;

    if ($isBuyer) {
      $qty += $qQty;
      $cost += $quote + $fee_usdt;
    } else {
      $avg = ($qty > $eps) ? ($cost / $qty) : 0.0;
      $qty -= $qQty;
      $cost -= $avg * $qQty;
      if (abs($qty) < 1e-8) { $qty = 0.0; $cost = 0.0; }
      if ($cost < 0) $cost = 0.0;
    }
  }
  $avg = ($qty > $eps) ? ($cost / $qty) : 0.0;
  // Binance max limit=500 per call — якщо рівно 500, історія може бути обрізана; не варто довіряти cost/qty для P/L.
  $tradeCount = count($trades);
  return [
    'ok' => true,
    'qty' => $qty,
    'cost' => $cost,
    'avg' => $avg,
    'note' => '',
    'trade_count' => $tradeCount,
    'possibly_truncated' => $tradeCount >= 500,
  ];
}

/** Public ticker price (no key needed) */
function bn_last_price_usdt(string $symbol): float {
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/ticker/price?symbol='.rawurlencode($symbol), [], 'GET');
  if ($e || $h >= 400) return 0.0;
  $j = json_decode((string)$r, true);
  $p = is_array($j) && isset($j['price']) ? (float)$j['price'] : 0.0;
  return $p > 0 ? $p : 0.0;
}

/**
 * Spot wallet balances (free+locked) per asset — same source as portfolio.php.
 * This is the Binance truth for inventory on the Spot account.
 */
function bn_spot_balances(string $apiKey, string $secret): array {
  if ($apiKey === '' || $secret === '') {
    return [];
  }
  $ts = bn_server_time();
  $q  = bn_sign_query(['timestamp'=>$ts,'recvWindow'=>5000], $secret);
  [$h,$r,$e,$er] = http_json(bn_base().'/api/v3/account?'.$q, ['X-MBX-APIKEY: '.$apiKey], 'GET');
  if ($e || $h >= 400) {
    return [];
  }
  $acc = json_decode((string)$r, true) ?: [];
  $out = [];
  foreach (($acc['balances'] ?? []) as $b) {
    $a = (string)($b['asset'] ?? '');
    if ($a === '') {
      continue;
    }
    $out[$a] = (float)($b['free'] ?? 0) + (float)($b['locked'] ?? 0);
  }
  return $out;
}

/**
 * Цільова ціна продажу ядра (engine core basis × tp_whole) та наступна докупка.
 */
function saas_trade_targets(mysqli $db, int $sid, ?array $pr, float $tpw): array {
  $out = [
    'main_sell_price' => null,
    'main_sell_basis' => null,
    'main_sell_source' => null,
    'next_buy_price' => null,
    'next_ptr' => null,
  ];
  if (!$pr) {
    return $out;
  }
  $pid = (int)($pr['id'] ?? 0);
  $qty = (float)($pr['qty'] ?? 0);
  $avg = (float)($pr['avg_price'] ?? 0);
  $base = (float)($pr['base_price'] ?? 0);
  $nextPtr = (int)($pr['next_ptr'] ?? 0);
  if ($qty > 0 && $nextPtr < 1) {
    $nextPtr = 1;
  }
  $out['next_ptr'] = $nextPtr > 0 ? $nextPtr : null;

  // R2: MAIN = core basis (без L5+), як handle_main_sell_core — не avg усієї позиції.
  if ($qty > 0 && $tpw > 0) {
    $core = function_exists('position_core_basis')
      ? position_core_basis($db, $pr)
      : ['basis' => $avg, 'core_qty' => $qty, 'hi_qty' => 0.0];
    $basis = (float)($core['basis'] ?? 0);
    if ($basis <= 0 && $avg > 0) {
      $basis = $avg;
    }
    if ($basis > 0) {
      $out['main_sell_basis'] = round($basis, 8);
      $out['main_sell_price'] = round($basis * (1 + $tpw / 100), 8);
      $out['main_sell_source'] = ((float)($core['hi_qty'] ?? 0) > 1e-12) ? 'core' : 'avg';
    }
  }

  if ($nextPtr < 1 || $sid <= 0) {
    return $out;
  }

  if (ladder_cascade_mode_active($db) && $pid > 0 && $base > 0) {
    $lvC = ladder_cascade_load_strategy_levels($db, $sid);
    $trC = ladder_cascade_build_triggers($db, $pid, $base, $lvC);
    if (isset($trC[$nextPtr]) && (float)$trC[$nextPtr] > 0) {
      $out['next_buy_price'] = round((float)$trC[$nextPtr], 8);
    }
    return $out;
  }

  $lr = $db->query("SELECT drop_pct FROM strategy_levels WHERE strategy_id=$sid AND lvl_idx=$nextPtr LIMIT 1");
  $drop = ($lr && ($row = $lr->fetch_assoc())) ? (float)$row['drop_pct'] : null;
  if ($drop === null) {
    return $out;
  }

  $anchor = 0.0;
  if ($nextPtr <= 4) {
    $anchor = $base > 0 ? $base : $avg;
  } elseif ($nextPtr === 5) {
    $anchor = $avg;
  } elseif ($pid > 0) {
    $prevLvl = $nextPtr - 1;
    $rr = $db->query("SELECT buy_price FROM sub_positions WHERE position_id=$pid AND level_idx=$prevLvl AND closed=0 ORDER BY id DESC LIMIT 1");
    $anchor = ($rr && ($r2 = $rr->fetch_assoc())) ? (float)$r2['buy_price'] : 0.0;
  }

  if ($anchor > 0) {
    $out['next_buy_price'] = round($anchor * (1 - $drop / 100), 8);
  }
  return $out;
}

/**
 * Відкриті саб-позиції L5+ з ціною take-profit (як у sub_positions.tp %).
 * L1–L4 — частина ядра (MAIN); їх не показуємо як підрівневий продаж.
 */
function saas_open_sub_sells(mysqli $db, int $pid): array {
  if ($pid <= 0) {
    return [];
  }
  $pid = (int)$pid;
  $out = [];
  $q = $db->query("
    SELECT sp.level_idx, sp.buy_price, sp.qty, sp.tp, sp.cb
    FROM sub_positions sp
    INNER JOIN (
      SELECT level_idx, MAX(id) AS max_id
      FROM sub_positions
      WHERE position_id = $pid AND closed = 0 AND level_idx >= 5
      GROUP BY level_idx
    ) u ON sp.id = u.max_id
    ORDER BY sp.level_idx ASC
  ");
  if (!$q) {
    return [];
  }
  while ($r = $q->fetch_assoc()) {
    $lvl = (int)($r['level_idx'] ?? 0);
    $bp = (float)($r['buy_price'] ?? 0);
    $tp = (float)($r['tp'] ?? 0);
    $cb = (float)($r['cb'] ?? 0);
    $qty = (float)($r['qty'] ?? 0);
    $sellT = ($bp > 0 && $tp > 0) ? round($bp * (1 + $tp / 100), 8) : null;
    $out[] = [
      'level' => $lvl,
      'buy_price' => $bp,
      'qty' => $qty,
      'tp_pct' => $tp,
      'cb_pct' => $cb,
      'sell_target' => $sellT,
    ];
  }
  return $out;
}

/**
 * Сума qty відкритих рівнів сітки L1+ — лише fallback для UI qty, не для блоку L5+.
 */
function saas_open_grid_qty(mysqli $db, int $pid): float {
  if ($pid <= 0) {
    return 0.0;
  }
  $pid = (int)$pid;
  $q = $db->query("
    SELECT COALESCE(SUM(sp.qty), 0) AS q
    FROM sub_positions sp
    INNER JOIN (
      SELECT level_idx, MAX(id) AS max_id
      FROM sub_positions
      WHERE position_id = $pid AND closed = 0 AND level_idx >= 1
      GROUP BY level_idx
    ) u ON sp.id = u.max_id
  ");
  if (!$q || !($r = $q->fetch_assoc())) {
    return 0.0;
  }
  return (float)($r['q'] ?? 0);
}

// Load user's binance keys (decrypted)
[$apiKey, $secret] = user_keys($mysqli, (int)$uid);
$apiKey = $apiKey ? (string)$apiKey : '';
$secret = $secret ? (string)$secret : '';

$spotBalances = ($apiKey !== '' && $secret !== '') ? bn_spot_balances($apiKey, $secret) : [];

$v2CanaryIds = [];
$modeFile = dirname(__DIR__, 2) . '/engine_v2/engine_mode.php';
if (is_file($modeFile)) {
  require_once $modeFile;
  if (function_exists('engine_v2_canary_strategy_ids')) {
    $v2CanaryIds = engine_v2_canary_strategy_ids();
  }
}
$hasSignalQueueTable = false;
$sqTbl = $mysqli->query("SHOW TABLES LIKE 'signal_queue'");
if ($sqTbl && $sqTbl->num_rows > 0) {
  $hasSignalQueueTable = true;
}

/**
 * @return array{v2_canary:bool,last_enqueue_at:?string,enqueue_lag_sec:?int,processing_lag_sec:?int,last_exec_at:?string,exec_lag_sec:?int,queue_queued:int,queue_processing:int}
 */
function saas_v2_signal_engine_stats(mysqli $db, int $strategyId, bool $isCanary, bool $hasTable): array
{
  $out = [
    'v2_canary' => $isCanary,
    'last_enqueue_at' => null,
    'enqueue_lag_sec' => null,
    'processing_lag_sec' => null,
    'last_exec_at' => null,
    'exec_lag_sec' => null,
    'queue_queued' => 0,
    'queue_processing' => 0,
  ];
  if (!$isCanary || !$hasTable || $strategyId <= 0) {
    return $out;
  }
  $q = $db->query("
    SELECT
      MAX(created_at) AS last_enqueue_at,
      MAX(CASE WHEN status = 'processing' THEN COALESCE(locked_at, updated_at) END) AS processing_since,
      MAX(CASE WHEN status IN ('done','failed') THEN updated_at END) AS last_exec_at,
      SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queue_queued,
      SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS queue_processing
    FROM signal_queue
    WHERE strategy_id = {$strategyId}
  ");
  if (!$q || !($row = $q->fetch_assoc())) {
    return $out;
  }
  $out['queue_queued'] = (int)($row['queue_queued'] ?? 0);
  $out['queue_processing'] = (int)($row['queue_processing'] ?? 0);
  $enAt = isset($row['last_enqueue_at']) ? (string)$row['last_enqueue_at'] : '';
  if ($enAt !== '') {
    $out['last_enqueue_at'] = $enAt;
    $ts = strtotime($enAt);
    if ($ts !== false) {
      $out['enqueue_lag_sec'] = max(0, time() - $ts);
    }
  }
  $procAt = isset($row['processing_since']) ? (string)$row['processing_since'] : '';
  if ($procAt !== '' && (int)($row['queue_processing'] ?? 0) > 0) {
    $ts = strtotime($procAt);
    if ($ts !== false) {
      $out['processing_lag_sec'] = max(0, time() - $ts);
    }
  }
  $exAt = isset($row['last_exec_at']) ? (string)$row['last_exec_at'] : '';
  if ($exAt !== '') {
    $out['last_exec_at'] = $exAt;
    $ts = strtotime($exAt);
    if ($ts !== false) {
      $out['exec_lag_sec'] = max(0, time() - $ts);
    }
  }
  return $out;
}

$items = [];
$spotFundsFrozen = spot_funds_user_is_frozen($mysqli, $uid);
$spotFundsFreezeState = $spotFundsFrozen ? spot_funds_freeze_state($mysqli, $uid) : null;
$spotFundsTriggerSid = ($spotFundsFreezeState && isset($spotFundsFreezeState['strategy_id']))
  ? (int)$spotFundsFreezeState['strategy_id']
  : 0;
$spotFundsTriggerSym = ($spotFundsFreezeState && !empty($spotFundsFreezeState['symbol']))
  ? (string)$spotFundsFreezeState['symbol']
  : '';
$hasLegacyPos = false;
$crLegacy = @$mysqli->query("SHOW COLUMNS FROM positions LIKE 'cost_avg'");
if ($crLegacy && $crLegacy->num_rows > 0) {
  $hasLegacyPos = true;
}
$posSelect = $hasLegacyPos
  ? 'id, qty, avg_price, base_price, next_ptr, ladder_missed_json, origin, cost_avg, grid_anchor, adopt_mode, legacy_qty, invested_usdt'
  : 'id, qty, avg_price, base_price, next_ptr, ladder_missed_json, invested_usdt';

$q = $mysqli->query("
  SELECT id, owner_id, symbol, status, start_ts, fba, tp_whole, cb_whole, global_cb, last_tick_at
  FROM strategies
  WHERE owner_id=$uid $statusSql $symbolSql
  ORDER BY id DESC
  LIMIT $limit
");

if ($q) {
  while ($st = $q->fetch_assoc()) {
    $sid = (int)$st['id'];
    $symbol = (string)$st['symbol'];
    $startTsMs = (int)($st['start_ts'] ?? 0);

    // Position qty (our cached position table for strategy)
    $pq = $mysqli->query("SELECT $posSelect FROM positions WHERE strategy_id=$sid ORDER BY id DESC LIMIT 1");
    if (!$pq) {
      $pq = $mysqli->query("SELECT id, qty, avg_price, base_price, next_ptr FROM positions WHERE strategy_id=$sid ORDER BY id DESC LIMIT 1");
    }
    $pr = $pq ? $pq->fetch_assoc() : null;
    $tpw = (float)($st['tp_whole'] ?? 0);
    $targets = saas_trade_targets($mysqli, $sid, $pr, $tpw);
    $pidForSubs = $pr ? (int)($pr['id'] ?? 0) : 0;
    $openSubSells = saas_open_sub_sells($mysqli, $pidForSubs);
    $lotsSnap = null;
    if ($pr && function_exists('position_lots_snapshot')) {
      $lotsSnap = position_lots_snapshot($mysqli, $pr);
    }
    $openGridQtyUi = saas_open_grid_qty($mysqli, $pidForSubs);
    $qty_pos = $pr ? (float)$pr['qty'] : 0.0;
    $avg_pos = $pr ? (float)($pr['avg_price'] ?? 0) : 0.0;
    $base_pos = $pr ? (float)($pr['base_price'] ?? 0) : 0.0;
    $invested_usdt = $pr ? (float)($pr['invested_usdt'] ?? 0) : 0.0;
    $cost_avg = ($pr && $hasLegacyPos) ? (float)($pr['cost_avg'] ?? 0) : 0.0;
    $grid_anchor = ($pr && $hasLegacyPos) ? (float)($pr['grid_anchor'] ?? 0) : 0.0;
    $origin = ($pr && $hasLegacyPos) ? (string)($pr['origin'] ?? 'strim') : 'strim';
    $adopt_mode = ($pr && $hasLegacyPos) ? (string)($pr['adopt_mode'] ?? '') : '';
    $legacy_qty = ($pr && $hasLegacyPos && isset($pr['legacy_qty']) && $pr['legacy_qty'] !== null)
      ? (float)$pr['legacy_qty']
      : null;

    $last_price = $symbol && str_ends_with($symbol,'USDT') ? bn_last_price_usdt($symbol) : 0.0;

    $base = ($symbol !== '' && str_ends_with($symbol, 'USDT')) ? substr($symbol, 0, -4) : '';
    $qty_spot = ($base !== '' && isset($spotBalances[$base])) ? (float)$spotBalances[$base] : 0.0;

    $unr = 0.0;
    $unr_pct = 0.0;
    $avg_fill = null;
    $qty_mytrades = 0.0;
    $cost_bn = 0.0;

    // Cost / qty line from Spot trade history (myTrades) — used only together with spot wallet qty.
    $fifoTruncated = false;
    if ($apiKey !== '' && $secret !== '' && $symbol && str_ends_with($symbol,'USDT')) {
      $basis = bn_cost_basis_cycle($symbol, $apiKey, $secret, $startTsMs);
      if (!empty($basis['ok'])) {
        $qty_mytrades = (float)($basis['qty'] ?? 0);
        $cost_bn = (float)($basis['cost'] ?? 0);
        $avg_fill = $qty_mytrades > 1e-12 ? (float)($basis['avg'] ?? 0) : null;
        $fifoTruncated = !empty($basis['possibly_truncated']);
      }
    }

    // Qty fallback: full open grid L1+ (not only L5+ sub-sells shown in the card).
    $openSubQtyUi = $openGridQtyUi;

    // Strategy lot (bot cycle), not the whole Spot bag which may include legacy coins.
    // Prefer positions.qty when it matches Spot (same source as admin U.PNL).
    // myTrades alone can undercount: start_ts mid-cycle, or Binance 500-trade page limit.
    $qty_strategy_lot = 0.0;
    $posMatchesSpot = (
      $qty_pos > 1e-12
      && $qty_spot > 1e-12
      && (abs($qty_spot - $qty_pos) / max($qty_spot, $qty_pos, 1e-12)) <= 0.25
    );
    if ($posMatchesSpot) {
      $qty_strategy_lot = $qty_pos;
    } elseif ($qty_mytrades > 1e-12 && $qty_pos > 1e-12) {
      $qty_strategy_lot = max($qty_mytrades, $qty_pos);
    } elseif ($qty_mytrades > 1e-12) {
      $qty_strategy_lot = $qty_mytrades;
    } elseif ($qty_pos > 1e-12) {
      $qty_strategy_lot = $qty_pos;
    } elseif ($openSubQtyUi > 1e-12) {
      $qty_strategy_lot = $openSubQtyUi;
    }
    $spotExceedsStrategyLot = (
      $qty_spot > 1e-12
      && $qty_strategy_lot > 1e-12
      && $qty_spot > ($qty_strategy_lot * 1.25)
    );

    // Display qty / open state:
    // With API keys: prefer Spot when it matches the strategy lot.
    // If Spot >> strategy (legacy bag on same asset), show strategy lot for this card.
    $hasKeys = ($apiKey !== '' && $secret !== '');
    if ($hasKeys) {
      if ($spotExceedsStrategyLot) {
        $qty_display = $qty_strategy_lot;
        $is_open_ui = ($qty_strategy_lot > 1e-12);
        $out_of_sync = true;
      } else {
        $qty_display = $qty_spot;
        $is_open_ui = ($qty_spot > 1e-12);
        $out_of_sync = ($qty_pos > 1e-12 && $qty_spot <= 1e-12);
        if ($qty_spot <= 1e-12 && $openSubQtyUi > 1e-12) {
          $qty_display = $openSubQtyUi;
          $is_open_ui = true;
          $out_of_sync = true;
        } elseif ($qty_spot <= 1e-12 && $qty_pos > 1e-12) {
          $qty_display = $qty_pos;
          $is_open_ui = true;
          $out_of_sync = true;
        }
      }
    } else {
      $qty_display = $qty_spot > 1e-12 ? $qty_spot : (($qty_mytrades > 1e-12) ? $qty_mytrades : max($qty_pos, $openSubQtyUi));
      $is_open_ui = ($qty_pos > 1e-12 || $qty_spot > 1e-12 || $openSubQtyUi > 1e-12);
      $out_of_sync = false;
    }

    // Qty used for mark value + unrealized of THIS strategy.
    $qty_pnl = $qty_spot;
    if ($spotExceedsStrategyLot) {
      $qty_pnl = $qty_strategy_lot;
    } elseif ($posMatchesSpot) {
      // Align with admin: positions.qty × mark − invested/avg
      $qty_pnl = $qty_pos;
    } elseif ($hasKeys && $qty_spot <= 1e-12 && $qty_strategy_lot > 1e-12) {
      $qty_pnl = $qty_strategy_lot;
    }
    $value_usdt = ($qty_pnl > 1e-12 && $last_price > 0) ? ($qty_pnl * $last_price) : 0.0;

    // Avg / unrealized: only meaningful when Spot holds the asset (with keys). Otherwise hide avg.
    if ($hasKeys && $qty_spot <= 1e-12) {
      $avg_fill = null;
    }
    // Do not show FIFO avg when Spot inventory does not match post-start trade lot (manual/other buys).
    if ($hasKeys && $qty_spot > 1e-12 && !$spotExceedsStrategyLot) {
      $relM = $qty_mytrades > 1e-12
        ? abs($qty_spot - $qty_mytrades) / max($qty_spot, $qty_mytrades, 1e-12)
        : 1.0;
      if ($qty_mytrades <= 1e-12 || $relM > 0.2) {
        $avg_fill = null;
      }
    }

    // Unrealized (economic): for legacy/hybrid prefer cost_avg so cabinet shows real drawdown vs history.
    // Grid/engine avg (avg_price ≈ spot after exit_plan/adopt_spot) stays in unrealized_vs_grid for ops.
    // Critical: never multiply full Spot bag by strategy-cycle avg when bag >> bot lot
    // (that produced fake ~0 PnL like -2 USDT on thousands of coins).
    $unr_grid = 0.0;
    $unr_grid_pct = 0.0;
    if ($qty_pnl > 1e-12 && $last_price > 0 && $avg_pos > 1e-18) {
      $cost_grid = $qty_pnl * $avg_pos;
      $unr_grid = $value_usdt - $cost_grid;
      $unr_grid_pct = ($cost_grid > 1e-12) ? (($value_usdt / $cost_grid) - 1.0) * 100.0 : 0.0;
    } elseif ($qty_pnl > 1e-12 && $last_price > 0 && $base_pos > 0) {
      $cost_grid = $qty_pnl * $base_pos;
      $unr_grid = $value_usdt - $cost_grid;
      $unr_grid_pct = ($cost_grid > 1e-12) ? (($value_usdt / $cost_grid) - 1.0) * 100.0 : 0.0;
    }

    // Cycle FIFO (myTrades since start_ts) often undercounts after pause/resume:
    // open L1–L4 bought before start_ts remain in positions, but cost_bn ≈ last level only.
    // Never apply that partial cost to the full strategy lot (fake +200% PnL on mixed Spot bags).
    $fifoCoversLot = (
      $qty_mytrades > 1e-12
      && $qty_pnl > 1e-12
      && (abs($qty_mytrades - $qty_pnl) / max($qty_mytrades, $qty_pnl, 1e-12)) <= 0.25
    );
    $posBookForLot = (
      $qty_pos > 1e-12
      && $qty_pnl > 1e-12
      && (abs($qty_pos - $qty_pnl) / max($qty_pos, $qty_pnl, 1e-12)) <= 0.25
      && ($invested_usdt > 1e-12 || $avg_pos > 1e-18)
    );

    $pnl_basis = 'grid';
    if ($qty_pnl > 1e-12 && $last_price > 0 && $cost_avg > 1e-18 && !$spotExceedsStrategyLot) {
      $cost_est = $qty_pnl * $cost_avg;
      $unr = $value_usdt - $cost_est;
      $unr_pct = ($cost_est > 1e-12) ? (($value_usdt / $cost_est) - 1.0) * 100.0 : 0.0;
      $pnl_basis = 'cost_avg';
    } elseif (
      $qty_pnl > 1e-12
      && $last_price > 0
      && $posBookForLot
      && ($posMatchesSpot || $spotExceedsStrategyLot)
    ) {
      // Spot matches lot, OR mixed Spot bag: mark strategy lot vs positions book (admin-aligned).
      $cost_admin = $invested_usdt > 1e-12
        ? $invested_usdt
        : ($avg_pos * $qty_pnl);
      $unr = $value_usdt - $cost_admin;
      $unr_pct = ($cost_admin > 1e-12) ? (($value_usdt / $cost_admin) - 1.0) * 100.0 : 0.0;
      $pnl_basis = $spotExceedsStrategyLot ? 'invested' : 'position';
    } elseif (
      $qty_pnl > 1e-12 && $last_price > 0 && $qty_mytrades > 1e-12 && $cost_bn > 1e-12 && !$fifoTruncated
      && $fifoCoversLot
      && (
        $spotExceedsStrategyLot
        || abs($qty_spot - $qty_mytrades) / max($qty_spot, $qty_mytrades, 1e-12) <= 0.2
      )
    ) {
      $cost_alloc = $spotExceedsStrategyLot
        ? $cost_bn
        : ($cost_bn * (min($qty_pnl, $qty_mytrades) / $qty_mytrades));
      $unr = $value_usdt - $cost_alloc;
      $unr_pct = ($cost_alloc > 1e-12) ? (($value_usdt / $cost_alloc) - 1.0) * 100.0 : 0.0;
      $pnl_basis = 'fifo';
    } elseif ($qty_pnl > 1e-12 && $last_price > 0 && $invested_usdt > 1e-12 && $spotExceedsStrategyLot) {
      $unr = $value_usdt - $invested_usdt;
      $unr_pct = (($value_usdt / $invested_usdt) - 1.0) * 100.0;
      $pnl_basis = 'invested';
    } elseif ($qty_pnl > 1e-12 && $last_price > 0 && $avg_pos > 1e-18) {
      $unr = $unr_grid;
      $unr_pct = $unr_grid_pct;
      $pnl_basis = 'grid';
    }
    if ($qty_pnl > 1e-12 && $last_price > 0 && abs($unr) < 1e-12 && $base_pos > 0 && $pnl_basis === 'grid') {
      $cost_est = $qty_pnl * $base_pos;
      $unr = $value_usdt - $cost_est;
      $unr_pct = ($cost_est > 1e-12) ? (($value_usdt / $cost_est) - 1.0) * 100.0 : 0.0;
    }

    // Cumulative bot result for strategy (realized PnL from sells, deduped).
    $realized = 0.0;
    $rq = $mysqli->query("
      SELECT COALESCE(SUM(x.pnl),0) AS s
      FROM (
        SELECT MAX(COALESCE(tl.pnl_usdt,0)) AS pnl
        FROM trade_ledger tl
        WHERE tl.strategy_id=$sid
          AND tl.side='SELL'
        GROUP BY
          tl.strategy_id,
          tl.event_type,
          COALESCE(tl.order_id,0),
          tl.created_at,
          ROUND(COALESCE(tl.price,0), 12),
          ROUND(COALESCE(tl.qty,0), 12)
      ) x
    ");
    if ($rq && ($rr = $rq->fetch_assoc())) {
      $realized = (float)($rr['s'] ?? 0.0);
    }

    $ladderMissed = [
      'count' => 0,
      'levels' => [],
      'hint_uk' => '',
    ];
    $rawLm = is_array($pr) ? ($pr['ladder_missed_json'] ?? null) : null;
    if (is_string($rawLm) && $rawLm !== '') {
      $jm = json_decode($rawLm, true);
      if (is_array($jm) && isset($jm['q']) && is_array($jm['q']) && count($jm['q']) > 0) {
        $lvls = [];
        foreach ($jm['q'] as $row) {
          if (!is_array($row)) {
            continue;
          }
          $ln = (int)($row['lvl'] ?? 0);
          if ($ln > 0) {
            $lvls[] = $ln;
          }
        }
        $ladderMissed['count'] = count($lvls);
        $ladderMissed['levels'] = $lvls;
        if ($ladderMissed['count'] > 0) {
          $labs = array_map(static function ($n) {
            return 'L' . (string)$n;
          }, $lvls);
          $ladderMissed['hint_uk'] =
            'Черга докупок (missed): ' . implode(', ', $labs) .
            '. Рушій намагається виконати їх при співпадінні ціни catch-up і достатнього вільного USDT під ордер (мін. нотіонал Binance).' .
            ' Якщо баланс уже поповнено — дивіться час останнього запису в журналі та рух ціни.' .
            ' TP або повне закриття циклу також можуть зняти чергу.';
        }
      }
    }

    $lastTickAt = isset($st['last_tick_at']) ? (string)$st['last_tick_at'] : '';
    $tickLagSec = null;
    if ($lastTickAt !== '') {
      $ts = strtotime($lastTickAt);
      if ($ts !== false) {
        $tickLagSec = max(0, time() - $ts);
      }
    }

    $priceAge = ws_price_age_sec($symbol);
    $maxAge = ws_price_max_age_sec();
    $priceFresh = ($priceAge !== null && $priceAge <= $maxAge);

    $isV2Canary = function_exists('engine_strategy_uses_signal_exec')
      ? engine_strategy_uses_signal_exec($sid)
      : in_array($sid, $v2CanaryIds, true);
    $v2Signal = saas_v2_signal_engine_stats($mysqli, $sid, $isV2Canary, $hasSignalQueueTable);
    $engineBlock = [
      'last_tick_at' => $lastTickAt !== '' ? $lastTickAt : null,
      'tick_lag_sec' => $tickLagSec,
      'v2_canary' => $isV2Canary,
      'v2_signal' => $isV2Canary,
      'last_enqueue_at' => $v2Signal['last_enqueue_at'],
      'enqueue_lag_sec' => $v2Signal['enqueue_lag_sec'],
      'processing_lag_sec' => $v2Signal['processing_lag_sec'],
      'last_exec_at' => $v2Signal['last_exec_at'],
      'exec_lag_sec' => $v2Signal['exec_lag_sec'],
      'queue_queued' => $v2Signal['queue_queued'],
      'queue_processing' => $v2Signal['queue_processing'],
    ];

    $isSpotFreezeTrigger = ($spotFundsFrozen && $spotFundsTriggerSid > 0 && $spotFundsTriggerSid === $sid);
    $spotFundsItemHint = null;
    if ($spotFundsFrozen) {
      if ($isSpotFreezeTrigger && $spotFundsFreezeState) {
        $spotFundsItemHint = (string)($spotFundsFreezeState['hint_uk'] ?? '');
      } elseif ($spotFundsTriggerSym !== '') {
        $spotFundsItemHint =
          'Докупки на акаунті заморожені (спільний USDT). Тригер: ' . $spotFundsTriggerSym
          . ($spotFundsTriggerSid > 0 ? ' #' . $spotFundsTriggerSid : '')
          . '. На ' . $symbol . ' бот працює окремо — див. «Що робить бот» нижче.';
      } elseif ($spotFundsFreezeState) {
        $spotFundsItemHint = (string)($spotFundsFreezeState['hint_uk'] ?? '');
      }
    }

    $items[] = [
      'strategy_id' => $sid,
      'symbol' => $symbol,
      'status' => (string)$st['status'],
      'spot_funds_frozen' => $spotFundsFrozen,
      'spot_funds_frozen_scope' => $spotFundsFrozen ? 'account' : null,
      'spot_funds_frozen_is_trigger' => $isSpotFreezeTrigger,
      'spot_funds_frozen_hint_uk' => $spotFundsItemHint,
      'price_feed' => [
        'age_sec' => $priceAge,
        'max_age_sec' => $maxAge,
        'fresh' => $priceFresh,
        'mid' => ws_cached_price_php($symbol) > 0 ? ws_cached_price_php($symbol) : null,
      ],
      'fba' => (float)($st['fba'] ?? 0),
      'tp_whole' => $tpw,
      'cb_whole' => (float)($st['cb_whole'] ?? 0),
      'global_cb' => (float)($st['global_cb'] ?? 0),
      'last_tick_at' => $lastTickAt !== '' ? $lastTickAt : null,
      'engine' => $engineBlock,
      'v2_canary' => $isV2Canary,
      'v2_signal' => $isV2Canary,
      'targets' => $targets,
      'open_sub_sells' => $openSubSells,
      'lots' => ($lotsSnap && (float)($lotsSnap['bag_qty'] ?? 0) > 1e-12) ? [
        'core' => [
          'qty' => round((float)$lotsSnap['core_qty'], 8),
          'basis' => round((float)$lotsSnap['core_basis'], 8),
          'cost' => round((float)$lotsSnap['core_cost'], 8),
          'tp_price' => ($tpw > 0 && (float)$lotsSnap['core_basis'] > 0)
            ? round((float)$lotsSnap['core_basis'] * (1 + $tpw / 100), 8)
            : null,
          'source' => (string)($lotsSnap['source'] ?? ''),
          'levels' => $lotsSnap['core_levels'] ?? [],
        ],
        'subs' => $lotsSnap['subs'] ?? [],
        'bag' => [
          'qty' => round((float)$lotsSnap['bag_qty'], 8),
          'basis' => round((float)$lotsSnap['bag_basis'], 8),
          'cost' => round((float)$lotsSnap['bag_cost'], 8),
        ],
      ] : null,
      'ladder_missed' => $ladderMissed,

      'position' => [
        'is_open' => $is_open_ui,
        'qty' => $qty_pos,
        'qty_spot' => $qty_spot,
        'qty_mytrades' => $qty_mytrades,
        'qty_exchange' => $qty_spot,
        'qty_display' => $qty_display,
        'avg_price_fill' => $avg_fill,
        'avg_price_engine' => ($avg_pos > 0 ? $avg_pos : ($base_pos > 0 ? $base_pos : null)),
        'base_price_anchor' => ($base_pos > 0 ? $base_pos : null),
        'cost_avg' => ($cost_avg > 0 ? $cost_avg : null),
        'grid_anchor' => ($grid_anchor > 0 ? $grid_anchor : null),
        'origin' => $origin !== '' ? $origin : 'strim',
        'adopt_mode' => $adopt_mode !== '' ? $adopt_mode : null,
        'legacy_qty' => $legacy_qty,
        'last_price' => $last_price,
        'value_usdt' => $value_usdt,
        'out_of_sync' => $out_of_sync,
        'spot_bag_mixed' => $spotExceedsStrategyLot,
        'qty_strategy_lot' => $qty_strategy_lot > 0 ? $qty_strategy_lot : null,
      ],

      'pnl' => [
        'unrealized_usdt' => $unr,
        'unrealized_pct' => $unr_pct,
        'unrealized_vs_grid_usdt' => $unr_grid,
        'unrealized_vs_grid_pct' => $unr_grid_pct,
        'basis' => $pnl_basis,
        'realized_usdt' => $realized,
      ],

      // next: will be filled later by engine_view (we keep contract stable now)
      'next' => [
        'human_lines' => [],
      ],
    ];
  }
}

$strategySymbols = [];
$sumStrategiesUnr = 0.0;
foreach ($items as $it) {
  $sym = strtoupper((string)($it['symbol'] ?? ''));
  if ($sym !== '') {
    $strategySymbols[$sym] = true;
  }
  if (!empty($it['position']['is_open'])) {
    $sumStrategiesUnr += (float)($it['pnl']['unrealized_usdt'] ?? 0);
  }
}

$legacyOutside = [];
$sumLegacyOutside = 0.0;
$reviewCosts = legacy_review_cost_map($mysqli, $uid);
foreach ($reviewCosts as $sym => $meta) {
  if (isset($strategySymbols[$sym])) {
    continue; // already counted via strategy economic PnL (cost_avg)
  }
  $base = substr($sym, 0, -4);
  $qtySpot = isset($spotBalances[$base]) ? (float)$spotBalances[$base] : 0.0;
  if ($qtySpot <= 1e-12) {
    continue;
  }
  $last = bn_last_price_usdt($sym);
  if ($last <= 0) {
    continue;
  }
  $avgCost = (float)($meta['avg_cost'] ?? 0);
  if ($avgCost <= 1e-18) {
    continue;
  }
  $value = $qtySpot * $last;
  $cost = $qtySpot * $avgCost;
  $unrOut = $value - $cost;
  $pctOut = ($cost > 1e-12) ? (($value / $cost) - 1.0) * 100.0 : 0.0;
  $sumLegacyOutside += $unrOut;
  $legacyOutside[] = [
    'symbol' => $sym,
    'qty' => $qtySpot,
    'avg_cost' => $avgCost,
    'last_price' => $last,
    'value_usdt' => $value,
    'unrealized_usdt' => $unrOut,
    'unrealized_pct' => $pctOut,
    'user_action' => (string)($meta['user_action'] ?? ''),
    'klass' => (string)($meta['klass'] ?? ''),
  ];
}

$pnlTotals = [
  'strategies_unrealized_usdt' => $sumStrategiesUnr,
  'legacy_outside_unrealized_usdt' => $sumLegacyOutside,
  'full_unrealized_usdt' => $sumStrategiesUnr + $sumLegacyOutside,
  'legacy_outside_count' => count($legacyOutside),
];

echo json_encode([
  'ok' => true,
  'spot_funds_frozen' => $spotFundsFrozen,
  'spot_funds_freeze' => $spotFundsFreezeState,
  'ts' => date('Y-m-d H:i:s'),
  'items' => $items,
  'legacy_outside' => $legacyOutside,
  'pnl_totals' => $pnlTotals,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
