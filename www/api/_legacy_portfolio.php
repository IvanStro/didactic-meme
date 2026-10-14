<?php
declare(strict_types=1);

/**
 * Shared legacy portfolio scan / classify / recommend (docs/LEGACY_ADOPT_UA.md).
 */

function legacy_scan_load_settings(mysqli $db): array
{
    $defaults = [
        'dust_usdt' => 7.0,
        'dd_ok_pct' => 15.0,
        'dd_mid_pct' => 40.0,
        'dd_deep_pct' => 40.0,
        'stale_days' => 30,
        'rq_step_min' => 5.5,
        'rq_step_max' => 8.0,
        'rq_like_ratio' => 0.60,
    ];
    $map = [
        'legacy_dust_usdt' => 'dust_usdt',
        'legacy_dd_ok_pct' => 'dd_ok_pct',
        'legacy_dd_mid_pct' => 'dd_mid_pct',
        'legacy_dd_deep_pct' => 'dd_deep_pct',
        'legacy_stale_days' => 'stale_days',
    ];
    foreach ($map as $k => $field) {
        $kk = $db->real_escape_string($k);
        $r = @$db->query("SELECT v FROM app_settings WHERE k='$kk' LIMIT 1");
        if ($r && ($row = $r->fetch_assoc()) && $row['v'] !== '' && $row['v'] !== null) {
            $defaults[$field] = is_numeric($row['v']) ? (float)$row['v'] : $defaults[$field];
            if ($field === 'stale_days') {
                $defaults[$field] = (int)$defaults[$field];
            }
        }
    }
    return $defaults;
}

function legacy_scan_classify(array $cfg, float $notional, ?float $ddPct, ?array $ours): string
{
    if ($ours && ((float)($ours['qty'] ?? 0) > 0 || in_array((string)($ours['status'] ?? ''), ['running', 'paused'], true))) {
        return 'ours';
    }
    if ($notional < (float)$cfg['dust_usdt']) {
        return 'dust';
    }
    if ($ddPct === null) {
        return 'unknown';
    }
    if ($ddPct < (float)$cfg['dd_ok_pct']) {
        return 'ok';
    }
    if ($ddPct < (float)$cfg['dd_deep_pct']) {
        return 'mid';
    }
    return 'deep';
}

function legacy_scan_recommend(array $cfg, string $klass, float $freeUsdt, array $tradeInfo, ?float $ddPct): array
{
    // Factual inventory only. StrIM does not select actions from an individual's holdings.
    return ['primary' => '', 'alternatives' => [], 'blocked' => [], 'reasons' => []];
}

function legacy_scan_summary(array $items): array
{
    $c = [
        'total' => count($items),
        'dust' => 0,
        'ok' => 0,
        'mid' => 0,
        'deep' => 0,
        'ours' => 0,
        'unknown' => 0,
        'needs_attention' => 0,
        'primary_hold' => 0,
        'primary_topup' => 0,
    ];
    foreach ($items as $it) {
        $k = (string)($it['klass'] ?? '');
        if (isset($c[$k])) {
            $c[$k]++;
        }
        if (in_array($k, ['deep', 'mid', 'unknown'], true)) {
            $c['needs_attention']++;
        }
        if (($it['primary'] ?? '') === 'hold') {
            $c['primary_hold']++;
        }
        if (($it['primary'] ?? '') === 'topup_usdt') {
            $c['primary_topup']++;
        }
    }
    return $c;
}

function legacy_scan_cluster_buys(array $trades): array
{
    $steps = [];
    $cur = null;
    foreach ($trades as $t) {
        if (empty($t['isBuyer'])) {
            if ($cur) {
                $steps[] = $cur;
                $cur = null;
            }
            continue;
        }
        $tm = (int)($t['time'] ?? 0);
        $quote = (float)($t['quoteQty'] ?? 0);
        if ($cur === null) {
            $cur = ['t' => $tm, 'quote' => $quote];
            continue;
        }
        if ($tm - $cur['t'] < 5000) {
            $cur['quote'] += $quote;
            $cur['t'] = $tm;
        } else {
            $steps[] = $cur;
            $cur = ['t' => $tm, 'quote' => $quote];
        }
    }
    if ($cur) {
        $steps[] = $cur;
    }
    return $steps;
}

