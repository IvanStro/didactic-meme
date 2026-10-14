<?php
declare(strict_types=1);

/**
 * Атомарна зміна status (running/paused) + журнал USER_* + symbol_index.
 */

/** @return array<string,mixed>|null */
function strategy_resolve_owned(mysqli $db, int $uid, string $symbol, int $strategyId = 0): ?array
{
    $uid = (int)$uid;
    if ($uid <= 0) {
        return null;
    }

    if ($strategyId > 0) {
        $sid = (int)$strategyId;
        $res = $db->query(
            "SELECT id, symbol, status, fba, tp_whole, cb_whole, global_cb
             FROM strategies WHERE id={$sid} AND owner_id={$uid} LIMIT 1"
        );
        if (!$res || !($row = $res->fetch_assoc())) {
            return null;
        }
        $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
        $want = strtoupper(trim($symbol));
        if ($want !== '' && $want !== $sym) {
            return null;
        }
        return $row;
    }

    $want = strtoupper(trim($symbol));
    if ($want === '' || !preg_match('/^[A-Z0-9]{3,}USDT$/', $want)) {
        return null;
    }
    $symEsc = $db->real_escape_string($want);
    $res = $db->query(
        "SELECT id, symbol, status, fba, tp_whole, cb_whole, global_cb
         FROM strategies WHERE symbol='{$symEsc}' AND owner_id={$uid}
         ORDER BY id DESC LIMIT 1"
    );
    if (!$res || !($row = $res->fetch_assoc())) {
        return null;
    }
    return $row;
}

function strategy_target_status(string $action): string
{
    return $action === 'start' ? 'running' : 'paused';
}

function strategy_journal_user_event(mysqli $db, int $sid, string $symbol, string $eventType, string $message): void
{
    if ($sid <= 0 || $eventType === '') {
        return;
    }
    if (function_exists('journal_log')) {
        journal_log($db, [
            'strategy_id' => $sid,
            'symbol' => $symbol,
            'event_type' => $eventType,
            'level_idx' => 0,
            'side' => null,
            'message' => $message,
        ]);
        return;
    }
    $symEsc = $db->real_escape_string($symbol);
    $typeEsc = $db->real_escape_string($eventType);
    $msgEsc = $db->real_escape_string($message);
    @$db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
         VALUES (NOW(), " . (int)$sid . ", '{$symEsc}', '{$typeEsc}', 0, NULL, '{$msgEsc}')"
    );
}

function strategy_cancel_pending_buy_signals(mysqli $db, int $strategyId): int
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0) {
        return 0;
    }
    $errEsc = $db->real_escape_string('cancelled:user_pause');
    $db->query(
        "UPDATE signal_queue
         SET status='failed', attempts=attempts+1, last_error='{$errEsc}', updated_at=NOW()
         WHERE strategy_id={$strategyId}
           AND status IN ('queued','processing')
           AND (
             signal_name='BUY0_ELIGIBLE'
             OR signal_name LIKE 'LADDER_L%'
           )"
    );
    return (int)$db->affected_rows;
}

