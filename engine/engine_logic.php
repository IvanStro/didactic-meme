<?php

if (!defined('SAFE_MODE')) {
    define('SAFE_MODE', true);
}


if (!function_exists('ws_ticker_price')) {
    function ws_ticker_price($symbol) {
        // тимчасово беремо через REST
        $url = "https://api.binance.com/api/v3/ticker/price?symbol=" . urlencode($symbol);
        $json = @file_get_contents($url);
        if (!$json) return 0.0;
        $data = json_decode($json, true);
        return (float)($data['price'] ?? 0);
    }
}

/**
 * Min low of recent 1m candles — для CB між тіками (snapshot ticker часто пропускає dip).
 */
if (!function_exists('ws_recent_low_1m')) {
    function ws_recent_low_1m(string $symbol, int $limit = 30): float
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return 0.0;
        }
        $limit = max(2, min(120, $limit));
        $url = 'https://api.binance.com/api/v3/klines?symbol=' . urlencode($symbol) . '&interval=1m&limit=' . $limit;
        $json = @file_get_contents($url);
        if (!$json) {
            return 0.0;
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return 0.0;
        }
        $low = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[3])) {
                continue;
            }
            $cLow = (float)$row[3];
            if ($cLow <= 0.0) {
                continue;
            }
            if ($low <= 0.0 || $cLow < $low) {
                $low = $cLow;
            }
        }
        return $low;
    }
}

/**
 * Max high of recent 1m candles — для SUB take-profit між тіками (WS snapshot часто пропускає spike).
 */
if (!function_exists('ws_recent_high_1m')) {
    function ws_recent_high_1m(string $symbol, int $limit = 30): float
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return 0.0;
        }
        $limit = max(2, min(120, $limit));
        $url = 'https://api.binance.com/api/v3/klines?symbol=' . urlencode($symbol) . '&interval=1m&limit=' . $limit;
        $json = @file_get_contents($url);
        if (!$json) {
            return 0.0;
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return 0.0;
        }
        $high = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[2])) {
                continue;
            }
            $cHigh = (float)$row[2];
            if ($cHigh <= 0.0) {
                continue;
            }
            if ($cHigh > $high) {
                $high = $cHigh;
            }
        }
        return $high;
    }
}


if (!function_exists('logx')) {
    function logx($msg) {
        echo "[LOG] " . $msg . "\n";
    }
}

/**
 * Telegram anti-spam for repetitive strategy messages (cross-worker via app_settings).
 */