/**
 * @return array{ok:bool,qty:float,avg:float,cost:float,trades:int,buy_steps:int,rq_like:bool,stale_days:?int,last_trade:?string}
 */
function legacy_scan_cycle_from_trades(string $symbol, string $apiKey, string $secret): array
{
    $empty = [
        'ok' => false,
        'qty' => 0.0,
        'avg' => 0.0,
        'cost' => 0.0,
        'trades' => 0,
        'buy_steps' => 0,
        'rq_like' => false,
        'stale_days' => null,
        'last_trade' => null,
    ];
    if (!function_exists('http_json') || !function_exists('bn_sign_query')) {
        return $empty;
    }
    $ts = function_exists('bn_server_time') ? bn_server_time() : (int)(microtime(true) * 1000);
    $q = bn_sign_query(
        ['symbol' => $symbol, 'timestamp' => $ts, 'limit' => 1000, 'recvWindow' => 5000],
        $secret
    );
    [$h, $r, $e] = http_json(
        (function_exists('bn_base') ? bn_base() : 'https://api.binance.com') . '/api/v3/myTrades?' . $q,
        ['X-MBX-APIKEY: ' . $apiKey],
        'GET'
    );
    if ($e || $h >= 400) {
        return $empty;
    }
    $trades = json_decode((string)$r, true);
    if (!is_array($trades)) {
        return $empty;
    }
    usort($trades, static fn($a, $b) => (int)($a['time'] ?? 0) <=> (int)($b['time'] ?? 0));

    $qty = 0.0;
    $cost = 0.0;
    $lastTs = 0;
    foreach ($trades as $t) {
        $qQty = (float)($t['qty'] ?? 0);
        $quote = (float)($t['quoteQty'] ?? 0);
        $tm = (int)($t['time'] ?? 0);
        if ($tm > $lastTs) {
            $lastTs = $tm;
        }
        if ($qQty <= 0) {
            continue;
        }
        if (!empty($t['isBuyer'])) {
            $qty += $qQty;
            $cost += $quote;
        } else {
            $avg = $qty > 1e-12 ? $cost / $qty : 0.0;
            $qty -= $qQty;
            $cost -= $avg * $qQty;
            if (abs($qty) < 1e-8) {
                $qty = 0.0;
                $cost = 0.0;
            }
            if ($cost < 0) {
                $cost = 0.0;
            }
        }
    }
    $avg = $qty > 1e-12 ? $cost / $qty : 0.0;
    $allBuySteps = legacy_scan_cluster_buys($trades);
    $baseSteps = 0;
    foreach ($allBuySteps as $s) {
        $qq = (float)$s['quote'];
        if ($qq >= 5.5 && $qq <= 8.0) {
            $baseSteps++;
        }
    }
    $rqLike = count($allBuySteps) >= 10 && ($baseSteps / max(1, count($allBuySteps))) >= 0.60;

    $staleDays = null;
    $lastTrade = null;
    if ($lastTs > 0) {
        $lastTrade = date('Y-m-d H:i', (int)($lastTs / 1000));
        $staleDays = (int)floor((time() - (int)($lastTs / 1000)) / 86400);
    }

    return [
        'ok' => true,
        'qty' => $qty,
        'avg' => $avg,
        'cost' => $cost,
        'trades' => count($trades),
        'buy_steps' => count($allBuySteps),
        'rq_like' => $rqLike,
        'stale_days' => $staleDays,
        'last_trade' => $lastTrade,
    ];
}

/**
 * Full scan for a user. Uses Binance account + myTrades + strategies.
 *
 * @return array{ok:bool,error?:string,user?:array,free_usdt?:float,settings?:array,items?:list,summary?:array}
 */
