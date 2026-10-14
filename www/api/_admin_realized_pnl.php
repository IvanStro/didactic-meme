<?php
declare(strict_types=1);

/**
 * Реалізований PnL з trade_ledger (дедуплікація подвійних SELL_MAIN/SELL_SUB).
 */
function admin_fetch_realized_pnl(mysqli $db, int $userId): float
{
    if ($userId <= 0) {
        return 0.0;
    }
    $uid = (int) $userId;
    $res = $db->query("
        SELECT COALESCE(SUM(x.pnl), 0) AS pnl
        FROM (
            SELECT MAX(COALESCE(tl.pnl_usdt, 0)) AS pnl
            FROM trade_ledger tl
            WHERE tl.user_id = {$uid}
              AND tl.event_type IN ('SELL_SUB', 'SELL_MAIN')
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
    return (float) ($row['pnl'] ?? 0);
}
