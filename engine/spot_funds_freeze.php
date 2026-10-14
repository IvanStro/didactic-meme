<?php
declare(strict_types=1);

/**
 * Заморозка докупок при нестачі USDT на Binance spot (статус стратегії лишається running).
 * Розморозка — коли free USDT знову достатній для мінімального ордера / FBA.
 *
 * Стан: app_settings.k = spot_funds_frozen_u{userId}
 */

function spot_funds_freeze_setting_key(int $userId): string
{
    return 'spot_funds_frozen_u' . max(0, (int)$userId);
}

/** @return array{strategy_id:?int,symbol:?string,label:string} */
function spot_funds_strategy_context(mysqli $db, int $userId, ?int $strategyId, ?string $symbol): array
{
    $userId = (int)$userId;
    $sid = (int)($strategyId ?? 0);
    $sym = strtoupper(trim((string)($symbol ?? '')));

    if ($sid > 0 && $sym === '') {
        $r = $db->query(
            "SELECT id, symbol FROM strategies
             WHERE id={$sid} AND (user_id={$userId} OR owner_id={$userId})
             LIMIT 1"
        );
        if ($r && ($row = $r->fetch_assoc())) {
            $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
        }
    }

    if ($sym !== '' && $sid > 0) {
        $label = "{$sym} sid={$sid}";
    } elseif ($sym !== '') {
        $label = $sym;
    } elseif ($sid > 0) {
        $label = "sid={$sid}";
    } else {
        $label = 'акаунт';
    }

    return [
        'strategy_id' => $sid > 0 ? $sid : null,
        'symbol' => $sym !== '' ? $sym : null,
        'label' => $label,
    ];
}

function spot_funds_freeze_backfill_context(mysqli $db, int $userId, array $j): array
{
    $strategyId = isset($j['strategy_id']) ? (int)$j['strategy_id'] : 0;
    $symbol = isset($j['symbol']) ? strtoupper(trim((string)$j['symbol'])) : '';
    $reason = isset($j['reason']) ? (string)$j['reason'] : '';

    if ($symbol === '' && $reason !== '' && preg_match('/^([A-Z0-9]+USDT)\s+sid=\d+:/u', $reason, $m)) {
        $symbol = (string)$m[1];
    }
    if ($strategyId <= 0 && $reason !== '' && preg_match('/\bsid=(\d+)\b/u', $reason, $m)) {
        $strategyId = (int)$m[1];
    }

    $ctx = spot_funds_strategy_context($db, $userId, $strategyId > 0 ? $strategyId : null, $symbol !== '' ? $symbol : null);
    return [
        'strategy_id' => $ctx['strategy_id'],
        'symbol' => $ctx['symbol'],
        'label' => $ctx['label'],
    ];
}

/** @return array{frozen:bool,at:?string,reason:?string,free_usdt:?float,strategy_id:?int,symbol:?string,scope:string,hint_uk:?string} */
function spot_funds_freeze_state(mysqli $db, int $userId): array
{
    $empty = [
        'frozen' => false,
        'at' => null,
        'reason' => null,
        'free_usdt' => null,
        'strategy_id' => null,
        'symbol' => null,
        'scope' => 'account',
        'hint_uk' => null,
    ];
    $userId = (int)$userId;
    if ($userId <= 0) {
        return $empty;
    }
    $k = $db->real_escape_string(spot_funds_freeze_setting_key($userId));
    $r = $db->query("SELECT v FROM app_settings WHERE k='{$k}' LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) {
        return $empty;
    }
    $j = json_decode((string)($row['v'] ?? ''), true);
    if (!is_array($j)) {
        return $empty;
    }
    if (empty($j['frozen'])) {
        return $empty;
    }

    $ctx = spot_funds_freeze_backfill_context($db, $userId, $j);
    $reason = isset($j['reason']) ? (string)$j['reason'] : null;
    $freeUsdt = isset($j['free_usdt']) ? (float)$j['free_usdt'] : null;
    $state = [
        'frozen' => true,
        'at' => isset($j['at']) ? (string)$j['at'] : null,
        'reason' => $reason,
        'free_usdt' => $freeUsdt,
        'strategy_id' => $ctx['strategy_id'],
        'symbol' => $ctx['symbol'],
        'scope' => 'account',
        'hint_uk' => spot_funds_freeze_hint_uk($ctx, $reason, $freeUsdt),
    ];
    return $state;
}