function legacy_portfolio_scan_user(mysqli $db, int $uid, string $apiKey, string $secret): array
{
    $cfg = legacy_scan_load_settings($db);
    $u = $db->query("SELECT id, email FROM users WHERE id=" . (int)$uid . " LIMIT 1");
    $user = $u ? $u->fetch_assoc() : ['id' => $uid, 'email' => ''];

    $strategies = [];
    $rs = $db->query(
        "SELECT s.id, s.symbol, s.status, s.fba, COALESCE(p.qty,0) AS qty, COALESCE(p.avg_price,0) AS avg_price, COALESCE(p.next_ptr,0) AS next_ptr
         FROM strategies s
         LEFT JOIN positions p ON p.strategy_id = s.id
         WHERE s.owner_id=" . (int)$uid . " AND UPPER(COALESCE(s.symbol,'')) <> 'TEMPLATE'"
    );
    if ($rs) {
        while ($row = $rs->fetch_assoc()) {
            $strategies[strtoupper((string)$row['symbol'])] = $row;
        }
    }

    if (!function_exists('http_json') || !function_exists('bn_sign_query')) {
        return ['ok' => false, 'error' => 'binance_helpers_missing'];
    }
    $ts = function_exists('bn_server_time') ? bn_server_time() : (int)(microtime(true) * 1000);
    $q = bn_sign_query(['timestamp' => $ts, 'recvWindow' => 5000], $secret);
    [$h, $r, $e] = http_json(
        (function_exists('bn_base') ? bn_base() : 'https://api.binance.com') . '/api/v3/account?' . $q,
        ['X-MBX-APIKEY: ' . $apiKey],
        'GET'
    );
    if ($e || $h >= 400) {
        return ['ok' => false, 'error' => 'binance_account', 'http' => $h];
    }
    $acc = json_decode((string)$r, true);
    if (!is_array($acc) || !isset($acc['balances'])) {
        return ['ok' => false, 'error' => 'binance_account_parse'];
    }

    $prices = [];
    [$ph, $pr] = http_json(
        (function_exists('bn_base') ? bn_base() : 'https://api.binance.com') . '/api/v3/ticker/price',
        [],
        'GET'
    );
    $priceList = ($ph < 400) ? json_decode((string)$pr, true) : null;
    if (is_array($priceList)) {
        foreach ($priceList as $t) {
            if (!empty($t['symbol'])) {
                $prices[(string)$t['symbol']] = (float)($t['price'] ?? 0);
            }
        }
    }

    $freeUsdt = 0.0;
    $held = [];
    foreach ($acc['balances'] as $b) {
        $asset = (string)($b['asset'] ?? '');
        $qty = (float)($b['free'] ?? 0) + (float)($b['locked'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if ($asset === 'USDT') {
            $freeUsdt = (float)($b['free'] ?? 0);
            continue;
        }
        $sym = $asset . 'USDT';
        $px = $prices[$sym] ?? 0.0;
        if ($px <= 0) {
            continue;
        }
        $notional = $qty * $px;
        if ($notional < 0.5) {
            continue;
        }
        $held[] = [
            'asset' => $asset,
            'symbol' => $sym,
            'qty' => $qty,
            'spot' => $px,
            'notional' => $notional,
        ];
    }
    usort($held, static fn($a, $b) => $b['notional'] <=> $a['notional']);

    $resolved = legacy_reviews_map($db, $uid);

    $items = [];
    foreach ($held as $h) {
        $sym = $h['symbol'];
        $ours = $strategies[$sym] ?? null;
        $tradeInfo = legacy_scan_cycle_from_trades($sym, $apiKey, $secret);
        $avgCost = (float)($tradeInfo['avg'] ?? 0);
        $qty = (float)$h['qty'];
        $spot = (float)$h['spot'];
        $notional = (float)$h['notional'];
        $costUsdt = $avgCost > 0 ? $avgCost * $qty : null;

        $ddPct = null;
        $recoveryPct = null;
        if ($avgCost > 0 && $spot > 0) {
            $ddPct = max(0.0, ($avgCost - $spot) / $avgCost * 100.0);
            $recoveryPct = $spot < $avgCost ? ($avgCost / $spot - 1.0) * 100.0 : 0.0;
        }

        $klass = legacy_scan_classify($cfg, $notional, $ddPct, $ours);
        $rec = legacy_scan_recommend($cfg, $klass, $freeUsdt, $tradeInfo, $ddPct);
        $rev = $resolved[$sym] ?? null;

        $items[] = [
            'symbol' => $sym,
            'asset' => $h['asset'],
            'qty' => round($qty, 8),
            'spot' => $spot,
            'notional' => round($notional, 2),
            'avg_cost' => $avgCost > 0 ? round($avgCost, 8) : null,
            'cost_usdt' => $costUsdt !== null ? round($costUsdt, 2) : null,
            'dd_pct' => $ddPct !== null ? round($ddPct, 2) : null,
            'recovery_pct' => $recoveryPct !== null ? round($recoveryPct, 2) : null,
            'klass' => $klass,
            'stale_days' => $tradeInfo['stale_days'],
            'rq_like' => $tradeInfo['rq_like'],
            'trades' => $tradeInfo['trades'],
            'buy_steps' => $tradeInfo['buy_steps'],
            'last_trade' => $tradeInfo['last_trade'],
            'strategy' => $ours ? [
                'id' => (int)$ours['id'],
                'status' => (string)$ours['status'],
                'fba' => (float)$ours['fba'],
                'qty' => (float)$ours['qty'],
                'next_ptr' => (int)$ours['next_ptr'],
            ] : null,
            'primary' => $rec['primary'],
            'alternatives' => $rec['alternatives'],
            'blocked' => $rec['blocked'],
            'reasons' => $rec['reasons'],
            'user_action' => $rev['user_action'] ?? null,
            'resolved_at' => $rev['resolved_at'] ?? null,
        ];
        if ($klass !== 'ours' && $avgCost > 0) {
            legacy_review_cache_scan_item($db, $uid, $items[count($items) - 1]);
        }
        usleep(80000);
    }

    return [
        'ok' => true,
        'user' => ['id' => (int)($user['id'] ?? $uid), 'email' => (string)($user['email'] ?? '')],
        'scanned_at' => date('c'),
        'free_usdt' => round($freeUsdt, 4),
        'settings' => $cfg,
        'items' => $items,
        'summary' => legacy_scan_summary($items),
    ];
}

function legacy_reviews_table_ready(mysqli $db): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $r = @$db->query("SHOW TABLES LIKE 'legacy_asset_reviews'");
    $ok = ($r && $r->num_rows > 0);
    return $ok;
}

/** @return array<string,array{user_action:string,resolved_at:?string}> */
function legacy_reviews_map(mysqli $db, int $uid): array
{
    $out = [];
    if (!legacy_reviews_table_ready($db)) {
        return $out;
    }
    $r = $db->query(
        "SELECT symbol, user_action, resolved_at FROM legacy_asset_reviews
         WHERE user_id=" . (int)$uid . " AND resolved_at IS NOT NULL
         ORDER BY id DESC"
    );
    if (!$r) {
        return $out;
    }
    while ($row = $r->fetch_assoc()) {
        $sym = strtoupper((string)$row['symbol']);
        if (!isset($out[$sym])) {
            $out[$sym] = [
                'user_action' => (string)$row['user_action'],
                'resolved_at' => $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
            ];
        }
    }
    return $out;
}

function legacy_review_upsert(
    mysqli $db,
    int $uid,
    string $symbol,
    string $action,
    array $snapshot,
    ?int $strategyId = null
): bool {
    if (!legacy_reviews_table_ready($db)) {
        return false;
    }
    $sym = strtoupper($symbol);
    $symEsc = $db->real_escape_string($sym);
    $actEsc = $db->real_escape_string($action);
    $payload = $db->real_escape_string(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    $klass = $db->real_escape_string((string)($snapshot['klass'] ?? ''));
    $primary = $db->real_escape_string((string)($snapshot['primary'] ?? ''));
    $notional = isset($snapshot['notional']) ? (float)$snapshot['notional'] : 0.0;
    $qty = isset($snapshot['qty']) ? (float)$snapshot['qty'] : 0.0;
    $spot = isset($snapshot['spot']) ? (float)$snapshot['spot'] : 0.0;
    $avg = isset($snapshot['avg_cost']) ? (float)$snapshot['avg_cost'] : 0.0;
    $dd = isset($snapshot['dd_pct']) ? (float)$snapshot['dd_pct'] : null;
    $ddSql = $dd === null ? 'NULL' : number_format($dd, 4, '.', '');
    $sidSql = ($strategyId !== null && $strategyId > 0) ? (string)(int)$strategyId : 'NULL';

    $db->query(
        "INSERT INTO legacy_asset_reviews
          (user_id, symbol, notional, qty, spot, avg_cost, dd_pct, klass, primary_action, user_action, strategy_id, payload_json, resolved_at, created_at, updated_at)
         VALUES
          ($uid, '$symEsc',
           " . number_format($notional, 8, '.', '') . ',
           ' . number_format($qty, 18, '.', '') . ',
           ' . number_format($spot, 18, '.', '') . ',
           ' . number_format($avg, 18, '.', '') . ",
           $ddSql, '$klass', '$primary', '$actEsc', $sidSql, '$payload', NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           notional=VALUES(notional), qty=VALUES(qty), spot=VALUES(spot), avg_cost=VALUES(avg_cost),
           dd_pct=VALUES(dd_pct), klass=VALUES(klass), primary_action=VALUES(primary_action),
           user_action=VALUES(user_action), strategy_id=VALUES(strategy_id), payload_json=VALUES(payload_json),
           resolved_at=NOW(), updated_at=NOW()"
    );
    return $db->errno === 0;
}

/**
 * Soft-cache scan metrics for cabinet full-picture PnL without resolving user action.
 */
function legacy_review_cache_scan_item(mysqli $db, int $uid, array $snapshot): bool
{
    if (!legacy_reviews_table_ready($db)) {
        return false;
    }
    $sym = strtoupper((string)($snapshot['symbol'] ?? ''));
    if ($sym === '') {
        return false;
    }
    $symEsc = $db->real_escape_string($sym);
    $payload = $db->real_escape_string(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    $klass = $db->real_escape_string((string)($snapshot['klass'] ?? ''));
    $primary = $db->real_escape_string((string)($snapshot['primary'] ?? ''));
    $notional = isset($snapshot['notional']) ? (float)$snapshot['notional'] : 0.0;
    $qty = isset($snapshot['qty']) ? (float)$snapshot['qty'] : 0.0;
    $spot = isset($snapshot['spot']) ? (float)$snapshot['spot'] : 0.0;
    $avg = isset($snapshot['avg_cost']) ? (float)$snapshot['avg_cost'] : 0.0;
    if ($avg <= 0) {
        return false;
    }
    $dd = isset($snapshot['dd_pct']) ? (float)$snapshot['dd_pct'] : null;
    $ddSql = $dd === null ? 'NULL' : number_format($dd, 4, '.', '');

    $db->query(
        "INSERT INTO legacy_asset_reviews
          (user_id, symbol, notional, qty, spot, avg_cost, dd_pct, klass, primary_action, user_action, strategy_id, payload_json, resolved_at, created_at, updated_at)
         VALUES
          ($uid, '$symEsc',
           " . number_format($notional, 8, '.', '') . ',
           ' . number_format($qty, 18, '.', '') . ',
           ' . number_format($spot, 18, '.', '') . ',
           ' . number_format($avg, 18, '.', '') . ",
           $ddSql, '$klass', '$primary', '', NULL, '$payload', NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           notional=VALUES(notional), qty=VALUES(qty), spot=VALUES(spot), avg_cost=VALUES(avg_cost),
           dd_pct=VALUES(dd_pct), klass=VALUES(klass), primary_action=VALUES(primary_action),
           payload_json=VALUES(payload_json), updated_at=NOW()"
    );
    return $db->errno === 0;
}

function legacy_ensure_strategy_levels(mysqli $db, int $sid): void
{
    $sid = (int)$sid;
    $r = $db->query("SELECT COUNT(*) c FROM strategy_levels WHERE strategy_id=$sid");
    $c = ($r && ($row = $r->fetch_assoc())) ? (int)$row['c'] : 0;
    if ($c > 0) {
        return;
    }
    $db->query(
        "INSERT INTO strategy_levels(strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct)
         SELECT $sid, lvl_idx, drop_pct, mult, tp_pct, cb_pct
         FROM strategy_levels WHERE strategy_id=0"
    );
}

/**
 * Ensure paused strategy + position row; refuse overwrite of running cycle with qty.
 *
 * @return array{ok:bool,error?:string,strategy_id?:int,position_id?:int}
 */
function legacy_ensure_paused_strategy_position(mysqli $db, int $uid, string $symbol, float $fba): array
{
    $symbol = strtoupper(trim($symbol));
    if (!preg_match('~^[A-Z0-9]{3,}USDT$~', $symbol)) {
        return ['ok' => false, 'error' => 'bad_symbol'];
    }
    if ($fba < 7) {
        $fba = 7.0;
    }
    $symEsc = $db->real_escape_string($symbol);
    $ss = $db->query("SELECT id, status FROM strategies WHERE symbol='$symEsc' AND owner_id=$uid LIMIT 1");
    $sid = 0;
    if ($ss && ($row = $ss->fetch_assoc())) {
        $sid = (int)$row['id'];
        if (in_array((string)($row['status'] ?? ''), ['running'], true)) {
            $pq = $db->query("SELECT qty FROM positions WHERE strategy_id=$sid LIMIT 1");
            $pqty = ($pq && ($pr = $pq->fetch_assoc())) ? (float)$pr['qty'] : 0.0;
            if ($pqty > 1e-12) {
                return ['ok' => false, 'error' => 'strategy_already_running'];
            }
        }
    } else {
        $db->query(
            "INSERT INTO strategies (owner_id, user_id, symbol, status, mode, fba, tp_whole, cb_whole, global_cb, start_ts)
             VALUES ($uid, $uid, '$symEsc', 'paused', 'subbin',
               " . number_format($fba, 8, '.', '') . ",
               1.400000, 0.100000, 0.100000,
               CAST(UNIX_TIMESTAMP(NOW(3))*1000 AS UNSIGNED))"
        );
        if ($db->errno) {
            return ['ok' => false, 'error' => 'db_insert_strategy', 'message' => $db->error];
        }
        $sid = (int)$db->insert_id;
    }

    legacy_ensure_strategy_levels($db, $sid);
    $db->query(
        "UPDATE strategies SET status='paused', fba=" . number_format($fba, 8, '.', '') . ",
         start_ts=CAST(UNIX_TIMESTAMP(NOW(3))*1000 AS UNSIGNED)
         WHERE id=$sid AND owner_id=$uid"
    );

    $db->query("INSERT IGNORE INTO positions (strategy_id, user_id, state, qty, created_at, updated_at)
                VALUES ($sid, $uid, 'open', 0, NOW(), NOW())");
    if ($db->errno) {
        $db->query("INSERT IGNORE INTO positions (strategy_id, state, qty, created_at, updated_at)
                    VALUES ($sid, 'open', 0, NOW(), NOW())");
    }
    $pidRow = $db->query("SELECT id FROM positions WHERE strategy_id=$sid ORDER BY id DESC LIMIT 1");
    $pid = ($pidRow && ($pr = $pidRow->fetch_assoc())) ? (int)$pr['id'] : 0;
    if ($pid <= 0) {
        return ['ok' => false, 'error' => 'no_position'];
    }
    return ['ok' => true, 'strategy_id' => $sid, 'position_id' => $pid];
}

function legacy_positions_has_legacy_fields(mysqli $db): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $r = @$db->query("SHOW COLUMNS FROM positions LIKE 'adopt_mode'");
    $ok = ($r && $r->num_rows > 0);
    return $ok;
}

/**
 * Apply wallet bag into position with legacy mode.
 * $mode: adopt_spot | hybrid | exit_plan
 */
function legacy_apply_position_bag(
    mysqli $db,
    int $uid,
    int $sid,
    int $pid,
    string $symbol,
    string $mode,
    float $qty,
    float $spot,
    array $snapshot
): array {
    $histAvg = isset($snapshot['avg_cost']) ? (float)$snapshot['avg_cost'] : 0.0;
    $dd = isset($snapshot['dd_pct']) ? (float)$snapshot['dd_pct'] : 0.0;
    $invested = $qty * $spot;

    $db->query("UPDATE sub_positions SET closed=1, updated_at=NOW() WHERE position_id=$pid AND closed=0");

    $origin = ($mode === 'hybrid') ? 'hybrid' : 'legacy';
    $legacyQtySql = ($mode === 'hybrid')
        ? number_format($qty, 18, '.', '')
        : 'NULL';

    $extra = '';
    if (legacy_positions_has_legacy_fields($db)) {
        $extra = ",
            origin='" . $db->real_escape_string($origin) . "',
            cost_avg=" . ($histAvg > 0 ? number_format($histAvg, 18, '.', '') : 'NULL') . ",
            grid_anchor=" . number_format($spot, 18, '.', '') . ",
            legacy_qty=$legacyQtySql,
            adopt_mode='" . $db->real_escape_string($mode) . "',
            adopted_at=NOW()";
    }

    $db->query(
        "UPDATE positions SET
            state='open',
            user_id=$uid,
            qty=" . number_format($qty, 18, '.', '') . ",
            invested_usdt=" . number_format($invested, 18, '.', '') . ",
            avg_price=" . number_format($spot, 18, '.', '') . ",
            entry_price=" . number_format($spot, 18, '.', '') . ",
            base_price=" . number_format($spot, 18, '.', '') . ",
            last_buy=" . number_format($spot, 18, '.', '') . ",
            next_ptr=1,
            sub_assigned_qty=0,
            last_peak=0,
            updated_at=NOW()
            $extra
         WHERE id=$pid"
    );
    if ($db->errno) {
        return ['ok' => false, 'error' => 'db_update_position', 'message' => $db->error];
    }

    $symEsc = $db->real_escape_string($symbol);
    $modeLabel = [
        'adopt_spot' => 'adopt_spot (сітка від ринку, докупки дозволені після старту)',
        'hybrid' => 'hybrid (спадок позначено; сітка від ринку; докупки дозволені)',
        'exit_plan' => 'exit_plan (лише вихід / TP, без нових докупок)',
    ][$mode] ?? $mode;
    $msg = sprintf(
        'ℹ️ %s LEGACY %s: qty≈%.8f @ spot≈%.8f. Історичний avg≈%.8f, просідання≈%.1f%%. Стратегія на паузі.',
        $symbol,
        $modeLabel,
        $qty,
        $spot,
        $histAvg,
        $dd
    );
    $msgEsc = $db->real_escape_string($msg);
    @$db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, message)
         VALUES (NOW(), $sid, '$symEsc', 'INFO', '$msgEsc')"
    );

    legacy_review_upsert($db, $uid, $symbol, $mode, $snapshot, $sid);

    return [
        'ok' => true,
        'strategy_id' => $sid,
        'status' => 'paused',
        'qty' => $qty,
        'spot' => $spot,
        'mode' => $mode,
        'note' => $mode . '_paused',
    ];
}

/**
 * @return array{ok:bool,error?:string,strategy_id?:int,status?:string}
 */
function legacy_resolve_adopt_mode(
    mysqli $db,
    int $uid,
    string $symbol,
    string $mode,
    float $qty,
    float $spot,
    float $fba,
    array $snapshot
): array {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['adopt_spot', 'hybrid', 'exit_plan'], true)) {
        return ['ok' => false, 'error' => 'bad_mode'];
    }
    if ($qty <= 0 || $spot <= 0) {
        return ['ok' => false, 'error' => 'bad_qty_or_spot'];
    }
    $klass = (string)($snapshot['klass'] ?? '');
    if ($klass === 'ours') {
        return ['ok' => false, 'error' => 'already_in_strim'];
    }
    // adopt_cost is blocked for mid/deep; adopt_spot/hybrid/exit_plan are allowed alternatives
    if (in_array($mode, (array)($snapshot['blocked'] ?? []), true)) {
        return ['ok' => false, 'error' => 'action_blocked'];
    }

    $ens = legacy_ensure_paused_strategy_position($db, $uid, $symbol, $fba);
    if (empty($ens['ok'])) {
        return $ens;
    }
    return legacy_apply_position_bag(
        $db,
        $uid,
        (int)$ens['strategy_id'],
        (int)$ens['position_id'],
        $symbol,
        $mode,
        $qty,
        $spot,
        $snapshot
    );
}

function legacy_resolve_adopt_spot(
    mysqli $db,
    int $uid,
    string $symbol,
    float $qty,
    float $spot,
    float $fba,
    array $snapshot
): array {
    return legacy_resolve_adopt_mode($db, $uid, $symbol, 'adopt_spot', $qty, $spot, $fba, $snapshot);
}

function legacy_resolve_hold(mysqli $db, int $uid, string $symbol, array $snapshot): array
{
    $symbol = strtoupper(trim($symbol));
    legacy_review_upsert($db, $uid, $symbol, 'hold', $snapshot, null);
    $symEsc = $db->real_escape_string($symbol);
    $msg = sprintf(
        'ℹ️ %s LEGACY hold: мішок залишено поза активною сіткою (просідання≈%s%%, notional≈%.2f USDT).',
        $symbol,
        isset($snapshot['dd_pct']) ? number_format((float)$snapshot['dd_pct'], 1, '.', '') : 'n/a',
        (float)($snapshot['notional'] ?? 0)
    );
    $msgEsc = $db->real_escape_string($msg);
    $sid = 0;
    $ss = $db->query("SELECT id FROM strategies WHERE owner_id=$uid AND symbol='$symEsc' LIMIT 1");
    if ($ss && ($r = $ss->fetch_assoc())) {
        $sid = (int)$r['id'];
    }
    if ($sid > 0) {
        @$db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, message)
             VALUES (NOW(), $sid, '$symEsc', 'INFO', '$msgEsc')"
        );
    }
    return ['ok' => true, 'action' => 'hold', 'symbol' => $symbol];
}

