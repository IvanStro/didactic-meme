<?php

// Common helpers for trading engine

require_once __DIR__ . '/../www/api/_binance_utils.php';
require_once __DIR__ . '/../www/api/_filters_utils.php';
require_once __DIR__ . '/../www/api/_ws_price.php';
require_once __DIR__ . '/../www/api/_tg_notify.php';
require_once __DIR__ . '/../www/api/_skip_utils.php';
require_once __DIR__ . '/../www/api/_skip_format.php';
require_once __DIR__ . '/../www/api/_free_only_shim.php';

/**
 * Place MARKET buy by quote amount (USDT/USDC).
 * Returns array($httpCode, $rawBody, $isError, $errorMessage)
 */
function place_quote_buy($symbol, $quote, $apiKey, $secret) {
    $symbol = (string)$symbol;
    

    // Derive base/quote assets from symbol for commission handling
    $baseAsset = $symbol;
    $quoteAsset = '';
    foreach (array('USDT','BUSD','USDC','BTC','ETH') as $q) {
        $qlen = strlen($q);
        if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $q) === 0) {
            $quoteAsset = $q;
            $baseAsset = substr($baseAsset, 0, -$qlen);
            break;
        }
    }
$quote  = (float)$quote;
    $apiKey = (string)$apiKey;
    $secret = (string)$secret;

    
    // SAFETY: global trading switch (default OFF)
    $te = $_ENV['TRADING_ENABLED'] ?? getenv('TRADING_ENABLED') ?? '0';
    if ((string)$te !== '1') {
        logx("[SAFE] TRADING_DISABLED blocking real order in %s for %s", __FUNCTION__, $symbol);
        return array(418, '{"error":"TRADING_DISABLED"}', true, 'TRADING_DISABLED');
    }

$ts = bn_server_time();

    $params = array(
        'symbol'     => $symbol,
        'side'       => 'BUY',
        'type'       => 'MARKET',
    'newOrderRespType' => 'FULL',
        'quoteOrderQty' => $quote,
        'timestamp'  => $ts,
        'recvWindow' => 5000,
    );

    $q = bn_sign_query($params, $secret);

    return http_json(
        bn_base() . '/api/v3/order?' . $q,
        array('X-MBX-APIKEY: ' . $apiKey),
        'POST'
    );
}

/**
 * Place MARKET sell by base quantity.
 * Returns array($httpCode, $rawBody, $isError, $errorMessage)
 */
function place_sell_qty($symbol, $qty, $apiKey, $secret) {
    $symbol = (string)$symbol;
    $qty    = (float)$qty;
    $apiKey = (string)$apiKey;
    $secret = (string)$secret;

    
    // SAFETY: global trading switch (default OFF)
    $te = $_ENV['TRADING_ENABLED'] ?? getenv('TRADING_ENABLED') ?? '0';
    if ((string)$te !== '1') {
        logx("[SAFE] TRADING_DISABLED blocking real order in %s for %s", __FUNCTION__, $symbol);
        return array(418, '{"error":"TRADING_DISABLED"}', true, 'TRADING_DISABLED');
    }

$ts = bn_server_time();

    $params = array(
        'symbol'     => $symbol,
        'side'       => 'SELL',
        'type'       => 'MARKET',
        'quantity'   => $qty,
        'timestamp'  => $ts,
        'recvWindow' => 5000,
    );

    $q = bn_sign_query($params, $secret);

    return http_json(
        bn_base() . '/api/v3/order?' . $q,
        array('X-MBX-APIKEY: ' . $apiKey),
        'POST'
    );
}

/**
 * Рекомендований мінімум початкової покупки (FBA), USDT.
 * Canonical: www/api/_filters_utils.php (strim_min_fba_usdt).
 */
if (!function_exists('strim_min_fba_usdt')) {
    function strim_min_fba_usdt(): float
    {
        return 7.0;
    }
}

/**
 * Зрозуміле для користувача TG-повідомлення про помилку продажу на Binance.
 *
 * @param string $kind  'sub' | 'main'
 * @param int|null $level рівень суба (для kind=sub)
 */
function tg_user_sell_error_message(string $symbol, string $rawBody, int $httpCode, string $kind = 'sub', ?int $level = null): string
{
    $symbol = strtoupper(trim($symbol));
    $body = (string)$rawBody;
    $j = json_decode($body, true);
    $code = is_array($j) ? (int)($j['code'] ?? 0) : 0;
    $msg = is_array($j) ? (string)($j['msg'] ?? '') : '';
    $msgU = strtoupper($msg);

    $where = ($kind === 'main')
        ? 'основної частини позиції'
        : ('рівня L' . (int)($level ?? 0));

    if ($code === -1013 || str_contains($msgU, 'NOTIONAL')) {
        return "⚠️ <b>{$symbol}</b> — продаж {$where} не виконано\n\n"
            . "Біржа Binance відхилила ордер: сума продажу менша за мінімум (~5 USDT).\n"
            . "Кошти лишились на вашому акаунті Binance — нічого не зникло.\n\n"
            . "<b>Що зробити:</b>\n"
            . "• зачекати вищої ціни, або\n"
            . "• наступного разу ставити початкову покупку <b>не менше "
            . number_format(strim_min_fba_usdt(), 0, '.', '')
            . " USDT</b> (рекомендація StrIM).";
    }

    if ($code === -2010 || str_contains($msgU, 'INSUFFICIENT')) {
        return "⚠️ <b>{$symbol}</b> — продаж {$where} не виконано\n\n"
            . "На біржі недостатньо монети для цього ордера (можливо, частина вже продана або заблокована).\n"
            . "Перевірте баланс у Binance Spot. Кошти через StrIM не виводяться.";
    }

    if ($code === -1003 || $httpCode === 429 || str_contains($msgU, 'TOO MANY')) {
        return "⚠️ <b>{$symbol}</b> — продаж {$where} тимчасово не пройшов\n\n"
            . "Binance обмежила частоту запитів. Стратегія спробує знову на наступному циклі.";
    }

    $short = $msg !== '' ? $msg : ('HTTP ' . $httpCode);
    if (mb_strlen($short) > 160) {
        $short = mb_substr($short, 0, 157) . '…';
    }

    return "⚠️ <b>{$symbol}</b> — продаж {$where} не виконано\n\n"
        . "Біржа повернула відповідь: " . htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . "Кошти лишаються на Binance. Якщо повторюється — напишіть у підтримку StrIM.";
}

/**
 * Canonical positions row: open + qty/subs, not empty duplicate (ORDER BY id DESC bug).
 */
function position_pick_row(mysqli $db, int $sid, int $uid): ?array
{
    $sid = (int)$sid;
    $uid = (int)$uid;
    if ($sid <= 0) {
        return null;
    }

    $sql = "
        SELECT p.*
        FROM positions p
        WHERE p.strategy_id = $sid
          AND (p.user_id = $uid OR p.user_id IS NULL OR p.user_id = 0)
        ORDER BY
          (p.state = 'open') DESC,
          (p.qty > 0) DESC,
          (EXISTS (
              SELECT 1 FROM sub_positions sp
              WHERE sp.position_id = p.id AND sp.closed = 0 AND sp.qty > 0
              LIMIT 1
          )) DESC,
          p.id ASC
        LIMIT 1
    ";
    $res = $db->query($sql);
    if ($res && ($row = $res->fetch_assoc())) {
        return $row;
    }

    return null;
}

/** Open sub_positions with qty > 0 for this position row. */
function position_has_open_subs(mysqli $db, int $positionId): bool
{
    $positionId = (int)$positionId;
    if ($positionId <= 0) {
        return false;
    }
    $q = $db->query(
        "SELECT 1 FROM sub_positions WHERE position_id = $positionId AND closed = 0 AND qty > 0.00000001 LIMIT 1"
    );
    return (bool)($q && $q->fetch_assoc());
}

/** Strategy still in an active cycle (core or ladder subs). */
function position_cycle_active(mysqli $db, ?array $pos): bool
{
    if (!$pos) {
        return false;
    }
    $qty = isset($pos['qty']) ? (float)$pos['qty'] : 0.0;
    if ($qty > 0.00000001) {
        return true;
    }
    $pid = (int)($pos['id'] ?? 0);
    return position_has_open_subs($db, $pid);
}

/**
 * Порядковий номер SELL_SUB цього рівня в поточному циклі (після останнього MAIN/CORE).
 * Викликати після ledger_write поточного продажу — тоді #1, #2, #3… відображають tip-цикли.
 */
function ladder_sub_sell_ordinal(mysqli $db, int $sid, int $levelIdx): int
{
    $sid = (int)$sid;
    $levelIdx = (int)$levelIdx;
    if ($sid <= 0 || $levelIdx <= 0) {
        return 1;
    }
    $afterId = 0;
    $m = $db->query(
        "SELECT MAX(id) AS mid FROM trade_ledger
         WHERE strategy_id={$sid}
           AND event_type IN ('SELL_MAIN','CORE_SELL')"
    );
    if ($m && ($row = $m->fetch_assoc())) {
        $afterId = (int)($row['mid'] ?? 0);
    }
    $c = $db->query(
        "SELECT COUNT(*) AS c FROM trade_ledger
         WHERE strategy_id={$sid}
           AND event_type='SELL_SUB'
           AND level_idx={$levelIdx}
           AND id > {$afterId}"
    );
    $n = ($c && ($r = $c->fetch_assoc())) ? (int)($r['c'] ?? 0) : 0;
    return max(1, $n);
}

