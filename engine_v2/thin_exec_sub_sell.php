<?php
declare(strict_types=1);

/**
 * C0 live thin: SUB_SELL_L{n} — мінімальний контракт без повного process_strategy.
 * Повторно перевіряє live spot/TP; ордери лише через існуючий handle_sub_sells.
 */

require_once __DIR__ . '/thin_exec_config.php';
require_once __DIR__ . '/../engine/engine_helpers.php';
require_once __DIR__ . '/../engine/engine_logic.php';
require_once __DIR__ . '/../www/api/_billing_access.php';
require_once __DIR__ . '/../www/api/_ws_price.php';

/**
 * @return array{ok:bool,status:string,reason:string,sold?:int,level?:int}
 *   status: sold|noop|fallback
 *   ok=true → thin завершив (sold/noop), full не потрібен
 *   ok=false + status=fallback → викликати process_strategy
 */
function thin_exec_try_sub_sell(
    mysqli $db,
    int $uid,
    int $sid,
    string $symbol,
    string $signal,
    ?float $tickPrice = null
): array {
    $symbol = strtoupper(trim($symbol));
    $signal = strtoupper(trim($signal));

    if ($sid <= 0 || $uid <= 0 || $symbol === '' || !str_starts_with($signal, 'SUB_SELL')) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'bad_input'];
    }

    $lvl = 0;
    if (preg_match('/^SUB_SELL_L(\d+)$/', $signal, $m)) {
        $lvl = (int)$m[1];
    }
    if ($lvl < 5) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'bad_level'];
    }

    $stRes = $db->query("SELECT id, user_id, status, symbol FROM strategies WHERE id={$sid} LIMIT 1");
    $st = $stRes ? $stRes->fetch_assoc() : null;
    if (!$st || (int)($st['user_id'] ?? 0) !== $uid) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'strategy_mismatch'];
    }
    $status = (string)($st['status'] ?? '');
    if ($status === 'running' && !billing_user_has_access($db, $uid)) {
        $db->query("UPDATE strategies SET status='paused' WHERE id={$sid} AND status='running' LIMIT 1");
        return ['ok' => true, 'status' => 'noop', 'reason' => 'billing_paused', 'level' => $lvl];
    }
    if ($status !== 'running' && $status !== 'paused') {
        return ['ok' => true, 'status' => 'noop', 'reason' => 'not_active', 'level' => $lvl];
    }

    if (!function_exists('user_keys')) {
        require_once __DIR__ . '/../www/api/_user_keys.php';
    }
    [$apiKey, $secret] = user_keys($db, $uid);
    if (!$apiKey || !$secret) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'no_keys'];
    }

    $stratLock = 'strat_proc_' . $sid;
    $stratLockEsc = $db->real_escape_string($stratLock);
    $stratLockGot = 0;
    $lockRes = $db->query("SELECT GET_LOCK('{$stratLockEsc}', 0) AS g");
    if ($lockRes && ($lockRow = $lockRes->fetch_assoc())) {
        $stratLockGot = (int)($lockRow['g'] ?? 0);
    }
    if ($stratLockGot !== 1) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'strat_lock_busy'];
    }

    try {
        $price = ($tickPrice !== null && $tickPrice > 0.0) ? (float)$tickPrice : 0.0;
        if ($price <= 0.0 && function_exists('ws_ticker_price')) {
            $price = (float)ws_ticker_price($symbol);
        }
        if ($price <= 0.0 && function_exists('exi_get_price')) {
            $price = (float)exi_get_price($symbol, $apiKey, $secret);
        }
        if ($price <= 0.0) {
            return ['ok' => false, 'status' => 'fallback', 'reason' => 'no_price', 'level' => $lvl];
        }

        $pos = function_exists('position_pick_row')
            ? position_pick_row($db, $sid, $uid)
            : null;
        if (!$pos || !is_array($pos)) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'no_position', 'level' => $lvl, 'sold' => 0];
        }

        $pid = (int)($pos['id'] ?? 0);
        if ($pid <= 0 || !function_exists('open_subpos')) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'no_pid', 'level' => $lvl, 'sold' => 0];
        }

        $subs = open_subpos($db, $pid, 5);
        if (!$subs || !is_array($subs)) {
            $subs = [];
        }

        $atLevel = 0;
        $atTp = 0;
        foreach ($subs as $sp) {
            if ((int)($sp['level_idx'] ?? 0) !== $lvl) {
                continue;
            }
            $atLevel++;
            $sq = (float)($sp['qty'] ?? 0);
            $bp = (float)($sp['buy_price'] ?? 0);
            $tp = (float)($sp['tp'] ?? 0);
            if ($sq <= 0.0 || $bp <= 0.0 || $tp <= 0.0) {
                continue;
            }
            $plPct = ($price / $bp - 1.0) * 100.0;
            if ($plPct >= $tp) {
                $atTp++;
            }
        }

        if ($atLevel === 0) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'no_open_at_level', 'level' => $lvl, 'sold' => 0];
        }
        if ($atTp === 0) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'below_tp', 'level' => $lvl, 'sold' => 0];
        }

        // Існуючий шлях: locks per-sub, place_sell, ledger, TG, sync після продажів.
        handle_sub_sells($db, $sid, $symbol, $uid, $pos, $price, $apiKey, $secret);

        return ['ok' => true, 'status' => 'sold', 'reason' => 'handle_sub_sells', 'level' => $lvl, 'sold' => $atTp];
    } catch (Throwable $e) {
        thin_exec_log(
            "thin SUB_SELL ERR sid={$sid} {$symbol} {$signal} " . $e->getMessage()
        );
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'exception:' . $e->getMessage(), 'level' => $lvl];
    } finally {
        $db->query("SELECT RELEASE_LOCK('{$stratLockEsc}')");
    }
}