function tg_notify_for_strategy_throttled(
    mysqli $db,
    int $strategyId,
    string $dedupeKey,
    string $text,
    int $cooldownSec = 900,
    array $opts = []
): array {
    $sid = (int)$strategyId;
    if ($sid <= 0) return [false, 'bad strategy_id'];

    $cooldown = max(1, (int)$cooldownSec);
    $key = 'tg_th_' . substr(sha1($sid . '|' . $dedupeKey), 0, 28);
    $now = time();

    $keyEsc = $db->real_escape_string($key);
    $res = $db->query("SELECT v FROM app_settings WHERE k='$keyEsc' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $lastTs = $row ? (int)($row['v'] ?? 0) : 0;

    if ($lastTs > 0 && ($now - $lastTs) < $cooldown) {
        return [false, 'throttled'];
    }

    $tsStr = (string)$now;
    $stmt = $db->prepare("
        INSERT INTO app_settings (k, v)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('ss', $key, $tsStr);
    $stmt->execute();

    return tg_notify_for_strategy($db, $sid, $text, $opts);
}

/**
 * Send Telegram only when strategy state marker changes.
 * Marker is persisted in app_settings, so it survives worker restarts.
 */
function tg_notify_for_strategy_on_state_change(
    mysqli $db,
    int $strategyId,
    string $scope,
    string $stateMarker,
    string $text,
    array $opts = []
): array {
    $sid = (int)$strategyId;
    if ($sid <= 0) return [false, 'bad strategy_id'];
    $scope = trim($scope);
    if ($scope === '') $scope = 'default';
    $stateMarker = trim($stateMarker);
    if ($stateMarker === '') return [false, 'empty_state'];

    $key = 'tg_st_' . substr(sha1($sid . '|' . $scope), 0, 28);
    $keyEsc = $db->real_escape_string($key);
    $res = $db->query("SELECT v FROM app_settings WHERE k='$keyEsc' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $prev = (string)($row['v'] ?? '');
    if ($prev === $stateMarker) {
        return [false, 'state_unchanged'];
    }

    $stmt = $db->prepare("
        INSERT INTO app_settings (k, v)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('ss', $key, $stateMarker);
    $stmt->execute();

    return tg_notify_for_strategy($db, $sid, $text, $opts);
}

function tg_strategy_state_marker_set(mysqli $db, int $strategyId, string $scope, string $stateMarker): void {
    $sid = (int)$strategyId;
    if ($sid <= 0) return;
    $scope = trim($scope);
    if ($scope === '') $scope = 'default';
    $key = 'tg_st_' . substr(sha1($sid . '|' . $scope), 0, 28);
    $stmt = $db->prepare("
        INSERT INTO app_settings (k, v)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('ss', $key, $stateMarker);
    $stmt->execute();
}


require_once __DIR__ . '/../www/api/_user_keys.php'; // BINANCE_QTY_SYNC_GLOBAL_V1

/**
 * Trading engine V3 (під твою логіку A+B+C)
 *
 * BUY0  – стартова покупка (L0), НЕ вважається рівнем.
 * L1..L4 – core-рівні, входять в основну позицію.
 * L5..L70 – sub-рівні, живуть окремо, продаються по своїх TP.
 *
 * ВАЖЛИВО:
 *  - MAIN SELL CORE спрацьовує ТІЛЬКИ коли:
 *      * Немає відкритих L5+ (усі sub level>=5 закриті)
 *      * core_avg дає TP Whole (tp_whole)
 *  - MAIN SELL CORE продає тільки coreQty (ядро), L5+ не чіпає.
 *  - SUB SELLS працює лише по L>=5 (L5–L70) і закриває кожну сабу окремо по її TP.
 */

// LEDGER_V1_HELPER
function ledger_write(mysqli $db, int $sid, string $event_type, ?int $level_idx, string $side, ?array $order, float $price, float $qty, float $quote_usdt, float $fee=0.0, ?string $fee_asset=null, ?float $basis_price=null, ?float $pnl_usdt=null, ?float $pnl_pct=null, ?array $meta=null): void {
    $sr = $db->query("SELECT user_id, symbol FROM strategies WHERE id=$sid LIMIT 1");
    $srow = $sr ? $sr->fetch_assoc() : null;
    $uid = (int)($srow['user_id'] ?? 0);
    $symbol = (string)($srow['symbol'] ?? '');

    $order_id = null;
    $client_order_id = null;
    if (is_array($order)) {
        if (isset($order['orderId'])) $order_id = (int)$order['orderId'];
        if (isset($order['clientOrderId'])) $client_order_id = (string)$order['clientOrderId'];
    }

    if ($order_id !== null && $order_id > 0) {
        $dup = $db->query("SELECT id FROM trade_ledger WHERE strategy_id={$sid} AND order_id={$order_id} LIMIT 1");
        if ($dup && $dup->fetch_assoc()) {
            return;
        }
    }
    if ($event_type === 'SELL_SUB' && is_array($meta) && isset($meta['sub_position_id'])) {
        $spId = (int)$meta['sub_position_id'];
        if ($spId > 0) {
            $needle = '"sub_position_id":' . $spId;
            $needleEsc = $db->real_escape_string($needle);
            $dupSp = $db->query(
                "SELECT id FROM trade_ledger
                 WHERE strategy_id={$sid} AND event_type='SELL_SUB' AND meta LIKE '%{$needleEsc}%'
                 LIMIT 1"
            );
            if ($dupSp && $dupSp->fetch_assoc()) {
                return;
            }
        }
    }
    if ($event_type === 'SELL_MAIN' && ($order_id === null || $order_id <= 0)) {
        $dupMain = $db->query(
            "SELECT id FROM trade_ledger
             WHERE strategy_id={$sid} AND event_type='SELL_MAIN'
               AND ROUND(COALESCE(price,0), 12) = ROUND(" . (float)$price . ", 12)
               AND ROUND(COALESCE(qty,0), 12) = ROUND(" . (float)$qty . ", 12)
               AND created_at >= (NOW() - INTERVAL 120 SECOND)
             LIMIT 1"
        );
        if ($dupMain && $dupMain->fetch_assoc()) {
            return;
        }
    }

    $lvl = is_null($level_idx) ? "NULL" : (string)(int)$level_idx;
    $oid = is_null($order_id) ? "NULL" : (string)(int)$order_id;

    $cid = $client_order_id !== null ? "'" . $db->real_escape_string($client_order_id) . "'" : "NULL";
    $feeA = $fee_asset !== null ? "'" . $db->real_escape_string($fee_asset) . "'" : "NULL";
    $basis = $basis_price !== null ? (string)(float)$basis_price : "NULL";
    $pnlU = $pnl_usdt !== null ? (string)(float)$pnl_usdt : "NULL";
    $pnlP = $pnl_pct !== null ? (string)(float)$pnl_pct : "NULL";
    $meta_json = $meta ? "'" . $db->real_escape_string(json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) . "'" : "NULL";

    $ev = $db->real_escape_string($event_type);
    $sy = $db->real_escape_string($symbol);
    $sd = $db->real_escape_string($side);

    $db->query("
      INSERT INTO trade_ledger
        (user_id, strategy_id, symbol, event_type, level_idx, side, order_id, client_order_id,
         price, qty, quote_usdt, fee, fee_asset, basis_price, pnl_usdt, pnl_pct, meta)
      VALUES
        ($uid, $sid, '$sy', '$ev', $lvl, '$sd', $oid, $cid,
         $price, $qty, $quote_usdt, $fee, $feeA, $basis, $pnlU, $pnlP, $meta_json)
    ");

    // Percent billing: 10% of positive realized PnL → platform wallet (idempotent per trade_ledger.id).
    $newLedgerId = (int)$db->insert_id;
    if (
        $newLedgerId > 0
        && $uid > 0
        && $pnl_usdt !== null
        && (float)$pnl_usdt > 1e-12
        && (strtoupper($side) === 'SELL' || str_starts_with(strtoupper($event_type), 'SELL_'))
    ) {
        $walletFile = dirname(__DIR__) . '/www/api/_billing_wallet.php';
        if (is_file($walletFile)) {
            try {
                require_once $walletFile;
                if (function_exists('billing_wallet_charge_profit_fee')) {
                    billing_wallet_charge_profit_fee($db, $uid, $newLedgerId, (float)$pnl_usdt);
                }
            } catch (Throwable $e) {
                logx('[BILLING_FEE] sid=' . $sid . ' ledger=' . $newLedgerId . ' ERR ' . $e->getMessage());
            }
        }
    }
}


// BINANCE_QTY_SYNC_GLOBAL_V1
function _bqs_base_asset(string $symbol): string {
  $base = $symbol;
  foreach (['USDT','BUSD','USDC','BTC','ETH'] as $q) {
    $qlen = strlen($q);
    if ($qlen>0 && strlen($base)>$qlen && strcasecmp(substr($base, -$qlen), $q)===0) {
      $base = substr($base, 0, -$qlen);
      break;
    }
  }
  return $base;
}

function _bqs_account_cached(mysqli $db, int $user_id): ?array {
  static $cache = []; // per request/tick
  if (array_key_exists($user_id, $cache)) return $cache[$user_id];

  [$K,$S] = user_keys($db, $user_id);
  if (!$K || !$S) { $cache[$user_id] = null; return null; }

  try {
    $acc = null;
    if (function_exists('scaling_binance_account')) {
      $acc = scaling_binance_account($K, $S, false);
    }
    if (!$acc) {
      $acc = exi_get_account($K, $S);
    }
    $cache[$user_id] = $acc;
    return $acc;
  } catch (Throwable $e) {
    $cache[$user_id] = null;
    return null;
  }
}

function _bqs_sync_qty_for_strategy(mysqli $db, array $st): void {
  $sid = (int)($st['id'] ?? 0);
  $uid = (int)($st['user_id'] ?? 0);
  $symbol = (string)($st['symbol'] ?? '');
  if ($sid <= 0 || $uid <= 0 || $symbol === '') return;

  $acc = _bqs_account_cached($db, $uid);
  if (!$acc) return;

  $base = _bqs_base_asset($symbol);

  $balQty = 0.0;
  foreach (($acc['balances'] ?? []) as $b) {
    if (($b['asset'] ?? '') === $base) {
      $balQty = (float)($b['free'] ?? 0) + (float)($b['locked'] ?? 0);
      break;
    }
  }

  $pos = $db->query("SELECT id, qty FROM positions WHERE strategy_id=$sid ORDER BY id DESC LIMIT 1");
  if (!$pos) return;
  $row = $pos->fetch_assoc();
  if (!$row) return;

  $pid = (int)($row['id'] ?? 0);
  if ($pid <= 0) return;

  $dbQty = (float)($row['qty'] ?? 0);
  if (abs($balQty - $dbQty) < 0.000001) return;

  $qtySql = number_format($balQty, 18, '.', '');
  $db->query("UPDATE positions SET qty=$qtySql, updated_at=NOW() WHERE id=$pid");
}



function run_all_strategies(mysqli $db, string $apiKey, string $secret): void {
    logx("=== ENGINE v3 START ===");

    $sql = "SELECT * FROM strategies WHERE status IN ('running','paused') ORDER BY id ASC";
    $res = $db->query($sql);
    if (!$res) {
        logx("[ERR] DB error reading strategies: ".$db->error);
        return;
    }
    while ($st = $res->fetch_assoc()) {
    // BINANCE_QTY_SYNC_GLOBAL_V1: always sync qty from Binance (per user, cached per tick)
    _bqs_sync_qty_for_strategy($db, $st);
        // paused: не пропускаємо тік — process_strategy сам блокує лише нові входи (BUY0, сітку)

        $sid    = (int)$st['id'];
        $uid    = (int)($st['user_id'] ?? 0);
        $symbol = (string)$st['symbol'];
        $fba    = isset($st['fba'])      ? (float)$st['fba']      : 0.0;
        // Не купувати «пил»: ефективний FBA не нижче StrIM floor (біржа ~5 USDT на продаж).
        if ($fba > 0.0 && function_exists('strim_min_fba_usdt')) {
            $fba = max($fba, (float)strim_min_fba_usdt());
        }
        $tpw    = isset($st['tp_whole']) ? (float)$st['tp_whole'] : 0.0;
        $cbw    = isset($st['cb_whole']) ? (float)$st['cb_whole'] : 0.0;

        if ($uid <= 0) {
            logx("[SKIP] Strategy $sid has no user_id");
            continue;
        }

        [$uApiKey, $uSecret] = user_keys($db, $uid);
        if (!$uApiKey || !$uSecret) {
            logx("[SKIP] Strategy $sid user $uid has no Binance keys");
            continue;
        }

        logx(">>> Strategy $sid for user $uid symbol $symbol");

        try {
            process_strategy($db, $sid, $uid, $symbol, $fba, $tpw, $cbw, $uApiKey, $uSecret);
        } catch (Throwable $ex) {
            logx("[EXCEPTION] Strategy $sid ($symbol): ".$ex->getMessage());
            tg_notify("❌ ENGINE EXCEPTION\n".$ex->getMessage());
        }
    }

    logx("=== ENGINE v3 END ===");
}

/**
 * Повний цикл для однієї стратегії.
 * @return bool true when tick ran; false when strategy lock was busy (caller must NOT mark job done).
 */
function process_strategy(
    mysqli $db,
    int $sid,
    int $uid,
    string $symbol,
    float $fba,
    float $tpw,
    float $cbw,
    string $apiKey,
    string $secret,
    string $tickMode = 'full',
    ?float $priceOverride = null
): bool {
    $isLight = ($tickMode === 'light');
    logx("[STRAT-$sid][$symbol] Start" . ($isLight ? ' [LIGHT]' : ' [FULL]'));

    $stratLock = 'strat_proc_' . $sid;
    $stratLockEsc = $db->real_escape_string($stratLock);
    $stratLockGot = 0;
    $stratLockRes = $db->query("SELECT GET_LOCK('{$stratLockEsc}', 0) AS g");
    if ($stratLockRes && ($stratLockRow = $stratLockRes->fetch_assoc())) {
        $stratLockGot = (int)($stratLockRow['g'] ?? 0);
    }
    if ($stratLockGot !== 1) {
        logx("[STRAT-$sid][$symbol] SKIP strategy lock busy");
        return false;
    }

    try {
    if (!function_exists('spot_funds_user_is_frozen')) {
        require_once __DIR__ . '/spot_funds_freeze.php';
    }
    if (!function_exists('profit_reinvest_buy_guard')) {
        require_once __DIR__ . '/profit_reinvest.php';
    }

    $pauseBlocksNewBuys = false;
    $rsPause = $db->query("SELECT status FROM strategies WHERE id=$sid LIMIT 1");
    if ($rsPause && ($rpRow = $rsPause->fetch_assoc())) {
        $pauseBlocksNewBuys = (($rpRow['status'] ?? '') === 'paused');
    }
    $fundsFrozen = spot_funds_user_is_frozen($db, $uid);
    $blocksNewBuys = $pauseBlocksNewBuys || $fundsFrozen;
    $didUnfreezeThisTick = false;
    if ($pauseBlocksNewBuys) {
        logx("[STRAT-$sid][$symbol] PAUSE_HOLD: нові BUY0/докупки вимкнені, виходи (TP/SELL) активні");
    } elseif ($fundsFrozen) {
        logx("[STRAT-$sid][$symbol] SPOT_FUNDS_FROZEN: докупки вимкнені до поповнення USDT, виходи (TP/SELL) активні");
    }

    if (!$isLight && $fundsFrozen) {
        spot_funds_try_unfreeze_user($db, $uid, $symbol, $apiKey, $secret, $fba);
        if (!spot_funds_user_is_frozen($db, $uid)) {
            $fundsFrozen = false;
            $blocksNewBuys = $pauseBlocksNewBuys;
            $didUnfreezeThisTick = true;
            logx("[STRAT-$sid][$symbol] SPOT_FUNDS_UNFROZEN this tick");
        }
    }

    // 1. Поточна ціна (optional override = той самий тік, що вже бачив thin shadow)
    $price = ($priceOverride !== null && $priceOverride > 0.0) ? (float)$priceOverride : 0.0;
    if ($price <= 0.0) {
        $price = ws_ticker_price($symbol);
        if ($price <= 0) {
            $price = exi_get_price($symbol, $apiKey, $secret);
            logx("[STRAT-$sid][$symbol] WS fail, REST=$price");
        }
    }
    if ($price <= 0) {
        logx("[STRAT-$sid][$symbol] SKIP: price invalid");
        return true;
    }

    // 2. Позиція
    $pos = get_position($db, $sid, $symbol, $uid);
    if (!$pos) {
        $pos = ensure_position($db, $sid, $uid);
        // NO_EMPTY_POSITION_V1: do not create empty positions before BUY0
    } else {
        logx("[STRAT-$sid][$symbol] Position loaded");
    }

    // 3. Sync з трейдами (light tick — лише БД; full — myTrades + adopt)
    if ($isLight) {
        $pos2 = $pos ?: ensure_position($db, $sid, $uid);
        if (!$pos2) {
            $pos2 = ensure_position($db, $sid, $uid);
        }
        $qty = isset($pos2['qty']) ? (float)$pos2['qty'] : 0.0;
        $avg = isset($pos2['avg_price']) ? (float)$pos2['avg_price'] : 0.0;
        // LIGHT_STALE_QTY: qty=0 у БД при відкритих sub → повний sync (інакше хибний BUY0 / пропуск ladder).
        if ($qty <= 0.00000001 && position_cycle_active($db, $pos2)) {
            logx("[STRAT-$sid][$symbol] LIGHT stale qty=0 with open subs -> force FULL sync");
            if (function_exists('scaling_request_full_tick')) {
                scaling_request_full_tick($sid);
            }
            $isLight = false;
        }
    }
    if (!$isLight) {
    $pos2 = sync_from_trades($db, $sid, $symbol, $apiKey, $secret, $uid);
    if ($pos2) {
        $pidSync = (int)($pos2['id'] ?? 0);
        if ($pidSync > 0 && position_resync_qty_from_open_subs($db, $pidSync)) {
            $pos2 = ensure_position($db, $sid, $uid) ?: $pos2;
        }
    }
    if ($pos2 && isset($pos2['qty'], $pos2['avg_price'])) {

    // FORCE ADOPT IF DB LOST POSITION
    if ((float)$pos2['qty'] > 0 && ((float)($pos['qty'] ?? 0)) == 0) {
        logx("[ADOPT_FIX] restoring position from Binance qty=".$pos2['qty']);
        $sid_i = (int)$sid;
        $qtySql = number_format((float)$pos2['qty'], 18, '.', '');
        $avgSql = number_format((float)$pos2['avg_price'], 18, '.', '');
        $db->query("UPDATE positions SET qty=$qtySql, avg_price=$avgSql, entry_price=$avgSql, state='open' WHERE strategy_id=$sid_i");
    }
        $qty = (float)$pos2['qty'];
        $avg = (float)$pos2['avg_price'];
        
        // DUST_FILTER_ADOPT_V1
        // If remaining position value is tiny (dust), treat as zero to allow new BUY0 cycle.
        $dust_usdt = 1.00; // threshold in USDT
        $mark = 0.0;
        try { $mark = (float)ws_ticker_price($symbol); } catch (Throwable $e) { $mark = 0.0; }
        if ($mark <= 0) {
            try { $mark = (float)exi_get_price($symbol); } catch (Throwable $e) { $mark = 0.0; }
        }
        $pidForDust = (int)($pos2['id'] ?? 0);
        $openSubCost = ($pidForDust > 0) ? position_open_sub_cost_sum($db, $pidForDust) : 0.0;
        if ($qty > 0 && $mark > 0 && ($qty * $mark) < $dust_usdt && $openSubCost < $dust_usdt) {
            logx("[$symbol] DUST_FILTER: qty=$qty mark=$mark notional=" . ($qty*$mark) . " < $dust_usdt -> treat as flat");
            $qty = 0.0;
            $avg = 0.0;

            // DUST_FILTER_PERSIST_V1
            // Persist dust-as-flat to DB so UI/engine_view won't show a phantom position.
            $pidDust = (int)($pos2['id'] ?? 0);
            if ($pidDust <= 0) {
                $pickDust = position_pick_row($db, $sid, $uid);
                $pidDust = $pickDust ? (int)($pickDust['id'] ?? 0) : 0;
            }
            if ($pidDust > 0) {
                $db->query("UPDATE sub_positions SET closed=1 WHERE position_id=$pidDust AND closed=0");
                $legacyDust = function_exists('position_legacy_reset_sql') ? position_legacy_reset_sql($db) : '';
                $db->query("UPDATE positions
                            SET state='closed',
                                qty=0,
                                sub_assigned_qty=0,
                                invested_usdt=0,
                                avg_price=0,
                                entry_price=0,
                                base_price=0,
                                last_buy=0,
                                next_ptr=1,
                                last_peak=0,
                                updated_at=NOW()
                                $legacyDust
                            WHERE id=$pidDust");
                // Keep a real position row so BUY0 handler can run in the same tick.
                $pos2 = ensure_position($db, $sid, $uid);

            }


        }

logx("[$symbol] adopt account qty=$qty avg=$avg");

        // ADOPT_EXTERNAL_BUY: NEVER merge exchange bag into strategy qty/avg.
        // External residual (manual buys / leftover coins) must not pollute basis or MAIN sell size.
        // Notice-only path: sync_post_tick_external_buy_check → SYNC_EXTERNAL_BUY journal.
        try {
            $localQty = isset($pos2['qty']) ? (float)$pos2['qty'] : 0.0;
            $binQty   = (float)$qty;
            $binAvg   = (float)$avg;
            if ($binQty > $localQty + 1e-8) {
                $delta = $binQty - $localQty;
                $cycleInv = function_exists('sync_ledger_open_cycle_inventory')
                    ? sync_ledger_open_cycle_inventory($db, $sid)
                    : ['qty' => $localQty];
                $tracked = (float)($cycleInv['qty'] ?? $localQty);
                if ($binQty > $tracked + max(1e-6, $tracked * 0.03)) {
                    logx("[$symbol][ADOPT_EXT][BLOCKED] delta=$delta bin=$binQty tracked=$tracked avg=$binAvg (no position write)");
                }
            }
        } catch (Throwable $e) {
            logx("[$symbol][ADOPT_EXT][ERR] " . $e->getMessage());
        }

    } else {
        $pos2 = $pos;
        $qty  = isset($pos2['qty'])       ? (float)$pos2['qty']       : 0.0;
        $avg  = isset($pos2['avg_price']) ? (float)$pos2['avg_price'] : 0.0;
    }
    } // end full sync branch

    // ORPHAN_CORE_TO_LADDER: qty на біржі/в positions без open sub (зайві BUY0) → злиття у верхній рівень (L4).
    if (is_array($pos2) && $pos2 && position_reconcile_orphan_core_to_ladder($db, $sid, $uid, $tpw, $cbw, $price)) {
        $pos2 = ensure_position($db, $sid, $uid) ?: $pos2;
    }

    // 4. SUB-sells (L5+)
    handle_sub_sells($db, $sid, $symbol, $uid, $pos2, $price, $apiKey, $secret);

    // 5. MAIN SELL CORE (L0–L4): arm on TP, execute on CB pullback
    handle_main_sell_core($db, $sid, $symbol, $uid, $pos2, $price, $tpw, $cbw, $apiKey, $secret);

    // Після MAIN SELL CORE позиція в БД уже qty=0, але $pos2 — snapshot на початок тіку.
    // Без reload BUY0 бачить qty>0 і мовчки пропускається в тому ж тіку.
    $posReload = ensure_position($db, $sid, $uid);
    if (is_array($posReload) && $posReload) {
        $pos2 = $posReload;
    }

    // LEGACY exit_plan: лише виходи, без нових докупок / BUY0
    if (is_array($pos2) && $pos2 && function_exists('position_legacy_blocks_new_buys') && position_legacy_blocks_new_buys($pos2)) {
        $blocksNewBuys = true;
        logx("[STRAT-$sid][$symbol] LEGACY_EXIT_PLAN: нові покупки вимкнені, виходи (TP/SELL) активні");
    }

    // 6. BUY0 (старт) – якщо позиції нема (на паузі не відкриваємо новий цикл)
    handle_buy0_start($db, $sid, $symbol, $uid, $pos2, $price, $fba, $tpw, $cbw, $apiKey, $secret, $blocksNewBuys);

    // 6b. Burst після розморозки USDT (варіант A) — до звичайного catch-up/ladder
    $skipLadderCatchup = false;
    if (!$blocksNewBuys && !$isLight && is_array($pos2) && $pos2) {
        if (!function_exists('ladder_unfreeze_burst_should_run')) {
            require_once __DIR__ . '/ladder_unfreeze_burst.php';
        }
        if (ladder_unfreeze_burst_should_run($db, $uid, $sid)) {
            $skipLadderCatchup = true;
            $burstN = ladder_unfreeze_burst_execute(
                $db,
                $sid,
                $symbol,
                $uid,
                $pos2,
                $price,
                $fba,
                $tpw,
                $cbw,
                $apiKey,
                $secret
            );
            ladder_unfreeze_burst_mark_strategy_done($db, $uid, $sid);
            if ($burstN > 0) {
                $posReloadBurst = ensure_position($db, $sid, $uid);
                if (is_array($posReloadBurst) && $posReloadBurst) {
                    $pos2 = $posReloadBurst;
                }
            }
            logx("[STRAT-$sid][$symbol] UNFREEZE_BURST tick bought={$burstN} skip_catchup=1");
        } elseif ($didUnfreezeThisTick) {
            // Розморозка на цьому тіку, але burst уже оброблено для sid — не змішувати з catch-up.
            $skipLadderCatchup = true;
        }
    }

    // 7. Ladder (L1..L70) — на паузі / заморозці докупки вимкнені
    handle_buy_ladder($db, $sid, $symbol, $uid, $pos2, $price, $fba, $tpw, $cbw, $apiKey, $secret, $blocksNewBuys, $skipLadderCatchup);

    // Після BUY0/L — перевірка зовнішніх купівель (лише full tick; потребує myTrades cache).
    if (!$isLight) {
        sync_post_tick_external_buy_check($db, $sid, $symbol, $uid);
    }

    if (!function_exists('engine_shadow_enabled')) {
        $modeFile = __DIR__ . '/../engine_v2/engine_mode.php';
        if (is_file($modeFile)) {
            require_once $modeFile;
        }
    }
    if (function_exists('engine_shadow_enabled') && engine_shadow_enabled()) {
        $evalFile = __DIR__ . '/signal/eval_strategy.php';
        if (is_file($evalFile)) {
            require_once $evalFile;
        }
    }
    if (function_exists('engine_shadow_enabled') && engine_shadow_enabled() && function_exists('signal_shadow_log')) {
        // null = свіжа позиція з БД (як signal_daemon), не snapshot на початок тіку
        signal_shadow_log($db, $sid, $symbol, $uid, $price, $tpw, $cbw, $fba, null);
    }

    logx("[STRAT-$sid][$symbol] End");
    } finally {
        $db->query("SELECT RELEASE_LOCK('{$stratLockEsc}')");
    }
    return true;
}

/**
 * SUB SELLS для рівнів L>=5:
 * продаємо тільки саб-рівні (L5..L70) по їх TP.
 */
function handle_sub_sells(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    array $pos,
    float $price,
    string $apiKey,
    string $secret
): void {
    if (!$pos) return;

    $pid  = (int)$pos['id'];
    $subs = open_subpos($db, $pid, 5); // тільки L>=5

    if (!$subs || !is_array($subs) || !count($subs)) return;

    // Execute SUB TP on live spot only. signal_eval may wake via ws_recent_high_1m,
    // but filling MARKET after a reverted spike produced tiny PnL (e.g. 0.17% vs 1.6% TP).
    $soldSomething = false;

    foreach ($subs as $sp) {
        $spId = (int)$sp['id'];
        $lvl  = (int)$sp['level_idx'];
        $sq   = (float)$sp['qty'];
        $bp   = (float)$sp['buy_price'];
        $tp   = isset($sp['tp']) ? (float)$sp['tp'] : 0.0;
        $ord  = null;
        $soldSub = false;

        if ($sq <= 0.0 || $bp <= 0.0) {
            continue;
        }

        $pl_pct = ($price / $bp - 1.0) * 100.0;
        if ($tp <= 0.0 || $pl_pct < $tp) {
            continue;
        }

        $needleEsc = $db->real_escape_string('"sub_position_id":' . $spId);
        $ledSp = $db->query(
            "SELECT id FROM trade_ledger
             WHERE strategy_id={$sid} AND event_type='SELL_SUB' AND meta LIKE '%{$needleEsc}%'
             LIMIT 1"
        );
        if ($ledSp && $ledSp->fetch_assoc()) {
            logx("[$symbol] SUB L$lvl SELL SKIP already in ledger spId=$spId");
            $db->query("UPDATE sub_positions SET closed=1 WHERE id={$spId} AND closed=0");
            continue;
        }

        $lockName = 'sub_sell_' . $sid . '_' . $spId;
        $lockEsc = $db->real_escape_string($lockName);
        $lockGot = 0;
        $lockRes = $db->query("SELECT GET_LOCK('{$lockEsc}', 0) AS g");
        if ($lockRes && ($lockRow = $lockRes->fetch_assoc())) {
            $lockGot = (int)($lockRow['g'] ?? 0);
        }
        if ($lockGot !== 1) {
            logx("[$symbol] SUB L$lvl SELL SKIP lock busy spId=$spId");
            continue;
        }

        try {
            $rowRes = $db->query("SELECT closed FROM sub_positions WHERE id={$spId} FOR UPDATE");
            $rowSub = $rowRes ? $rowRes->fetch_assoc() : null;
            if (!$rowSub || (int)($rowSub['closed'] ?? 0) === 1) {
                logx("[$symbol] SUB L$lvl SELL SKIP sub closed spId=$spId");
                continue;
            }

            $sellQty = exi_normalize_sell_qty($symbol, $sq, $apiKey, $secret);
            if ($sellQty <= 0.0) {
                logx("[$symbol] SUB L$lvl SELL SKIP qty norm=0");
                $px = (float)$price;
                if ($px <= 0.0 && function_exists('exi_get_price')) {
                    $px = (float)exi_get_price($symbol);
                }
                $notional = $sq * max(0.0, $px);
                // Пил < minNotional: злити в сусідній відкритий рівень і закрити, щоб не блокував каскад/алерти.
                if ($notional > 0.0 && $notional < 5.0 && function_exists('ladder_absorb_dust_sub') && $pid > 0) {
                    $abs = ladder_absorb_dust_sub($db, $pid, $spId, $px, 5.0);
                    if (!empty($abs['ok'])) {
                        logx("[$symbol] SUB L$lvl DUST absorbed: " . json_encode($abs, JSON_UNESCAPED_UNICODE));
                        if (function_exists('tg_notify_for_strategy_throttled')) {
                            $toLvl = (int)($abs['to_level'] ?? 0);
                            $txt = $toLvl > 0
                                ? sprintf(
                                    "ℹ️ <b>%s</b> L%d був занадто малий для продажу (~%.2f USDT) — злито в L%d і закрито.",
                                    $symbol,
                                    $lvl,
                                    $notional,
                                    $toLvl
                                )
                                : sprintf(
                                    "ℹ️ <b>%s</b> L%d був занадто малий для продажу (~%.2f USDT) — рівень закрито в обліку.",
                                    $symbol,
                                    $lvl,
                                    $notional
                                );
                            tg_notify_for_strategy_throttled($db, $sid, 'SUB_DUST_ABSORB_L' . $lvl, $txt, 21600);
                        }
                        continue;
                    }
                }
                if ($notional > 0.0 && $notional < 5.0 && function_exists('tg_user_sell_error_message')) {
                    $needPx = ($sq > 0) ? (5.05 / $sq) : 0.0;
                    $txt = tg_user_sell_error_message(
                        $symbol,
                        '{"code":-1013,"msg":"Filter failure: NOTIONAL"}',
                        400,
                        'sub',
                        $lvl
                    );
                    if ($needPx > $px && $px > 0) {
                        $txt .= "\n\nЗараз ~" . number_format($notional, 2, '.', '')
                            . " USDT; продаж пройде орієнтовно від ціни <b>"
                            . rtrim(rtrim(number_format($needPx, 8, '.', ''), '0'), '.')
                            . "</b> (автоматично).";
                    }
                    tg_notify_for_strategy_throttled($db, $sid, 'SUB_SELL_DUST_L' . $lvl, $txt, 21600);
                }
                continue;
            }

            [$h, $r, $e, $er] = place_sell_qty($symbol, $sellQty, $apiKey, $secret);
            if ($e || $h >= 400) {
                logx("[$symbol] SUB L$lvl SELL ERR http=$h body=$r");
                $txt = function_exists('tg_user_sell_error_message')
                    ? tg_user_sell_error_message($symbol, (string)$r, (int)$h, 'sub', $lvl)
                    : sprintf("⚠️ <b>%s</b> — продаж рівня L%d не виконано (біржа відхилила ордер).", $symbol, $lvl);
                tg_notify_for_strategy_throttled($db, $sid, 'SUB_SELL_ERR_L' . $lvl, $txt, 3600);
                continue;
            }

            if (isset($r) && is_string($r)) {
                $tmp = json_decode($r, true);
                if (is_array($tmp)) {
                    $ord = $tmp;
                }
            }
            $orderId = is_array($ord) ? (int)($ord['orderId'] ?? 0) : 0;
            if ($orderId > 0) {
                $dupOrd = $db->query("SELECT id FROM trade_ledger WHERE strategy_id={$sid} AND order_id={$orderId} LIMIT 1");
                if ($dupOrd && $dupOrd->fetch_assoc()) {
                    logx("[$symbol] SUB L$lvl SELL SKIP dup orderId=$orderId");
                    $db->query("UPDATE sub_positions SET closed=1 WHERE id={$spId} AND closed=0");
                    continue;
                }
            }

            $execQty = $sellQty;
            $quoteQ  = 0.0;
            $feeSum  = 0.0;
            $feeAst  = null;
            if (is_array($ord)) {
                if (isset($ord['executedQty']) && (float)$ord['executedQty'] > 0) {
                    $execQty = (float)$ord['executedQty'];
                }
                if (isset($ord['cummulativeQuoteQty'])) {
                    $quoteQ = (float)$ord['cummulativeQuoteQty'];
                }
                if (!empty($ord['fills']) && is_array($ord['fills'])) {
                    foreach ($ord['fills'] as $f) {
                        $feeSum += (float)($f['commission'] ?? 0);
                        $feeAst = (string)($f['commissionAsset'] ?? $feeAst);
                    }
                }
            }

            $pSell = 0.0;
            if (is_array($ord) && !empty($ord['fills']) && is_array($ord['fills'])) {
                $sum = 0.0;
                $w = 0.0;
                foreach ($ord['fills'] as $f) {
                    $fp = (float)($f['price'] ?? 0);
                    $fq = (float)($f['qty'] ?? 0);
                    if ($fp > 0 && $fq > 0) {
                        $sum += $fp * $fq;
                        $w += $fq;
                    }
                }
                if ($w > 0) {
                    $pSell = $sum / $w;
                }
            }
            if ($pSell <= 0 && $quoteQ > 0 && $execQty > 0) {
                $pSell = $quoteQ / $execQty;
            }
            if ($pSell <= 0) {
                $pSell = $price;
            }

            $rp    = ($execQty * $pSell) - ($execQty * $bp);
            $rpPct = $bp > 0 ? (($pSell / $bp - 1.0) * 100.0) : 0.0;
            $feeAstStr = $feeAst !== null ? (string)$feeAst : null;
            if (function_exists('trade_sell_pnl_net') && $bp > 0 && $execQty > 0) {
                $pnlNet = trade_sell_pnl_net($execQty, $bp, $pSell, $feeSum, $feeAstStr, $symbol);
                $rp = (float)$pnlNet['net'];
                $rpPct = (float)$pnlNet['net_pct'];
            }

            logx("[$symbol] SUB L$lvl SELL ok qty=$execQty buy@$bp price=$pSell orderId=$orderId spId=$spId netPnl=$rp fee=$feeSum $feeAst");

            try {
                $lvlN = (int)$lvl;
                $basis = $bp;
                if ($quoteQ <= 0 && $pSell > 0 && $execQty > 0) {
                    $quoteQ = $pSell * $execQty;
                }
                $pnlU = $rp;
                $pnlP = $rpPct;
                ledger_write($db, $sid, 'SELL_SUB', $lvlN, 'SELL', $ord, $pSell, $execQty, $quoteQ, $feeSum, $feeAst, $basis, $pnlU, $pnlP, [
                    'src' => 'sell_sub',
                    'sub_position_id' => $spId,
                    'pnl_net' => true,
                    'exchange_fee_usdt' => function_exists('trade_exchange_fee_usdt')
                        ? trade_exchange_fee_usdt($feeSum, $feeAstStr, $pSell, $symbol, $quoteQ)
                        : null,
                ]);
            } catch (Throwable $e) {
                logx("[$symbol] SUB L$lvl ledger_write ERR " . $e->getMessage());
            }

            $db->query("UPDATE sub_positions SET closed=1 WHERE id={$spId} AND closed=0");

            $feeUShow = function_exists('trade_exchange_fee_usdt')
                ? trade_exchange_fee_usdt($feeSum, $feeAstStr, $pSell, $symbol, $quoteQ)
                : 0.0;
            $feeLine = sprintf('Fee біржі: %.4f USDT', $feeUShow);
            if ($feeUShow <= 1e-12 && $feeSum > 1e-18 && $feeAstStr) {
                $feeLine = sprintf(
                    'Fee біржі: %.8f %s',
                    $feeSum,
                    strtoupper($feeAstStr)
                );
            } elseif ($feeUShow > 1e-12 && $feeAstStr && strtoupper($feeAstStr) !== 'USDT') {
                $feeLine = sprintf(
                    'Fee біржі: %.4f USDT (%.8f %s)',
                    $feeUShow,
                    $feeSum,
                    strtoupper($feeAstStr)
                );
            }
            $sellOrd = function_exists('ladder_sub_sell_ordinal')
                ? ladder_sub_sell_ordinal($db, (int)$sid, (int)$lvl)
                : 1;
            $txt = sprintf(
                "🟢 <b>%s</b> SUB L%d SELL #%d\nQty: %.8f\nBuy: %.8f\nPrice: %.8f\nPnL net: %.2f (%.2f%%)\n%s",
                $symbol,
                $lvl,
                $sellOrd,
                $execQty,
                $bp,
                $pSell,
                $rp,
                $rpPct,
                $feeLine
            );
            ladder_engine_journal_sub_sell(
                $db,
                (int)$sid,
                $symbol,
                (int)$lvl,
                (float)$execQty,
                (float)$bp,
                (float)$pSell,
                (float)$rp,
                (float)$rpPct,
                $sellOrd
            );
            tg_notify_for_strategy_throttled($db, $sid, 'SUB_SELL_L' . $lvl, $txt, 3600, ['skip_journal' => true]);
            $soldSub = true;
            $soldSomething = true;
        } finally {
            $db->query("SELECT RELEASE_LOCK('{$lockEsc}')");
        }

        if (!$soldSub) {
            continue;
        }
    }

    // якщо нічого не продали – нічого не робимо далі
    if (!$soldSomething) {
        return;
    }

    // синхронізуємо позицію після серії продажів
    if (function_exists('scaling_after_trade')) {
        scaling_after_trade($db, $sid, $apiKey, $symbol);
    }
    $posAfter = sync_from_trades($db, $sid, $symbol, $apiKey, $secret, $uid, ['force_fresh' => true]);
    $pidAfter = $pid; // позиція та сама
    // Lot SoT: qty/avg з відкритих лотів (ядро + кожен саб), не з bag-avg біржі.
    if ($pidAfter > 0 && function_exists('position_resync_from_open_lots')) {
        position_resync_from_open_lots($db, $pidAfter);
        $resP = $db->query("SELECT * FROM positions WHERE id={$pidAfter} LIMIT 1");
        if ($resP && ($rowP = $resP->fetch_assoc())) {
            $posAfter = $rowP;
        }
    }

    // ОНОВЛЮЄМО last_buy:
    // беремо останній (по id) відкритий саб (будь-який рівень), якщо такий є
    $resLb = $db->query(
        "SELECT buy_price 
         FROM sub_positions 
         WHERE position_id=$pidAfter AND closed=0 
         ORDER BY id DESC 
         LIMIT 1"
    );

    if ($resLb && ($rowLb = $resLb->fetch_assoc()) && $rowLb['buy_price'] > 0) {
        $lb = (float)$rowLb['buy_price'];
        $lbSql = number_format($lb, 18, '.', '');
        $db->query("
            UPDATE positions 
            SET last_buy=$lbSql, updated_at=NOW() 
            WHERE id=$pidAfter
        ");
        logx("[$symbol] last_buy updated after SUB SELL → $lb");
    } else {
        // якщо відкритих сабів не залишилось – можна обнулити або дати fallback на avg
        $db->query("
            UPDATE positions 
            SET last_buy=0, updated_at=NOW() 
            WHERE id=$pidAfter
        ");
        logx("[$symbol] last_buy reset to 0 after SUB SELL (no open subs)");
    }
}

/**
 * MAIN SELL CORE (trailing):
 *  - якщо є відкриті значимі L5+ → core не продаємо
 *  - коли ціна торкнулася TP (tp_whole), армуємо last_peak
 *  - продаємо coreQty тільки після відкату на cb_whole від peak.
 */
function handle_main_sell_core(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    array $pos,
    float $price,
    float $tp_whole_adj,
    float $cb_whole_adj,
    string $apiKey,
    string $secret
): void {
    if (!$pos) return;

    $pid   = (int)$pos['id'];
    $qty   = (float)$pos['qty'];
    $avg   = (float)$pos['avg_price'];
    $last  = isset($pos['last_buy']) ? (float)$pos['last_buy'] : 0.0;

    if ($qty <= 0.0) return;
    if ($tp_whole_adj <= 0.0) return;

    $epsQty = 0.00000001;

    // 1. L5+ саби: пил закрити; підрахунок відкритого hi_qty (блокує MAIN).
    $hiSubs = open_subpos($db, $pid, 5);
    $hiQtyOpen = 0.0;
    if ($hiSubs && is_array($hiSubs)) {
        foreach ($hiSubs as $hs) {
            $hsId = (int)($hs['id'] ?? 0);
            $hsQ  = (float)($hs['qty'] ?? 0.0);
            if ($hsQ > $epsQty) {
                $hiQtyOpen += $hsQ;
            } elseif ($hsId > 0) {
                $db->query("UPDATE sub_positions SET closed=1 WHERE id=$hsId");
            }
        }
    }

    // 2. Core SoT — лоти L1–L4 (position_lots_snapshot / position_core_basis).
    $coreInfo = position_core_basis($db, $pos);
    $hiQty = (float)($coreInfo['hi_qty'] ?? 0.0);
    $coreQty = (float)($coreInfo['core_qty'] ?? 0.0);
    $basis = (float)($coreInfo['basis'] ?? 0.0);
    $coreBasisSource = (string)($coreInfo['source'] ?? '');

    // CAP qty з trade_ledger: не продавати external bag. Basis з cycle — НІКОЛИ.
    if (function_exists('sync_ledger_open_cycle_inventory')) {
        $cycleInv = sync_ledger_open_cycle_inventory($db, $sid);
        $trackedCore = max(0.0, (float)$cycleInv['qty'] - $hiQty);
        if ($trackedCore > 1e-12 && $coreQty > $trackedCore + max(1e-6, $trackedCore * 0.03)) {
            logx("[$symbol] MAIN SELL CORE CAP qty $coreQty -> tracked $trackedCore (no external bag)");
            $coreQty = $trackedCore;
        }
    }
    logx(
        "[$symbol] MAIN SELL CORE lot-SoT source=%s basis=%s coreQty=%s hiQty=%s",
        $coreBasisSource,
        number_format($basis, 8, '.', ''),
        number_format($coreQty, 8, '.', ''),
        number_format($hiQty, 8, '.', '')
    );

    if ($coreQty <= 0.0) {
        return;
    }
    if ($basis <= 0.0) {
        $basis = $last > 0.0 ? $last : $price;
    }
    if ($basis <= 0.0) {
        return;
    }

    $tpPrice = $basis * (1.0 + $tp_whole_adj / 100.0);

    // 3. Відкриті L5+ → core не продаємо; при досягненні MAIN TP сповіщаємо (throttled).
    if ($hiQtyOpen > $epsQty) {
        logx("[$symbol] MAIN SELL CORE SKIP: high levels still open hi_qty=$hiQtyOpen tpPrice=".number_format($tpPrice, 8, '.', '')." price=".number_format($price, 8, '.', ''));
        if ($price >= $tpPrice) {
            $subLines = [];
            if ($hiSubs && is_array($hiSubs)) {
                foreach ($hiSubs as $hs) {
                    $lv = (int)($hs['level_idx'] ?? 0);
                    if ($lv < 5) {
                        continue;
                    }
                    $bp = (float)($hs['buy_price'] ?? 0.0);
                    $tq = (float)($hs['qty'] ?? 0.0);
                    $tpPct = isset($hs['tp']) ? (float)$hs['tp'] : 0.0;
                    if ($tq <= $epsQty || $bp <= 0.0) {
                        continue;
                    }
                    $sellT = ($tpPct > 0.0) ? ($bp * (1.0 + $tpPct / 100.0)) : 0.0;
                    $subLines[] = sprintf(
                        '• L%d: qty≈%s, TP %s%% → ~%s',
                        $lv,
                        number_format($tq, 6, '.', ''),
                        number_format($tpPct, 2, '.', ''),
                        $sellT > 0.0 ? number_format($sellT, 8, '.', '') : '—'
                    );
                }
            }
            $subDetail = count($subLines) ? implode("\n", $subLines) : '(немає відкритих L5+ рядків)';
            $txt = sprintf(
                "⏸ <b>%s</b> MAIN SELL <b>відкладено</b>\n" .
                "Ціна <code>%s</code> ≥ MAIN TP <code>%s</code> (basis <code>%s</code>, +%s%%).\n" .
                "Спочатку закриття сабів L5+:\n%s",
                $symbol,
                number_format($price, 8, '.', ''),
                number_format($tpPrice, 8, '.', ''),
                number_format($basis, 8, '.', ''),
                number_format((float)$tp_whole_adj, 2, '.', ''),
                $subDetail
            );
            tg_notify_for_strategy_throttled(
                $db,
                $sid,
                'main_sell_deferred_open_l5',
                $txt,
                1200,
                ['skip_journal' => true]
            );
        }
        return;
    }

    $cbPct    = max(0.0, $cb_whole_adj);
    $lastPeak = isset($pos['last_peak']) ? (float)$pos['last_peak'] : 0.0;

    // Перше досягнення TP Whole — arming (last_peak).
    if ($lastPeak <= 0.0) {
        if ($price >= $tpPrice) {
            $lastPeak = $price;
            $lpSql = number_format($lastPeak, 18, '.', '');
            $db->query("UPDATE positions SET last_peak=$lpSql WHERE id=$pid");
            logx("[$symbol] MAIN SELL CORE ARM/PEAK tp=".number_format($tpPrice, 8, '.', '')." peak=".number_format($lastPeak, 8, '.', '')." cb=".$cbPct."%");
        }
        return;
    }

    // Вже armed: спочатку CB (не disarm при відкаті нижче TP — trailing тримає peak).
    $cbTrigger = $cbPct > 0.0 ? ($lastPeak * (1.0 - $cbPct / 100.0)) : $lastPeak;
    $cbPrice = $price;
    $recentLow = ws_recent_low_1m($symbol);
    if ($recentLow > 0.0 && $recentLow < $cbPrice) {
        $cbPrice = $recentLow;
    }

    if ($cbPrice > $cbTrigger) {
        if ($price >= $tpPrice && $price > $lastPeak) {
            $lastPeak = $price;
            $lpSql = number_format($lastPeak, 18, '.', '');
            $db->query("UPDATE positions SET last_peak=$lpSql WHERE id=$pid");
            logx("[$symbol] MAIN SELL CORE ARM/PEAK tp=".number_format($tpPrice, 8, '.', '')." peak=".number_format($lastPeak, 8, '.', '')." cb=".$cbPct."%");
        }
        logx(
            "[$symbol] MAIN SELL CORE WAIT cb price=".number_format($price, 8, '.', '').
            " low1m=".number_format($recentLow, 8, '.', '').
            " trigger=".number_format($cbTrigger, 8, '.', '').
            " peak=".number_format($lastPeak, 8, '.', '')
        );
        return;
    }

    logx(
        "[$symbol] MAIN SELL CORE CB_HIT price=".number_format($price, 8, '.', '').
        " cbPrice=".number_format($cbPrice, 8, '.', '').
        " trigger=".number_format($cbTrigger, 8, '.', '').
        " peak=".number_format($lastPeak, 8, '.', '')
    );

    // 3. Продаємо тільки coreQty
    // CORE_QTY_DEBUG
    logx(
        "[CORE_QTY_DEBUG] symbol=%s pos_qty=%s coreQty=%s avg=%s price=%s",
        $symbol,
        (string)($pos['qty'] ?? 'NULL'),
        (string)$coreQty,
        (string)($pos['avg_price'] ?? 'NULL'),
        (string)$price
    );

    // Orphan warn: core містить qty поза відкритими L1–L4 (як BTC Jan leftover).
    $trackedL14 = 0.0;
    if ($allSubs && is_array($allSubs)) {
        foreach ($allSubs as $sp) {
            $lvl = (int)($sp['level_idx'] ?? 0);
            if ($lvl >= 1 && $lvl <= 4) {
                $trackedL14 += (float)($sp['qty'] ?? 0);
            }
        }
    }
    $coreOrphan = max(0.0, $coreQty - $trackedL14);
    if ($coreOrphan > 1e-8 && ($coreOrphan * $price) >= 5.0 && $trackedL14 > 1e-8) {
        $frac = $coreQty > 0 ? ($coreOrphan / $coreQty) : 0.0;
        logx(sprintf(
            "[%s] MAIN SELL CORE WARN orphan_in_core=%.8f tracked_L1_4=%.8f frac=%.3f",
            $symbol,
            $coreOrphan,
            $trackedL14,
            $frac
        ));
        if ($frac >= 0.15 && function_exists('tg_notify_for_strategy_throttled')) {
            $txt = sprintf(
                "⚠️ <b>%s</b> MAIN SELL CORE: у ядрі ≈%.2f USDT без відкритих L1–L4 (orphan/prior cycle). Продаж іде повним coreQty.",
                $symbol,
                $coreOrphan * $price
            );
            tg_notify_for_strategy_throttled($db, $sid, 'main_sell_orphan_warn', $txt, 1800);
        }
    }

$sellCore = exi_normalize_sell_qty($symbol, $coreQty, $apiKey, $secret);
    
if ($sellCore <= 0.0) {
        logx("[$symbol] MAIN SELL CORE SKIP qty norm=0");
        return;
    }

    [$h, $r, $e, $er] = place_sell_qty($symbol, $sellCore, $apiKey, $secret);
    if ($e || $h >= 400) {
        logx("[$symbol] MAIN SELL CORE ERR http=$h body=$r");
        $txt = function_exists('tg_user_sell_error_message')
            ? tg_user_sell_error_message($symbol, (string)$r, (int)$h, 'main', null)
            : sprintf("⚠️ <b>%s</b> — продаж основної частини позиції не виконано (біржа відхилила ордер).", $symbol);
        tg_notify_for_strategy_throttled($db, $sid, 'MAIN_SELL_CORE_ERR', $txt, 3600);
        return;
    }

    $ordMain = null;
    if (isset($r) && is_string($r)) {
        $tmpMain = json_decode($r, true);
        if (is_array($tmpMain)) {
            $ordMain = $tmpMain;
        }
    }

    // Синхронізуємо позицію після продажу core
    if (function_exists('scaling_after_trade')) {
        scaling_after_trade($db, $sid, $apiKey, $symbol);
    }
    sync_from_trades($db, $sid, $symbol, $apiKey, $secret, $uid, ['force_fresh' => true]);

    // Закриваємо тільки core-саби (L<=4)
    
    // FIX_UNDEFINED_PID_CORE_CLOSE
    // Ensure we have position_id ($pid) for closing core sub-levels (L<=4)
    if (!isset($pid) || (int)$pid <= 0) {
        $qpid = $db->query("SELECT id FROM positions WHERE strategy_id=$sid AND user_id=$uid ORDER BY id DESC LIMIT 1");
        $pid = ($qpid && ($rp = $qpid->fetch_assoc())) ? (int)($rp['id'] ?? 0) : 0;
    }

$db->query("UPDATE sub_positions SET closed=1 WHERE position_id=$pid AND closed=0 AND level_idx<=4");

    // PnL / TG / ledger: фактичний fill з біржі (як SUB SELL), не WS tick що лише тригернув CB.
    // Інакше (tick 9.1555 vs fill 9.133) завищений профіт у журналі/кабінеті.
    $execQty = (float)$sellCore;
    $quoteQ = 0.0;
    $feeSum = 0.0;
    $feeAst = null;
    $pSell = 0.0;
    if (is_array($ordMain)) {
        if (isset($ordMain['executedQty']) && (float)$ordMain['executedQty'] > 0) {
            $execQty = (float)$ordMain['executedQty'];
        }
        if (isset($ordMain['cummulativeQuoteQty'])) {
            $quoteQ = (float)$ordMain['cummulativeQuoteQty'];
        }
        if (!empty($ordMain['fills']) && is_array($ordMain['fills'])) {
            $sum = 0.0;
            $w = 0.0;
            foreach ($ordMain['fills'] as $f) {
                $fp = (float)($f['price'] ?? 0);
                $fq = (float)($f['qty'] ?? 0);
                if ($fp > 0 && $fq > 0) {
                    $sum += $fp * $fq;
                    $w += $fq;
                }
                $feeSum += (float)($f['commission'] ?? 0);
                if (!empty($f['commissionAsset'])) {
                    $feeAst = (string)$f['commissionAsset'];
                }
            }
            if ($w > 0) {
                $pSell = $sum / $w;
            }
        }
    }
    if ($pSell <= 0 && $quoteQ > 0 && $execQty > 0) {
        $pSell = $quoteQ / $execQty;
    }
    if ($pSell <= 0) {
        $pSell = (float)$price;
    }
    if ($quoteQ <= 0 && $pSell > 0 && $execQty > 0) {
        $quoteQ = $pSell * $execQty;
    }

    $rp    = ($execQty * $pSell) - ($execQty * $basis);
    $rpPct = $basis > 0.0 ? (($pSell / $basis - 1.0) * 100.0) : 0.0;
    $feeAstStr = $feeAst !== null ? (string)$feeAst : null;
    $feeUShow = 0.0;
    if (function_exists('trade_sell_pnl_net') && $basis > 0.0 && $execQty > 0.0) {
        $pnlNet = trade_sell_pnl_net($execQty, $basis, $pSell, $feeSum, $feeAstStr, $symbol);
        $rp = (float)$pnlNet['net'];
        $rpPct = (float)$pnlNet['net_pct'];
        $feeUShow = (float)$pnlNet['fee_usdt'];
    }

    logx("[$symbol] MAIN SELL CORE ok qty=$execQty basis=$basis fill=$pSell tick=$price trigger=".number_format($cbTrigger,8,'.','')." peak=".number_format($lastPeak,8,'.','')." netPnl=$rp fee=$feeSum $feeAst");

    $feeLine = sprintf('Fee біржі: %.4f USDT', $feeUShow);
    if ($feeUShow <= 1e-12 && $feeSum > 1e-18 && $feeAstStr) {
        $feeLine = sprintf('Fee біржі: %.8f %s', $feeSum, strtoupper($feeAstStr));
    } elseif ($feeUShow > 1e-12 && $feeAstStr && strtoupper($feeAstStr) !== 'USDT') {
        $feeLine = sprintf(
            'Fee біржі: %.4f USDT (%.8f %s)',
            $feeUShow,
            $feeSum,
            strtoupper($feeAstStr)
        );
    }
    $txt = sprintf(
        "🔔 <b>%s</b> MAIN SELL CORE\nQty: %.8f\nPrice: %.8f\nBasis: %.8f\nPnL net: %.2f (%.2f%%)\n%s",
        $symbol,
        $execQty,
        $pSell,
        $basis,
        $rp,
        $rpPct,
        $feeLine
    );
            // TG_SELL_NOTIFY_PATCH — journal via worker DB (never symbol-lookup), TG without journal side-effect
        ladder_engine_journal_core_sell($db, (int)$sid, $symbol, (float)$execQty, (float)$pSell, (float)$basis, (float)$rp, (float)$rpPct);
        tg_notify_for_strategy($db, $sid, $txt, ['skip_journal' => true]);
        // LEDGER_V1: log MAIN SELL CORE (best-effort) — exactly one row (no duplicates)
        try {
          ledger_write($db, $sid, 'SELL_MAIN', null, 'SELL', $ordMain, $pSell, $execQty, $quoteQ, $feeSum, $feeAst, (float)$basis, (float)$rp, (float)$rpPct, [
              'pnl_net' => true,
              'exchange_fee_usdt' => $feeUShow,
          ]);
        } catch (Throwable $e) {}
// RESET_AFTER_MAIN_SELL_CORE
        // MAIN SELL CORE = повний кінець циклу (за задумом).
        // Після SELL: повний reset стану, щоб наступний цикл стартував з BUY0 (FBA), а не з L1.
        $pidQ = $db->query("SELECT id FROM positions WHERE strategy_id=$sid AND user_id=$uid ORDER BY id DESC LIMIT 1");
        $pidRow = ($pidQ && ($pidQ->num_rows ?? 0) > 0) ? $pidQ->fetch_assoc() : null;
        $pid = (int)($pidRow['id'] ?? 0);

        if ($pid > 0) {
            // Закриваємо всі рівні (і ядро, і SUB) для поточної позиції
            $db->query("UPDATE sub_positions SET closed=1 WHERE position_id=$pid AND closed=0");

            // Скидаємо позицію в нуль (історія лишається у рядку, але цикл закритий)
            $missedReset = db_positions_has_ladder_missed_json($db)
                ? ", ladder_missed_json=NULL"
                : "";
            $legacyReset = function_exists('position_legacy_reset_sql') ? position_legacy_reset_sql($db) : "";
            $db->query("UPDATE positions
                        SET state='closed',
                            qty=0,
                            sub_assigned_qty=0,
                            invested_usdt=0,
                            avg_price=0,
                            entry_price=0,
                            base_price=0,
                            last_buy=0,
                            next_ptr=1,
                            last_peak=0
                            $missedReset
                            $legacyReset
                        WHERE id=$pid");
        }

        logx("[%s] RESET_AFTER_MAIN_SELL_CORE: cycle ended -> state reset (next cycle BUY0)", $symbol);
        // CYCLE_RESTART_AUTO_BUY0
        // Explicit marker: cycle fully closed, BUY0 will be executed on next tick
        $fbaRow = $db->query("SELECT fba FROM strategies WHERE id=$sid LIMIT 1");
        $fbaVal = ($fbaRow && ($fr = $fbaRow->fetch_assoc())) ? (float)$fr['fba'] : 0.0;
        logx("[%s][CYCLE] MAIN SELL CONFIRMED -> AUTO BUY0 NEXT TICK (fba=%.4f)", $symbol, $fbaVal);


        // Дуже важливо: не продовжувати цей тик (щоб не зробити BUY L1 в тому ж циклі)
        // CYCLE_AUTO_BUY0_AFTER_RESET_V2
        // After confirmed MAIN SELL CORE + RESET:
        // - якщо не на паузі: лишаємось у running + новий start_ts для наступного циклу
        // - якщо пауза: лишаємось у paused, лише оновлюємо start_ts (новий BUY0 не піде до «Старт»)
        $stPause = $db->query("SELECT status FROM strategies WHERE id=$sid LIMIT 1");
        $isPaused = false;
        if ($stPause && ($sp = $stPause->fetch_assoc())) {
            $isPaused = (($sp['status'] ?? '') === 'paused');
        }
        $tsMs = 'CAST(UNIX_TIMESTAMP(NOW(3))*1000 AS UNSIGNED)';
        if ($isPaused) {
            $db->query("UPDATE strategies SET start_ts=$tsMs WHERE id=$sid");
            logx("[%s] CYCLE_END_UNDER_PAUSE: без автоперезапуску, статус лишається paused", $symbol);
        } else {
            $db->query("
                UPDATE strategies
                SET status='running',
                    start_ts=$tsMs
                WHERE id=$sid
            ");
        }
return;

}

/**
 * BUY0 – стартова покупка (L0).
 */
function handle_buy0_start(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    array $pos,
    float $price,
    float $fba,
    float $tpw,
    float $cbw,
    string $apiKey,
    string $secret,
    bool $pauseBlocksNewBuys = false
): void {
    if (!$pos) return;

    $pid = (int)$pos['id'];
    $qty = isset($pos['qty']) ? (float)$pos['qty'] : 0.0;

    // Стартова покупка тільки якщо позиція пуста (немає core і відкритих sub)
    if ($qty > 0.0) {
        logx("[$symbol] BUY0 SKIP qty>0 snapshot qty=$qty (reload position after MAIN SELL if unexpected)");
        return;
    }
    if (position_cycle_active($db, $pos)) {
        logx("[$symbol] BUY0 SKIP active cycle (open sub_positions, main qty=0)");
        return;
    }
    if ($pauseBlocksNewBuys) {
        logx("[$symbol] BUY0 SKIP (пауза або заморозка USDT — без нового входу)");
        return;
    }
    if ($fba <= 0.0) return;

    // BUY0_REENTRY_GUARD_V1
    // Якщо вже був BUY0 і після нього не було CORE_SELL — не відкриваємо повторний BUY0.
    // Це захищає від хибного повторного старту циклу при тимчасово некоректному sync_from_trades.
    try {
        $buy0Ts = 0;
        $closeTs = 0;
        $rb0 = $db->query("SELECT UNIX_TIMESTAMP(created_at) AS ts FROM engine_journal WHERE strategy_id=$sid AND event_type='BUY0' ORDER BY id DESC LIMIT 1");
        if ($rb0 && ($r0 = $rb0->fetch_assoc())) {
            $buy0Ts = (int)($r0['ts'] ?? 0);
        }
        $rcs = $db->query("SELECT UNIX_TIMESTAMP(created_at) AS ts FROM engine_journal WHERE strategy_id=$sid AND event_type IN ('CORE_SELL','MAIN_SELL_ALL','SELL_MAIN') ORDER BY id DESC LIMIT 1");
        if ($rcs && ($rs = $rcs->fetch_assoc())) {
            $closeTs = (int)($rs['ts'] ?? 0);
        }
        $rtl = $db->query("SELECT UNIX_TIMESTAMP(created_at) AS ts FROM trade_ledger WHERE strategy_id=$sid AND event_type IN ('SELL_MAIN','CORE_SELL') ORDER BY id DESC LIMIT 1");
        if ($rtl && ($rl = $rtl->fetch_assoc())) {
            $closeTs = max($closeTs, (int)($rl['ts'] ?? 0));
        }
        $nowTs = time();
        $openSubs = position_has_open_subs($db, $pid);
        if ($openSubs) {
            logx("[$symbol] BUY0_REENTRY_GUARD skip: open sub_positions (cycle active)");
            return;
        }
        // Короткий захист від подвійного BUY0, поки sync ще не підтягнув qty.
        // Цикл вважаємо закритим, якщо після BUY0 є SELL_MAIN / CORE_SELL (журнал або ledger).
        if ($buy0Ts > 0 && $closeTs < $buy0Ts && ($nowTs - $buy0Ts) < 180) {
            logx("[$symbol] BUY0_REENTRY_GUARD skip: recent BUY0 still settling (buy0Ts=$buy0Ts closeTs=$closeTs)");
            return;
        }
    } catch (Throwable $e) {
        // fail-open: if guard query fails, keep old behavior
    }

    // 1) Нормалізована сума - скільки реально купуємо в quote (USDT)

    $qNorm = exi_normalize_quote_buy($symbol, $fba, $apiKey, $secret);
    if ($qNorm <= 0.0) {
        logx("[$symbol] BUY0 SKIP quote norm=0 fba=$fba");
        [$freeU, $minN] = ladder_funds_snapshot($symbol, $apiKey, $secret);
        if (function_exists('spot_funds_freeze_user')) {
            spot_funds_freeze_user($db, $uid, 'BUY0: недостатньо USDT', $freeU, $sid, $symbol);
        }
        $t = "⚠️ <b>{$symbol}</b> — <b>стартову покупку</b> зараз не виконано (код події: <code>BUY0</code>)\n" .
             "Причина: недостатньо вільного USDT на spot або сума менша за мінімальний нотіонал біржі для MARKET.\n" .
             sprintf("Free USDT: ~%.2f | minNotional: ~%.2f | планова сума ордера (FBA): ~%.2f USDT", $freeU, $minN, $fba) .
             "\nПоповніть баланс — бот знову спробує <b>перший вхід у цикл</b> (те саме, що в логах позначається як BUY0).";
        tg_notify_for_strategy_throttled(
            $db,
            $sid,
            'buy0_skip_quote_norm_zero',
            $t,
            1800
        );
        return;
    }
    if (function_exists('profit_reinvest_buy_guard')) {
        $g = profit_reinvest_buy_guard($db, $uid, $symbol, $apiKey, $secret, (float)$qNorm);
        if (empty($g['ok'])) {
            logx(sprintf(
                "[$symbol] BUY0 SKIP profit_reserve free=%.4f buyable=%.4f reserve=%.4f need=%.4f",
                (float)$g['free'],
                (float)$g['buyable'],
                (float)$g['reserve'],
                (float)$qNorm
            ));
            return;
        }
    }
    // BUY0_GUARD_TRADING_ENABLED
    // If trading is globally disabled, do not attempt BUY0 and do not spam Telegram.
    $te = $_ENV['TRADING_ENABLED'] ?? getenv('TRADING_ENABLED') ?? '0';
    if ((string)$te !== '1') {
        logx("[$symbol] BUY0 BLOCKED (TRADING_ENABLED=0) fba=$fba price=$price");
        return;
    }



    // 2) Реальний MARKET BUY0
    [$h,$r,$e,$er] = place_quote_buy($symbol, $qNorm, $apiKey, $secret);
    if ($e || $h >= 400) {
        logx("[$symbol] BUY0 ERR http=$h body=$r");
        $txt = sprintf(
            "❌ <b>%s</b> — помилка <b>стартової покупки</b> (<code>BUY0</code>)\n" .
            "Біржа відхилила або не виконала перший MARKET buy циклу.\n" .
            "HTTP: %d\nВідповідь: %s",
            $symbol,
            $h,
            $r
        );
        tg_notify($txt);
        return;
    }
    // BUY0_AVG_FROM_FILLS
    // Після BUY0 беремо Avg/Base ТІЛЬКИ з Binance fills (джерело істини), а не з sync_from_trades.
    $order = json_decode((string)$r, true);
    if (!is_array($order)) {
        logx("[$symbol] BUY0 WARN: bad JSON in order response");
        return;
    }

    // derive base asset (e.g. ZECUSDT -> ZEC)
    $baseAsset = $symbol;
    foreach (array('USDT','BUSD','USDC','BTC','ETH') as $q) {
        $qlen = strlen($q);
        if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $q) === 0) {
            $baseAsset = substr($baseAsset, 0, -$qlen);
            break;
        }
    }

    $quoteSum = 0.0;
    $grossQty = 0.0;
    $feeBase  = 0.0;

    if (!empty($order['fills']) && is_array($order['fills'])) {
        foreach ($order['fills'] as $f) {
            $fp = (float)($f['price'] ?? 0);
            $fq = (float)($f['qty'] ?? 0);
            $fc = (float)($f['commission'] ?? 0);
            $fa = strtoupper((string)($f['commissionAsset'] ?? ''));

            if ($fp > 0 && $fq > 0) {
                $grossQty += $fq;
                $quoteSum += ($fp * $fq);
            }
            if ($fc > 0 && $fa === strtoupper($baseAsset)) {
                $feeBase += $fc;
            }
        }
    }

    // fallbacks if fills missing (should not happen with FULL, but keep safe)
    if ($quoteSum <= 0 && isset($order['cummulativeQuoteQty'])) $quoteSum = (float)$order['cummulativeQuoteQty'];
    if ($grossQty <= 0 && isset($order['executedQty']))          $grossQty = (float)$order['executedQty'];

    $netQty = max(0.0, $grossQty - $feeBase);
    if ($netQty <= 0 || $quoteSum <= 0) {
        logx("[$symbol] BUY0 WARN: cannot compute avg from fills (netQty=$netQty quoteSum=$quoteSum)");
        return;
    }

    $avg = $quoteSum / $netQty;

    // Ensure we have a positions row (latest for this strategy)
    $pid2 = 0;
    $qpid = $db->query("SELECT id FROM positions WHERE strategy_id=$sid AND user_id=$uid ORDER BY id DESC LIMIT 1");
    if ($qpid && ($rr = $qpid->fetch_assoc())) $pid2 = (int)($rr['id'] ?? 0);

    $avgSql   = number_format($avg, 18, '.', '');
    $qtySql   = number_format($netQty, 18, '.', '');
    $quoteSql = number_format($quoteSum, 18, '.', '');

    if ($pid2 > 0) {
        $db->query("
            UPDATE positions SET
                state='open',
                qty=$qtySql,
                avg_price=$avgSql,
                base_price=$avgSql,
                entry_price=$avgSql,
                invested_usdt=$quoteSql,
                last_buy=$avgSql,
                next_ptr=1,
                sub_assigned_qty=0,
                updated_at=NOW()
            WHERE id=$pid2
        ");
    } else {
        $db->query("
            INSERT INTO positions (user_id,strategy_id,state,qty,avg_price,base_price,entry_price,invested_usdt,last_buy,next_ptr,sub_assigned_qty,updated_at)
            VALUES ($uid,$sid,'open',$qtySql,$avgSql,$avgSql,$avgSql,$quoteSql,$avgSql,1,0,NOW())
        ");
        $pid2 = (int)$db->insert_id;
    }

    $base = $avg;   // база BUY0
    // LEDGER_V1: log BUY0
    try {
      ledger_write($db, $sid, 'BUY0', 0, 'BUY', $order, (float)$avg, (float)$netQty, (float)$quoteSum, 0.0, null, null, null, null, ['src'=>'fills']);
    } catch (Throwable $e) {}
    // для наступних розрахунків
    $newQty = $netQty;
logx("[$symbol] BUY0 ok quote=$qNorm @ $price qty=$newQty base_price=$base");

    if (function_exists('scaling_after_trade')) {
        scaling_after_trade($db, $sid, $apiKey, $symbol);
    }

    ladder_engine_journal_buy0_ok($db, $sid, $symbol, $qNorm, $price, $base, $newQty);

    $txt = sprintf(
        "🟢 <b>%s</b> — <b>стартова покупка</b> (перший вхід у цикл; у системі: <code>BUY0</code>)\n" .
        "Сума ордера (quote): %.2f USDT\nЦіна (тік): %.8f\nСередня ціна бази: %.8f\nКількість: %.8f",
        $symbol,
        $qNorm,
        $price,
        $base,
        $newQty
    );
    $symEscB0 = $db->real_escape_string($symbol);
    $tgr0 = tg_notify_for_strategy($db, $sid, $txt, ['skip_journal' => true]);
    if (!$tgr0[0]) {
        $w0 = 'BUY0: Telegram не доставлено — ' . (string)($tgr0[1] ?? '');
        $w0Esc = $db->real_escape_string($w0);
        @$db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message) VALUES (NOW(), $sid, '$symEscB0', 'TG_WARN', 0, NULL, '$w0Esc')"
        );
    }
}

/**
 * Missed-ladder queue stored in positions.ladder_missed_json (see run/migrations/20260423_add_positions_ladder_missed_json.sql).
 */
function db_positions_has_ladder_missed_json(mysqli $db): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = $db->query("SHOW COLUMNS FROM `positions` LIKE 'ladder_missed_json'");
    $cache = ($r && (int)($r->num_rows ?? 0) > 0);
    return $cache;
}

function binance_response_likely_insufficient_funds(?string $raw): bool {
    if ($raw === null || $raw === '') {
        return false;
    }
    if (strpos($raw, '-2010') !== false) {
        return true;
    }
    if (preg_match('~insufficient|not\\s*enough~i', $raw) && stripos($raw, 'alance') !== false) {
        return true;
    }
    return false;
}

/**
 * @return array{0: float, 1: float} [freeUSDT, minNotional]
 */
function ladder_funds_snapshot(string $symbol, string $apiKey, string $secret): array {
    if (!function_exists('exi_get_account')) {
        return [0.0, 0.0];
    }
    $acc = exi_get_account($apiKey, $secret);
    $free = 0.0;
    if (function_exists('exi_get_free_only')) {
        $free = (float)exi_get_free_only($acc, 'USDT');
    } elseif (function_exists('exi_get_free_asset')) {
        $free = (float)exi_get_free_asset($acc, 'USDT');
    }
    if (!function_exists('exi_symbol_filters')) {
        return [$free, 0.0];
    }
    $filters = exi_symbol_filters($symbol);
    $minN = max(0.0, (float)($filters['minNotional'] ?? 0.0));
    return [$free, $minN];
}

/**
 * @return list<array{lvl:int,trg:float,mult:float,desq:float,ts:int}>
 */
function ladder_missed_load(mysqli $db, int $pid): array {
    if (!db_positions_has_ladder_missed_json($db) || $pid <= 0) {
        return [];
    }
    $q = $db->query("SELECT ladder_missed_json FROM positions WHERE id=" . (int)$pid . " LIMIT 1");
    if (!$q) {
        return [];
    }
    $row = $q->fetch_assoc() ?: null;
    if ($row === null) {
        return [];
    }
    $raw = (string)($row['ladder_missed_json'] ?? '');
    if ($raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['q']) || !is_array($j['q'])) {
        return [];
    }
    $out = [];
    foreach ($j['q'] as $x) {
        if (!is_array($x)) {
            continue;
        }
        $out[] = $x;
    }
    return $out;
}

function ladder_missed_save(mysqli $db, int $pid, array $items): void {
    if (!db_positions_has_ladder_missed_json($db) || $pid <= 0) {
        return;
    }
    $payload = json_encode(
        ['v' => 1, 'q' => array_values($items)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload) || $payload === 'null') {
        return;
    }
    $pe = $db->real_escape_string($payload);
    $db->query("UPDATE positions SET ladder_missed_json='".$pe."', updated_at=NOW() WHERE id=" . (int)$pid);
}

function ladder_missed_push(
    mysqli $db,
    int $pid,
    int $lvl,
    float $trg,
    float $mult,
    float $desQuote
): void {
    $rows = ladder_missed_load($db, $pid);
    foreach ($rows as $r) {
        if ((int)($r['lvl'] ?? 0) === (int)$lvl) {
            return;
        }
    }
    $rows[] = [
        'lvl' => (int)$lvl,
        'trg' => (float)$trg,
        'mult' => (float)$mult,
        'desq' => (float)$desQuote,
        'ts' => (int)time(),
    ];
    usort(
        $rows,
        function ($a, $b) {
            return ((int)($a['lvl'] ?? 0)) <=> ((int)($b['lvl'] ?? 0));
        }
    );
    ladder_missed_save($db, $pid, $rows);
}

function ladder_missed_remove_level(mysqli $db, int $pid, int $lvlIdx): void {
    if (!db_positions_has_ladder_missed_json($db) || $pid <= 0) {
        return;
    }
    $rows = ladder_missed_load($db, $pid);
    $out = [];
    foreach ($rows as $r) {
        if ((int)($r['lvl'] ?? 0) === (int)$lvlIdx) {
            continue;
        }
        $out[] = $r;
    }
    if (!count($out)) {
        $db->query("UPDATE positions SET ladder_missed_json=NULL, updated_at=NOW() WHERE id=" . (int)$pid);
        return;
    }
    ladder_missed_save($db, $pid, $out);
}

/**
 * Throttled user-facing explanation for "could not top-up" ladder levels.
 */
/**
 * Write BUY_L_SKIP directly via engine mysqli (worker context); avoids _journal_insert separate DB/env issues.
 */
function ladder_engine_journal_buy_l_skip(
    mysqli $db,
    int $sid,
    string $symbol,
    int $lvlIdx,
    string $plainMessage
): void {
    $sid = (int)$sid;
    $lvl = (int)$lvlIdx;
    if ($sid <= 0 || $lvl < 1 || $symbol === '') {
        return;
    }
    $symEsc = $db->real_escape_string($symbol);
    $msgEsc = $db->real_escape_string($plainMessage);
    $dup = $db->query(
        "SELECT id FROM engine_journal WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='BUY_L_SKIP' AND level_idx=$lvl AND created_at >= (NOW() - INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1"
    );
    if ($dup && (int)($dup->num_rows ?? 0) > 0) {
        return;
    }
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message) VALUES (NOW(), $sid, '$symEsc', 'BUY_L_SKIP', $lvl, NULL, '$msgEsc')"
    );
}

/**
 * Успішна докупка L1+ — запис у engine_journal тим самим mysqli, що воркер (дашборд не залежить від _journal_insert / TG).
 */
function ladder_engine_journal_buy_l_ok(
    mysqli $db,
    int $sid,
    string $symbol,
    int $lvlIdx,
    float $qNorm,
    float $price,
    float $acq,
    float $needPrice,
    float $dropPct,
    string $sourceTag,
    bool $usedOrderApiFallback
): void {
    $sid = (int)$sid;
    $lvl = (int)$lvlIdx;
    if ($sid <= 0 || $lvl < 1 || $symbol === '') {
        return;
    }
    $symEsc = $db->real_escape_string($symbol);
    $dup = $db->query(
        "SELECT id FROM engine_journal WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='BUY_L' AND level_idx=$lvl AND created_at >= (NOW() - INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1"
    );
    if ($dup && (int)($dup->num_rows ?? 0) > 0) {
        return;
    }
    $kindUk = ($sourceTag === 'CATCHUP') ? 'catch-up після черги missed' : 'звичайна докупка по сітці';
    $extra = $usedOrderApiFallback ? ' Підтвердження обсягу з відповіді біржі (до індексації myTrades).' : '';
    $plain = sprintf(
        '✅ %s BUY L%d — %s.%s Quote ордера: ~%.2f USDT. Фактична ціна: ~%.8f. Кількість: ~%.8f. Drop рівня: ~%.4f%%. Тригер сходинки: ~%.8f.',
        $symbol,
        $lvl,
        $kindUk,
        $extra,
        $qNorm,
        $price,
        $acq,
        $dropPct,
        $needPrice
    );
    $msgEsc = $db->real_escape_string($plain);
    $qtySql = number_format($acq, 12, '.', '');
    $pxSql = number_format($price, 12, '.', '');
    $okIns = $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, qty, price, message) VALUES (NOW(), $sid, '$symEsc', 'BUY_L', $lvl, 'BUY', $qtySql, $pxSql, '$msgEsc')"
    );
    if (!$okIns) {
        logx("[JOURNAL] BUY_L insert failed sid=%d sym=%s err=%s", $sid, $symbol, $db->error);
    }
}

/**
 * Успішний SUB L5+ sell — journal через worker mysqli (ізоляція strategy_id).
 */
function ladder_engine_journal_sub_sell(
    mysqli $db,
    int $sid,
    string $symbol,
    int $lvl,
    float $qty,
    float $buyPx,
    float $sellPx,
    float $pnlUsdt,
    float $pnlPct,
    int $sellOrd = 0
): void {
    $sid = (int)$sid;
    $lvl = (int)$lvl;
    if ($sid <= 0 || $symbol === '' || $lvl <= 0) {
        return;
    }
    if ($sellOrd <= 0 && function_exists('ladder_sub_sell_ordinal')) {
        $sellOrd = ladder_sub_sell_ordinal($db, $sid, $lvl);
    }
    if ($sellOrd <= 0) {
        $sellOrd = 1;
    }
    $symEsc = $db->real_escape_string($symbol);
    $dup = $db->query(
        "SELECT id FROM engine_journal WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='SUB_SELL' AND level_idx=$lvl AND created_at >= (NOW() - INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1"
    );
    if ($dup && (int)($dup->num_rows ?? 0) > 0) {
        return;
    }
    $plain = sprintf(
        "🟢 %s SUB L%d SELL #%d\nQty: %.8f\nBuy: %.8f\nPrice: %.8f\nPnL net: %.2f (%.2f%%)",
        $symbol,
        $lvl,
        $sellOrd,
        $qty,
        $buyPx,
        $sellPx,
        $pnlUsdt,
        $pnlPct
    );
    $msgEsc = $db->real_escape_string($plain);
    $qtySql = number_format($qty, 12, '.', '');
    $pxSql = number_format($sellPx, 12, '.', '');
    $pnlSql = number_format($pnlUsdt, 12, '.', '');
    $pctSql = number_format($pnlPct, 6, '.', '');
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message) VALUES (NOW(), $sid, '$symEsc', 'SUB_SELL', $lvl, 'SELL', $qtySql, $pxSql, $pnlSql, $pctSql, '$msgEsc')"
    );
}

/**
 * Успішний MAIN SELL CORE — journal через worker mysqli (ізоляція strategy_id).
 */
function ladder_engine_journal_core_sell(
    mysqli $db,
    int $sid,
    string $symbol,
    float $qty,
    float $price,
    float $basis,
    float $pnlUsdt,
    float $pnlPct
): void {
    $sid = (int)$sid;
    if ($sid <= 0 || $symbol === '') {
        return;
    }
    $symEsc = $db->real_escape_string($symbol);
    $dup = $db->query(
        "SELECT id FROM engine_journal WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='CORE_SELL' AND created_at >= (NOW() - INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1"
    );
    if ($dup && (int)($dup->num_rows ?? 0) > 0) {
        return;
    }
    $plain = sprintf(
        "🔔 %s MAIN SELL CORE\nQty: %.8f\nPrice: %.8f\nBasis: %.8f\nPnL net: %.2f (%.2f%%)",
        $symbol,
        $qty,
        $price,
        $basis,
        $pnlUsdt,
        $pnlPct
    );
    $msgEsc = $db->real_escape_string($plain);
    $qtySql = number_format($qty, 12, '.', '');
    $pxSql = number_format($price, 12, '.', '');
    $pnlSql = number_format($pnlUsdt, 12, '.', '');
    $pctSql = number_format($pnlPct, 6, '.', '');
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message) VALUES (NOW(), $sid, '$symEsc', 'CORE_SELL', NULL, 'SELL', $qtySql, $pxSql, $pnlSql, $pctSql, '$msgEsc')"
    );
}

