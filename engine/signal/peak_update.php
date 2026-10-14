<?php
declare(strict_types=1);

/**
 * Оновлення positions.last_peak для trailing MAIN SELL (V2 signal_daemon).
 * Раніше MAIN_PEAK_UPDATE лише логувався, але не потрапляв у signal_queue.
 */

/**
 * MAIN TP/arm basis = lot SoT (L1–L4), не positions.avg / cycle-avg.
 */
function signal_main_sell_basis(mysqli $db, array $pos, float $price): float
{
    if (function_exists('position_core_basis')) {
        $core = position_core_basis($db, $pos);
        $basis = (float)($core['basis'] ?? 0);
        if ($basis > 1e-12) {
            return $basis;
        }
    }

    $avg = (float)($pos['avg_price'] ?? 0);
    $base = (float)($pos['base_price'] ?? 0);
    $nextPtr = (int)($pos['next_ptr'] ?? 1);
    $basis = $avg > 0 ? $avg : $price;
    if ($nextPtr <= 1 && $base > 0) {
        $basis = $base;
    }

    return $basis;
}

/**
 * Підняти last_peak, якщо ціна на TP+ (arm / trailing update).
 *
 * @return array{updated:bool,old_peak:float,new_peak:float,tp_price:float}
 */
function signal_position_apply_peak_update(
    mysqli $db,
    int $sid,
    int $uid,
    string $symbol,
    float $price,
    float $tpWhole
): array {
    $out = ['updated' => false, 'old_peak' => 0.0, 'new_peak' => 0.0, 'tp_price' => 0.0];

    if ($sid <= 0 || $symbol === '' || $price <= 0 || $tpWhole <= 0) {
        return $out;
    }

    if (!function_exists('get_position')) {
        return $out;
    }

    $pos = get_position($db, $sid, $symbol, $uid);
    if (!$pos) {
        return $out;
    }

    $qty = (float)($pos['qty'] ?? 0);
    if ($qty <= 1e-8) {
        return $out;
    }

    $basis = signal_main_sell_basis($db, $pos, $price);
    if ($basis <= 0) {
        return $out;
    }

    $tpPrice = $basis * (1.0 + $tpWhole / 100.0);
    $lastPeak = (float)($pos['last_peak'] ?? 0);
    $out['old_peak'] = $lastPeak;
    $out['tp_price'] = $tpPrice;

    $newPeak = 0.0;
    if ($lastPeak <= 1e-12) {
        if ($price + 1e-12 >= $tpPrice) {
            $newPeak = $price;
        }
    } elseif ($price + 1e-12 >= $tpPrice && $price > $lastPeak + 1e-12) {
        $newPeak = $price;
    }

    if ($newPeak <= 0) {
        return $out;
    }

    $pid = (int)($pos['id'] ?? 0);
    if ($pid <= 0) {
        return $out;
    }

    $lpSql = number_format($newPeak, 18, '.', '');
    $db->query("UPDATE positions SET last_peak={$lpSql}, updated_at=NOW() WHERE id={$pid}");

    $out['updated'] = true;
    $out['new_peak'] = $newPeak;

    return $out;
}
