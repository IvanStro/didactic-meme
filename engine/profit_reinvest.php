<?php
declare(strict_types=1);

/**
 * Bot rule: when profit_reinvest=0, positive realized PnL since OFF
 * is reserved and not used for BUY0 / ladder buys.
 */

function profit_reinvest_schema_ready(mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $r = @$db->query("SHOW COLUMNS FROM users LIKE 'profit_reinvest'");
    $ready = ($r && $r->num_rows > 0);
    return $ready;
}

/**
 * Positive realized PnL (deduped) since datetime, net as stored in trade_ledger.
 */
function profit_reinvest_positive_realized_since(mysqli $db, int $userId, string $since): float
{
    $uid = (int)$userId;
    if ($uid <= 0 || $since === '') {
        return 0.0;
    }
    $sinceEsc = $db->real_escape_string($since);
    $res = $db->query("
        SELECT COALESCE(SUM(CASE WHEN x.pnl > 0 THEN x.pnl ELSE 0 END), 0) AS pnl
        FROM (
            SELECT MAX(COALESCE(tl.pnl_usdt, 0)) AS pnl
            FROM trade_ledger tl
            WHERE tl.user_id = {$uid}
              AND tl.event_type IN ('SELL_SUB', 'SELL_MAIN')
              AND tl.created_at >= '{$sinceEsc}'
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
    return max(0.0, (float)($row['pnl'] ?? 0));
}

/**
 * @return array{
 *   enabled:bool,
 *   reserve_usdt:float,
 *   reserve_since:?string,
 *   schema:bool
 * }
 */
function profit_reinvest_user_state(mysqli $db, int $userId): array
{
    $out = [
        'enabled' => true,
        'reserve_usdt' => 0.0,
        'reserve_since' => null,
        'schema' => profit_reinvest_schema_ready($db),
    ];
    if (!$out['schema'] || $userId <= 0) {
        return $out;
    }
    $r = $db->query(
        'SELECT profit_reinvest, profit_reserve_usdt, profit_reserve_since
         FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        return $out;
    }
    $out['enabled'] = ((int)($row['profit_reinvest'] ?? 1) === 1);
    $out['reserve_usdt'] = max(0.0, (float)($row['profit_reserve_usdt'] ?? 0));
    $since = $row['profit_reserve_since'] ?? null;
    $out['reserve_since'] = ($since !== null && $since !== '') ? (string)$since : null;
    return $out;
}

/**
 * Refresh cached reserve from ledger (when OFF). Optionally clamp to free USDT
 * after user withdrew part of the reserved funds from Spot.
 */
function profit_reinvest_refresh(mysqli $db, int $userId, ?float $freeUsdt = null): array
{
    $st = profit_reinvest_user_state($db, $userId);
    if (!$st['schema'] || $userId <= 0) {
        return $st;
    }
    if ($st['enabled'] || $st['reserve_since'] === null) {
        if (!$st['enabled'] && $st['reserve_since'] === null) {
            // Corrupt/partial state → treat as ON
            $db->query(
                'UPDATE users SET profit_reinvest=1, profit_reserve_usdt=0, profit_reserve_since=NULL
                 WHERE id=' . (int)$userId . ' LIMIT 1'
            );
            $st['enabled'] = true;
            $st['reserve_usdt'] = 0.0;
        }
        return $st;
    }
    $raw = profit_reinvest_positive_realized_since($db, $userId, $st['reserve_since']);
    if ($freeUsdt !== null && is_finite($freeUsdt)) {
        $raw = min($raw, max(0.0, $freeUsdt));
    }
    $raw = round($raw, 8);
    if (abs($raw - $st['reserve_usdt']) > 1e-8) {
        $sql = number_format($raw, 8, '.', '');
        $db->query(
            "UPDATE users SET profit_reserve_usdt={$sql} WHERE id=" . (int)$userId . ' LIMIT 1'
        );
        $st['reserve_usdt'] = $raw;
    }
    return $st;
}

/** USDT the bot may spend on buys. */
function profit_reinvest_buyable_usdt(mysqli $db, int $userId, float $freeUsdt): float
{
    $freeUsdt = max(0.0, $freeUsdt);
    $st = profit_reinvest_refresh($db, $userId, $freeUsdt);
    if ($st['enabled']) {
        return $freeUsdt;
    }
    $reserve = min($st['reserve_usdt'], $freeUsdt);
    return max(0.0, $freeUsdt - $reserve);
}

/**
 * Turn reinvest ON (release reserve) or OFF (start accumulating from now).
 */
function profit_reinvest_set(mysqli $db, int $userId, bool $enabled): array
{
    if (!profit_reinvest_schema_ready($db) || $userId <= 0) {
        return ['ok' => false, 'error' => 'schema'];
    }
    if ($enabled) {
        $db->query(
            'UPDATE users SET profit_reinvest=1, profit_reserve_usdt=0, profit_reserve_since=NULL
             WHERE id=' . (int)$userId . ' LIMIT 1'
        );
    } else {
        $db->query(
            'UPDATE users SET profit_reinvest=0, profit_reserve_usdt=0, profit_reserve_since=NOW()
             WHERE id=' . (int)$userId . ' LIMIT 1'
        );
    }
    return [
        'ok' => true,
        'state' => profit_reinvest_user_state($db, $userId),
    ];
}

/**
 * Guard before MARKET buy: false if reserved profit leaves too little free.
 *
 * @return array{ok:bool,free:float,buyable:float,reserve:float,min_notional:float}
 */
function profit_reinvest_buy_guard(
    mysqli $db,
    int $userId,
    string $symbol,
    string $apiKey,
    string $secret,
    float $needQuote
): array {
    $free = 0.0;
    $minN = 0.0;
    if (function_exists('ladder_funds_snapshot')) {
        [$free, $minN] = ladder_funds_snapshot($symbol, $apiKey, $secret);
    }
    $buyable = profit_reinvest_buyable_usdt($db, $userId, $free);
    $st = profit_reinvest_user_state($db, $userId);
    $reserve = $st['enabled'] ? 0.0 : min($st['reserve_usdt'], $free);
    $ok = ($needQuote <= 0.0) || ($buyable + 1e-6 >= $needQuote);
    return [
        'ok' => $ok,
        'free' => $free,
        'buyable' => $buyable,
        'reserve' => $reserve,
        'min_notional' => $minN,
    ];
}