function legacy_human_reason_uk(string $code): string
{
    $map = [
        'deep_drawdown' => 'глибоке просідання',
        'mid_drawdown' => 'помірне просідання',
        'stale' => 'давно без угод',
        'rq_like_grid' => 'схоже на сітку іншого бота',
        'low_usdt' => 'мало вільного USDT',
        'dust' => 'пил (&lt;7 USDT)',
        'already_in_strim' => 'вже в StrIM',
        'low_drawdown' => 'мале просідання',
        'unknown_cost_basis' => 'немає собівартості',
    ];
    return $map[$code] ?? $code;
}

function legacy_human_action_uk(string $code): string
{
    $map = [
        'hold' => 'Залишити під наглядом',
        'adopt_spot' => 'Підхопити від ринку',
        'adopt_cost' => 'Підхопити від собівартості',
        'hybrid' => 'Гібрид (спадок + сітка від ринку)',
        'exit_plan' => 'План виходу (без докупок)',
        'topup_usdt' => 'Спочатку поповнити USDT',
        'ignore' => 'Ігнорувати пил',
        'sell_dust' => 'Продати пил',
        'manage_existing' => 'Керувати в StrIM',
    ];
    return $map[$code] ?? $code;
}

/**
 * Latest stored cost basis per symbol from legacy_asset_reviews (for cabinet full-picture PnL).
 *
 * @return array<string, array{avg_cost:float,user_action:string,dd_pct:?float,klass:string}>
 */