/**
 * Ensure that we have a row in positions table for given strategy.
 * Returns associative array with row data.
 */
function ensure_position($db, $sid, $uid) {
    $sid = (int)$sid;
    $uid = (int)$uid;

    $row = position_pick_row($db, $sid, $uid);
    if ($row) {
        return $row;
    }

    $db->query("
        INSERT INTO positions (user_id, strategy_id, qty, invested_usdt, avg_price, last_buy, next_ptr, sub_assigned_qty, state, created_at, updated_at)
        VALUES ($uid, $sid, 0, 0, 0, 0, 1, 0, 'idle', NOW(), NOW())
    ");

    $row2 = position_pick_row($db, $sid, $uid);
    return $row2 ?: array();
}

/**
 * Insert new sub-position (ladder level).
 */
function insert_subpos($db, $positionId, $levelIdx, $qty, $buyPrice, $tp, $cb) {
    $positionId = (int)$positionId;
    $levelIdx   = (int)$levelIdx;
    $qty        = (float)$qty;
    $buyPrice   = (float)$buyPrice;
    $tp         = (float)$tp;
    $cb         = (float)$cb;

    $db->query("
        INSERT INTO sub_positions (position_id, level_idx, qty, buy_price, tp, cb, closed, created_at)
        VALUES (
            $positionId,
            $levelIdx,
            " . number_format($qty, 18, '.', '') . ",
            " . number_format($buyPrice, 18, '.', '') . ",
            " . number_format($tp, 6, '.', '') . ",
            " . number_format($cb, 6, '.', '') . ",
            0,
            NOW()
        )
    ");
}

/** Sum qty of all open sub_positions for a position row. */
function position_open_sub_qty_sum(mysqli $db, int $positionId): float
{
    $positionId = (int)$positionId;
    if ($positionId <= 0) {
        return 0.0;
    }
    $r = $db->query(
        "SELECT COALESCE(SUM(qty), 0) AS s FROM sub_positions WHERE position_id = $positionId AND closed = 0"
    );
    if ($r && ($row = $r->fetch_assoc())) {
        return (float)($row['s'] ?? 0);
    }
    return 0.0;
}

/** Cost basis (qty×buy_price) of open sub_positions. */
function position_open_sub_cost_sum(mysqli $db, int $positionId): float
{
    $positionId = (int)$positionId;
    if ($positionId <= 0) {
        return 0.0;
    }
    $r = $db->query(
        "SELECT COALESCE(SUM(qty * buy_price), 0) AS s
         FROM sub_positions
         WHERE position_id = $positionId AND closed = 0 AND qty > 0"
    );
    if ($r && ($row = $r->fetch_assoc())) {
        return (float)($row['s'] ?? 0);
    }
    return 0.0;
}

/**
 * If core qty was wiped (e.g. sync start_ts) but ladder subs are still open — restore qty/invested/avg.
 * Returns true when a row was updated.
 */
function position_resync_qty_from_open_subs(mysqli $db, int $positionId): bool
{
    $positionId = (int)$positionId;
    if ($positionId <= 0) {
        return false;
    }
    $subQty = position_open_sub_qty_sum($db, $positionId);
    if ($subQty <= 1e-12) {
        return false;
    }
    $r = $db->query("SELECT qty, invested_usdt, avg_price FROM positions WHERE id = $positionId LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        return false;
    }
    $qty = (float)($row['qty'] ?? 0);
    // Only lift when DB under-reports vs open ladder (never shrink here).
    if ($qty + 1e-12 >= $subQty) {
        return false;
    }
    $cost = position_open_sub_cost_sum($db, $positionId);
    $avg = $subQty > 0 ? ($cost / $subQty) : 0.0;
    $qSql = number_format($subQty, 18, '.', '');
    $cSql = number_format($cost, 18, '.', '');
    $aSql = number_format($avg, 18, '.', '');
    $db->query(
        "UPDATE positions SET
            qty = $qSql,
            invested_usdt = $cSql,
            avg_price = $aSql,
            sub_assigned_qty = GREATEST(COALESCE(sub_assigned_qty,0), $qSql),
            state = 'open',
            updated_at = NOW()
         WHERE id = $positionId
         LIMIT 1"
    );
    if (function_exists('logx')) {
        logx("[POS_RESYNC_SUBS] pid=$positionId qty $qty -> $subQty invested=$cost avg=$avg");
    }
    return true;
}

/** Highest open ladder level (1..70), or 0 if none. */
function position_max_open_level_idx(mysqli $db, int $positionId): int
{
    $positionId = (int)$positionId;
    if ($positionId <= 0) {
        return 0;
    }
    $r = $db->query(
        "SELECT MAX(level_idx) AS mx FROM sub_positions WHERE position_id = $positionId AND closed = 0 AND qty > 0"
    );
    if ($r && ($row = $r->fetch_assoc())) {
        return (int)($row['mx'] ?? 0);
    }
    return 0;
}

/**
 * Наступний дозволений рівень сітки (лише кінчик): max(open)+1.
 * Забороняє gap-fill (rebuy L5 при відкритому L6+).
 */
function ladder_next_tip_level(mysqli $db, int $positionId): int
{
    return position_max_open_level_idx($db, $positionId) + 1;
}

function ladder_is_tip_buy_level(mysqli $db, int $positionId, int $lvlIdx): bool
{
    $lvlIdx = (int)$lvlIdx;
    if ($lvlIdx < 1) {
        return false;
    }
    return $lvlIdx === ladder_next_tip_level($db, $positionId);
}

/**
 * Мін. крок вниз від попереднього відкритого tip (%).
 * Щоб L5+ не відкривались «по тій самій ціні», що ядро / попередній рівень.
 */
function ladder_tip_min_gap_pct(float $levelDropPct): float
{
    $drop = max(0.0, (float)$levelDropPct);
    // щонайменше 0.35%; інакше ~40% від drop рівня (але не більше самого drop)
    $fromDrop = ($drop > 0.0) ? min($drop, max(0.35, $drop * 0.40)) : 0.35;

    return max(0.35, $fromDrop);
}

/**
 * Чи дозволена докупка tip за ціною споту (чітка «сходинка вниз»).
 *
 * @param array<int,array{drop?:float,mult?:float,tp?:float,cb?:float}> $levels
 * @return array{ok:bool,reason:string,prev_buy:float,core_basis:float,need_below:float}
 */
function ladder_tip_buy_price_ok(
    mysqli $db,
    int $positionId,
    int $tipLvl,
    float $spot,
    array $levels,
    ?array $posRow = null
): array {
    $out = [
        'ok' => false,
        'reason' => '',
        'prev_buy' => 0.0,
        'core_basis' => 0.0,
        'need_below' => 0.0,
    ];
    $tipLvl = (int)$tipLvl;
    $spot = (float)$spot;
    if ($tipLvl < 1 || $spot <= 0.0) {
        $out['reason'] = 'bad_args';
        return $out;
    }

    $drop = 0.0;
    if (isset($levels[$tipLvl]) && is_array($levels[$tipLvl])) {
        $drop = (float)($levels[$tipLvl]['drop'] ?? 0.0);
    }
    $gapPct = ladder_tip_min_gap_pct($drop);
    $needBelow = $spot; // placeholder

    if ($tipLvl > 1) {
        $prevBuy = ladder_open_sub_buy_price($db, $positionId, $tipLvl - 1);
        $out['prev_buy'] = $prevBuy;
        if ($prevBuy > 0.0) {
            $needBelow = $prevBuy * (1.0 - $gapPct / 100.0);
            $out['need_below'] = $needBelow;
            if ($spot >= $needBelow) {
                $out['reason'] = sprintf(
                    'gap: spot=%.8f need<%.8f (prevL%d=%.8f gap≥%.2f%%)',
                    $spot,
                    $needBelow,
                    $tipLvl - 1,
                    $prevBuy,
                    $gapPct
                );
                return $out;
            }
        }
    }

    // L5+: вхід має бути нижче собівартості ядра L1–L4 (інакше ТП sub > MAIN при вищому %TP).
    if ($tipLvl >= 5 && is_array($posRow)) {
        $core = position_core_basis($db, $posRow);
        $coreBasis = (float)($core['basis'] ?? 0.0);
        $out['core_basis'] = $coreBasis;
        if ($coreBasis > 0.0) {
            $needCore = $coreBasis * (1.0 - $gapPct / 100.0);
            if ($needBelow <= 0.0 || $needCore < $needBelow) {
                $needBelow = $needCore;
            }
            $out['need_below'] = $needBelow;
            if ($spot >= $needCore) {
                $out['reason'] = sprintf(
                    'core: spot=%.8f need<%.8f (core=%.8f gap≥%.2f%%)',
                    $spot,
                    $needCore,
                    $coreBasis,
                    $gapPct
                );
                return $out;
            }
        }
    }

    $out['ok'] = true;
    $out['need_below'] = $needBelow;
    return $out;
}

/**
 * Qty у позиції, не покритий відкритими sub_positions (BUY0 residual / orphan).
 */
function position_orphan_qty(mysqli $db, int $positionId, float $posQty): float
{
    $openSum = position_open_sub_qty_sum($db, $positionId);
    return max(0.0, (float)$posQty - $openSum);
}

/**
 * Блокувати докупку сітки, якщо в позиції «зайві» монети поза відкритими рівнями
 * (сценарій BTC Lenina128: старий BUY0 + нові L1–L3 → MAIN продав усе разом).
 *
 * @return array{block:bool,reason:string,orphan:float,max_open:int}
 */
function ladder_orphan_should_block_buys(
    mysqli $db,
    int $positionId,
    float $posQty,
    float $price,
    float $fba
): array {
    $out = ['block' => false, 'reason' => '', 'orphan' => 0.0, 'max_open' => 0];
    $positionId = (int)$positionId;
    if ($positionId <= 0 || $posQty <= 1e-12) {
        return $out;
    }
    $maxOpen = position_max_open_level_idx($db, $positionId);
    $orphan = position_orphan_qty($db, $positionId, $posQty);
    $out['orphan'] = $orphan;
    $out['max_open'] = $maxOpen;
    $px = max(0.0, (float)$price);
    $orphanUsdt = $px > 0.0 ? ($orphan * $px) : 0.0;

    if ($maxOpen >= 1) {
        $openSum = position_open_sub_qty_sum($db, $positionId);
        $threshQty = max(1e-8, $openSum * 0.05);
        if ($orphan > $threshQty && $orphanUsdt >= 5.0) {
            $out['block'] = true;
            $out['reason'] = sprintf(
                'orphan_qty=%.8f (≈%.2f USDT) при відкритих L1+; докупка заблокована до зведення',
                $orphan,
                $orphanUsdt
            );
        }
        return $out;
    }

    // Немає відкритих L1+: дозволений лише лот ≈ BUY0 (fba/price). Надлишок = prior-cycle orphan.
    $expected = ($px > 0.0 && $fba > 0.0) ? ($fba / $px) : 0.0;
    if ($expected > 0.0 && $posQty > ($expected * 1.35)) {
        $extra = $posQty - $expected;
        $extraUsdt = $extra * $px;
        if ($extraUsdt >= 5.0) {
            $out['block'] = true;
            $out['orphan'] = $extra;
            $out['reason'] = sprintf(
                'prior_cycle_orphan≈%.8f (≈%.2f USDT) поверх очікуваного BUY0; L1+ заблоковано',
                $extra,
                $extraUsdt
            );
        }
    }
    return $out;
}

/**
 * Last price of ASSETUSDT for fee conversion (engine path).
 * Prefer WS cache → public REST ticker (no API keys).
 */
function trade_fee_asset_usdt_price(string $assetUsdtSymbol): float
{
    $sym = strtoupper(trim($assetUsdtSymbol));
    if ($sym === '' || !str_ends_with($sym, 'USDT')) {
        return 0.0;
    }
    if (function_exists('ws_ticker_price')) {
        $px = (float)ws_ticker_price($sym);
        if ($px > 1e-12) {
            return $px;
        }
    }
    if (function_exists('bn_last_price_usdt')) {
        $px = (float)bn_last_price_usdt($sym);
        if ($px > 1e-12) {
            return $px;
        }
    }
    $url = 'https://api.binance.com/api/v3/ticker/price?symbol=' . rawurlencode($sym);
    $json = @file_get_contents($url);
    if ($json) {
        $data = json_decode($json, true);
        $px = is_array($data) ? (float)($data['price'] ?? 0) : 0.0;
        if ($px > 1e-12) {
            return $px;
        }
    }
    return 0.0;
}

/**
 * Комісія біржі → USDT (для чистого PnL).
 * USDT/USDC/… як є; fee в base → × fillPrice; BNB/інше → last price *USDT.
 *
 * @param float $quoteNotionalUsdt якщо >0 — sanity: spot fee рідко >2% notional
 */
function trade_exchange_fee_usdt(
    float $feeAmt,
    ?string $feeAsset,
    float $fillPrice,
    string $symbol,
    float $quoteNotionalUsdt = 0.0
): float {
    if ($feeAmt <= 1e-18) {
        return 0.0;
    }
    $fa = strtoupper(trim((string)$feeAsset));
    if ($fa === '' || $fa === 'USDT' || $fa === 'USDC' || $fa === 'BUSD' || $fa === 'FDUSD' || $fa === 'TUSD') {
        // Порожній asset при fee≈0.1% quote — типово USDT
        return $feeAmt;
    }
    $sym = strtoupper($symbol);
    $base = $sym;
    foreach (['USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD'] as $q) {
        $n = strlen($q);
        if (strlen($base) > $n && substr($base, -$n) === $q) {
            $base = substr($base, 0, -$n);
            break;
        }
    }
    if ($fa === $base && $fillPrice > 1e-12) {
        $asBase = $feeAmt * $fillPrice;
        return trade_fee_usdt_sane($asBase, $feeAmt, $fa, $quoteNotionalUsdt);
    }
    $px = trade_fee_asset_usdt_price($fa . 'USDT');
    if ($px > 1e-12) {
        $asAlt = $feeAmt * $px;
        return trade_fee_usdt_sane($asAlt, $feeAmt, $fa, $quoteNotionalUsdt);
    }
    return 0.0;
}

/**
 * Захист від змішаних fills (USDT+BNB) / кривого commissionAsset:
 * якщо «BNB×ціна» дає >2% номіналу, а сира сума схожа на USDT-fee (0.01–0.2%),
 * трактуємо amount як уже USDT.
 */
function trade_fee_usdt_sane(
    float $convertedUsdt,
    float $rawAmt,
    string $feeAsset,
    float $quoteNotionalUsdt
): float {
    if ($quoteNotionalUsdt <= 1e-9 || $convertedUsdt <= 1e-18) {
        return $convertedUsdt;
    }
    $pct = $convertedUsdt / $quoteNotionalUsdt;
    if ($pct <= 0.02) {
        return $convertedUsdt;
    }
    $rawPct = $rawAmt / $quoteNotionalUsdt;
    $fa = strtoupper($feeAsset);
    // Типовий баг: commission≈0.05 USDT, але asset=BNB → ×780 = десятки USDT «збитку».
    if ($fa !== '' && $fa !== 'USDT' && $rawPct >= 0.00005 && $rawPct <= 0.0025) {
        return $rawAmt;
    }
    // Інакше обмежити оцінкою taker ~0.1% (краще за фейковий −50%).
    return $quoteNotionalUsdt * 0.001;
}

/**
 * Сума комісій з fills ордера (кожен fill → USDT окремо).
 *
 * @param list<array<string,mixed>> $fills
 * @return array{fee_amt:float,fee_asset:?string,fee_usdt:float}
 */
function trade_fills_fee_aggregate(array $fills, float $fillPrice, string $symbol, float $quoteNotionalUsdt = 0.0): array
{
    $feeUsdt = 0.0;
    $rawByAsset = [];
    foreach ($fills as $f) {
        if (!is_array($f)) {
            continue;
        }
        $amt = (float)($f['commission'] ?? 0);
        if ($amt <= 1e-18) {
            continue;
        }
        $ast = strtoupper(trim((string)($f['commissionAsset'] ?? '')));
        if ($ast === '') {
            $ast = 'USDT';
        }
        if (!isset($rawByAsset[$ast])) {
            $rawByAsset[$ast] = 0.0;
        }
        $rawByAsset[$ast] += $amt;
        $feeUsdt += trade_exchange_fee_usdt($amt, $ast, $fillPrice, $symbol, $quoteNotionalUsdt);
    }
    $feeAmt = 0.0;
    $feeAst = null;
    if ($rawByAsset !== []) {
        // Для ledger: домінуючий asset (після sanity fee_usdt уже коректний).
        arsort($rawByAsset);
        $feeAst = (string)array_key_first($rawByAsset);
        $feeAmt = (float)$rawByAsset[$feeAst];
        // Якщо домінує «BNB», але sanity вже трактував як USDT — збережемо USDT amount.
        if ($quoteNotionalUsdt > 1e-9 && $feeUsdt > 1e-12) {
            $domPct = ($feeAmt * (($feeAst === 'BNB') ? max(1.0, trade_fee_asset_usdt_price('BNBUSDT')) : 1.0)) / $quoteNotionalUsdt;
            if ($feeAst === 'BNB' && $domPct > 0.02 && abs($feeUsdt - $feeAmt) < 1e-6) {
                $feeAst = 'USDT';
                $feeAmt = $feeUsdt;
            } elseif ($feeAst === 'BNB' && $domPct > 0.02) {
                // raw BNB був кривий — у ledger пишемо вже USDT-еквівалент після sane
                $feeAst = 'USDT';
                $feeAmt = $feeUsdt;
            }
        }
    }
    return [
        'fee_amt' => $feeAmt,
        'fee_asset' => $feeAst,
        'fee_usdt' => $feeUsdt,
    ];
}

/**
 * Чистий PnL угоди продажу після комісії Binance на SELL.
 * @return array{gross:float,fee_usdt:float,net:float,net_pct:float}
 */
function trade_sell_pnl_net(
    float $execQty,
    float $basis,
    float $sellPrice,
    float $feeAmt,
    ?string $feeAsset,
    string $symbol
): array {
    $gross = ($sellPrice - $basis) * $execQty;
    $quote = $sellPrice * $execQty;
    $feeU = trade_exchange_fee_usdt($feeAmt, $feeAsset, $sellPrice, $symbol, $quote);
    $net = $gross - $feeU;
    $cost = $basis * $execQty;
    $netPct = ($cost > 1e-12) ? (($net / $cost) * 100.0) : 0.0;
    return [
        'gross' => $gross,
        'fee_usdt' => $feeU,
        'net' => $net,
        'net_pct' => $netPct,
    ];
}

/**
 * Lot SoT (source of truth): основна позиція (ядро) і кожен саб окремо.
 *
 * Правила:
 *  - MAIN TP / MAIN sell qty / MAIN PnL / fee → тільки ядро (L1–L4 + orphan BUY0).
 *  - SUB TP / SUB sell / SUB PnL → тільки buy_price цього sub_positions.
 *  - positions.avg_price — довідкова «середня по всій сітці», НЕ для рішень MAIN.
 *  - cycle-avg ledger — лише CAP qty проти external bag, НЕ basis MAIN.
 *
 * @return array{
 *   core_qty:float,core_cost:float,core_basis:float,core_levels:list,
 *   hi_qty:float,hi_cost:float,subs:list,
 *   bag_qty:float,bag_cost:float,bag_basis:float,source:string
 * }
 */
function position_lots_snapshot(mysqli $db, array $pos): array
{
    $pid = (int)($pos['id'] ?? 0);
    $qtyPos = (float)($pos['qty'] ?? 0);
    $basePrice = (float)($pos['base_price'] ?? 0);
    $nextPtr = (int)($pos['next_ptr'] ?? 0);

    $out = [
        'core_qty' => 0.0,
        'core_cost' => 0.0,
        'core_basis' => 0.0,
        'core_levels' => [],
        'hi_qty' => 0.0,
        'hi_cost' => 0.0,
        'subs' => [],
        'bag_qty' => 0.0,
        'bag_cost' => 0.0,
        'bag_basis' => 0.0,
        'source' => 'empty',
    ];

    $l14Qty = 0.0;
    $l14Cost = 0.0;

    if ($pid > 0 && function_exists('open_subpos')) {
        $all = open_subpos($db, $pid, null);
        if (is_array($all)) {
            foreach ($all as $sp) {
                $lv = (int)($sp['level_idx'] ?? 0);
                $sq = (float)($sp['qty'] ?? 0);
                $bp = (float)($sp['buy_price'] ?? 0);
                $tp = isset($sp['tp']) ? (float)$sp['tp'] : 0.0;
                if ($sq <= 1e-12 || $bp <= 0.0) {
                    continue;
                }
                $row = [
                    'id' => (int)($sp['id'] ?? 0),
                    'level' => $lv,
                    'qty' => $sq,
                    'buy_price' => $bp,
                    'tp_pct' => $tp,
                    'tp_price' => $tp > 0.0 ? ($bp * (1.0 + $tp / 100.0)) : null,
                    'cost' => $sq * $bp,
                ];
                if ($lv >= 5) {
                    $out['hi_qty'] += $sq;
                    $out['hi_cost'] += $sq * $bp;
                    $out['subs'][] = $row;
                } elseif ($lv >= 1 && $lv <= 4) {
                    $l14Qty += $sq;
                    $l14Cost += $sq * $bp;
                    $out['core_levels'][] = $row;
                }
            }
        }
    }

    // Ядро = лоти L1–L4. Orphan (qty у positions без рядка саба) — лише поверх L1–4, ціна base_price.
    $coreQty = $l14Qty;
    $coreCost = $l14Cost;
    $source = ($l14Qty > 1e-12) ? 'l1_l4_lots' : 'empty';

    $impliedCore = max(0.0, $qtyPos - $out['hi_qty']);
    $orphanQty = $impliedCore - $l14Qty;
    if ($orphanQty > 1e-6) {
        $orphanPx = $basePrice > 1e-12 ? $basePrice : (($l14Qty > 1e-12) ? ($l14Cost / $l14Qty) : 0.0);
        if ($orphanPx > 1e-12) {
            $coreQty += $orphanQty;
            $coreCost += $orphanQty * $orphanPx;
            $source = ($l14Qty > 1e-12) ? 'l1_l4_plus_orphan' : 'orphan_base';
        }
    }

    // Ще немає L1–4 (тільки BUY0): ядро = залишок positions мінус L5+, basis = base_price.
    if ($coreQty <= 1e-12 && $impliedCore > 1e-12 && $basePrice > 1e-12) {
        $coreQty = $impliedCore;
        $coreCost = $impliedCore * $basePrice;
        $source = 'base_price';
    }

    if ($nextPtr <= 1 && $basePrice > 1e-12 && $coreQty > 1e-12 && $l14Qty <= 1e-12) {
        $coreCost = $coreQty * $basePrice;
        $source = 'base_price';
    }

    $out['core_qty'] = $coreQty;
    $out['core_cost'] = $coreCost;
    $out['core_basis'] = ($coreQty > 1e-12 && $coreCost > 0.0) ? ($coreCost / $coreQty) : 0.0;
    $out['bag_qty'] = $out['core_qty'] + $out['hi_qty'];
    $out['bag_cost'] = $out['core_cost'] + $out['hi_cost'];
    $out['bag_basis'] = ($out['bag_qty'] > 1e-12 && $out['bag_cost'] > 0.0)
        ? ($out['bag_cost'] / $out['bag_qty'])
        : 0.0;
    $out['source'] = $source;
    return $out;
}

/**
 * Engine core basis (без L5+) — для MAIN TP / MAIN PnL.
 * SoT: лоти L1–L4 (+ orphan base), не positions.avg_price.
 *
 * @return array{basis:float,core_qty:float,hi_qty:float,source:string}
 */
function position_core_basis(mysqli $db, array $pos): array
{
    $snap = position_lots_snapshot($db, $pos);
    $basis = (float)$snap['core_basis'];
    $source = (string)$snap['source'];

    // Останній fallback — лише якщо лотів немає взагалі (не для змішаного bag-avg як MAIN).
    if ($basis <= 1e-12) {
        $avg = (float)($pos['avg_price'] ?? 0);
        $qty = (float)($pos['qty'] ?? 0);
        $hiQty = (float)$snap['hi_qty'];
        $hiCost = (float)$snap['hi_cost'];
        $coreQty = max(0.0, $qty - $hiQty);
        $coreCost = ($qty * $avg) - $hiCost;
        if ($coreQty > 1e-12 && $coreCost > 0.0) {
            $basis = $coreCost / $coreQty;
            $source = 'avg_minus_hi';
            return [
                'basis' => $basis,
                'core_qty' => $coreQty,
                'hi_qty' => $hiQty,
                'source' => $source,
            ];
        }
        if ($avg > 0.0) {
            $basis = $avg;
            $source = 'avg';
        }
    }

    return [
        'basis' => $basis,
        'core_qty' => (float)$snap['core_qty'],
        'hi_qty' => (float)$snap['hi_qty'],
        'source' => $source,
    ];
}

/**
 * Після BUY/SUB: вирівняти positions.qty/invested/avg з відкритих лотів (ядро+саби).
 * avg лишається bag-blend для UI; рішення MAIN читають position_core_basis().
 */
function position_resync_from_open_lots(mysqli $db, int $positionId): bool
{
    $positionId = (int)$positionId;
    if ($positionId <= 0) {
        return false;
    }
    $r = $db->query("SELECT * FROM positions WHERE id={$positionId} LIMIT 1");
    $pos = $r ? $r->fetch_assoc() : null;
    if (!$pos) {
        return false;
    }
    $snap = position_lots_snapshot($db, $pos);
    $bagQty = (float)$snap['bag_qty'];
    $bagCost = (float)$snap['bag_cost'];
    if ($bagQty <= 1e-12) {
        return false;
    }
    $bagAvg = $bagCost / $bagQty;
    $qSql = number_format($bagQty, 18, '.', '');
    $cSql = number_format($bagCost, 18, '.', '');
    $aSql = number_format($bagAvg, 18, '.', '');
    $db->query(
        "UPDATE positions SET
            qty = {$qSql},
            invested_usdt = {$cSql},
            avg_price = {$aSql},
            sub_assigned_qty = GREATEST(COALESCE(sub_assigned_qty,0), {$qSql}),
            state = 'open',
            updated_at = NOW()
         WHERE id = {$positionId}
         LIMIT 1"
    );
    if (function_exists('logx')) {
        logx(
            '[POS_RESYNC_LOTS] pid=%s bag_qty=%s bag_avg=%s core_basis=%s source=%s',
            (string)$positionId,
            (string)$bagQty,
            (string)$bagAvg,
            (string)$snap['core_basis'],
            (string)$snap['source']
        );
    }
    return ((int)$db->affected_rows) >= 0;
}

/**
 * Додати qty до відкритого sub рівня (злиття середньої ціни) — напр. зайвий BUY0 → L4.
 */
function engine_ladder_merge_into_open_level(
    mysqli $db,
    int $positionId,
    int $levelIdx,
    float $addQty,
    float $addPrice,
    float $tpWhole,
    float $cbWhole
): bool {
    $positionId = (int)$positionId;
    $levelIdx = (int)$levelIdx;
    $addQty = (float)$addQty;
    $addPrice = (float)$addPrice;
    if ($positionId <= 0 || $levelIdx < 1 || $addQty <= 1e-10 || $addPrice <= 0) {
        return false;
    }

    $tpFor = $levelIdx <= 4 ? (float)$tpWhole : (float)$tpWhole;
    $cbFor = $levelIdx <= 4 ? (float)$cbWhole : (float)$cbWhole;

    $r = $db->query(
        "SELECT id, qty, buy_price, tp, cb FROM sub_positions
         WHERE position_id = $positionId AND level_idx = $levelIdx AND closed = 0
         ORDER BY id DESC LIMIT 1"
    );
    $row = ($r && ($x = $r->fetch_assoc())) ? $x : null;

    if ($row) {
        $spId = (int)($row['id'] ?? 0);
        $oldQ = (float)($row['qty'] ?? 0);
        $oldP = (float)($row['buy_price'] ?? 0);
        $newQ = $oldQ + $addQty;
        $newP = $newQ > 1e-12 ? (($oldQ * $oldP) + ($addQty * $addPrice)) / $newQ : $addPrice;
        $qSql = number_format($newQ, 18, '.', '');
        $pSql = number_format($newP, 18, '.', '');
        $db->query("UPDATE sub_positions SET qty = $qSql, buy_price = $pSql WHERE id = $spId LIMIT 1");
    } else {
        insert_subpos($db, $positionId, $levelIdx, $addQty, $addPrice, $tpFor, $cbFor);
    }

    $addSql = number_format($addQty, 18, '.', '');
    $db->query(
        "UPDATE positions SET sub_assigned_qty = sub_assigned_qty + $addSql, updated_at = NOW() WHERE id = $positionId LIMIT 1"
    );
    return true;
}

/**
 * Злити «пильний» відкритий sub (notional < minNotional) у найближчий відкритий сусідній рівень і закрити його.
 * Не чіпає Binance — qty уже на акаунті; лише бухгалтерія рівнів.
 *
 * @return array{ok:bool,reason?:string,from_level?:int,to_level?:int,qty?:float}|null
 */
function ladder_absorb_dust_sub(
    mysqli $db,
    int $positionId,
    int $dustSpId,
    float $markPrice = 0.0,
    float $minNotionalUsd = 5.0
): array {
    $positionId = (int)$positionId;
    $dustSpId = (int)$dustSpId;
    if ($positionId <= 0 || $dustSpId <= 0) {
        return ['ok' => false, 'reason' => 'bad_ids'];
    }

    $r = $db->query(
        "SELECT id, level_idx, qty, buy_price, tp, cb, closed
         FROM sub_positions WHERE id = {$dustSpId} AND position_id = {$positionId} LIMIT 1"
    );
    $dust = ($r && ($x = $r->fetch_assoc())) ? $x : null;
    if (!$dust || (int)($dust['closed'] ?? 1) === 1) {
        return ['ok' => false, 'reason' => 'dust_missing_or_closed'];
    }

    $fromLvl = (int)($dust['level_idx'] ?? 0);
    $qty = (float)($dust['qty'] ?? 0);
    $buy = (float)($dust['buy_price'] ?? 0);
    if ($fromLvl < 1 || $qty <= 1e-12) {
        $db->query("UPDATE sub_positions SET closed=1, updated_at=NOW() WHERE id={$dustSpId} LIMIT 1");
        return ['ok' => true, 'reason' => 'zero_qty_closed', 'from_level' => $fromLvl, 'qty' => $qty];
    }

    $px = $markPrice > 0 ? $markPrice : $buy;
    $notional = $qty * max(0.0, $px);
    if ($minNotionalUsd > 0 && $notional >= $minNotionalUsd) {
        return ['ok' => false, 'reason' => 'not_dust', 'qty' => $qty, 'from_level' => $fromLvl];
    }

    // Найближчий відкритий сусід (спочатку нижчий рівень при однаковій відстані).
    $nbr = $db->query(
        "SELECT id, level_idx, qty, buy_price
         FROM sub_positions
         WHERE position_id = {$positionId} AND closed = 0 AND id <> {$dustSpId}
         ORDER BY ABS(level_idx - {$fromLvl}) ASC, level_idx ASC
         LIMIT 1"
    );
    $target = ($nbr && ($n = $nbr->fetch_assoc())) ? $n : null;
    if (!$target) {
        // Немає сусіда — просто закрити пил у БД (монета лишається в positions/на біржі).
        $db->query("UPDATE sub_positions SET closed=1, updated_at=NOW() WHERE id={$dustSpId} LIMIT 1");
        return ['ok' => true, 'reason' => 'closed_no_neighbor', 'from_level' => $fromLvl, 'qty' => $qty];
    }

    $toId = (int)$target['id'];
    $toLvl = (int)$target['level_idx'];
    $oldQ = (float)$target['qty'];
    $oldP = (float)$target['buy_price'];
    $newQ = $oldQ + $qty;
    $newP = $newQ > 1e-12 ? (($oldQ * $oldP) + ($qty * $buy)) / $newQ : $buy;
    $qSql = number_format($newQ, 18, '.', '');
    $pSql = number_format($newP, 18, '.', '');

    $db->query("UPDATE sub_positions SET qty={$qSql}, buy_price={$pSql}, updated_at=NOW() WHERE id={$toId} LIMIT 1");
    $db->query("UPDATE sub_positions SET closed=1, updated_at=NOW() WHERE id={$dustSpId} LIMIT 1");
    $db->query("UPDATE positions SET updated_at=NOW() WHERE id={$positionId} LIMIT 1");

    if (function_exists('logx')) {
        logx(sprintf(
            '[DUST_ABSORB] pid=%d L%d(sp=%d) qty=%s -> L%d(sp=%d) new_qty=%s',
            $positionId,
            $fromLvl,
            $dustSpId,
            (string)$qty,
            $toLvl,
            $toId,
            (string)$newQ
        ));
    }

    return [
        'ok' => true,
        'reason' => 'merged',
        'from_level' => $fromLvl,
        'to_level' => $toLvl,
        'qty' => $qty,
    ];
}

/**
 * Якщо positions.qty > суми open subs — «сирі» монети зливаємо у верхній відкритий рівень (зазвичай L4).
 */
function position_reconcile_orphan_core_to_ladder(
    mysqli $db,
    int $sid,
    int $uid,
    float $tpWhole,
    float $cbWhole,
    float $markPrice = 0.0
): bool {
    $pos = position_pick_row($db, $sid, $uid);
    if (!$pos) {
        return false;
    }
    $pid = (int)($pos['id'] ?? 0);
    $totalQty = (float)($pos['qty'] ?? 0);
    if ($pid <= 0 || $totalQty <= 1e-10) {
        return false;
    }

    $openSum = position_open_sub_qty_sum($db, $pid);
    $orphan = $totalQty - $openSum;
    if ($orphan <= 0.0001) {
        return false;
    }

    $maxLvl = position_max_open_level_idx($db, $pid);
    if ($maxLvl < 1) {
        return false;
    }

    $addPrice = (float)($pos['avg_price'] ?? 0);
    if ($addPrice <= 0 && $markPrice > 0) {
        $addPrice = $markPrice;
    }
    if ($addPrice <= 0) {
        $addPrice = (float)($pos['last_buy'] ?? 0);
    }
    if ($addPrice <= 0) {
        return false;
    }

    engine_ladder_merge_into_open_level($db, $pid, $maxLvl, $orphan, $addPrice, $tpWhole, $cbWhole);
    $logLine = sprintf(
        '[ORPHAN_TO_L%d] sid=%d pid=%d merged orphan qty=%s @ ~%s (open_sum=%s total=%s)',
        $maxLvl,
        $sid,
        $pid,
        (string) $orphan,
        (string) $addPrice,
        (string) $openSum,
        (string) $totalQty
    );
    if (function_exists('logx')) {
        logx($logLine);
    }
    return true;
}

/**
 * Get open sub-positions.
 * If \$fromLevel is null – return all.
 * If \$fromLevel >= 5 – only levels >=5, else only levels <=4.
 */
function open_subpos($db, $positionId, $fromLevel = null) {
    $positionId = (int)$positionId;

    if ($fromLevel === null) {
        $where = "";
    } else {
        $fromLevel = (int)$fromLevel;
        if ($fromLevel >= 5) {
            $where = "AND level_idx >= 5";
        } else {
            $where = "AND level_idx <= 4";
        }
    }

    $rows = array();
    $sql  = "SELECT * FROM sub_positions WHERE position_id = $positionId AND closed = 0 $where ORDER BY id ASC";
    $res  = $sql ? $db->query($sql) : false;

    if ($res) {
        while ($x = $res->fetch_assoc()) {
            $rows[] = $x;
        }
    }

    return $rows;
}

/**
 * buy_price останнього ВІДКРИТОГО sub-рівня (closed=0). Закриті рядки минулих циклів ігноруються.
 */
function ladder_open_sub_buy_price(mysqli $db, int $pid, int $levelIdx): float
{
    $pid = (int)$pid;
    $levelIdx = (int)$levelIdx;
    if ($pid <= 0 || $levelIdx < 1) {
        return 0.0;
    }
    $q = $db->query(
        "SELECT buy_price FROM sub_positions " .
        "WHERE position_id=$pid AND level_idx=$levelIdx AND closed=0 " .
        "ORDER BY id DESC LIMIT 1"
    );
    if ($q && ($row = $q->fetch_assoc())) {
        return (float)($row['buy_price'] ?? 0.0);
    }
    return 0.0;
}

/**
 * Parallel-режим: чи можна будувати тригер / виконувати BUY для рівня Ln.
 * L1–L4 від base; L5 від avg; L6+ лише якщо попередній SUB-рівень відкритий у поточному циклі.
 */
function ladder_parallel_level_chain_ready(mysqli $db, int $pid, int $lvlIdx, float $base, float $avg): bool
{
    $lvlIdx = (int)$lvlIdx;
    if ($lvlIdx <= 4) {
        return $base > 0.0;
    }
    if ($lvlIdx === 5) {
        return $base > 0.0 && $avg > 0.0;
    }
    return ladder_open_sub_buy_price($db, $pid, $lvlIdx - 1) > 0.0;
}

/**
 * Whether positions table has legacy-adopt columns (P2).
 */
function db_positions_has_legacy_fields(mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $r = @$db->query("SHOW COLUMNS FROM positions LIKE 'adopt_mode'");
    $cached = ($r && $r->num_rows > 0);
    return $cached;
}

/**
 * Grid base: prefer grid_anchor (legacy adopt @ spot / hybrid), else base_price.
 */
function position_grid_base(array $pos): float
{
    $ga = isset($pos['grid_anchor']) ? (float)$pos['grid_anchor'] : 0.0;
    if ($ga > 0.0) {
        return $ga;
    }
    return isset($pos['base_price']) ? (float)$pos['base_price'] : 0.0;
}

/**
 * Historical cost for UI / recovery (legacy bag). Falls back to avg_price.
 */
function position_cost_avg(array $pos): float
{
    $c = isset($pos['cost_avg']) ? (float)$pos['cost_avg'] : 0.0;
    if ($c > 0.0) {
        return $c;
    }
    return isset($pos['avg_price']) ? (float)$pos['avg_price'] : 0.0;
}

/**
 * exit_plan: no new BUY0 / ladder buys (only sells allowed).
 */
function position_legacy_blocks_new_buys(array $pos): bool
{
    $mode = strtolower(trim((string)($pos['adopt_mode'] ?? '')));
    return $mode === 'exit_plan';
}

/**
 * SQL fragment to clear legacy fields after cycle end (when columns exist).
 */
function position_legacy_reset_sql(mysqli $db): string
{
    if (!db_positions_has_legacy_fields($db)) {
        return '';
    }
    return ", origin='strim', cost_avg=NULL, grid_anchor=NULL, legacy_qty=NULL, adopt_mode=NULL, adopted_at=NULL";
}

/**
 * Known Binance orderIds from trade_ledger for a strategy.
 *
 * @return array<string, true>
 */
function sync_ledger_known_order_ids(mysqli $db, int $sid): array
{
    $known = array();
    $sid = (int)$sid;
    if ($sid <= 0) {
        return $known;
    }
    $res = $db->query(
        "SELECT DISTINCT order_id FROM trade_ledger " .
        "WHERE strategy_id=$sid AND order_id IS NOT NULL AND order_id <> ''"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $oid = trim((string)($row['order_id'] ?? ''));
            if ($oid !== '') {
                $known[$oid] = true;
            }
        }
    }
    return $known;
}

/** Кеш myTrades на один тік process_strategy (для post-tick перевірки SYNC_EXTERNAL). */
function sync_tick_cache_set(int $sid, string $symbol, array $data, float $qtyBefore): void
{
    if (!isset($GLOBALS['sync_tick_cache']) || !is_array($GLOBALS['sync_tick_cache'])) {
        $GLOBALS['sync_tick_cache'] = [];
    }
    $GLOBALS['sync_tick_cache'][(int)$sid] = [
        'symbol' => (string)$symbol,
        'data' => $data,
        'qty_before' => (float)$qtyBefore,
    ];
}

function sync_tick_cache_get(int $sid): ?array
{
    if (!isset($GLOBALS['sync_tick_cache']) || !is_array($GLOBALS['sync_tick_cache'])) {
        return null;
    }
    $row = $GLOBALS['sync_tick_cache'][(int)$sid] ?? null;
    return is_array($row) ? $row : null;
}

function sync_tick_cache_clear(int $sid): void
{
    if (isset($GLOBALS['sync_tick_cache'][(int)$sid])) {
        unset($GLOBALS['sync_tick_cache'][(int)$sid]);
    }
}

/**
 * Інвентар поточного циклу з trade_ledger (після останнього MAIN/CORE SELL).
 * Без BUY_EXT — зовнішні залишки на біржі не входять у стратегію.
 *
 * @return array{qty:float,cost:float,basis:float}
 */
function sync_ledger_open_cycle_inventory(mysqli $db, int $sid): array
{
    $out = ['qty' => 0.0, 'cost' => 0.0, 'basis' => 0.0];
    $sid = (int)$sid;
    if ($sid <= 0) {
        return $out;
    }
    $lastSellId = 0;
    $r = $db->query(
        "SELECT id FROM trade_ledger
         WHERE strategy_id={$sid}
           AND event_type IN ('SELL_MAIN','CORE_SELL','MAIN_SELL_ALL')
         ORDER BY id DESC LIMIT 1"
    );
    if ($r && ($row = $r->fetch_assoc())) {
        $lastSellId = (int)$row['id'];
    }
    $q = $db->query(
        "SELECT side, event_type, qty, quote_usdt, price
         FROM trade_ledger
         WHERE strategy_id={$sid}
           AND id > {$lastSellId}
           AND event_type IN ('BUY0','BUY_L','BUY_LADDER','SELL_SUB','SELL_MAIN','CORE_SELL')
         ORDER BY id ASC"
    );
    $qty = 0.0;
    $cost = 0.0;
    while ($q && ($t = $q->fetch_assoc())) {
        $side = strtoupper((string)($t['side'] ?? ''));
        $et = strtoupper((string)($t['event_type'] ?? ''));
        $tq = (float)($t['qty'] ?? 0);
        $quote = (float)($t['quote_usdt'] ?? 0);
        if ($quote <= 0 && $tq > 0 && (float)($t['price'] ?? 0) > 0) {
            $quote = $tq * (float)$t['price'];
        }
        if ($side === 'BUY' || str_starts_with($et, 'BUY')) {
            $qty += $tq;
            $cost += $quote;
        } elseif ($side === 'SELL' || str_starts_with($et, 'SELL') || $et === 'CORE_SELL') {
            if ($qty > 1e-12 && $cost > 0 && $tq > 0) {
                $avg = $cost / $qty;
                $take = min($tq, $qty);
                $qty -= $take;
                $cost -= $avg * $take;
                if ($qty < 1e-12) {
                    $qty = 0.0;
                    $cost = 0.0;
                }
            } else {
                $qty = max(0.0, $qty - $tq);
            }
        }
    }
    $out['qty'] = $qty;
    $out['cost'] = max(0.0, $cost);
    $out['basis'] = ($qty > 1e-12 && $cost > 0) ? ($cost / $qty) : 0.0;
    return $out;
}

/**
 * Чи пояснює недавня bot-купівля «зовнішнє» збільшення (false positive SYNC_EXTERNAL).
 */
function sync_bot_buy_covers_untracked(mysqli $db, int $sid, string $symbol, float $untrackedQty): bool
{
    if ($sid <= 0 || $symbol === '' || $untrackedQty <= 1e-8) {
        return false;
    }
    $symEsc = $db->real_escape_string($symbol);
    $qtyLo = number_format($untrackedQty * 0.75, 12, '.', '');
    $qtyHi = number_format($untrackedQty * 1.25, 12, '.', '');

    $r = $db->query(
        "SELECT id FROM trade_ledger
         WHERE strategy_id=$sid AND symbol='$symEsc' AND side='BUY'
           AND created_at >= (NOW() - INTERVAL 8 MINUTE)
           AND qty BETWEEN $qtyLo AND $qtyHi
         LIMIT 1"
    );
    if ($r && (int)($r->num_rows ?? 0) > 0) {
        return true;
    }

    $r2 = $db->query(
        "SELECT id FROM engine_journal
         WHERE strategy_id=$sid AND symbol='$symEsc'
           AND event_type IN ('BUY0','BUY_L')
           AND created_at >= (NOW() - INTERVAL 8 MINUTE)
           AND (message LIKE '%✅%' OR message LIKE '%BUY%')
         LIMIT 1"
    );
    if ($r2 && (int)($r2->num_rows ?? 0) > 0) {
        return true;
    }

    // Будь-яка bot-купівля в ledger за останні 2 хв — типовий race після MARKET fill.
    $r3 = $db->query(
        "SELECT id FROM trade_ledger
         WHERE strategy_id=$sid AND symbol='$symEsc' AND side='BUY'
           AND created_at >= (NOW() - INTERVAL 2 MINUTE)
         LIMIT 1"
    );
    return $r3 && (int)($r3->num_rows ?? 0) > 0;
}

/**
 * Після BUY0/Ladder у тому ж тіку — перевірити untracked з уже записаним ledger.
 */
function sync_post_tick_external_buy_check(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid
): void {
    $cache = sync_tick_cache_get($sid);
    if (!$cache || !is_array($cache['data'] ?? null)) {
        return;
    }
    $data = $cache['data'];
    $qtyBefore = (float)($cache['qty_before'] ?? 0.0);
    sync_tick_cache_clear($sid);

    $baseAsset = (string)$symbol;
    foreach (array('USDT', 'BUSD', 'USDC', 'BTC', 'ETH') as $q) {
        $qlen = strlen($q);
        if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $q) === 0) {
            $baseAsset = substr($baseAsset, 0, -$qlen);
            break;
        }
    }

    $untrackedQty = sync_untracked_buy_qty_from_trades($db, $sid, $data, $baseAsset);
    if ($untrackedQty <= 1e-8) {
        return;
    }
    if (sync_bot_buy_covers_untracked($db, $sid, $symbol, $untrackedQty)) {
        logx("[SYNC_EXTERNAL][SKIP] sid=$sid symbol=$symbol untracked=$untrackedQty (recent bot buy)");
        return;
    }

    $pos = ensure_position($db, $sid, $uid);
    $netQty = $pos && isset($pos['qty']) ? (float)$pos['qty'] : 0.0;
    $avg = $pos && isset($pos['avg_price']) ? (float)$pos['avg_price'] : 0.0;
    $lastBuy = $pos && isset($pos['last_buy']) ? (float)$pos['last_buy'] : 0.0;
    $deltaQty = max($untrackedQty, $netQty - $qtyBefore);

    journal_sync_external_buy_notice(
        $db,
        $sid,
        $symbol,
        $deltaQty,
        $avg,
        $lastBuy,
        $untrackedQty
    );
}

