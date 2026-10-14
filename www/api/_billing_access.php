<?php
declare(strict_types=1);

/**
 * Єдині правила доступу за підпискою / гаманцем (trial / active / exhausted / blocked).
 * EXPIRED/exhausted: жодних ручних дій; running → paused; лише wind-down (продажі без нового циклу).
 *
 * Percent model (docs/BILLING_PERCENT_PLAN_UA.md): доступ за balance_usdt / wallet_activated_at.
 */

/** Percent wallet active when users.balance_usdt exists and billing_model ≠ subscription. */
function billing_percent_mode_active(mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $col = @$db->query("SHOW COLUMNS FROM users LIKE 'balance_usdt'");
    if (!$col || $col->num_rows === 0) {
        $cached = false;
        return false;
    }
    $s = @$db->query("SELECT v FROM app_settings WHERE k='billing_model' LIMIT 1");
    $v = ($s && ($r = $s->fetch_assoc())) ? strtolower(trim((string)($r['v'] ?? ''))) : 'percent';
    $cached = ($v !== 'subscription');
    return $cached;
}

function billing_effective_status(string $status, ?string $trialUntil, ?string $paidUntil): string
{
    if ($status === 'blocked') {
        return 'blocked';
    }
    $now = time();
    if ($paidUntil !== null && $paidUntil !== '' && strtotime($paidUntil) > $now) {
        return 'active';
    }
    if ($trialUntil !== null && $trialUntil !== '' && strtotime($trialUntil) > $now) {
        return 'trial';
    }
    return 'expired';
}

/**
 * @param array<string,mixed>|null $userRow
 */
function billing_user_row_effective_status(?array $userRow): string
{
    if (!$userRow) {
        return 'expired';
    }
    $st = (string)($userRow['billing_status'] ?? 'trial');
    if ($st === 'blocked') {
        return 'blocked';
    }

    if (array_key_exists('wallet_activated_at', $userRow) || array_key_exists('balance_usdt', $userRow)) {
        $activated = !empty($userRow['wallet_activated_at']);
        if (!$activated) {
            return 'trial';
        }
        $bal = (float)($userRow['balance_usdt'] ?? 0);
        if ($bal > 1e-8) {
            return 'active';
        }
        return 'exhausted';
    }

    return billing_effective_status(
        $st,
        isset($userRow['trial_until']) ? (string)$userRow['trial_until'] : null,
        isset($userRow['paid_until']) ? (string)$userRow['paid_until'] : null
    );
}

function billing_user_row_has_access(?array $userRow): bool
{
    $eff = billing_user_row_effective_status($userRow);
    return $eff === 'active' || $eff === 'trial';
}

function billing_user_select_sql(mysqli $db): string
{
    if (billing_percent_mode_active($db)) {
        return 'billing_status, trial_until, paid_until, balance_usdt, fee_shortfall_usdt, wallet_activated_at';
    }
    return 'billing_status, trial_until, paid_until';
}

