<?php
declare(strict_types=1);

/**
 * B1 shadow: для SUB_SELL_* лише лог «що зробив би thin» — без ордерів.
 */

require_once __DIR__ . '/thin_exec_config.php';
require_once __DIR__ . '/../www/api/_ws_price.php';
require_once __DIR__ . '/../engine/engine_helpers.php';

/**
 * @param array<string,mixed> $row signal_queue row
 * @param float|null $tickPrice та сама ціна тіка, що піде в process_strategy (якщо вже відома)
 */
function thin_exec_shadow_observe(mysqli $db, array $row, ?float $tickPrice = null): void
{
    $sid = (int)($row['strategy_id'] ?? 0);
    $uid = (int)($row['user_id'] ?? 0);
    $symbol = strtoupper(trim((string)($row['symbol'] ?? '')));
    $signal = strtoupper(trim((string)($row['signal_name'] ?? ($row['signal'] ?? ''))));
    $jobId = (string)($row['id'] ?? '');

    if ($sid <= 0 || $symbol === '' || !str_starts_with($signal, 'SUB_SELL')) {
        thin_exec_log("shadow skip sid={$sid} signal={$signal} reason=not_sub_sell job={$jobId}");
        return;
    }

    $lvl = 0;
    if (preg_match('/^SUB_SELL_L(\d+)$/', $signal, $m)) {
        $lvl = (int)$m[1];
    }

    $price = ($tickPrice !== null && $tickPrice > 0.0) ? (float)$tickPrice : 0.0;
    if ($price <= 0.0 && function_exists('ws_ticker_price')) {
        $price = (float)ws_ticker_price($symbol);
    }
    if ($price <= 0.0 && isset($row['price'])) {
        $price = (float)$row['price'];
    }

    $pos = null;
    if (function_exists('position_pick_row')) {
        $pos = position_pick_row($db, $sid, $uid);
    }
    if (!$pos || !is_array($pos)) {
        thin_exec_log("shadow WOULD_SKIP sid={$sid} {$symbol} {$signal} reason=no_position price={$price} job={$jobId}");
        return;
    }

    $pid = (int)($pos['id'] ?? 0);
    if ($pid <= 0 || !function_exists('open_subpos')) {
        thin_exec_log("shadow WOULD_SKIP sid={$sid} {$symbol} {$signal} reason=no_pid price={$price} job={$jobId}");
        return;
    }

    $subs = open_subpos($db, $pid, 5);
    if (!$subs || !is_array($subs)) {
        $subs = [];
    }

    $matched = 0;
    $wouldSell = 0;
    foreach ($subs as $sp) {
        $spLvl = (int)($sp['level_idx'] ?? 0);
        if ($lvl > 0 && $spLvl !== $lvl) {
            continue;
        }
        if ($spLvl < 5) {
            continue;
        }
        $matched++;
        $spId = (int)($sp['id'] ?? 0);
        $sq = (float)($sp['qty'] ?? 0);
        $bp = (float)($sp['buy_price'] ?? 0);
        $tp = (float)($sp['tp'] ?? 0);
        if ($sq <= 0 || $bp <= 0 || $tp <= 0 || $price <= 0) {
            thin_exec_log(
                "shadow WOULD_SKIP sid={$sid} {$symbol} L{$spLvl} spId={$spId} reason=bad_inputs qty={$sq} bp={$bp} tp={$tp} price={$price} job={$jobId}"
            );
            continue;
        }
        // Як у handle_sub_sells: sell коли pl_pct >= tp (тобто НЕ pl_pct < tp).
        $plPct = ($price / $bp - 1.0) * 100.0;
        $target = $bp * (1.0 + $tp / 100.0);
        if ($plPct < $tp) {
            thin_exec_log(
                "shadow WOULD_SKIP sid={$sid} {$symbol} L{$spLvl} spId={$spId} reason=below_tp pl_pct="
                . round($plPct, 4) . " tp={$tp} price={$price} target=" . round($target, 8) . " job={$jobId}"
            );
            continue;
        }

        $needleEsc = $db->real_escape_string('"sub_position_id":' . $spId);
        $led = $db->query(
            "SELECT id FROM trade_ledger
             WHERE strategy_id={$sid} AND event_type='SELL_SUB' AND meta LIKE '%{$needleEsc}%'
             LIMIT 1"
        );
        if ($led && $led->fetch_assoc()) {
            thin_exec_log(
                "shadow WOULD_SKIP sid={$sid} {$symbol} L{$spLvl} spId={$spId} reason=already_ledger price={$price} job={$jobId}"
            );
            continue;
        }

        $wouldSell++;
        thin_exec_log(
            "shadow WOULD_SELL sid={$sid} {$symbol} L{$spLvl} spId={$spId} qty={$sq} bp={$bp} tp={$tp} "
            . "pl_pct=" . round($plPct, 4) . " price={$price} target=" . round($target, 8) . " job={$jobId}"
        );
    }

    if ($matched === 0) {
        // Пояснити cascade / вже закритий рівень: завжди з lvl + spId якщо є в БД.
        thin_exec_shadow_log_missing_sub($db, $sid, $symbol, $signal, $lvl, $pid, $price, $jobId);
    } elseif ($wouldSell === 0) {
        thin_exec_log("shadow DONE sid={$sid} {$symbol} {$signal} matched={$matched} would_sell=0 job={$jobId}");
    } else {
        thin_exec_log("shadow DONE sid={$sid} {$symbol} {$signal} matched={$matched} would_sell={$wouldSell} job={$jobId}");
    }
}

/**
 * Немає open sub на рівень сигналу — знайти рядок (в т.ч. closed) і залогувати spId.
 */
function thin_exec_shadow_log_missing_sub(
    mysqli $db,
    int $sid,
    string $symbol,
    string $signal,
    int $lvl,
    int $pid,
    float $price,
    string $jobId
): void {
    if ($lvl <= 0 || $pid <= 0) {
        thin_exec_log(
            "shadow WOULD_SKIP sid={$sid} {$symbol} {$signal} reason=no_matching_sub lvl={$lvl} spId=0 price={$price} job={$jobId}"
        );
        return;
    }

    $lvl = (int)$lvl;
    $pid = (int)$pid;
    $res = $db->query(
        "SELECT id, closed, qty, buy_price, tp
         FROM sub_positions
         WHERE position_id={$pid} AND level_idx={$lvl}
         ORDER BY id DESC
         LIMIT 3"
    );
    $found = 0;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $found++;
            $spId = (int)($row['id'] ?? 0);
            $closed = (int)($row['closed'] ?? 0);
            $reason = $closed === 1 ? 'already_closed' : 'no_matching_sub';
            thin_exec_log(
                "shadow WOULD_SKIP sid={$sid} {$symbol} L{$lvl} spId={$spId} reason={$reason}"
                . " closed={$closed} qty=" . (float)($row['qty'] ?? 0)
                . " bp=" . (float)($row['buy_price'] ?? 0)
                . " tp=" . (float)($row['tp'] ?? 0)
                . " price={$price} job={$jobId}"
            );
        }
    }
    if ($found === 0) {
        thin_exec_log(
            "shadow WOULD_SKIP sid={$sid} {$symbol} L{$lvl} spId=0 reason=no_sub_row lvl={$lvl} price={$price} job={$jobId}"
        );
    }
}