/**
 * Sum effective BUY qty from myTrades rows whose orderId is absent in trade_ledger.
 */
function sync_untracked_buy_qty_from_trades(
    mysqli $db,
    int $sid,
    array $data,
    string $baseAsset
): float {
    $known = sync_ledger_known_order_ids($db, $sid);
    $untracked = 0.0;
    foreach ($data as $t) {
        if (empty($t['isBuyer'])) {
            continue;
        }
        $oid = trim((string)($t['orderId'] ?? ''));
        if ($oid !== '' && isset($known[$oid])) {
            continue;
        }
        $q = isset($t['qty']) ? (float)$t['qty'] : 0.0;
        $comm = isset($t['commission']) ? (float)$t['commission'] : 0.0;
        $cAst = isset($t['commissionAsset']) ? (string)$t['commissionAsset'] : '';
        if ($cAst !== '' && strcasecmp($cAst, $baseAsset) === 0) {
            $q -= $comm;
            if ($q < 0.0) {
                $q = 0.0;
            }
        }
        $untracked += $q;
    }
    return $untracked;
}

/**
 * Exchange wallet base qty (free + locked) for symbol's base asset.
 */
function sync_exchange_base_qty(string $apiKey, string $secret, string $baseAsset): float
{
    if ($baseAsset === '' || $apiKey === '' || $secret === '') {
        return 0.0;
    }
    $acc = null;
    if (function_exists('scaling_binance_account')) {
        $acc = scaling_binance_account($apiKey, $secret, false);
    }
    if (!$acc && function_exists('exi_get_account')) {
        $acc = exi_get_account($apiKey, $secret);
    }
    return $acc ? (float)exi_get_free_asset($acc, $baseAsset) : 0.0;
}