function billing_user_has_access(mysqli $db, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $res = $db->query(
        'SELECT ' . billing_user_select_sql($db) . ' FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    return billing_user_row_has_access($row ?: null);
}

function billing_user_effective_status(mysqli $db, int $userId): string
{
    if ($userId <= 0) {
        return 'expired';
    }
    $res = $db->query(
        'SELECT ' . billing_user_select_sql($db) . ' FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    $eff = billing_user_row_effective_status($row ?: null);
    if ($eff === 'exhausted') {
        return 'expired';
    }
    return $eff;
}

function billing_user_is_expired(mysqli $db, int $userId): bool
{
    $eff = billing_user_effective_status($db, $userId);
    return $eff === 'expired' || $eff === 'blocked';
}

/**
 * @return array{ok:false,error:string,message:string,billing_status:string}|null
 */
function billing_require_trading_access(mysqli $db, int $userId): ?array
{
    $res = $db->query(
        'SELECT ' . billing_user_select_sql($db) . ' FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    $eff = billing_user_row_effective_status($row ?: null);
    if ($eff === 'active' || $eff === 'trial') {
        return null;
    }
    if ($eff === 'blocked') {
        return [
            'ok' => false,
            'error' => 'billing_blocked',
            'billing_status' => 'blocked',
            'message' => 'Акаунт заблоковано. Зверніться до підтримки.',
        ];
    }
    $msg = billing_percent_mode_active($db)
        ? 'Баланс вичерпано. Доступ до керування стратегіями вимкнено. '
            . 'Існуючі позиції можуть бути закриті ботом автоматично; новий цикл не відкривається. '
            . 'Поповніть баланс від 500 грн для відновлення доступу.'
        : 'Підписка закінчилась (EXPIRED). Доступ до керування стратегіями вимкнено. '
            . 'Існуючі позиції можуть бути закриті ботом автоматично; новий цикл не відкривається. '
            . 'Оплатіть тариф для відновлення доступу.';
    return [
        'ok' => false,
        'error' => 'billing_expired',
        'billing_status' => $eff === 'exhausted' ? 'exhausted' : 'expired',
        'message' => $msg,
    ];
}

function billing_emit_trading_denied(?array $denied): void
{
    if ($denied === null) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($denied, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array{expired_users:int, paused_strategies:int}
 */
function billing_sync_expired_users(mysqli $db): array
{
    if (billing_percent_mode_active($db)) {
        $db->query("
            UPDATE users
            SET billing_status = 'expired'
            WHERE billing_status NOT IN ('blocked', 'expired')
              AND wallet_activated_at IS NOT NULL
              AND COALESCE(balance_usdt, 0) <= 0
        ");
        $expiredUsers = (int)$db->affected_rows;
        $paused = billing_pause_expired_running_strategies($db);
        return ['expired_users' => $expiredUsers, 'paused_strategies' => $paused];
    }

    $db->query("
        UPDATE users
        SET billing_status = 'expired'
        WHERE billing_status NOT IN ('blocked', 'expired')
          AND (paid_until IS NULL OR paid_until <= NOW())
          AND (trial_until IS NULL OR trial_until <= NOW())
    ");
    $expiredUsers = (int)$db->affected_rows;
    $paused = billing_pause_expired_running_strategies($db);
    return ['expired_users' => $expiredUsers, 'paused_strategies' => $paused];
}

function billing_max_running_strategies(mysqli $db, int $userId): int
{
    $trialDefault = billing_percent_mode_active($db) ? 3 : 2;
    $paidDefault = billing_percent_mode_active($db) ? 50 : 5;
    $hardCap = 100;

    $override = billing_get_max_running_override($db, $userId);
    if ($override !== null) {
        return $override;
    }

    $res = $db->query(
        'SELECT ' . billing_user_select_sql($db) . ' FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    $eff = billing_user_row_effective_status($row ?: null);
    if ($eff === 'exhausted') {
        $eff = 'expired';
    }

    $key = ($eff === 'trial') ? 'max_running_strategies_trial' : 'max_running_strategies_paid';
    $fallback = ($eff === 'trial') ? $trialDefault : $paidDefault;

    $s = $db->query("SELECT v FROM app_settings WHERE k='" . $db->real_escape_string($key) . "' LIMIT 1");
    if ($s && ($r = $s->fetch_assoc())) {
        $n = (int)($r['v'] ?? 0);
        if ($n >= 1 && $n <= $hardCap) {
            return $n;
        }
    }

    return $fallback;
}

function billing_get_max_running_override(mysqli $db, int $userId): ?int
{
    if ($userId <= 0) {
        return null;
    }
    $uk = 'max_running_strategies_u' . (int)$userId;
    $su = $db->query("SELECT v FROM app_settings WHERE k='" . $db->real_escape_string($uk) . "' LIMIT 1");
    if ($su && ($ru = $su->fetch_assoc())) {
        $n = (int)($ru['v'] ?? 0);
        if ($n >= 1 && $n <= 100) {
            return $n;
        }
    }
    return null;
}

function billing_set_max_running_override(mysqli $db, int $userId, ?int $max): bool
{
    if ($userId <= 0) {
        return false;
    }
    $uk = 'max_running_strategies_u' . (int)$userId;
    $kEsc = $db->real_escape_string($uk);
    if ($max === null || $max <= 0) {
        return (bool)$db->query("DELETE FROM app_settings WHERE k='{$kEsc}' LIMIT 1");
    }
    $n = max(1, min(100, $max));
    $vEsc = $db->real_escape_string((string)$n);
    return (bool)$db->query(
        "INSERT INTO app_settings(k,v) VALUES ('{$kEsc}','{$vEsc}') ON DUPLICATE KEY UPDATE v=VALUES(v)"
    );
}

function billing_count_running_strategies(mysqli $db, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $res = $db->query(
        "SELECT COUNT(*) AS c FROM strategies WHERE owner_id=" . (int)$userId . " AND status='running' AND symbol NOT LIKE '%TEMPLATE%'"
    );
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['c'] ?? 0);
}

function billing_pause_expired_running_strategies(mysqli $db): int
{
    if (billing_percent_mode_active($db)) {
        $sel = $db->query("
            SELECT s.id, s.symbol
            FROM strategies s
            INNER JOIN users u ON u.id = s.owner_id
            WHERE s.status = 'running'
              AND UPPER(COALESCE(s.symbol,'')) <> 'TEMPLATE'
              AND s.symbol NOT LIKE '%TEMPLATE%'
              AND (
                u.billing_status = 'blocked'
                OR (
                  u.wallet_activated_at IS NOT NULL
                  AND COALESCE(u.balance_usdt, 0) <= 0
                )
              )
        ");
    } else {
        $sel = $db->query("
            SELECT s.id, s.symbol
            FROM strategies s
            INNER JOIN users u ON u.id = s.owner_id
            WHERE s.status = 'running'
              AND UPPER(COALESCE(s.symbol,'')) <> 'TEMPLATE'
              AND s.symbol NOT LIKE '%TEMPLATE%'
              AND (
                u.billing_status = 'blocked'
                OR (
                  (u.paid_until IS NULL OR u.paid_until <= NOW())
                  AND (u.trial_until IS NULL OR u.trial_until <= NOW())
                )
              )
        ");
    }
    $rows = [];
    while ($sel && ($row = $sel->fetch_assoc())) {
        $rows[] = $row;
    }
    if ($rows === []) {
        return 0;
    }

    $ids = [];
    foreach ($rows as $row) {
        $sid = (int)($row['id'] ?? 0);
        if ($sid > 0) {
            $ids[] = $sid;
        }
    }
    if ($ids === []) {
        return 0;
    }

    $idList = implode(',', $ids);
    $db->query("UPDATE strategies SET status = 'paused' WHERE id IN ({$idList}) AND status = 'running'");
    $n = (int)$db->affected_rows;

    $pauseMsg = billing_percent_mode_active($db)
        ? 'Пауза через вичерпаний баланс. Нові входи вимкнено; після поповнення ця стратегія має відновитись автоматично.'
        : 'Пауза через закінчення підписки (EXPIRED). Нові входи вимкнено; після оплати ця стратегія має відновитись автоматично.';

    foreach ($rows as $row) {
        $sid = (int)($row['id'] ?? 0);
        $sym = trim((string)($row['symbol'] ?? ''));
        if ($sid <= 0 || $sym === '') {
            continue;
        }
        $symEsc = $db->real_escape_string($sym);
        $msgEsc = $db->real_escape_string($pauseMsg);
        $db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
             VALUES (NOW(), {$sid}, '{$symEsc}', 'BILLING_PAUSE', NULL, NULL, '{$msgEsc}')"
        );
    }

    return $n;
}

function billing_resume_strategies_after_renewal(mysqli $db, int $userId): int
{
    // A top-up restores access only. A user must explicitly press Start.
    return 0;
}

function billing_strategy_should_auto_resume(mysqli $db, int $strategyId): bool
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0) {
        return false;
    }

    $r = $db->query("
        SELECT event_type
        FROM engine_journal
        WHERE strategy_id = {$strategyId}
          AND event_type IN ('USER_PAUSE', 'USER_STOP', 'USER_START', 'BILLING_PAUSE', 'BILLING_RESUME')
        ORDER BY id DESC
        LIMIT 1
    ");
    $last = ($r && ($row = $r->fetch_assoc())) ? (string)($row['event_type'] ?? '') : '';

    if ($last === 'BILLING_PAUSE') {
        return true;
    }
    if ($last === 'USER_PAUSE' || $last === 'USER_STOP') {
        return false;
    }
    if ($last === '' || $last === 'USER_START' || $last === 'BILLING_RESUME') {
        return true;
    }

    return false;
}

/** Max FBA for trial under percent model (0 = no extra cap). */
function billing_trial_max_fba_usdt(mysqli $db, int $userId): float
{
    if (!billing_percent_mode_active($db) || $userId <= 0) {
        return 0.0;
    }
    $res = $db->query(
        'SELECT ' . billing_user_select_sql($db) . ' FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    $raw = billing_user_row_effective_status($row ?: null);
    if ($raw !== 'trial') {
        return 0.0;
    }
    return 10.0;
}
