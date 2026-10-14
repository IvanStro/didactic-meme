<?php
declare(strict_types=1);

function signal_queue_dedupe_key(int $strategyId, string $signal, float $price): string
{
    if (
        str_starts_with($signal, 'LADDER_L')
        || str_starts_with($signal, 'SUB_SELL_L')
        || $signal === 'BUY0_ELIGIBLE'
        || $signal === 'MAIN_ARM'
        || $signal === 'MAIN_PEAK_UPDATE'
        || $signal === 'MAIN_SELL_FIRE'
        || $signal === 'RECONCILE_TICK'
    ) {
        return $strategyId . ':' . $signal;
    }
    $px = (int)round($price * 100000);
    return $strategyId . ':' . $signal . ':' . $px;
}