/**
 * myTrades з опційним file-cache (етап 2b).
 *
 * @return array{ok:bool,http:int,data:array,err:string,cached:bool}
 */
function sync_fetch_my_trades_filtered(
    string $apiKey,
    string $secret,
    string $symbol,
    int $startTs,
    bool $forceFresh = false
): array {
    $fail = static function (int $http, string $err): array {
        return ['ok' => false, 'http' => $http, 'data' => [], 'err' => $err, 'cached' => false];
    };

    if (function_exists('scaling_binance_my_trades_rows')) {
        $res = scaling_binance_my_trades_rows($apiKey, $secret, $symbol, $forceFresh);
        if (!$res['ok']) {
            return $fail((int)$res['http'], (string)$res['err']);
        }
        $data = is_array($res['rows']) ? $res['rows'] : [];
    } else {
        if (!function_exists('bn_server_time') || !function_exists('bn_sign_query') || !function_exists('http_json')) {
            return $fail(0, 'binance utils missing');
        }
        $ts = bn_server_time();
        $params = [
            'symbol' => $symbol,
            'timestamp' => $ts,
            'limit' => 1000,
            'recvWindow' => 5000,
        ];
        $q = bn_sign_query($params, $secret);
        [$h, $r, $e, $er] = http_json(
            bn_base() . '/api/v3/myTrades?' . $q,
            ['X-MBX-APIKEY: ' . $apiKey],
            'GET'
        );
        if ($e || $h >= 400) {
            return $fail((int)$h, $e ? (string)$e : (string)$er);
        }
        $tmp = json_decode((string)$r, true);
        $data = is_array($tmp) ? $tmp : [];
        $res = ['ok' => true, 'http' => (int)$h, 'cached' => false];
    }

    if ($startTs > 0 && is_array($data)) {
        $filtered = [];
        foreach ($data as $t) {
            $tt = isset($t['time']) ? (int)$t['time'] : 0;
            if ($tt >= $startTs) {
                $filtered[] = $t;
            }
        }
        $data = $filtered;
    }

    return [
        'ok' => true,
        'http' => (int)($res['http'] ?? 200),
        'data' => $data,
        'err' => '',
        'cached' => !empty($res['cached']),
    ];
}