/**
 * Успішний BUY0 — той самий надійний шлях у engine_journal.
 */
function ladder_engine_journal_buy0_ok(
    mysqli $db,
    int $sid,
    string $symbol,
    float $qNorm,
    float $tickPx,
    float $avgBase,
    float $qty
): void {
    $sid = (int)$sid;
    if ($sid <= 0 || $symbol === '') {
        return;
    }
    $symEsc = $db->real_escape_string($symbol);
    $dup = $db->query(
        "SELECT id FROM engine_journal WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='BUY0' AND created_at >= (NOW() - INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1"
    );
    if ($dup && (int)($dup->num_rows ?? 0) > 0) {
        return;
    }
    $plain = sprintf(
        '✅ %s BUY0 — стартовий вхід у цикл. Quote: ~%.2f USDT. Ціна тіку при ордері: ~%.8f. Avg/Base: ~%.8f. Кількість: ~%.8f.',
        $symbol,
        $qNorm,
        $tickPx,
        $avgBase,
        $qty
    );
    $msgEsc = $db->real_escape_string($plain);
    $qtySql = number_format($qty, 12, '.', '');
    $pxBase = $avgBase > 0 ? $avgBase : $tickPx;
    $pxSql = number_format($pxBase, 12, '.', '');
    $okIns0 = $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, qty, price, message) VALUES (NOW(), $sid, '$symEsc', 'BUY0', 0, 'BUY', $qtySql, $pxSql, '$msgEsc')"
    );
    if (!$okIns0) {
        logx("[JOURNAL] BUY0 insert failed sid=%d sym=%s err=%s", $sid, $symbol, $db->error);
    }
}