function strategy_sync_symbol_index(mysqli $db, int $strategyId): void
{
    try {
        require_once __DIR__ . '/../../engine/signal/symbol_index.php';
        symbol_index_sync_strategy($db, $strategyId);
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * @return array{ok:bool,error?:string,strategy_id?:int,symbol?:string,status?:string,prev_status?:string,changed?:bool,cancelled_signals?:int}
 */
function strategy_apply_status(
    mysqli $db,
    int $uid,
    int $sid,
    string $action,
    ?float $fba = null
): array {
    $uid = (int)$uid;
    $sid = (int)$sid;
    $action = strtolower(trim($action));
    if ($uid <= 0 || $sid <= 0 || !in_array($action, ['start', 'pause', 'stop'], true)) {
        return ['ok' => false, 'error' => 'bad_request'];
    }

    if ($action === 'start') { require_once __DIR__.'/_legal.php'; strim_legal_require_json($db,$uid); }
    $target = strategy_target_status($action);
    $txStarted = false;

    try {
        if (!$db->begin_transaction()) {
            return ['ok' => false, 'error' => 'db_tx'];
        }
        $txStarted = true;

        $lock = $db->query(
            "SELECT id, symbol, status, fba
             FROM strategies WHERE id={$sid} AND owner_id={$uid} LIMIT 1 FOR UPDATE"
        );
        if (!$lock || !($row = $lock->fetch_assoc())) {
            $db->rollback();
            return ['ok' => false, 'error' => 'strategy_not_found'];
        }

        $symbol = strtoupper(trim((string)($row['symbol'] ?? '')));
        $prev = (string)($row['status'] ?? '');
        $changed = ($prev !== $target);

        if ($changed) {
            $sets = ["status='{$target}'"];
            if ($fba !== null) {
                $sets[] = 'fba=' . number_format($fba, 8, '.', '');
            }
            if ($action === 'start' && $prev !== 'running') {
                $sets[] = 'start_ts=UNIX_TIMESTAMP(NOW())*1000';
            }
            // Пауза з відкритою позицією: due для RECONCILE (виходи MAIN/SUB) — лише звичайний StrIM цикл.
            // LEGACY adopt_mode (exit_plan/…) не будимо: basis сітки ≠ історичний cost_avg.
            if ($target === 'paused' && ($action === 'pause' || $action === 'stop')) {
                $hasNext = false;
                $col = @$db->query("SHOW COLUMNS FROM strategies LIKE 'next_tick_at'");
                if ($col && $col->num_rows > 0) {
                    $hasNext = true;
                }
                if ($hasNext) {
                    $pq = $db->query(
                        "SELECT id, adopt_mode, origin FROM positions
                         WHERE strategy_id={$sid} AND qty > 1e-12
                         ORDER BY id DESC LIMIT 1"
                    );
                    $prow = $pq ? $pq->fetch_assoc() : null;
                    if ($prow) {
                        $am = strtolower(trim((string)($prow['adopt_mode'] ?? '')));
                        $orig = strtolower(trim((string)($prow['origin'] ?? '')));
                        $legacyWake = ($am !== '' && $am !== 'strim')
                            || ($orig !== '' && $orig !== 'strim');
                        if (!$legacyWake) {
                            $sets[] = 'next_tick_at=NOW()';
                        }
                    }
                }
            }
            if (!$db->query('UPDATE strategies SET ' . implode(',', $sets) . " WHERE id={$sid} AND owner_id={$uid}")) {
                $db->rollback();
                return ['ok' => false, 'error' => 'db_update', 'message' => $db->error];
            }
            if ($db->affected_rows < 1) {
                $db->rollback();
                return ['ok' => false, 'error' => 'status_not_applied'];
            }

            $verify = $db->query("SELECT status FROM strategies WHERE id={$sid} LIMIT 1");
            $vrow = $verify ? $verify->fetch_assoc() : null;
            if (!$vrow || (string)($vrow['status'] ?? '') !== $target) {
                $db->rollback();
                return ['ok' => false, 'error' => 'status_verify_failed'];
            }
        } elseif ($fba !== null) {
            $db->query(
                'UPDATE strategies SET fba=' . number_format($fba, 8, '.', '') . " WHERE id={$sid} AND owner_id={$uid}"
            );
        }

        $db->commit();
        $txStarted = false;
    } catch (Throwable $e) {
        if ($txStarted) {
            $db->rollback();
        }
        return ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }

    $cancelled = 0;
    if ($target === 'paused' && $changed) {
        $cancelled = strategy_cancel_pending_buy_signals($db, $sid);
    }
    strategy_sync_symbol_index($db, $sid);

    if ($changed) {
        strategy_emit_user_status_journal($db, $uid, $sid, $symbol, $action, $prev, $target);
    }

    return [
        'ok' => true,
        'strategy_id' => $sid,
        'symbol' => $symbol,
        'status' => $target,
        'prev_status' => $prev,
        'changed' => $changed,
        'cancelled_signals' => $cancelled,
    ];
}

function strategy_emit_user_status_journal(
    mysqli $db,
    int $uid,
    int $sid,
    string $symbol,
    string $action,
    string $prevStatus,
    string $newStatus
): void {
    if ($action === 'pause' && $newStatus === 'paused') {
        $msg =
            'Пауза: користувач перервав добір. Виходи активні; після повного закриття позицій цикл завершиться без нового BUY0 до «Старт».';
        strategy_journal_user_event($db, $sid, $symbol, 'USER_PAUSE', $msg);
        if (function_exists('strategy_tg_send_user')) {
            strategy_tg_send_user(
                $db,
                $uid,
                "⏸ Пауза: {$symbol}\n\n" .
                "Ви натиснули паузу для цієї торгової пари. Бот продовжує керувати виходами (продажі підрівнів та ядра за правилами стратегії).\n\n" .
                "Нові докупки та стартовий BUY0 не виконуються. Після закриття всіх підрівневих і головної позиції поточний цикл буде завершено; новий цикл не почнеться, доки ви не натиснете «Старт»."
            );
        }
        return;
    }

    if ($action === 'stop' && $newStatus === 'paused') {
        $msg = 'Зупинка: користувач зупинив стратегію. Нові входи вимкнені; виходи за правилами стратегії лишаються активними до повного закриття циклу.';
        strategy_journal_user_event($db, $sid, $symbol, 'USER_STOP', $msg);
        if (function_exists('strategy_tg_send_user')) {
            strategy_tg_send_user($db, $uid, "🛑 Нові покупки припинені; налаштовані продажі можуть тривати\n{$symbol}");
        }
        return;
    }

    if ($action === 'start' && $newStatus === 'running') {
        if ($prevStatus === 'paused') {
            $msg =
                'Старт: користувач зняв паузу. Докупки по сітці та BUY0 знову дозволені за правилами стратегії.';
            strategy_journal_user_event($db, $sid, $symbol, 'USER_START', $msg);
            if (function_exists('strategy_tg_send_user')) {
                strategy_tg_send_user(
                    $db,
                    $uid,
                    "▶️ Старт після паузи: {$symbol}\n\n" .
                    "Паузу знято. Бот знову може виконувати докупки по сітці та стартовий BUY0 за правилами стратегії."
                );
            }
        } else {
            $msg = 'Старт: користувач запустив стратегію.';
            strategy_journal_user_event($db, $sid, $symbol, 'USER_START', $msg);
            if (function_exists('strategy_tg_send_user')) {
                strategy_tg_send_user($db, $uid, "🚀 Стратегія запущена\n{$symbol}");
            }
        }
    }
}