/**
 * C0 canary: RECONCILE_TICK часто продає tip через full раніше за SUB_SELL job.
 * Тоді thin_sold не росте, хоча DOGE реально торгує. Перед full — спроба tip через thin.
 *
 * Не skip-ає full (потрібні BUY/MAIN); лише забирає tip-sell у thin_sold метрику.
 *
 * @return array{ok:bool,status:string,reason:string,sold?:int,levels?:list<int>}
 */
function thin_exec_try_reconcile_tip_sells(
    mysqli $db,
    int $uid,
    int $sid,
    string $symbol,
    ?float $tickPrice = null
): array {
    $symbol = strtoupper(trim($symbol));
    if ($sid <= 0 || $uid <= 0 || $symbol === '') {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'bad_input'];
    }

    $stRes = $db->query("SELECT id, user_id, status, symbol FROM strategies WHERE id={$sid} LIMIT 1");
    $st = $stRes ? $stRes->fetch_assoc() : null;
    if (!$st || (int)($st['user_id'] ?? 0) !== $uid) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'strategy_mismatch'];
    }
    $status = (string)($st['status'] ?? '');
    if ($status !== 'running' && $status !== 'paused') {
        return ['ok' => true, 'status' => 'noop', 'reason' => 'not_active', 'sold' => 0];
    }

    if (!function_exists('user_keys')) {
        require_once __DIR__ . '/../www/api/_user_keys.php';
    }
    [$apiKey, $secret] = user_keys($db, $uid);
    if (!$apiKey || !$secret) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'no_keys'];
    }

    $stratLock = 'strat_proc_' . $sid;
    $stratLockEsc = $db->real_escape_string($stratLock);
    $stratLockGot = 0;
    $lockRes = $db->query("SELECT GET_LOCK('{$stratLockEsc}', 0) AS g");
    if ($lockRes && ($lockRow = $lockRes->fetch_assoc())) {
        $stratLockGot = (int)($lockRow['g'] ?? 0);
    }
    if ($stratLockGot !== 1) {
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'strat_lock_busy'];
    }

    try {
        $price = ($tickPrice !== null && $tickPrice > 0.0) ? (float)$tickPrice : 0.0;
        if ($price <= 0.0 && function_exists('ws_ticker_price')) {
            $price = (float)ws_ticker_price($symbol);
        }
        if ($price <= 0.0 && function_exists('exi_get_price')) {
            $price = (float)exi_get_price($symbol, $apiKey, $secret);
        }
        if ($price <= 0.0) {
            return ['ok' => false, 'status' => 'fallback', 'reason' => 'no_price'];
        }

        $pos = function_exists('position_pick_row')
            ? position_pick_row($db, $sid, $uid)
            : null;
        if (!$pos || !is_array($pos)) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'no_position', 'sold' => 0];
        }

        $pid = (int)($pos['id'] ?? 0);
        if ($pid <= 0 || !function_exists('open_subpos')) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'no_pid', 'sold' => 0];
        }

        $subs = open_subpos($db, $pid, 5);
        if (!$subs || !is_array($subs)) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'no_open_subs', 'sold' => 0];
        }

        $atTp = 0;
        $levels = [];
        foreach ($subs as $sp) {
            $lvl = (int)($sp['level_idx'] ?? 0);
            $sq = (float)($sp['qty'] ?? 0);
            $bp = (float)($sp['buy_price'] ?? 0);
            $tp = (float)($sp['tp'] ?? 0);
            if ($lvl < 5 || $sq <= 0.0 || $bp <= 0.0 || $tp <= 0.0) {
                continue;
            }
            $plPct = ($price / $bp - 1.0) * 100.0;
            if ($plPct >= $tp) {
                $atTp++;
                $levels[$lvl] = $lvl;
            }
        }

        if ($atTp === 0) {
            return ['ok' => true, 'status' => 'noop', 'reason' => 'below_tp', 'sold' => 0];
        }

        handle_sub_sells($db, $sid, $symbol, $uid, $pos, $price, $apiKey, $secret);

        return [
            'ok' => true,
            'status' => 'sold',
            'reason' => 'reconcile_thin_tip',
            'sold' => $atTp,
            'levels' => array_values($levels),
        ];
    } catch (Throwable $e) {
        thin_exec_log(
            "thin RECONCILE_TIP ERR sid={$sid} {$symbol} " . $e->getMessage()
        );
        return ['ok' => false, 'status' => 'fallback', 'reason' => 'exception:' . $e->getMessage()];
    } finally {
        $db->query("SELECT RELEASE_LOCK('{$stratLockEsc}')");
    }
}