function ladder_log_insufficient_for_level(
    mysqli $db,
    int $sid,
    string $symbol,
    int $lvlIdx,
    float $neededQuote,
    float $freeU,
    float $minN,
    float $trg
): void {
    // Text must match _journal_parse (… BUY L{n} …) for any legacy tg+journal path.
    $t = "⚠️ <b>{$symbol}</b> BUY L{$lvlIdx} SKIP (недостатньо free USDT / min notional)\n" .
         "Через нестачу вільного USDT на spot (для MARKET buy потрібен мін-нотіонал біржі).\n" .
         sprintf("Free USDT (лише вільний): ~%.2f | minNotional біржі: ~%.2f | бажаний order (FBA×mult): ~%.2f USDT | тригер: ~%.6f", $freeU, $minN, $neededQuote, $trg) .
         "\nПоповніть баланс (або дочекайтесь take-profit) — рівень у черзі; catch-up, коли коштів вистачить і ціна не вище тригера.";
    $plain = trim(preg_replace('/<[^>]+>/', '', $t));
    ladder_engine_journal_buy_l_skip($db, (int)$sid, $symbol, (int)$lvlIdx, $plain);
    $levelScope = 'ladder_skip_L' . (int)$lvlIdx;
    $state = 'BUY_L_SKIP';
    tg_notify_for_strategy_on_state_change($db, (int)$sid, $levelScope, $state, $t, ['skip_journal' => true]);
}