function spot_funds_freeze_hint_uk(array $ctx, ?string $reason, ?float $freeUsdt): string
{
    $label = trim((string)($ctx['label'] ?? ''));
    $who = $label !== '' && $label !== 'акаунт' ? $label : 'акаунт';
    $msg = 'Заморозка докупок на всьому акаунті (USDT спільний для всіх пар).';
    if ($reason !== null && $reason !== '') {
        $msg .= ' Причина: ' . $reason . '.';
    } elseif ($who !== 'акаунт') {
        $msg .= ' Тригер: ' . $who . '.';
    }
    if ($freeUsdt !== null) {
        $msg .= sprintf(' Free USDT≈%.2f.', $freeUsdt);
    }
    $msg .= ' Поповніть торговий баланс — докупки відновляться автоматично. Продажі по TP працюють.';
    return $msg;
}

function spot_funds_user_is_frozen(mysqli $db, int $userId): bool
{
    return spot_funds_freeze_state($db, $userId)['frozen'];
}

function spot_funds_min_usdt_to_trade(float $fba, float $minNotional): float
{
    $need = max((float)$fba, (float)$minNotional);
    return $need > 0 ? $need * 1.02 : 5.0;
}

function spot_funds_user_has_sufficient_usdt(
    mysqli $db,
    int $userId,
    string $symbol,
    string $apiKey,
    string $secret,
    float $fba
): bool {
    if ($userId <= 0 || $symbol === '' || $apiKey === '' || $secret === '') {
        return false;
    }
    if (!function_exists('ladder_funds_snapshot')) {
        return false;
    }
    [$free, $minN] = ladder_funds_snapshot($symbol, $apiKey, $secret);
    $need = spot_funds_min_usdt_to_trade($fba, $minN);
    return $free + 1e-6 >= $need;
}

function spot_funds_journal_event(
    mysqli $db,
    int $userId,
    string $eventType,
    string $message,
    ?int $strategyId = null,
    ?string $symbol = null
): void {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return;
    }

    $ctx = spot_funds_strategy_context($db, $userId, $strategyId, $symbol);
    $sid = (int)($ctx['strategy_id'] ?? 0);
    $symRaw = (string)($ctx['symbol'] ?? '');

    if ($sid <= 0 || $symRaw === '') {
        $r = $db->query(
            "SELECT id, symbol FROM strategies
             WHERE (user_id={$userId} OR owner_id={$userId}) AND status='running'
             ORDER BY id ASC LIMIT 1"
        );
        if (!$r || !($st = $r->fetch_assoc())) {
            return;
        }
        $sid = (int)($st['id'] ?? 0);
        $symRaw = (string)($st['symbol'] ?? '');
    }
    if ($sid <= 0 || $symRaw === '') {
        return;
    }

    $sym = $db->real_escape_string($symRaw);
    $et = $db->real_escape_string($eventType);
    $msg = $db->real_escape_string(mb_substr($message, 0, 2000));
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, qty, price, message)
         VALUES (NOW(), {$sid}, '{$sym}', '{$et}', NULL, NULL, NULL, NULL, '{$msg}')"
    );
}