/**
 * Запис у engine_journal: поповнення базового активу з біржі (ручна купівля / сторонній ордер),
 * яке видно в myTrades, але не пройшло через place_quote_buy / ledger_write цього тіку.
 */
function journal_sync_external_buy_notice(
    mysqli $db,
    int $sid,
    string $symbol,
    float $deltaQty,
    float $avgAfter,
    float $lastBuyPx,
    float $untrackedQty = 0.0
): void {
    $sid = (int)$sid;
    if ($sid <= 0 || $symbol === '' || $deltaQty <= 1e-10) {
        return;
    }
    if ($untrackedQty <= 1e-8) {
        return;
    }
    $dRound = round($deltaQty, 6);
    $symEsc = $db->real_escape_string($symbol);
    $dup = $db->query(
        "SELECT id FROM engine_journal WHERE strategy_id=$sid AND event_type='SYNC_EXTERNAL_BUY' " .
        "AND symbol='$symEsc' AND message LIKE '%Δ≈" . $db->real_escape_string((string)$dRound) . "%' " .
        "AND created_at >= (NOW() - INTERVAL 45 MINUTE) LIMIT 1"
    );
    if ($dup && (int)($dup->num_rows ?? 0) > 0) {
        return;
    }
    $msg = sprintf(
        'SYNC_EXTERNAL_BUY %s: біржа показала збільшення базового активу на ≈%.8f (Δ≈%.6f) поза ордерами стратегії. ' .
        'Середня з повного myTrades (довідково): ≈%.8f; last buy ≈%.8f. ' .
        'Це НЕ додається в позицію стратегії і НЕ змінює basis/TP — лише сповіщення ' .
        '(залишок на Binance поза ботом / ручна угода).',
        $symbol,
        $deltaQty,
        $dRound,
        $avgAfter,
        $lastBuyPx
    );
    $msgEsc = $db->real_escape_string($msg);
    @$db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, qty, price, message) " .
        "VALUES (NOW(), $sid, '$symEsc', 'SYNC_EXTERNAL_BUY', NULL, 'BUY', " .
        number_format($deltaQty, 12, '.', '') . ", " .
        number_format($lastBuyPx > 0 ? $lastBuyPx : $avgAfter, 12, '.', '') . ", '$msgEsc')"
    );
}