/**
 * Фактичний обсяг/котирування з FULL-відповіді Binance (після myTrades lag sync може дати acq=0).
 *
 * @return array{qty: float, quote: float, px: float}
 */
function ladder_metrics_from_binance_order_json(?array $ord, float $fallbackPrice): array
{
    $qty = 0.0;
    $quote = 0.0;
    $px = 0.0;
    if (!is_array($ord)) {
        return ['qty' => 0.0, 'quote' => 0.0, 'px' => $fallbackPrice];
    }
    if (!empty($ord['fills']) && is_array($ord['fills'])) {
        $sumPxQty = 0.0;
        $sumQ = 0.0;
        foreach ($ord['fills'] as $f) {
            $fq = (float)($f['qty'] ?? 0);
            $fp = (float)($f['price'] ?? 0);
            if ($fq > 0 && $fp > 0) {
                $sumPxQty += $fp * $fq;
                $sumQ += $fq;
            }
        }
        if ($sumQ > 1e-12) {
            $qty = $sumQ;
            $px = $sumPxQty / $sumQ;
        }
    }
    if ($qty <= 0 && isset($ord['executedQty'])) {
        $qty = (float)$ord['executedQty'];
    }
    if (isset($ord['cummulativeQuoteQty'])) {
        $quote = (float)$ord['cummulativeQuoteQty'];
    }
    if ($px <= 0 && $qty > 0 && $quote > 0) {
        $px = $quote / $qty;
    }
    if ($px <= 0 && $fallbackPrice > 0) {
        $px = $fallbackPrice;
    }
    if ($quote <= 0 && $qty > 0 && $px > 0) {
        $quote = $qty * $px;
    }

    return ['qty' => $qty, 'quote' => $quote, 'px' => $px];
}