function legacy_review_cost_map(mysqli $db, int $uid): array
{
    $out = [];
    if (!legacy_reviews_table_ready($db) || $uid <= 0) {
        return $out;
    }
    $uid = (int)$uid;
    $r = @$db->query(
        "SELECT lar.symbol, lar.avg_cost, lar.user_action, lar.dd_pct, lar.klass
         FROM legacy_asset_reviews lar
         INNER JOIN (
           SELECT symbol, MAX(id) AS mid
           FROM legacy_asset_reviews
           WHERE user_id={$uid}
           GROUP BY symbol
         ) t ON t.mid = lar.id
         WHERE lar.avg_cost IS NOT NULL AND lar.avg_cost > 0"
    );
    if (!$r) {
        return $out;
    }
    while ($row = $r->fetch_assoc()) {
        $sym = strtoupper((string)($row['symbol'] ?? ''));
        if ($sym === '' || !str_ends_with($sym, 'USDT')) {
            continue;
        }
        $avg = (float)($row['avg_cost'] ?? 0);
        if ($avg <= 0) {
            continue;
        }
        $dd = $row['dd_pct'];
        $out[$sym] = [
            'avg_cost' => $avg,
            'user_action' => (string)($row['user_action'] ?? ''),
            'dd_pct' => ($dd !== null && $dd !== '') ? (float)$dd : null,
            'klass' => (string)($row['klass'] ?? ''),
        ];
    }
    return $out;
}
