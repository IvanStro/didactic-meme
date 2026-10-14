<?php
declare(strict_types=1);

require_once __DIR__ . '/../engine_helpers.php';
require_once __DIR__ . '/../spot_funds_freeze.php';

/**
 * SUB take-profit сигнали для L5+ (від buy_price + tp%).
 * 1m high лише будить exec: фактичний MARKET sell у handle_sub_sells йде лише якщо live spot >= TP.
 *
 * @return array{signals:list<string>,meta:array<string,mixed>}
 */
function signal_eval_sub_sell_signals(mysqli $db, int $sid, int $pid, string $symbol, float $price): array
{
    $signals = [];
    $meta = [];
    if ($sid <= 0 || $pid <= 0 || $symbol === '' || $price <= 0) {
        return ['signals' => $signals, 'meta' => $meta];
    }

    // Wake on spike (high), but exec will re-check live spot so we do not sell below TP.
    $sellPrice = $price;
    if (function_exists('ws_recent_high_1m')) {
        $recentHigh = ws_recent_high_1m($symbol, 10);
        if ($recentHigh > 0.0 && $recentHigh > $sellPrice) {
            $sellPrice = $recentHigh;
            $meta['sub_sell_high_1m'] = $recentHigh;
        }
    }
    $meta['sub_sell_price'] = $sellPrice;

    if (!function_exists('open_subpos')) {
        return ['signals' => $signals, 'meta' => $meta];
    }

    $subs = open_subpos($db, $pid, 5);
    if (!$subs || !is_array($subs)) {
        return ['signals' => $signals, 'meta' => $meta];
    }

    $eps = 1e-8;
    foreach ($subs as $sp) {
        $lvl = (int)($sp['level_idx'] ?? 0);
        $bp = (float)($sp['buy_price'] ?? 0);
        $tp = (float)($sp['tp'] ?? 0);
        $sq = (float)($sp['qty'] ?? 0);
        if ($lvl < 5 || $sq <= $eps || $bp <= 0.0 || $tp <= 0.0) {
            continue;
        }
        $target = $bp * (1.0 + $tp / 100.0);
        if ($sellPrice + 1e-10 >= $target) {
            $signals[] = 'SUB_SELL_L' . $lvl;
            $meta['sub_targets'][$lvl] = round($target, 8);
        }
    }

    return ['signals' => $signals, 'meta' => $meta];
}

/**
 * Read-only оцінка сигналів (V2 shadow). Не виконує угод.
 *
 * @return array{signals:list<string>,meta:array<string,mixed>}
 */