function engine_ladder_apply_successful_buy(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    int $lvlIdx,
    float $price,
    float $qNorm,
    string $r,
    float $qtyBefore,
    float $base,
    float $needPrice,
    array $levels,
    float $tpw,
    float $cbw,
    float $fba,
    float $mult,
    string $apiKey,
    string $secret,
    string $sourceTag,
    ?float $subBuyPriceOverride = null
): void {
    if (function_exists('scaling_after_trade')) {
        scaling_after_trade($db, $sid, $apiKey, $symbol);
    }
    $orderApiFallbackUsed = false;
    $pos2 = null;
    $qty2 = 0.0;
    $acq = 0.0;
    for ($syncTry = 0; $syncTry < 14; $syncTry++) {
        if ($syncTry > 0) {
            usleep(180000);
        }
        $pos2 = sync_from_trades($db, $sid, $symbol, $apiKey, $secret, $uid, ['force_fresh' => true]);
        if (!$pos2) {
            continue;
        }
        $qty2 = (float)$pos2['qty'];
        $acq = max(0.0, $qty2 - $qtyBefore);
        if ($acq > 0.0) {
            break;
        }
    }
    if (!$pos2) {
        logx("[$symbol] BUY L$lvlIdx WARN: sync empty ($sourceTag)");
        return;
    }
    // Prefer Binance order fill over myTrades delta for sub/journal/TG.
    // Burst/catch-up can leave sync_delta as a tiny residual while the order filled ~FBA
    // (journal historically showed 0.24 vs ledger ~6.7). Position qty from sync is usually
    // already correct in that case — only patch positions when sync missed the fill entirely.
    $ordGuess = null;
    if ($r !== '') {
        $tmpO = json_decode($r, true);
        if (is_array($tmpO)) {
            $ordGuess = $tmpO;
        }
    }
    $m = ladder_metrics_from_binance_order_json($ordGuess, $price);
    $orderQty = (float)($m['qty'] ?? 0.0);
    $orderQuote = (float)($m['quote'] ?? 0.0);
    $orderPx = (float)($m['px'] ?? 0.0);
    $syncAcq = $acq;
    $orderBeatsSync = ($orderQty > 1e-10) && (
        $acq <= 1e-10
        || ($orderQty / max($acq, 1e-12)) >= 1.5
        || abs($orderQty - $acq) > max(0.05 * $orderQty, 1e-6)
    );
    if ($orderBeatsSync) {
        $acq = $orderQty;
        if ($orderPx <= 0) {
            $orderPx = $price > 0 ? $price : $orderPx;
        }
        if ($orderQuote <= 0 && $orderPx > 0) {
            $orderQuote = $orderQty * $orderPx;
        }
        if ($syncAcq <= 1e-10 && $pos2) {
            $pidPatch = (int)$pos2['id'];
            $oldQty = (float)$pos2['qty'];
            $oldInv = (float)($pos2['invested_usdt'] ?? 0);
            $newQty = $oldQty + $acq;
            $newInv = $oldInv + $orderQuote;
            $newAvg = $newQty > 1e-12 ? ($newInv / $newQty) : $orderPx;
            $nq = number_format($newQty, 18, '.', '');
            $na = number_format($newAvg, 18, '.', '');
            $ni = number_format($newInv, 18, '.', '');
            $lb = number_format($orderPx > 0 ? $orderPx : $newAvg, 18, '.', '');
            $db->query(
                "UPDATE positions SET qty=$nq, avg_price=$na, invested_usdt=$ni, last_buy=$lb, updated_at=NOW() WHERE id=$pidPatch LIMIT 1"
            );
            $pos2['qty'] = $newQty;
            $pos2['avg_price'] = $newAvg;
            $pos2['invested_usdt'] = $newInv;
            logx(
                "[$symbol] BUY L$lvlIdx FALLBACK: myTrades lag but order API qty=%s px≈%s — merged DB pos ($sourceTag)",
                (string)$acq,
                (string)$orderPx
            );
        } elseif ($syncAcq > 1e-10) {
            logx(
                "[$symbol] BUY L$lvlIdx ORDER_FILL: sync_delta=%s order_qty=%s px≈%s — sub/journal use order ($sourceTag)",
                (string)$syncAcq,
                (string)$acq,
                (string)$orderPx
            );
        }
        $price = $orderPx > 0 ? $orderPx : $price;
        $orderApiFallbackUsed = true;
    } elseif ($acq <= 0.0) {
        $xq = 0.0;
        if (is_array($ordGuess) && isset($ordGuess['executedQty'])) {
            $xq = (float)$ordGuess['executedQty'];
        }
        logx("[$symbol] BUY L$lvlIdx WARN: acquired qty=0 ($sourceTag) sync_tries=14 order_execQty=%s", (string)$xq);
        return;
    }
    $pid2 = (int)$pos2['id'];
    $lv = $levels[$lvlIdx] ?? null;
    if (!is_array($lv)) {
        logx("[$symbol] BUY L$lvlIdx WARN: strategy_levels немає lvl_idx=%d — підставляємо tp/cb з ядра ($sourceTag)", (int)$lvlIdx);
        $lv = ['drop' => 0.0, 'tp' => $tpw, 'cb' => $cbw];
    }
    $tp_l = isset($lv['tp']) ? (float)$lv['tp'] : $tpw;
    $cb_l = isset($lv['cb']) ? (float)$lv['cb'] : $cbw;
    if ($lvlIdx <= 4) {
        $tp_for = $tpw;
        $cb_for = $cbw;
    } else {
        $tp_for = $tp_l;
        $cb_for = $cb_l;
    }
    $subBp = ($subBuyPriceOverride !== null && $subBuyPriceOverride > 0.0) ? $subBuyPriceOverride : $price;
    insert_subpos($db, $pid2, $lvlIdx, $acq, $subBp, $tp_for, $cb_for);
    $acqSql = number_format($acq, 18, '.', '');
    $db->query("
        UPDATE positions SET
            sub_assigned_qty = sub_assigned_qty + $acqSql,
            updated_at = NOW()
        WHERE id = $pid2
    ");
    ladder_missed_remove_level($db, $pid2, $lvlIdx);
    $tag = $sourceTag !== '' ? $sourceTag : 'LADDER';
    if ($subBuyPriceOverride !== null && $subBuyPriceOverride > 0.0 && abs($subBp - $price) > 1e-9) {
        logx("[$symbol] $tag: BUY L$lvlIdx ok quote=$qNorm fill=$price sub_bp=$subBp acq=$acq trigger=$needPrice base=$base");
    } else {
        logx("[$symbol] $tag: BUY L$lvlIdx ok quote=$qNorm @ $price acq=$acq trigger=$needPrice base=$base");
    }

    ladder_engine_journal_buy_l_ok(
        $db,
        $sid,
        $symbol,
        $lvlIdx,
        $qNorm,
        $price,
        $acq,
        $needPrice,
        (float)($lv['drop'] ?? 0),
        $sourceTag,
        $orderApiFallbackUsed
    );

    $sfx = ($sourceTag === 'CATCHUP') ? ' (catch-up)' : '';
    $txt = sprintf(
        "🟢 <b>%s</b> — <b>докупка рівня L%d</b>%s\nСума (quote): %.2f USDT\nЦіна: %.8f\nКількість: %.8f\nПросадка: %.2f%%\nТригер: %.4f",
        $symbol,
        $lvlIdx,
        $sfx,
        $qNorm,
        $price,
        $acq,
        $lv['drop'],
        $needPrice
    );
    $symEsc = $db->real_escape_string($symbol);
    $tgr = tg_notify_for_strategy($db, $sid, $txt, ['skip_journal' => true]);
    if (!$tgr[0]) {
        $w = 'BUY L' . (int)$lvlIdx . ': Telegram не доставлено — ' . (string)($tgr[1] ?? '');
        $wEsc = $db->real_escape_string($w);
        @$db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message) VALUES (NOW(), $sid, '$symEsc', 'TG_WARN', " . (int)$lvlIdx . ", NULL, '$wEsc')"
        );
    }
    // Status changed: this level is no longer in SKIP state.
    tg_strategy_state_marker_set($db, $sid, 'ladder_skip_L' . (int)$lvlIdx, 'BUY_L_OK');

    try {
        $lvlN = (int)$lvlIdx;
        $ord = null;
        if ($r !== '') {
            $tmp = json_decode($r, true);
            if (is_array($tmp)) {
                $ord = $tmp;
            }
        }
        $execQty = 0.0;
        $quoteQ = 0.0;
        $feeSum = 0.0;
        $feeAst = null;
        if (is_array($ord)) {
            if (isset($ord['executedQty'])) {
                $execQty = (float)$ord['executedQty'];
            }
            if (isset($ord['cummulativeQuoteQty'])) {
                $quoteQ = (float)$ord['cummulativeQuoteQty'];
            }
            if (!empty($ord['fills']) && is_array($ord['fills'])) {
                foreach ($ord['fills'] as $f) {
                    $feeSum += (float)($f['commission'] ?? 0);
                    $feeAst = (string)($f['commissionAsset'] ?? $feeAst);
                }
            }
        }
        if ($quoteQ <= 0 && $fba > 0) {
            $quoteQ = (float)$fba * (float)$mult;
        }
        $pFill = 0.0;
        if (is_array($ord) && !empty($ord['fills']) && is_array($ord['fills'])) {
            $sum = 0.0;
            $w = 0.0;
            foreach ($ord['fills'] as $f) {
                $fp = (float)($f['price'] ?? 0);
                $fq = (float)($f['qty'] ?? 0);
                if ($fp > 0 && $fq > 0) {
                    $sum += $fp * $fq;
                    $w += $fq;
                }
            }
            if ($w > 0) {
                $pFill = $sum / $w;
            }
        }
        if ($pFill <= 0) {
            $pFill = (float)$price;
        }
        ledger_write($db, $sid, 'BUY_L', $lvlN, 'BUY', $ord, $pFill, $execQty, $quoteQ, $feeSum, $feeAst);
    } catch (Throwable $e) {
    }
}