/**
 * Sync position with real trades from Binance (myTrades).
 * Uses quoteQty + commission, applies start_ts filter,
 * and resets position to zero when net qty is effectively zero (dust).
 *
 * Returns updated position row as array.
 */
function sync_from_trades($db, $sid, $symbol, $apiKey, $secret, $uid, array $opts = []) {

    // AUTO_FIX_STATE_OPEN (DISABLED)
    // BUG: $pos is not initialized here; and this auto-fix must not run before we have a reliable position snapshot.
    // (kept disabled to prevent accidental state/qty drift)


    $sid    = (int)$sid;
    $symbol = (string)$symbol;
    $forceFresh = !empty($opts['force_fresh']);

    // Derive base asset from symbol (e.g. BTCUSDT -> BTC) for commission handling
    $baseAsset = $symbol;
    foreach (array('USDT','BUSD','USDC','BTC','ETH') as $q) {
        $qlen = strlen($q);
        if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $q) === 0) {
            $baseAsset = substr($baseAsset, 0, -$qlen);
            break;
        }
    }
    $apiKey = (string)$apiKey;
    $secret = (string)$secret;

    $startTs = 0;
    $resTs = $db->query("SELECT start_ts FROM strategies WHERE id = $sid LIMIT 1");
    if ($resTs && ($rowTs = $resTs->fetch_assoc())) {
        if (isset($rowTs['start_ts'])) {
            $startTs = (int)$rowTs['start_ts'];
        }
    }

    $fetch0 = sync_fetch_my_trades_filtered($apiKey, $secret, $symbol, $startTs, $forceFresh);
    $data = $fetch0['data'];

    // GUARD: if Binance myTrades request failed, do NOT overwrite local position with zeros
    if (!$fetch0['ok']) {
        logx("[SYNC_FROM_TRADES][ERR] sid=%s symbol=%s http=%s err=%s -> keep current position",
            (string)$sid, (string)$symbol, (string)$fetch0['http'], (string)$fetch0['err']);
        $posKeep = ensure_position($db, (int)$sid, (int)$uid);
        return $posKeep ? $posKeep : array();
    }
    if (!empty($fetch0['cached'])) {
        logx('[SYNC_FROM_TRADES] myTrades cache hit sid=%s symbol=%s', (string)$sid, (string)$symbol);
    }

    $posBefore = ensure_position($db, (int)$sid, (int)$uid);
    $qtyBefore = $posBefore && isset($posBefore['qty']) ? (float)$posBefore['qty'] : 0.0;
    sync_tick_cache_set((int)$sid, (string)$symbol, is_array($data) ? $data : [], $qtyBefore);

    // Після MARKET-ордера myTrades часто порожній 100–400 мс — без повтору GUARD_EMPTY ріже синк і
    // engine_ladder_apply_successful_buy бачить acquired qty=0 (немає subpos / TG / журналу).
    $lagAttempt = 0;
    while (
        $qtyBefore > 0.0
        && is_array($data)
        && count($data) === 0
        && $lagAttempt < 15
    ) {
        usleep(200000);
        $lagAttempt++;
        $fetchR = sync_fetch_my_trades_filtered($apiKey, $secret, $symbol, $startTs, true);
        if (!$fetchR['ok']) {
            break;
        }
        $data = $fetchR['data'];
    }
    if ($lagAttempt > 0 && is_array($data) && count($data) > 0) {
        logx('[SYNC_FROM_TRADES] myTrades lag retry ok attempts=%s rows=%s', (string)$lagAttempt, (string)count($data));
    }

    // When DB qty is zero but wallet already holds base, myTrades can lag after MARKET fills.
    if (
        $qtyBefore <= 0.0
        && (empty($data) || !is_array($data))
        && $lagAttempt === 0
    ) {
        $walletBase = sync_exchange_base_qty($apiKey, $secret, $baseAsset);
        if ($walletBase > 1e-8) {
            $walletAttempt = 0;
            while (
                (empty($data) || !is_array($data))
                && $walletAttempt < 15
            ) {
                usleep(200000);
                $walletAttempt++;
                $fetchW = sync_fetch_my_trades_filtered($apiKey, $secret, $symbol, $startTs, true);
                if (!$fetchW['ok']) {
                    break;
                }
                $data = $fetchW['data'];
            }
            if ($walletAttempt > 0 && is_array($data) && count($data) > 0) {
                logx(
                    '[SYNC_FROM_TRADES] wallet lag retry ok sid=%s attempts=%s rows=%s walletBase=%s',
                    (string)$sid,
                    (string)$walletAttempt,
                    (string)count($data),
                    (string)$walletBase
                );
            }
        }
    }

    // GUARD: if we already have an open qty, but filtered trades are empty (e.g., bad start_ts or partial history),
    // do NOT reset position to zero.
    if ($qtyBefore > 0.0 && (empty($data) || !is_array($data))) {
        
        $sid_i = (int)$sid;
        $r = $db->query("SELECT entry_price FROM positions WHERE strategy_id=$sid_i AND user_id=$uid LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        $entryFallback = $row ? (float)$row['entry_price'] : 0.0;

        if ($entryFallback > 0.0) {
            $sid_i = (int)$sid;
            $epSql = number_format((float)$entryFallback, 8, '.', '');
            $db->query("UPDATE positions SET avg_price=$epSql, last_buy=$epSql WHERE strategy_id=$sid_i AND user_id=$uid LIMIT 1");
            if (is_array($posBefore)) {
                $posBefore['avg_price'] = $entryFallback;
                $posBefore['last_buy']  = $entryFallback;
            }
        }
        logx("[SYNC_FROM_TRADES][GUARD_EMPTY] sid=%s symbol=%s qtyBefore=%s startTs=%s -> keep current position",
            (string)$sid, (string)$symbol, (string)$qtyBefore, (string)$startTs);
        return $posBefore;
    }

    // Aggregate buys / sells
    $netQty    = 0.0;
    $costBuy   = 0.0; // total spent in quote
    $costSell  = 0.0; // total received in quote
    $lastBuy   = 0.0;
    $lastBuyTs = 0;

    foreach ($data as $t) {
        $p    = isset($t['price']) ? (float)$t['price'] : 0.0;
        $q    = isset($t['qty']) ? (float)$t['qty'] : 0.0;
        $qAmt = isset($t['quoteQty']) ? (float)$t['quoteQty'] : ($p * $q);
        $tm   = isset($t['time']) ? (int)$t['time'] : 0;
        $isB  = !empty($t['isBuyer']);
        $comm = isset($t['commission']) ? (float)$t['commission'] : 0.0;
        $cAst = isset($t['commissionAsset']) ? (string)$t['commissionAsset'] : '';

        if ($isB) {
            // adjust base qty for commission in base asset
            $qEff = $q;
            if ($cAst !== '' && strcasecmp($cAst, $baseAsset) === 0) {
                $qEff -= $comm;
                if ($qEff < 0) {
                    $qEff = 0.0;
                }
            }

            $netQty  += $qEff;
            $costBuy += $qAmt;

            if ($tm >= $lastBuyTs) {
                $lastBuyTs = $tm;
                $lastBuy   = $p;
            }
        } else {
            // sell side
            $qEff = $q;
            if ($cAst !== '' && strcasecmp($cAst, $baseAsset) === 0) {
                $qEff -= $comm;
                if ($qEff < 0) {
                    $qEff = 0.0;
                }
            }

            $netQty   -= $qEff;
            $costSell += $qAmt;
        }
    }

    $netCost = $costBuy - $costSell;
    // Dust handling (USDT-based): flatten only when actual exchange/base qty is also dust or zero.
    $epsCost = 0.50; // USDT threshold
    $epsQty  = 0.00000001;
    if (abs($netCost) <= $epsCost && $netQty <= $epsQty) {
        $netQty  = 0.0;
        $netCost = 0.0;
    }

    if ($netQty <= 0.0) {
        $netQty  = 0.0;
        $netCost = 0.0;
    }

    $avg = $netQty > 0.0 ? ($netCost / $netQty) : 0.0;

    $pos = ensure_position($db, $sid, $uid);
    if ($pos) {
        $id    = (int)$pos['id'];
        // Never wipe below open ladder inventory (myTrades/start_ts can miss older buys that still have open subs).
        $openSubQty = position_open_sub_qty_sum($db, $id);
        if ($openSubQty > 1e-12 && $netQty + 1e-12 < $openSubQty) {
            $openCost = position_open_sub_cost_sum($db, $id);
            logx(
                '[SYNC_FROM_TRADES] floor qty to open_subs sid=%s net=%s open_subs=%s',
                (string)$sid,
                (string)$netQty,
                (string)$openSubQty
            );
            $netQty = $openSubQty;
            if ($openCost > 1e-12) {
                $netCost = $openCost;
            }
            $avg = $netQty > 0.0 ? ($netCost / $netQty) : 0.0;
        }

        // CAP: не втягувати зовнішній залишок біржі в qty/avg стратегії (лише bot ledger cycle).
        $cycleInv = sync_ledger_open_cycle_inventory($db, $sid);
        if ($cycleInv['qty'] > 1e-12) {
            $cap = $cycleInv['qty'];
            $epsCap = max(1e-6, $cap * 0.03);
            if ($netQty > $cap + $epsCap) {
                logx(
                    '[SYNC_FROM_TRADES][CAP_EXTERNAL] sid=%s net=%s ledger_cycle=%s -> cap (no adopt bag)',
                    (string)$sid,
                    (string)$netQty,
                    (string)$cap
                );
                $netQty = max($cap, $openSubQty);
                if ($cycleInv['cost'] > 1e-12) {
                    $netCost = $cycleInv['cost'] * ($netQty / max($cycleInv['qty'], 1e-12));
                }
                $avg = $netQty > 0.0 ? ($netCost / $netQty) : 0.0;
            }
        }

        // Reject nonsensical avg (e.g. negative after bag/sell mismatch).
        if ($avg <= 0.0) {
            if ($cycleInv['basis'] > 0.0) {
                $avg = $cycleInv['basis'];
                $netCost = $avg * $netQty;
                logx('[SYNC_FROM_TRADES][AVG_FIX] sid=%s use ledger basis=%s', (string)$sid, (string)$avg);
            } elseif ($lastBuy > 0.0) {
                $avg = $lastBuy;
                $netCost = $avg * $netQty;
                logx('[SYNC_FROM_TRADES][AVG_FIX] sid=%s use lastBuy=%s', (string)$sid, (string)$avg);
            } elseif ($qtyBefore > 0.0 && is_array($posBefore) && (float)($posBefore['avg_price'] ?? 0) > 0) {
                logx('[SYNC_FROM_TRADES][AVG_FIX] sid=%s keep previous avg (computed avg<=0)', (string)$sid);
                return $posBefore;
            }
        }

        $qSql  = number_format($netQty, 18, '.', '');
        $cSql  = number_format($netCost, 18, '.', '');
        $aSql  = number_format($avg, 18, '.', '');
        $lbSql = number_format($lastBuy, 18, '.', '');

        $db->query("
            UPDATE positions SET
                qty           = $qSql,
                invested_usdt = $cSql,
                avg_price     = $aSql,
                last_buy      = $lbSql,
                updated_at    = NOW()
            WHERE id = $id
        ");

        // Lot SoT: якщо є відкриті саби/ядро в sub_positions — qty/avg з лотів, не cycle-bag.
        if ($openSubQty > 1e-12 && function_exists('position_resync_from_open_lots')) {
            position_resync_from_open_lots($db, $id);
        }

        // SYNC_EXTERNAL_BUY — лише після тіку (sync_post_tick_external_buy_check), коли ledger уже оновлений.

        $resP = $db->query("SELECT * FROM positions WHERE id = $id LIMIT 1");
        if ($resP && ($rowP = $resP->fetch_assoc())) {
            return $rowP;
        }
    }

    return $pos ? $pos : array();
}

