<?php
declare(strict_types=1);

/**
 * Trading efficiency analytics: daily snapshots, period reports, advice.
 */

require_once __DIR__ . '/_admin_realized_pnl.php';
require_once __DIR__ . '/_user_keys.php';
require_once __DIR__ . '/_ws_price.php';
require_once __DIR__ . '/_binance_utils.php';

function analytics_tables_ready(mysqli $db): bool
{
    $r = @$db->query("SHOW TABLES LIKE 'analytics_user_daily'");
    return $r && $r->num_rows > 0;
}

function analytics_realized_pnl_since(mysqli $db, int $userId, ?string $sinceSql = null, ?int $strategyId = null): float
{
    if ($userId <= 0) {
        return 0.0;
    }
    $uid = (int)$userId;
    $extra = '';
    if ($sinceSql !== null && $sinceSql !== '') {
        $extra .= " AND tl.created_at >= '" . $db->real_escape_string($sinceSql) . "'";
    }
    if ($strategyId !== null && $strategyId > 0) {
        $extra .= ' AND tl.strategy_id=' . (int)$strategyId;
    }
    $res = $db->query("
        SELECT COALESCE(SUM(x.pnl), 0) AS pnl
        FROM (
            SELECT MAX(COALESCE(tl.pnl_usdt, 0)) AS pnl
            FROM trade_ledger tl
            WHERE tl.user_id = {$uid}
              AND tl.event_type IN ('SELL_SUB', 'SELL_MAIN')
              {$extra}
            GROUP BY
                tl.strategy_id,
                tl.event_type,
                COALESCE(tl.order_id, 0),
                tl.created_at,
                ROUND(COALESCE(tl.price, 0), 12),
                ROUND(COALESCE(tl.qty, 0), 12)
        ) x
    ");
    if (!$res || !($row = $res->fetch_assoc())) {
        return 0.0;
    }
    return (float)($row['pnl'] ?? 0);
}

function analytics_count_events(mysqli $db, int $userId, array $types, string $sinceSql, ?int $strategyId = null): int
{
    $uid = (int)$userId;
    $in = [];
    foreach ($types as $t) {
        $in[] = "'" . $db->real_escape_string((string)$t) . "'";
    }
    if ($in === []) {
        return 0;
    }
    $extra = $strategyId !== null && $strategyId > 0 ? (' AND strategy_id=' . (int)$strategyId) : '';
    $sinceEsc = $db->real_escape_string($sinceSql);
    $r = $db->query(
        "SELECT COUNT(*) c FROM trade_ledger
         WHERE user_id={$uid} AND event_type IN (" . implode(',', $in) . ")
           AND created_at >= '{$sinceEsc}' {$extra}"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return (int)($row['c'] ?? 0);
}

/**
 * @return array{free:float,locked:float,ok:bool,error?:string}
 */
function analytics_binance_usdt(mysqli $db, int $userId): array
{
    try {
        [$apiKey, $secret] = user_keys($db, $userId);
        if (!$apiKey || !$secret) {
            return ['free' => 0.0, 'locked' => 0.0, 'ok' => false, 'error' => 'no_keys'];
        }
        if (!function_exists('http_json') || !function_exists('bn_base') || !function_exists('bn_sign_query')) {
            return ['free' => 0.0, 'locked' => 0.0, 'ok' => false, 'error' => 'no_bn_helpers'];
        }
        $ts = function_exists('bn_server_time') ? bn_server_time() : (int)(microtime(true) * 1000);
        $q = bn_sign_query(['timestamp' => $ts, 'recvWindow' => 5000], $secret);
        [$h, $r, $e] = http_json(bn_base() . '/api/v3/account?' . $q, ['X-MBX-APIKEY: ' . $apiKey], 'GET');
        if ($e || $h >= 400) {
            return ['free' => 0.0, 'locked' => 0.0, 'ok' => false, 'error' => 'http_' . (int)$h];
        }
        $acc = json_decode((string)$r, true) ?: [];
        foreach (($acc['balances'] ?? []) as $b) {
            if (($b['asset'] ?? '') === 'USDT') {
                return [
                    'free' => (float)($b['free'] ?? 0),
                    'locked' => (float)($b['locked'] ?? 0),
                    'ok' => true,
                ];
            }
        }
        return ['free' => 0.0, 'locked' => 0.0, 'ok' => true];
    } catch (Throwable $ex) {
        return ['free' => 0.0, 'locked' => 0.0, 'ok' => false, 'error' => $ex->getMessage()];
    }
}

/**
 * Best-effort Binance USDT deposits for last N days (needs capital reading permission).
 */
function analytics_binance_deposits_usdt(mysqli $db, int $userId, int $days = 2): ?float
{
    try {
        [$apiKey, $secret] = user_keys($db, $userId);
        if (!$apiKey || !$secret || !function_exists('http_json')) {
            return null;
        }
        $ts = function_exists('bn_server_time') ? bn_server_time() : (int)(microtime(true) * 1000);
        $start = $ts - max(1, $days) * 86400000;
        $params = [
            'coin' => 'USDT',
            'status' => 1,
            'startTime' => $start,
            'timestamp' => $ts,
            'recvWindow' => 5000,
        ];
        $q = bn_sign_query($params, $secret);
        [$h, $r, $e] = http_json(bn_base() . '/sapi/v1/capital/deposit/hisrec?' . $q, ['X-MBX-APIKEY: ' . $apiKey], 'GET');
        if ($e || $h >= 400) {
            return null;
        }
        $list = json_decode((string)$r, true);
        if (!is_array($list)) {
            return null;
        }
        $sum = 0.0;
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtoupper((string)($row['coin'] ?? '')) !== 'USDT') {
                continue;
            }
            $sum += (float)($row['amount'] ?? 0);
        }
        return $sum;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{score:int,flags:list<string>}
 */
function analytics_risk_score(
    float $capital,
    float $fbaSum,
    int $pairsRunning,
    int $maxOpenLevel,
    int $openSubs
): array {
    $flags = [];
    $score = 0;
    if ($capital <= 1e-9) {
        $flags[] = 'немає даних капіталу Binance';
        $score += 20;
        $capital = max($fbaSum, 1.0);
    }
    $ratio = $fbaSum / max($capital, 1e-9);
    if ($ratio > 0.85) {
        $flags[] = 'сума FBA близька/вища за капітал на Binance';
        $score += 35;
    } elseif ($ratio > 0.55) {
        $flags[] = 'високе навантаження FBA відносно капіталу';
        $score += 20;
    }
    if ($pairsRunning >= 8) {
        $flags[] = 'багато одночасних пар (' . $pairsRunning . ')';
        $score += 15;
    } elseif ($pairsRunning >= 5) {
        $score += 8;
    }
    if ($maxOpenLevel >= 14) {
        $flags[] = 'глибока сітка (L' . $maxOpenLevel . ')';
        $score += 20;
    } elseif ($maxOpenLevel >= 10) {
        $flags[] = 'середня глибина сітки (L' . $maxOpenLevel . ')';
        $score += 10;
    }
    if ($openSubs >= 40) {
        $flags[] = 'багато відкритих L5+ (' . $openSubs . ')';
        $score += 15;
    }
    if ($pairsRunning > 0 && $fbaSum / $pairsRunning < 7.0) {
        $flags[] = 'FBA нижче рекомендованих 7 USDT';
        $score += 10;
    }
    return ['score' => max(0, min(100, $score)), 'flags' => $flags];
}

/**
 * Collect one day snapshot for all trading users.
 * @return array{users:int,pairs:int,errors:list<string>}
 */
function analytics_collect_daily(mysqli $db, ?string $snapDate = null): array
{
    if (!analytics_tables_ready($db)) {
        throw new RuntimeException('analytics tables missing — apply migration 20260927_create_trading_analytics.sql');
    }
    $date = $snapDate ?: date('Y-m-d');
    $dateEsc = $db->real_escape_string($date);
    $d1 = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') - 86400);
    $d7 = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') - 7 * 86400);
    $d30 = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') - 30 * 86400);

    $users = [];
    $ur = $db->query("
        SELECT DISTINCT u.id, u.email, u.nickname, u.billing_status
        FROM users u
        INNER JOIN strategies s ON s.user_id = u.id
        WHERE s.status IN ('running','paused')
           OR EXISTS (
             SELECT 1 FROM trade_ledger tl
             WHERE tl.user_id = u.id AND tl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           )
    ");
    while ($ur && ($row = $ur->fetch_assoc())) {
        $users[] = $row;
    }

    $userN = 0;
    $pairN = 0;
    $errors = [];

    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $bn = analytics_binance_usdt($db, $uid);
        if (!$bn['ok'] && !empty($bn['error']) && $bn['error'] !== 'no_keys') {
            $errors[] = "uid={$uid} bn=" . $bn['error'];
        }
        $depApi = analytics_binance_deposits_usdt($db, $uid, 2);

        $st = $db->query("
            SELECT id, symbol, status, fba
            FROM strategies
            WHERE user_id={$uid} AND status IN ('running','paused')
            ORDER BY id
        ");
        $pairsRunning = 0;
        $pairsPaused = 0;
        $fbaSum = 0.0;
        $fbas = [];
        $invMark = 0.0;
        $openSubs = 0;
        $maxLvl = 0;
        $pairRows = [];

        while ($st && ($s = $st->fetch_assoc())) {
            $sid = (int)$s['id'];
            $sym = strtoupper((string)$s['symbol']);
            $status = (string)$s['status'];
            $fba = (float)$s['fba'];
            if ($status === 'running') {
                $pairsRunning++;
                $fbaSum += $fba;
                $fbas[] = $fba;
            } else {
                $pairsPaused++;
            }

            $pos = $db->query("
                SELECT id, qty, avg_price, invested_usdt
                FROM positions
                WHERE strategy_id={$sid} AND user_id={$uid}
                ORDER BY id DESC LIMIT 1
            ")->fetch_assoc();
            $qty = $pos ? (float)$pos['qty'] : 0.0;
            $avg = $pos ? (float)$pos['avg_price'] : 0.0;
            $pid = $pos ? (int)$pos['id'] : 0;
            $mark = 0.0;
            if ($sym !== '' && $sym !== 'TEMPLATE' && function_exists('ws_ticker_price')) {
                $mark = (float)ws_ticker_price($sym);
            }
            $markInv = ($qty > 0 && $mark > 0) ? $qty * $mark : 0.0;
            $unreal = ($qty > 0 && $mark > 0 && $avg > 0) ? ($mark - $avg) * $qty : 0.0;
            $invMark += $markInv;

            $subsN = 0;
            $lvlMax = 0;
            if ($pid > 0) {
                $sr = $db->query("
                    SELECT COUNT(*) n, COALESCE(MAX(level_idx),0) mx
                    FROM sub_positions WHERE position_id={$pid} AND closed=0 AND level_idx>=5
                ")->fetch_assoc();
                $subsN = (int)($sr['n'] ?? 0);
                $lvlMax = (int)($sr['mx'] ?? 0);
            }
            $openSubs += $subsN;
            $maxLvl = max($maxLvl, $lvlMax);

            $rpAll = analytics_realized_pnl_since($db, $uid, null, $sid);
            $rp1 = analytics_realized_pnl_since($db, $uid, $d1, $sid);
            $rp7 = analytics_realized_pnl_since($db, $uid, $d7, $sid);

            $pairRows[] = [
                'strategy_id' => $sid,
                'symbol' => $sym,
                'status' => $status,
                'fba' => $fba,
                'qty' => $qty,
                'avg' => $avg,
                'mark' => $mark,
                'inv' => $markInv,
                'unreal' => $unreal,
                'rp_all' => $rpAll,
                'rp_1' => $rp1,
                'rp_7' => $rp7,
                'subs' => $subsN,
                'max_lvl' => $lvlMax,
            ];
        }

        $capital = (float)$bn['free'] + (float)$bn['locked'] + $invMark;
        $risk = analytics_risk_score($capital, $fbaSum, $pairsRunning, $maxLvl, $openSubs);
        $rpAll = admin_fetch_realized_pnl($db, $uid);
        $rp1 = analytics_realized_pnl_since($db, $uid, $d1);
        $rp7 = analytics_realized_pnl_since($db, $uid, $d7);
        $rp30 = analytics_realized_pnl_since($db, $uid, $d30);
        $sells1 = analytics_count_events($db, $uid, ['SELL_SUB', 'SELL_MAIN'], $d1);
        $sells7 = analytics_count_events($db, $uid, ['SELL_SUB', 'SELL_MAIN'], $d7);
        $buys1 = analytics_count_events($db, $uid, ['BUY0', 'BUY_L', 'BUY_SUB'], $d1);

        $prev = $db->query(
            "SELECT capital_total_usdt FROM analytics_user_daily
             WHERE user_id={$uid} AND snap_date < '{$dateEsc}'
             ORDER BY snap_date DESC LIMIT 1"
        )->fetch_assoc();
        $prevCap = $prev ? (float)$prev['capital_total_usdt'] : null;
        $inferred = 0.0;
        if ($prevCap !== null && $capital > 0) {
            $jump = $capital - $prevCap - $rp1;
            // Large unexplained capital increase ≈ deposit (proxy)
            if ($jump >= 20.0) {
                $inferred = round($jump, 8);
            }
        }

        $fbaPct = $capital > 1e-9 ? round(100.0 * $fbaSum / $capital, 4) : null;
        $eff = $capital > 1e-9 ? round(100.0 * $rp7 / $capital, 4) : null;
        $fbaAvg = $fbas !== [] ? array_sum($fbas) / count($fbas) : 0.0;
        $nickEsc = $db->real_escape_string((string)($u['nickname'] ?? ''));
        $emailEsc = $db->real_escape_string((string)($u['email'] ?? ''));
        $billEsc = $db->real_escape_string((string)($u['billing_status'] ?? ''));
        $flagsJson = $db->real_escape_string(json_encode($risk['flags'], JSON_UNESCAPED_UNICODE));
        $metaJson = $db->real_escape_string(json_encode([
            'bn_ok' => $bn['ok'],
            'bn_error' => $bn['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE));

        $depApiSql = $depApi === null ? 'NULL' : number_format($depApi, 8, '.', '');
        $fbaPctSql = $fbaPct === null ? 'NULL' : number_format($fbaPct, 4, '.', '');
        $effSql = $eff === null ? 'NULL' : number_format($eff, 4, '.', '');

        $db->query("
            INSERT INTO analytics_user_daily (
              snap_date, user_id, nickname, email, billing_status,
              pairs_running, pairs_paused, pairs_total,
              fba_sum_running, fba_avg_running, fba_min_running, fba_max_running,
              binance_usdt_free, binance_usdt_locked, inventory_mark_usdt, capital_total_usdt,
              deposit_inferred_usdt, deposit_api_usdt,
              realized_pnl_all, realized_pnl_1d, realized_pnl_7d, realized_pnl_30d,
              sells_1d, sells_7d, buys_1d, open_subs_l5plus, max_open_level,
              fba_to_capital_pct, risk_score, risk_flags_json, efficiency_score, meta_json
            ) VALUES (
              '{$dateEsc}', {$uid}, '{$nickEsc}', '{$emailEsc}', '{$billEsc}',
              {$pairsRunning}, {$pairsPaused}, " . ($pairsRunning + $pairsPaused) . ",
              " . number_format($fbaSum, 8, '.', '') . ",
              " . number_format($fbaAvg, 8, '.', '') . ",
              " . ($fbas ? number_format(min($fbas), 8, '.', '') : 'NULL') . ",
              " . ($fbas ? number_format(max($fbas), 8, '.', '') : 'NULL') . ",
              " . number_format((float)$bn['free'], 8, '.', '') . ",
              " . number_format((float)$bn['locked'], 8, '.', '') . ",
              " . number_format($invMark, 8, '.', '') . ",
              " . number_format($capital, 8, '.', '') . ",
              " . number_format($inferred, 8, '.', '') . ",
              {$depApiSql},
              " . number_format($rpAll, 8, '.', '') . ",
              " . number_format($rp1, 8, '.', '') . ",
              " . number_format($rp7, 8, '.', '') . ",
              " . number_format($rp30, 8, '.', '') . ",
              {$sells1}, {$sells7}, {$buys1}, {$openSubs}, {$maxLvl},
              {$fbaPctSql}, {$risk['score']}, '{$flagsJson}', {$effSql}, '{$metaJson}'
            )
            ON DUPLICATE KEY UPDATE
              nickname=VALUES(nickname), email=VALUES(email), billing_status=VALUES(billing_status),
              pairs_running=VALUES(pairs_running), pairs_paused=VALUES(pairs_paused), pairs_total=VALUES(pairs_total),
              fba_sum_running=VALUES(fba_sum_running), fba_avg_running=VALUES(fba_avg_running),
              fba_min_running=VALUES(fba_min_running), fba_max_running=VALUES(fba_max_running),
              binance_usdt_free=VALUES(binance_usdt_free), binance_usdt_locked=VALUES(binance_usdt_locked),
              inventory_mark_usdt=VALUES(inventory_mark_usdt), capital_total_usdt=VALUES(capital_total_usdt),
              deposit_inferred_usdt=VALUES(deposit_inferred_usdt), deposit_api_usdt=VALUES(deposit_api_usdt),
              realized_pnl_all=VALUES(realized_pnl_all), realized_pnl_1d=VALUES(realized_pnl_1d),
              realized_pnl_7d=VALUES(realized_pnl_7d), realized_pnl_30d=VALUES(realized_pnl_30d),
              sells_1d=VALUES(sells_1d), sells_7d=VALUES(sells_7d), buys_1d=VALUES(buys_1d),
              open_subs_l5plus=VALUES(open_subs_l5plus), max_open_level=VALUES(max_open_level),
              fba_to_capital_pct=VALUES(fba_to_capital_pct), risk_score=VALUES(risk_score),
              risk_flags_json=VALUES(risk_flags_json), efficiency_score=VALUES(efficiency_score),
              meta_json=VALUES(meta_json)
        ");
        $userN++;

        foreach ($pairRows as $pr) {
            $symEsc = $db->real_escape_string($pr['symbol']);
            $stEsc = $db->real_escape_string($pr['status']);
            $db->query("
                INSERT INTO analytics_pair_daily (
                  snap_date, user_id, strategy_id, symbol, status, fba,
                  open_qty, avg_price, mark_price, inventory_mark_usdt, unrealized_pnl_usdt,
                  realized_pnl_all, realized_pnl_1d, realized_pnl_7d,
                  open_subs, max_open_level
                ) VALUES (
                  '{$dateEsc}', {$uid}, {$pr['strategy_id']}, '{$symEsc}', '{$stEsc}',
                  " . number_format((float)$pr['fba'], 8, '.', '') . ",
                  " . number_format((float)$pr['qty'], 12, '.', '') . ",
                  " . number_format((float)$pr['avg'], 12, '.', '') . ",
                  " . number_format((float)$pr['mark'], 12, '.', '') . ",
                  " . number_format((float)$pr['inv'], 8, '.', '') . ",
                  " . number_format((float)$pr['unreal'], 8, '.', '') . ",
                  " . number_format((float)$pr['rp_all'], 8, '.', '') . ",
                  " . number_format((float)$pr['rp_1'], 8, '.', '') . ",
                  " . number_format((float)$pr['rp_7'], 8, '.', '') . ",
                  {$pr['subs']}, {$pr['max_lvl']}
                )
                ON DUPLICATE KEY UPDATE
                  status=VALUES(status), fba=VALUES(fba), open_qty=VALUES(open_qty),
                  avg_price=VALUES(avg_price), mark_price=VALUES(mark_price),
                  inventory_mark_usdt=VALUES(inventory_mark_usdt),
                  unrealized_pnl_usdt=VALUES(unrealized_pnl_usdt),
                  realized_pnl_all=VALUES(realized_pnl_all),
                  realized_pnl_1d=VALUES(realized_pnl_1d),
                  realized_pnl_7d=VALUES(realized_pnl_7d),
                  open_subs=VALUES(open_subs), max_open_level=VALUES(max_open_level)
            ");
            $pairN++;
        }
    }

    return ['users' => $userN, 'pairs' => $pairN, 'errors' => $errors];
}

/**
 * @return array{id:int,title:string,summary_uk:string}
 */
function analytics_build_period_report(mysqli $db, string $periodType, string $start, string $end): array
{
    if (!in_array($periodType, ['week', 'month', 'year'], true)) {
        throw new InvalidArgumentException('bad period_type');
    }
    $startEsc = $db->real_escape_string($start);
    $endEsc = $db->real_escape_string($end);

    $rows = [];
    $r = $db->query("
        SELECT d.*
        FROM analytics_user_daily d
        INNER JOIN (
          SELECT user_id, MAX(snap_date) mx
          FROM analytics_user_daily
          WHERE snap_date BETWEEN '{$startEsc}' AND '{$endEsc}'
          GROUP BY user_id
        ) t ON t.user_id = d.user_id AND t.mx = d.snap_date
        ORDER BY COALESCE(d.efficiency_score, -999) DESC, d.realized_pnl_7d DESC
    ");
    while ($r && ($row = $r->fetch_assoc())) {
        $rows[] = $row;
    }

    // Period PnL from first→last snapshot delta of realized_pnl_all
    $ranking = [];
    $sumPnlPeriod = 0.0;
    $sumDep = 0.0;
    $highRisk = 0;
    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        $first = $db->query("
            SELECT realized_pnl_all, capital_total_usdt, pairs_running, fba_sum_running
            FROM analytics_user_daily
            WHERE user_id={$uid} AND snap_date BETWEEN '{$startEsc}' AND '{$endEsc}'
            ORDER BY snap_date ASC LIMIT 1
        ")->fetch_assoc();
        $last = $row;
        $pnlPeriod = (float)$last['realized_pnl_all'] - (float)($first['realized_pnl_all'] ?? $last['realized_pnl_all']);
        // fallback: use 7d/30d fields on last snap for short windows
        if (abs($pnlPeriod) < 1e-9) {
            $pnlPeriod = $periodType === 'week'
                ? (float)$last['realized_pnl_7d']
                : (float)$last['realized_pnl_30d'];
        }
        $sumPnlPeriod += $pnlPeriod;
        $dep = (float)$db->query("
            SELECT COALESCE(SUM(GREATEST(deposit_inferred_usdt, COALESCE(deposit_api_usdt,0))),0) s
            FROM analytics_user_daily
            WHERE user_id={$uid} AND snap_date BETWEEN '{$startEsc}' AND '{$endEsc}'
        ")->fetch_assoc()['s'];
        $sumDep += $dep;
        if ((int)$last['risk_score'] >= 50) {
            $highRisk++;
        }
        $cap = (float)($last['capital_total_usdt'] ?? 0);
        $ranking[] = [
            'user_id' => $uid,
            'nickname' => (string)($last['nickname'] ?: ('#' . $uid)),
            'pnl_period' => round($pnlPeriod, 4),
            'efficiency_score' => $last['efficiency_score'] !== null ? (float)$last['efficiency_score'] : null,
            'capital_usdt' => round($cap, 2),
            'pairs_running' => (int)$last['pairs_running'],
            'fba_sum' => round((float)$last['fba_sum_running'], 2),
            'fba_to_capital_pct' => $last['fba_to_capital_pct'] !== null ? (float)$last['fba_to_capital_pct'] : null,
            'risk_score' => (int)$last['risk_score'],
            'risk_flags' => json_decode((string)($last['risk_flags_json'] ?? '[]'), true) ?: [],
            'max_open_level' => (int)$last['max_open_level'],
            'deposit_period_usdt' => round($dep, 2),
        ];
    }
    usort($ranking, static function ($a, $b) {
        return $b['pnl_period'] <=> $a['pnl_period'];
    });

    // Pair symbols performance (sum realized_1d across days ≈ rough; use last snap 7d by symbol)
    $pairStats = [];
    $pr = $db->query("
        SELECT symbol,
               COUNT(DISTINCT user_id) users_n,
               ROUND(AVG(fba),2) fba_avg,
               ROUND(SUM(realized_pnl_7d),4) pnl7_sum,
               ROUND(AVG(open_subs),1) subs_avg,
               ROUND(AVG(max_open_level),1) lvl_avg
        FROM analytics_pair_daily
        WHERE snap_date = '{$endEsc}' AND status='running' AND symbol <> 'TEMPLATE'
        GROUP BY symbol
        ORDER BY pnl7_sum DESC
        LIMIT 30
    ");
    while ($pr && ($p = $pr->fetch_assoc())) {
        $pairStats[] = $p;
    }

    $label = match ($periodType) {
        'week' => 'Тижневий',
        'month' => 'Місячний',
        'year' => 'Річний',
    };
    $title = "{$label} звіт ефективності торгівлі ({$start} — {$end})";

    $top = array_slice($ranking, 0, 5);
    $bottom = array_slice(array_reverse($ranking), 0, 3);

    $summary = sprintf(
        "%s огляд StrIM: користувачів у вибірці %d; сумарний реалізований PnL за період ≈ %.2f USDT; оцінені поповнення капіталу ≈ %.2f USDT; з підвищеним ризиком налаштувань — %d.",
        $label,
        count($ranking),
        $sumPnlPeriod,
        $sumDep,
        $highRisk
    );

    $lines = [];
    $lines[] = $summary;
    $lines[] = '';
    $lines[] = '1) Рейтинг ефективності (за приростом реалізованого PnL)';
    if ($top === []) {
        $lines[] = 'Немає знімків за період — спочатку має відпрацювати щоденний збір о 08:00.';
    } else {
        $i = 1;
        foreach ($top as $t) {
            $lines[] = sprintf(
                '%d. %s — PnL періоду %.2f USDT; пар running %d; FBAΣ %.2f; капітал ~%.2f; ризик %d/100; eff_7d %s.',
                $i++,
                $t['nickname'],
                $t['pnl_period'],
                $t['pairs_running'],
                $t['fba_sum'],
                $t['capital_usdt'],
                $t['risk_score'],
                $t['efficiency_score'] === null ? 'н/д' : (string)$t['efficiency_score']
            );
            if ($t['risk_flags']) {
                $lines[] = '   Ризик-прапорці: ' . implode('; ', $t['risk_flags']);
            }
        }
    }
    $lines[] = '';
    $lines[] = '2) Менш ефективні / ризикові профілі';
    foreach ($bottom as $t) {
        if ($t['pnl_period'] >= 0 && $t['risk_score'] < 40) {
            continue;
        }
        $lines[] = sprintf(
            '• %s — PnL %.2f; ризик %d/100; пар %d; FBA/капітал %s%%',
            $t['nickname'],
            $t['pnl_period'],
            $t['risk_score'],
            $t['pairs_running'],
            $t['fba_to_capital_pct'] === null ? 'н/д' : (string)round($t['fba_to_capital_pct'], 1)
        );
    }
    $lines[] = '';
    $lines[] = '3) Пари (останній знімок періоду, running)';
    foreach (array_slice($pairStats, 0, 10) as $p) {
        $lines[] = sprintf(
            '• %s — юзерів %d; сер. FBA %.2f; Σ PnL_7d %.2f; сер. глибина L%.0f',
            $p['symbol'],
            (int)$p['users_n'],
            (float)$p['fba_avg'],
            (float)$p['pnl7_sum'],
            (float)$p['lvl_avg']
        );
    }
    $lines[] = '';
    $lines[] = '4) Висновки щодо налаштувань';
    $lines[] = '• Ефективніше зазвичай виглядають акаунти з помірною кількістю пар і FBA, узгодженим із вільним USDT (орієнтир: сума FBA running не більше ~50–60% капіталу).';
    $lines[] = '• Глибокі сітки (L10+) підвищують ризик заморозки коштів — потрібен запас USDT або менший FBA.';
    $lines[] = '• FBA нижче 7 USDT частіше дає біржові NOTIONAL/пил і погіршує реалізацію.';
    $lines[] = '• Порівняння між юзерами орієнтовне: різний час старту, депозити і волатильність пар.';
    $lines[] = '';
    $lines[] = 'Увага: це аналітика ефективності налаштувань, а не інвестиційна порада і не гарантія прибутку.';

    $body = implode("\n", $lines);
    $advice = analytics_compose_advice_uk($ranking, $pairStats, $periodType);

    $metrics = [
        'users' => count($ranking),
        'pnl_period_usdt' => round($sumPnlPeriod, 4),
        'deposits_proxy_usdt' => round($sumDep, 4),
        'high_risk_users' => $highRisk,
        'pairs_top' => $pairStats,
    ];
    $metricsJson = $db->real_escape_string(json_encode($metrics, JSON_UNESCAPED_UNICODE));
    $rankJson = $db->real_escape_string(json_encode($ranking, JSON_UNESCAPED_UNICODE));
    $titleEsc = $db->real_escape_string($title);
    $sumEsc = $db->real_escape_string($summary);
    $bodyEsc = $db->real_escape_string($body);
    $ptEsc = $db->real_escape_string($periodType);

    $db->query("
        INSERT INTO analytics_report (period_type, period_start, period_end, title, summary_uk, body_uk, metrics_json, ranking_json)
        VALUES ('{$ptEsc}', '{$startEsc}', '{$endEsc}', '{$titleEsc}', '{$sumEsc}', '{$bodyEsc}', '{$metricsJson}', '{$rankJson}')
        ON DUPLICATE KEY UPDATE
          title=VALUES(title), summary_uk=VALUES(summary_uk), body_uk=VALUES(body_uk),
          metrics_json=VALUES(metrics_json), ranking_json=VALUES(ranking_json)
    ");
    $idRes = $db->query("SELECT id FROM analytics_report WHERE period_type='{$ptEsc}' AND period_start='{$startEsc}' AND period_end='{$endEsc}' LIMIT 1");
    $id = (int)(($idRes && ($x = $idRes->fetch_assoc())) ? $x['id'] : 0);

    if ($id > 0) {
        $db->query("UPDATE analytics_advice SET active=0 WHERE report_id={$id} AND scope='global'");
        $advEsc = $db->real_escape_string($advice);
        $db->query("
            INSERT INTO analytics_advice (report_id, scope, advice_uk, priority, active)
            VALUES ({$id}, 'global', '{$advEsc}', 80, 1)
        ");
    }

    return ['id' => $id, 'title' => $title, 'summary_uk' => $summary];
}

/**
 * @param list<array<string,mixed>> $ranking
 * @param list<array<string,mixed>> $pairStats
 */
function analytics_compose_advice_uk(array $ranking, array $pairStats, string $periodType): string
{
    $parts = [];
    $parts[] = 'Узагальнююча порада для майбутніх користувачів StrIM (' . $periodType . '):';
    $parts[] = '';
    $bestPairs = array_slice($pairStats, 0, 3);
    if ($bestPairs !== []) {
        $names = array_map(static fn ($p) => (string)$p['symbol'], $bestPairs);
        $parts[] = '• Серед активних пар у вибірці відносно краще трималися: ' . implode(', ', $names) . '. Це не гарантія на майбутнє — ліквідність і волатильність змінюються.';
    } else {
        $parts[] = '• Поки мало даних по парах — після кількох щоденних знімків рейтинг пар стане інформативнішим.';
    }

    $good = null;
    foreach ($ranking as $t) {
        if ($t['pnl_period'] > 0 && $t['risk_score'] < 45 && $t['pairs_running'] > 0) {
            $good = $t;
            break;
        }
    }
    if ($good) {
        $parts[] = sprintf(
            '• Орієнтир з ефективніших профілів: близько %d пар, сума FBA ~%.0f USDT при капіталі ~%.0f USDT (FBA/капітал ~%s%%).',
            (int)$good['pairs_running'],
            (float)$good['fba_sum'],
            (float)$good['capital_usdt'],
            $good['fba_to_capital_pct'] === null ? 'н/д' : (string)round((float)$good['fba_to_capital_pct'], 0)
        );
    }
    $parts[] = '• Рекомендований старт: 1–3 пари, FBA не менше 7 USDT, запас вільного USDT на кілька сходинок сітки.';
    $parts[] = '• Уникайте одночасного запуску багатьох пар на весь депозит — зростає ризик заморозки коштів і пропуску докупок.';
    $parts[] = '• Глибина сітки L10+ має сенс лише з достатнім капіталом; інакше зменшіть FBA або кількість пар.';
    $parts[] = '';
    $parts[] = 'Це узагальнення внутрішньої аналітики платформи, не індивідуальна фінпорада і без гарантії прибутку.';
    return implode("\n", $parts);
}

/**
 * Sunday job: week + month (if month boundary week) + year (if year boundary week).
 * Always builds last complete week (Mon–Sun) ending yesterday or today if Sunday.
 *
 * @return list<array{id:int,title:string}>
 */
function analytics_run_scheduled_reports(mysqli $db, ?DateTimeInterface $now = null): array
{
    $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
    // Week: last 7 days including today
    $end = $nowDt->format('Y-m-d');
    $start = $nowDt->modify('-6 days')->format('Y-m-d');
    $out = [];
    $out[] = analytics_build_period_report($db, 'week', $start, $end);

    // Month-to-date
    $mStart = $nowDt->format('Y-m-01');
    $out[] = analytics_build_period_report($db, 'month', $mStart, $end);

    // Year-to-date
    $yStart = $nowDt->format('Y-01-01');
    $out[] = analytics_build_period_report($db, 'year', $yStart, $end);

    return $out;
}