/**
 * @param array<int,float> $candidates
 * @param array<int,bool> $openLvls
 */
function handle_ladder_catchup(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    array $pos,
    float $price,
    float $fba,
    float $tpw,
    float $cbw,
    string $apiKey,
    string $secret,
    array $levels,
    array $openLvls,
    float $base,
    float $qty,
    array $candidates
): bool {
    if (!db_positions_has_ladder_missed_json($db) || $fba <= 0.0 || $qty <= 0.0) {
        return false;
    }
    $pid = (int)($pos['id'] ?? 0);
    if ($pid <= 0) {
        return false;
    }
    $te = $_ENV['TRADING_ENABLED'] ?? getenv('TRADING_ENABLED') ?? '0';
    if ((string)$te !== '1') {
        return false;
    }
    $rows = ladder_missed_load($db, $pid);
    if (!count($rows)) {
        return false;
    }
    usort(
        $rows,
        function ($a, $b) {
            return ((int)($a['lvl'] ?? 0)) <=> ((int)($b['lvl'] ?? 0));
        }
    );
    foreach ($rows as $it) {
        $lvlIdx = (int)($it['lvl'] ?? 0);
        if ($lvlIdx < 1) {
            continue;
        }
        if (isset($openLvls[$lvlIdx])) {
            ladder_missed_remove_level($db, $pid, $lvlIdx);
            continue;
        }
        // R1: catch-up лише кінчика сітки (не заповнювати дірки).
        if (function_exists('ladder_is_tip_buy_level') && !ladder_is_tip_buy_level($db, $pid, $lvlIdx)) {
            logx("[$symbol] CATCHUP L$lvlIdx SKIP tip-only");
            continue;
        }
        // Встановлений тригер на момент SKIP (stored) + актуальна геометрія (cand).
        // Купуємо лише коли spot <= тригера; якщо spot вище — рівень знімаємо з черги (не buy вище сходинки).
        $cand = isset($candidates[$lvlIdx]) ? (float)$candidates[$lvlIdx] : 0.0;
        $storedTrg = isset($it['trg']) ? (float)$it['trg'] : 0.0;
        if ($storedTrg > 0.0 && $cand > 0.0) {
            $need_price = min($cand, $storedTrg);
        } elseif ($storedTrg > 0.0) {
            $need_price = $storedTrg;
        } elseif ($cand > 0.0) {
            $need_price = $cand;
        } else {
            ladder_missed_remove_level($db, $pid, $lvlIdx);
            continue;
        }

        if ($price > $need_price) {
            // Ціна піднялась вище тригера пропуску — черга більше не дійсна для цієї сходинки.
            logx(
                "[$symbol] CATCHUP L$lvlIdx DROP missed (spot above trigger) spot=$price need=$need_price cand=$cand stored_trg=$storedTrg"
            );
            $symEsc = $db->real_escape_string($symbol);
            $msgEsc = $db->real_escape_string(
                "Missed L{$lvlIdx} знято з черги: спот вище тригера (spot≈"
                . number_format($price, 8, '.', '')
                . ", trigger≈"
                . number_format($need_price, 8, '.', '')
                . "). Докупка лише коли ціна знову ≤ тригера — новий пропуск додасться окремо."
            );
            $db->query(
                "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
                 VALUES (NOW(), " . (int)$sid . ", '{$symEsc}', 'MISS_DROP', " . (int)$lvlIdx . ", NULL, '{$msgEsc}')"
            );
            ladder_missed_remove_level($db, $pid, $lvlIdx);
            continue;
        }

        // spot <= need_price — дозволена докупка missed.
        $lv = $levels[$lvlIdx] ?? null;
        if (!is_array($lv)) {
            ladder_missed_remove_level($db, $pid, $lvlIdx);
            continue;
        }
        if (function_exists('ladder_tip_buy_price_ok')) {
            $gapChk = ladder_tip_buy_price_ok($db, $pid, $lvlIdx, (float)$price, $levels, $pos);
            if (empty($gapChk['ok'])) {
                logx("[$symbol] CATCHUP L$lvlIdx SKIP ladder_gap: " . (string)($gapChk['reason'] ?? ''));
                continue;
            }
        }
        $mult = isset($lv['mult']) ? (float)$lv['mult'] : 1.0;
        $quote = $fba * ($mult > 0.0 ? $mult : 1.0);
        $qNorm = exi_normalize_quote_buy($symbol, $quote, $apiKey, $secret);
        if ($qNorm <= 0.0) {
            continue;
        }
        if (function_exists('profit_reinvest_buy_guard')) {
            $g = profit_reinvest_buy_guard($db, $uid, $symbol, $apiKey, $secret, (float)$qNorm);
            if (empty($g['ok'])) {
                logx(sprintf(
                    "[$symbol] CATCHUP L$lvlIdx SKIP profit_reserve buyable=%.4f reserve=%.4f need=%.4f",
                    (float)$g['buyable'],
                    (float)$g['reserve'],
                    (float)$qNorm
                ));
                continue;
            }
        }
        [$h, $r, $e, $er] = place_quote_buy($symbol, $qNorm, $apiKey, $secret);
        if ($e || $h >= 400) {
            logx("[$symbol] CATCHUP L$lvlIdx ERR http=$h body=$r");
            if (binance_response_likely_insufficient_funds((string)$r)) {
                continue;
            }
            return false;
        }
        logx("[$symbol] CATCHUP L$lvlIdx: filled quote=$qNorm (tick=$price need_price=$need_price cand=$cand stored_trg=$storedTrg)");
        engine_ladder_apply_successful_buy(
            $db,
            $sid,
            $symbol,
            $uid,
            $lvlIdx,
            $price,
            $qNorm,
            (string)$r,
            $qty,
            $base,
            $need_price,
            $levels,
            $tpw,
            $cbw,
            $fba,
            $mult,
            $apiKey,
            $secret,
            'CATCHUP'
        );
        return true;
    }
    return false;
}

/**
 * BUY LADDER – L1..L70 (докупки).
 */