function spot_funds_freeze_user(
    mysqli $db,
    int $userId,
    string $reason,
    ?float $freeUsdt = null,
    ?int $strategyId = null,
    ?string $symbol = null
): void {
    $userId = (int)$userId;
    if ($userId <= 0 || spot_funds_user_is_frozen($db, $userId)) {
        return;
    }

    $ctx = spot_funds_strategy_context($db, $userId, $strategyId, $symbol);
    $shortReason = mb_substr(trim($reason), 0, 500);
    $fullReason = ($ctx['label'] !== '' && $ctx['label'] !== 'акаунт')
        ? ($ctx['label'] . ': ' . $shortReason)
        : $shortReason;

    $payload = json_encode([
        'frozen' => 1,
        'at' => date('Y-m-d H:i:s'),
        'reason' => $fullReason,
        'trigger_reason' => $shortReason,
        'free_usdt' => $freeUsdt,
        'strategy_id' => $ctx['strategy_id'],
        'symbol' => $ctx['symbol'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }
    $k = spot_funds_freeze_setting_key($userId);
    $stmt = $db->prepare(
        'INSERT INTO app_settings (k, v) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $k, $payload);
    $stmt->execute();
    $stmt->close();

    if (function_exists('logx')) {
        logx("[SPOT_FREEZE] uid={$userId} {$fullReason} free=" . ($freeUsdt !== null ? (string)$freeUsdt : '?'));
    }

    $journalMsg = spot_funds_freeze_hint_uk($ctx, $fullReason, $freeUsdt);
    spot_funds_journal_event(
        $db,
        $userId,
        'SPOT_FUNDS_FROZEN',
        $journalMsg,
        $ctx['strategy_id'],
        $ctx['symbol']
    );
}

function spot_funds_unfreeze_user(mysqli $db, int $userId, ?float $freeUsdt = null, string $note = ''): void
{
    $userId = (int)$userId;
    if ($userId <= 0 || !spot_funds_user_is_frozen($db, $userId)) {
        return;
    }

    $prev = spot_funds_freeze_state($db, $userId);
    $ctx = [
        'strategy_id' => $prev['strategy_id'],
        'symbol' => $prev['symbol'],
        'label' => spot_funds_strategy_context(
            $db,
            $userId,
            $prev['strategy_id'],
            $prev['symbol']
        )['label'],
    ];

    $k = spot_funds_freeze_setting_key($userId);
    $ke = $db->real_escape_string($k);
    $db->query("DELETE FROM app_settings WHERE k='{$ke}' LIMIT 1");

    if (function_exists('logx')) {
        logx("[SPOT_UNFREEZE] uid={$userId} free=" . ($freeUsdt !== null ? (string)$freeUsdt : '?') . " {$note}");
    }

    $journalMsg = 'Розморозка: на spot достатньо USDT для докупок.'
        . ($freeUsdt !== null ? sprintf(' free≈%.2f USDT.', $freeUsdt) : '')
        . ($note !== '' ? ' ' . $note : '');
    spot_funds_journal_event(
        $db,
        $userId,
        'SPOT_FUNDS_UNFROZEN',
        $journalMsg,
        $ctx['strategy_id'],
        $ctx['symbol']
    );

    if (!function_exists('ladder_unfreeze_burst_set_pending')) {
        $burstFile = __DIR__ . '/ladder_unfreeze_burst.php';
        if (is_file($burstFile)) {
            require_once $burstFile;
        }
    }
    if (function_exists('ladder_unfreeze_burst_set_pending')) {
        ladder_unfreeze_burst_set_pending($db, $userId);
    }
}

/**
 * Перевірка балансу та розморозка (full tick / cron).
 */
function spot_funds_try_unfreeze_user(
    mysqli $db,
    int $userId,
    string $symbol,
    string $apiKey,
    string $secret,
    float $fba
): bool {
    if (!spot_funds_user_is_frozen($db, $userId)) {
        return false;
    }
    if (spot_funds_user_has_sufficient_usdt($db, $userId, $symbol, $apiKey, $secret, $fba)) {
        [$free] = ladder_funds_snapshot($symbol, $apiKey, $secret);
        spot_funds_unfreeze_user($db, $userId, $free, 'auto_balance_ok');
        return true;
    }
    return false;
}

/** @param list<string> $signals */
function signal_strip_buy_signals_when_frozen(array $signals, bool $frozen): array
{
    if (!$frozen) {
        return $signals;
    }
    $out = [];
    foreach ($signals as $s) {
        $s = (string)$s;
        if ($s === 'BUY0_ELIGIBLE' || str_starts_with($s, 'LADDER_L')) {
            continue;
        }
        $out[] = $s;
    }
    return $out;
}
