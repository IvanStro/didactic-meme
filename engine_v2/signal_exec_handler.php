<?php
declare(strict_types=1);

require_once __DIR__ . '/job_handler.php';
require_once __DIR__ . '/thin_exec_config.php';
require_once __DIR__ . '/thin_exec_shadow.php';
require_once __DIR__ . '/thin_exec_sub_sell.php';

/**
 * Signal queue job → execution path.
 * B1: mode=shadow → observe, потім завжди full.
 * C0: mode=canary/on + live → try thin SUB_SELL; success → skip full; else FALLBACK_FULL.
 *
 * @param array<string,mixed> $row рядок з signal_queue_pop
 */
function signal_handle_exec_job(array $row): void
{
    $userId = (int)($row['user_id'] ?? 0);
    $strategyId = (int)($row['strategy_id'] ?? 0);
    $symbol = (string)($row['symbol'] ?? '');
    $signal = (string)($row['signal_name'] ?? ($row['signal'] ?? ''));
    $jobId = $row['id'] ?? '';

    $mode = thin_exec_mode();
    $candidate = thin_exec_is_candidate($strategyId, $signal);
    $live = thin_exec_live_enabled($strategyId, $signal);

    $tickPrice = 0.0;
    $symUp = strtoupper(trim($symbol));
    if ($symUp !== '' && function_exists('ws_ticker_price')) {
        $tickPrice = (float)ws_ticker_price($symUp);
    }
    if ($tickPrice <= 0.0 && isset($row['price'])) {
        $tickPrice = (float)$row['price'];
    }

    $path = 'full';
    $skipFull = false;

    // Shadow observe: у shadow — завжди для candidate; у canary/on — перед live (порівняння).
    if ($candidate && ($mode === 'shadow' || $live)) {
        try {
            $db = db();
            thin_exec_shadow_observe($db, $row, $tickPrice > 0.0 ? $tickPrice : null);
        } catch (Throwable $e) {
            thin_exec_log('shadow ERR sid=' . $strategyId . ' signal=' . $signal . ' ' . $e->getMessage());
        }
    }

    if ($mode === 'shadow' && $candidate) {
        $path = 'full+shadow';
    } elseif ($live) {
        $path = 'thin';
        $thinOk = false;
        $thinStatus = 'fallback';
        $thinReason = 'not_run';
        try {
            $db = db();
            $r = thin_exec_try_sub_sell(
                $db,
                $userId,
                $strategyId,
                $symbol,
                $signal,
                $tickPrice > 0.0 ? $tickPrice : null
            );
            $thinOk = !empty($r['ok']);
            $thinStatus = (string)($r['status'] ?? '');
            $thinReason = (string)($r['reason'] ?? '');
            thin_exec_log(
                "thin result sid={$strategyId} signal={$signal} status={$thinStatus} reason={$thinReason}"
                . (isset($r['sold']) ? (' sold=' . (int)$r['sold']) : '')
                . " job={$jobId}"
            );
        } catch (Throwable $e) {
            $thinOk = false;
            $thinStatus = 'fallback';
            $thinReason = 'handler_exception:' . $e->getMessage();
            thin_exec_log("thin ERR sid={$strategyId} signal={$signal} {$thinReason} job={$jobId}");
        }

        if ($thinOk && ($thinStatus === 'sold' || $thinStatus === 'noop')) {
            $skipFull = true;
            $path = 'thin_' . $thinStatus;
            // Heartbeat / schedule — як після успішного handle_job, без full tick.
            try {
                $db = db();
                $db->query("UPDATE strategies SET last_tick_at=NOW() WHERE id={$strategyId} LIMIT 1");
                if (function_exists('scaling_schedule_next_tick') && function_exists('scaling_tick_interval_for_strategy')) {
                    scaling_schedule_next_tick(
                        $db,
                        $strategyId,
                        scaling_tick_interval_for_strategy($db, $strategyId)
                    );
                }
            } catch (Throwable $e) {
                thin_exec_log("thin heartbeat ERR sid={$strategyId} " . $e->getMessage());
            }
        } else {
            if (!thin_exec_fallback_full()) {
                thin_exec_log(
                    "thin FALLBACK_DISABLED sid={$strategyId} signal={$signal} reason={$thinReason} → skip job body"
                );
                $skipFull = true;
                $path = 'thin_fail_no_fallback';
            } else {
                $path = 'full+fallback';
                thin_exec_log(
                    "thin FALLBACK_FULL sid={$strategyId} signal={$signal} reason={$thinReason} job={$jobId}"
                );
            }
        }
    } elseif (
        $mode === 'canary'
        && in_array($strategyId, thin_exec_canary_sids(), true)
        && thin_exec_signal_matches_actions('SUB_SELL')
        && (str_starts_with(strtoupper($signal), 'RECONCILE'))
    ) {
        // Tip sells часто відбуваються на RECONCILE full, минаючи SUB_SELL → thin_sold стоїть.
        // Забрати tip через thin (метрика C0), full далі для BUY/MAIN.
        try {
            $db = db();
            $r = thin_exec_try_reconcile_tip_sells(
                $db,
                $userId,
                $strategyId,
                $symbol,
                $tickPrice > 0.0 ? $tickPrice : null
            );
            $thinStatus = (string)($r['status'] ?? 'noop');
            $thinReason = (string)($r['reason'] ?? '');
            $soldN = (int)($r['sold'] ?? 0);
            thin_exec_log(
                "thin result sid={$strategyId} signal={$signal} status={$thinStatus} reason={$thinReason}"
                . " sold={$soldN} job={$jobId}"
            );
            if ($thinStatus === 'sold' && $soldN > 0) {
                $path = 'full+thin_tip';
            }
        } catch (Throwable $e) {
            thin_exec_log(
                "thin RECONCILE_TIP ERR sid={$strategyId} " . $e->getMessage() . " job={$jobId}"
            );
        }
    }

    thin_exec_log(
        "exec path={$path} mode={$mode} sid={$strategyId} signal={$signal} job={$jobId} candidate="
        . ($candidate ? '1' : '0')
        . ($tickPrice > 0.0 ? (' tick_price=' . $tickPrice) : '')
    );

    if ($skipFull) {
        return;
    }

    $job = [
        'job_type' => 'PROCESS_STRATEGY',
        'user_id' => $userId,
        'strategy_id' => $strategyId,
        'symbol' => $symbol,
    ];
    if ($tickPrice > 0.0) {
        $job['tick_price'] = $tickPrice;
    }
    handle_job($job);
}