function handle_buy_ladder(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    array $pos,
    float $price,
    float $fba,
    float $tpw,
    float $cbw,
    string $apiKey,
    string $secret,
    bool $pauseBlocksNewBuys = false,
    bool $skipCatchup = false
): void {
    if (!$pos) return;
    if ($pauseBlocksNewBuys) {
        logx("[$symbol] BUY LADDER SKIP (пауза або заморозка USDT — докупки вимкнені)");
        return;
    }

    $pid      = (int)$pos['id'];
    $qty      = (float)$pos['qty'];
    $avg      = (float)$pos['avg_price'];
    $base     = function_exists('position_grid_base')
        ? position_grid_base($pos)
        : (isset($pos['base_price']) ? (float)$pos['base_price'] : 0.0);

    if ($qty <= 0.0) return;        // нема позиції – нема ladder
    if ($fba <= 0.0) return;       // нема FBA – нема з чим працювати
    if ($base <= 0.0) {
        // ще не було BUY0 – не будуємо каскад
        return;
    }

    // ORPHAN_GUARD: не докуповувати сітку поверх «привидів» попереднього циклу (BTC-кейс).
    if (function_exists('ladder_orphan_should_block_buys')) {
        $ob = ladder_orphan_should_block_buys($db, $pid, $qty, $price, $fba);
        if (!empty($ob['block'])) {
            logx("[$symbol] BUY LADDER SKIP orphan: " . (string)($ob['reason'] ?? ''));
            if (function_exists('tg_notify_for_strategy_throttled')) {
                $txt = sprintf(
                    "⏸ <b>%s</b> докупки сітки <b>зупинено</b>\n%s\nПотрібне зведення orphan qty (не змішувати зі свіжим L1+).",
                    $symbol,
                    (string)($ob['reason'] ?? 'orphan qty')
                );
                tg_notify_for_strategy_throttled($db, $sid, 'ladder_orphan_block', $txt, 3600);
            }
            return;
        }
    }

    // 1) Завантажуємо рівні
    $levels = load_strategy_levels($db, $sid);
    if (!$levels || !is_array($levels)) return;

    // Сортуємо за lvl_idx
    ksort($levels);
    require_once __DIR__ . '/engine_logic_ladder_cascade.php';
    // 2) Тригери докупок: parallel (legacy) або cascade (app_settings engine_ladder_mode або .env ENGINE_LADDER_MODE)
    if (ladder_cascade_mode_active($db)) {
        $trigger = ladder_cascade_build_triggers($db, $pid, $base, $levels);
    } else {
        // ANCHOR_LOGIC_L1_L4_L5_L6PLUS (legacy)
        //   - L1..L4: від base_price (BUY0 anchor), НЕ каскадом
        //   - L5: від avg_price ядра (MAIN)
        //   - L6+: від buy_price попереднього SUB-рівня (ланцюжок SUB)
        $trigger = []; // lvl_idx => trigger_price
        foreach ($levels as $idx => $lv) {
            if ($idx < 1) {
                continue;
            }
            $drop = isset($lv['drop']) ? (float)$lv['drop'] : 0.0;

            $anchor = 0.0;

            if ($idx <= 4) {
                $anchor = $base;
            } elseif ($idx == 5) {
                $anchor = $avg;
            } else {
                $prevLvl = $idx - 1;
                $anchor = ladder_open_sub_buy_price($db, $pid, $prevLvl);
                if ($anchor <= 0.0) {
                    continue;
                }
            }

            if ($anchor <= 0.0) {
                continue;
            }
            $trigger[$idx] = $anchor * (1.0 - $drop / 100.0);
        }
    }

    // 3) Відкриті сабпозиції – не хочемо дублювати рівні, які вже відкриті
    $openSubs = open_subpos($db, $pid, null);
    $openLvls = [];
    if ($openSubs && is_array($openSubs)) {
        foreach ($openSubs as $sp) {
            if (!empty($sp['closed'])) continue;
            $lvlIdx = (int)$sp['level_idx'];
            if ($lvlIdx >= 1) {
                $openLvls[$lvlIdx] = true;
            }
        }
    }
    // 4) Кандидати: вільні рівні + лише кінчик сітки (R1: no gap-fill).
    $tipLvl = function_exists('ladder_next_tip_level') ? ladder_next_tip_level($db, $pid) : 1;
    $candidates = [];
    foreach ($trigger as $lvlIdx => $px) {
        if (!isset($levels[$lvlIdx])) continue;
        if (isset($openLvls[$lvlIdx])) continue;
        if ((int)$lvlIdx !== (int)$tipLvl) {
            continue;
        }
        $candidates[$lvlIdx] = $px;
    }
    if (!$skipCatchup && handle_ladder_catchup(
        $db, $sid, $symbol, $uid, $pos, $price, $fba, $tpw, $cbw, $apiKey, $secret,
        $levels, $openLvls, $base, $qty, $candidates
    )) {
        return;
    }
    if (!$candidates) {
        logx("[$symbol] BUY LADDER: no free tip level (tip=L$tipLvl)");
        return;
    }

    // 5) Шукаємо найперший рівень, де ціна <= каскадної trigger-ціни
    ksort($candidates);
    foreach ($candidates as $lvlIdx => $need_price) {
        if ($price > $need_price) {
            // ще не дійшли до цієї сходинки
            continue;
        }
        // R1 defense: tip-only even if candidates were widened elsewhere
        if (function_exists('ladder_is_tip_buy_level') && !ladder_is_tip_buy_level($db, $pid, (int)$lvlIdx)) {
            logx("[$symbol] BUY L$lvlIdx SKIP tip-only (allowed tip=L" . ladder_next_tip_level($db, $pid) . ")");
            continue;
        }
        // Чітка сходинка: tip лише з мін. gap нижче prev tip (і L5+ нижче ядра L1–L4).
        if (function_exists('ladder_tip_buy_price_ok')) {
            $gapChk = ladder_tip_buy_price_ok($db, $pid, (int)$lvlIdx, (float)$price, $levels, $pos);
            if (empty($gapChk['ok'])) {
                logx("[$symbol] BUY L$lvlIdx SKIP ladder_gap: " . (string)($gapChk['reason'] ?? ''));
                continue;
            }
        } elseif ($tipLvl > 1) {
            $prevBp = function_exists('ladder_open_sub_buy_price')
                ? ladder_open_sub_buy_price($db, $pid, $tipLvl - 1)
                : 0.0;
            if ($prevBp > 0.0 && $price >= $prevBp) {
                logx("[$symbol] BUY L$lvlIdx SKIP monotonic: spot=$price >= prevL" . ($tipLvl - 1) . " buy=$prevBp");
                continue;
            }
        }
        if (!ladder_cascade_mode_active($db)
            && !ladder_parallel_level_chain_ready($db, $pid, (int)$lvlIdx, $base, $avg)) {
            logx("[$symbol] BUY L$lvlIdx SKIP chain: prev open sub missing (parallel anchor guard)");
            continue;
        }
        $lv   = $levels[$lvlIdx];
        $mult = isset($lv['mult']) ? (float)$lv['mult'] : 1.0;

        $quote = $fba * ($mult > 0.0 ? $mult : 1.0);
        $qNorm = exi_normalize_quote_buy($symbol, $quote, $apiKey, $secret);
        if ($qNorm > 0.0 && function_exists('profit_reinvest_buy_guard')) {
            $g = profit_reinvest_buy_guard($db, $uid, $symbol, $apiKey, $secret, (float)$qNorm);
            if (empty($g['ok'])) {
                logx(sprintf(
                    "[$symbol] BUY L$lvlIdx SKIP profit_reserve free=%.4f buyable=%.4f reserve=%.4f need=%.4f",
                    (float)$g['free'],
                    (float)$g['buyable'],
                    (float)$g['reserve'],
                    (float)$qNorm
                ));
                // Treat like insufficient for ladder queue: do not spend reserved profit.
                $qNorm = 0.0;
            }
        }
        if ($qNorm <= 0.0) {
            logx("[$symbol] BUY L$lvlIdx SKIP quote norm=0 (insufficient free USDT vs min notional?)");
            [$freeU, $minN] = ladder_funds_snapshot($symbol, $apiKey, $secret);
            if (function_exists('spot_funds_freeze_user') && (!isset($g) || (float)($g['reserve'] ?? 0) <= 1e-8)) {
                spot_funds_freeze_user($db, $uid, "BUY L{$lvlIdx}: недостатньо USDT", $freeU, $sid, $symbol);
            }
            // Queue all triggered levels reached by current price (not only first one).
            foreach ($candidates as $qLvl => $qNeedPrice) {
                if ($price > (float)$qNeedPrice) continue;
                if (!isset($levels[$qLvl])) continue;
                $qMult = isset($levels[$qLvl]['mult']) ? (float)$levels[$qLvl]['mult'] : 1.0;
                $qQuote = $fba * ($qMult > 0.0 ? $qMult : 1.0);
                ladder_missed_push($db, $pid, (int)$qLvl, (float)$qNeedPrice, (float)$qMult, (float)$qQuote);
                ladder_log_insufficient_for_level(
                    $db,
                    $sid,
                    $symbol,
                    (int)$qLvl,
                    (float)$qQuote,
                    (float)$freeU,
                    (float)$minN,
                    (float)$qNeedPrice
                );
            }
            return;
        }

        [$h, $r, $e, $er] = place_quote_buy($symbol, $qNorm, $apiKey, $secret);
        if ($e || $h >= 400) {
            logx("[$symbol] BUY L$lvlIdx ERR http=$h body=$r");
            if (binance_response_likely_insufficient_funds((string)$r)) {
                [$freeU, $minN] = ladder_funds_snapshot($symbol, $apiKey, $secret);
                if (function_exists('spot_funds_freeze_user')) {
                    spot_funds_freeze_user($db, $uid, "BUY L{$lvlIdx}: Binance insufficient balance", $freeU, $sid, $symbol);
                }
                // Queue all triggered levels reached by current price (not only first one).
                foreach ($candidates as $qLvl => $qNeedPrice) {
                    if ($price > (float)$qNeedPrice) continue;
                    if (!isset($levels[$qLvl])) continue;
                    $qMult = isset($levels[$qLvl]['mult']) ? (float)$levels[$qLvl]['mult'] : 1.0;
                    $qQuote = $fba * ($qMult > 0.0 ? $qMult : 1.0);
                    ladder_missed_push($db, $pid, (int)$qLvl, (float)$qNeedPrice, (float)$qMult, (float)$qQuote);
                    ladder_log_insufficient_for_level(
                        $db,
                        $sid,
                        $symbol,
                        (int)$qLvl,
                        (float)$qQuote,
                        (float)$freeU,
                        (float)$minN,
                        (float)$qNeedPrice
                    );
                }
            } else {
                $txt = sprintf(
                    "❌ <b>%s</b> — помилка <b>докупки L%d</b> (<code>BUY L%d</code>)\nHTTP: %d\nВідповідь: %s",
                    $symbol,
                    $lvlIdx,
                    $lvlIdx,
                    $h,
                    $r
                );
                tg_notify_for_strategy($db, $sid, $txt, ['skip_journal' => true]);
            }
            return;
        }
        engine_ladder_apply_successful_buy(
            $db,
            $sid,
            $symbol,
            $uid,
            (int)$lvlIdx,
            $price,
            $qNorm,
            (string)$r,
            $qty,
            $base,
            (float)$need_price,
            $levels,
            $tpw,
            $cbw,
            $fba,
            (float)$mult,
            $apiKey,
            $secret,
            'LADDER'
        );
        return; // відкрили один рівень – на цей тік досить
    }

    logx("[$symbol] BUY LADDER HOLD: no level triggered on cascade");
}

/**
 * Завантаження рівнів стратегії.
 */
function load_strategy_levels(mysqli $db, int $sid): array {
    $sid = (int)$sid;
    $levels = [];

    // Спочатку шукаємо рівні для конкретної стратегії
    $q1 = "SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct
           FROM strategy_levels
           WHERE strategy_id=$sid
           ORDER BY lvl_idx ASC";
    $r1 = $db->query($q1);
    if ($r1 && $r1->num_rows > 0) {
        while ($row = $r1->fetch_assoc()) {
            $idx = (int)$row['lvl_idx'];
            $levels[$idx] = [
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp'   => (float)$row['tp_pct'],
                'cb'   => (float)$row['cb_pct'],
            ];
        }
        return $levels;
    }

    // Якщо немає – беремо глобальні (strategy_id=0)
    $q2 = "SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct
           FROM strategy_levels
           WHERE strategy_id=0
           ORDER BY lvl_idx ASC";
    $r2 = $db->query($q2);
    if ($r2) {
        while ($row = $r2->fetch_assoc()) {
            $idx = (int)$row['lvl_idx'];
            $levels[$idx] = [
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp'   => (float)$row['tp_pct'],
                'cb'   => (float)$row['cb_pct'],
            ];
        }
    }

    return $levels;
}

/**
 * Отримання позиції по strategy_id.
 */
function get_position(mysqli $db, int $sid, string $symbol, int $uid): ?array {
    $sid = (int)$sid;

    $row = position_pick_row($db, $sid, $uid);
    if ($row) {

        // Normalize: if qty>0 but state empty -> mark as open
        if (((float)($row['qty'] ?? 0)) > 0 && empty($row['state'])) {
            $pidN = (int)($row['id'] ?? 0);
            if ($pidN > 0) {
                $db->query("UPDATE positions SET state='open' WHERE id=$pidN");
                $row['state'] = 'open';
            }
        }

        // Gate: only active position is state='open'
        if (($row['state'] ?? '') !== 'open') {
            return null;
        }

        // AUTO_SYNC_NEXT_PTR_FROM_SUB_POSITIONS
        // Keep next_ptr/last_buy consistent with реально відкритими рівнями (sub_positions).
        $pid = (int)($row['id'] ?? 0);
        if ($pid > 0) {
            $mx = $db->query("SELECT MAX(level_idx) AS mx FROM sub_positions WHERE position_id=$pid AND closed=0");
            $mxv = ($mx && ($r = $mx->fetch_assoc())) ? (int)($r['mx'] ?? 0) : 0;

            if ($mxv > 0) {
                $q2 = $db->query("SELECT buy_price FROM sub_positions WHERE position_id=$pid AND level_idx=$mxv AND closed=0 ORDER BY id DESC LIMIT 1");
                $lb = ($q2 && ($r2 = $q2->fetch_assoc())) ? (float)($r2['buy_price'] ?? 0) : 0.0;

                $np = $mxv + 1;

                if ((int)($row['next_ptr'] ?? 0) !== $np) {
                    if ($lb > 0) {
                        $db->query("UPDATE positions SET next_ptr=$np, last_buy=$lb WHERE id=$pid");
                        $row['last_buy'] = $lb;
                    } else {
                        $db->query("UPDATE positions SET next_ptr=$np WHERE id=$pid");
                    }
                    $row['next_ptr'] = $np;
                }
            } else {
                // If no levels yet, but position qty>0 -> next_ptr at least 1
                $qty = (float)($row['qty'] ?? 0);
                $cur = (int)($row['next_ptr'] ?? 0);
                if ($qty > 0 && $cur < 1) {
                    $db->query("UPDATE positions SET next_ptr=1 WHERE id=$pid");
                    $row['next_ptr'] = 1;
                }
            }
        }


        return $row;
    }

    return null;
}