function signal_eval_strategy(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    float $price,
    float $tpWhole,
    float $cbWhole,
    float $fba,
    ?array $pos = null
): array {
    $signals = [];
    $meta = ['price' => $price, 'sid' => $sid];

    if ($sid <= 0 || $symbol === '' || $price <= 0) {
        return ['signals' => $signals, 'meta' => $meta];
    }

    if (!$pos) {
        $pos = get_position($db, $sid, $symbol, $uid);
    }
    if (!$pos) {
        $pos = ensure_position($db, $sid, $uid);
    }
    if (!$pos) {
        return ['signals' => $signals, 'meta' => $meta];
    }

    $pid = (int)($pos['id'] ?? 0);
    $qty = (float)($pos['qty'] ?? 0);
    $avg = (float)($pos['avg_price'] ?? 0);
    $base = function_exists('position_grid_base')
        ? position_grid_base($pos)
        : (float)($pos['base_price'] ?? 0);
    $eps = 1e-8;

    $fundsFrozen = spot_funds_user_is_frozen($db, $uid);
    if ($fundsFrozen) {
        $meta['spot_funds_frozen'] = true;
    }
    $legacyExit = function_exists('position_legacy_blocks_new_buys') && position_legacy_blocks_new_buys($pos);
    if ($legacyExit) {
        $meta['legacy_exit_plan'] = true;
    }

    $subEval = signal_eval_sub_sell_signals($db, $sid, $pid, $symbol, $price);
    $signals = array_merge($signals, $subEval['signals']);
    if (!empty($subEval['meta'])) {
        $meta = array_merge($meta, $subEval['meta']);
    }

    if ($qty <= $eps && $fba > 0 && !position_has_open_subs($db, $pid)) {
        $signals[] = 'BUY0_ELIGIBLE';
    }

    if ($qty <= $eps || $tpWhole <= 0) {
        return ['signals' => $signals, 'meta' => $meta];
    }

    $hiQtyOpen = 0.0;
    if ($pid > 0 && function_exists('open_subpos')) {
        $hiSubs = open_subpos($db, $pid, 5);
        if ($hiSubs && is_array($hiSubs)) {
            foreach ($hiSubs as $hs) {
                $hsQ = (float)($hs['qty'] ?? 0);
                if ($hsQ > $eps) {
                    $hiQtyOpen += $hsQ;
                }
            }
        }
    }

    // MAIN arm/TP: lot SoT (L1–L4). avg/cycle — лише UI / CAP qty, не поріг продажу.
    $basis = 0.0;
    if (function_exists('position_core_basis')) {
        $core = position_core_basis($db, $pos);
        $basis = (float)($core['basis'] ?? 0);
        if ($basis > 1e-12) {
            $meta['main_basis_source'] = (string)($core['source'] ?? 'core');
        }
    }
    if ($basis <= 1e-12) {
        $basis = $avg > 0 ? $avg : $price;
        $nextPtr = (int)($pos['next_ptr'] ?? 1);
        if ($nextPtr <= 1 && $base > 0) {
            $basis = $base;
        }
        $meta['main_basis_source'] = 'avg_fallback';
    }
    $tpPrice = $basis * (1.0 + $tpWhole / 100.0);
    $lastPeak = (float)($pos['last_peak'] ?? 0);
    $meta['tp_price'] = $tpPrice;
    $meta['last_peak'] = $lastPeak;

    if ($hiQtyOpen > $eps && $price >= $tpPrice) {
        $signals[] = 'MAIN_DEFER_L5_OPEN';
    } elseif ($lastPeak <= $eps) {
        if ($price >= $tpPrice) {
            $signals[] = 'MAIN_ARM';
        }
    } else {
        $cbPct = max(0.0, $cbWhole);
        $cbTrigger = $cbPct > 0 ? ($lastPeak * (1.0 - $cbPct / 100.0)) : $lastPeak;
        $meta['cb_trigger'] = $cbTrigger;

        if ($price <= $cbTrigger) {
            $signals[] = 'MAIN_SELL_FIRE';
        } elseif ($price >= $tpPrice && $price > $lastPeak) {
            $signals[] = 'MAIN_PEAK_UPDATE';
        } else {
            $signals[] = 'MAIN_WAIT_CB';
        }
    }

    if ($base > 0 && $fba > 0 && function_exists('load_strategy_levels')) {
        $levels = load_strategy_levels($db, $sid);
        if ($levels && is_array($levels)) {
            $trigger = [];
            if (function_exists('ladder_cascade_mode_active') && ladder_cascade_mode_active($db)) {
                if (!function_exists('ladder_cascade_build_triggers')) {
                    require_once __DIR__ . '/../engine_logic_ladder_cascade.php';
                }
                $trigger = ladder_cascade_build_triggers($db, $pid, $base, $levels);
            } else {
                foreach ($levels as $idx => $lv) {
                    if ($idx < 1) {
                        continue;
                    }
                    $drop = (float)($lv['drop'] ?? 0);
                    $anchor = 0.0;
                    if ($idx <= 4) {
                        $anchor = $base;
                    } elseif ($idx === 5) {
                        $anchor = $avg;
                    } else {
                        $anchor = ladder_open_sub_buy_price($db, $pid, $idx - 1);
                    }
                    if ($anchor > 0 && $drop > 0) {
                        $trigger[$idx] = $anchor * (1.0 - $drop / 100.0);
                    }
                }
            }

            $openLvls = [];
            if (function_exists('open_subpos')) {
                $openSubs = open_subpos($db, $pid, null);
                if ($openSubs) {
                    foreach ($openSubs as $sp) {
                        if (empty($sp['closed'])) {
                            $openLvls[(int)($sp['level_idx'] ?? 0)] = true;
                        }
                    }
                }
            }

            // Як у V1 CB: для ladder враховуємо мінімум за 1m, не лише snapshot WS.
            $ladderPrice = $price;
            if (function_exists('ws_recent_low_1m')) {
                $recentLow = ws_recent_low_1m($symbol);
                if ($recentLow > 0.0 && $recentLow < $ladderPrice) {
                    $ladderPrice = $recentLow;
                    $meta['ladder_low_1m'] = $recentLow;
                }
            }

            // R1: будити лише кінчик сітки (maxOpen+1), не дірки.
            $tipLvl = function_exists('ladder_next_tip_level') ? ladder_next_tip_level($db, $pid) : 1;
            $meta['ladder_tip'] = $tipLvl;
            foreach ($trigger as $lvlIdx => $px) {
                if ((int)$lvlIdx !== (int)$tipLvl) {
                    continue;
                }
                if (isset($openLvls[$lvlIdx])) {
                    continue;
                }
                if ($ladderPrice <= (float)$px) {
                    if (function_exists('ladder_tip_buy_price_ok') && is_array($pos)) {
                        $pidGap = (int)($pos['id'] ?? 0);
                        if ($pidGap > 0) {
                            $gapChk = ladder_tip_buy_price_ok($db, $pidGap, (int)$lvlIdx, (float)$ladderPrice, $levels, $pos);
                            if (empty($gapChk['ok'])) {
                                $meta['ladder_gap_skip'] = (string)($gapChk['reason'] ?? '');
                                continue;
                            }
                        }
                    }
                    $signals[] = 'LADDER_L' . (int)$lvlIdx;
                }
            }
        }
    }

    $signals = signal_strip_buy_signals_when_frozen($signals, $fundsFrozen);
    if (!empty($legacyExit)) {
        $signals = signal_strip_buy_signals_when_frozen($signals, true);
    }

    return ['signals' => $signals, 'meta' => $meta];
}

function signal_shadow_log(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    float $price,
    float $tpWhole,
    float $cbWhole,
    float $fba,
    ?array $pos = null
): void {
    if (!function_exists('logx')) {
        return;
    }

    $eval = signal_eval_strategy($db, $sid, $symbol, $uid, $price, $tpWhole, $cbWhole, $fba, $pos);
    $list = $eval['signals'];
    $sigStr = $list === [] ? 'none' : implode(',', $list);
    logx("[SHADOW][$symbol] sid=$sid signals=$sigStr price=$price meta=" . json_encode($eval['meta'], JSON_UNESCAPED_UNICODE));
}
