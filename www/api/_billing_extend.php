<?php
declare(strict_types=1);

require_once __DIR__ . '/_tg_notify.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_billing_wallet.php';

/**
 * Підтвердження оплати → депозит на гаманець (percent model)
 * або legacy +30 днів якщо billing_model=subscription.
 *
 * @return array{ok:bool, error?:string, paid_until?:string, amount_usdt?:float, fx_rate?:float}
 */
function billing_extend_subscription_30d(
    mysqli $db,
    int $userId,
    string $paymentSource,
    string $txRef = '',
    float $referralAmountUsdt = 10.0,
    ?float $amountUah = null
): array {
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    $paymentSource = preg_replace('/[^a-z0-9_]/', '', strtolower($paymentSource)) ?: 'unknown';

    $uRes = $db->query(
        'SELECT email, nickname, ref_code FROM users WHERE id=' . (int) $userId . ' LIMIT 1'
    );
    $uRow = $uRes ? $uRes->fetch_assoc() : null;
    if (!$uRow) {
        return ['ok' => false, 'error' => 'user_not_found'];
    }
    $email = (string) ($uRow['email'] ?? '-');
    $refLine = $txRef !== '' ? ("\nref: " . $txRef) : '';
    $labels = [
        'whitepay' => 'WhitePay',
        'privat_bank' => 'Банк (Приват)',
        'manual' => 'Адмін',
    ];
    $label = $labels[$paymentSource] ?? $paymentSource;

    // --- Percent wallet ---
    if (billing_percent_mode_active($db)) {
        $uah = $amountUah !== null && $amountUah > 0 ? $amountUah : BILLING_MIN_DEPOSIT_UAH;
        $dep = billing_wallet_deposit_uah(
            $db,
            $userId,
            $uah,
            $paymentSource,
            $txRef !== '' ? $txRef : ($paymentSource . '_' . $userId . '_' . time())
        );
        if (empty($dep['ok'])) {
            return ['ok' => false, 'error' => (string)($dep['error'] ?? 'deposit_failed')];
        }
        $usdt = (float)($dep['amount_usdt'] ?? 0);
        $fx = (float)($dep['fx_rate'] ?? 0);
        $resumed = (int)($dep['resumed_strategies'] ?? 0);
        tg_notify(
            "💰 {$label} — депозит підтверджено\nuser: {$email}\n"
            . number_format($uah, 2, '.', ' ') . " UAH → "
            . number_format($usdt, 4, '.', '') . " USDT (fx {$fx})"
            . $refLine
        );
        billing_insert_journal(
            $db,
            $userId,
            "💳 {$label} — депозит "
            . number_format($uah, 2, '.', '') . " грн ≈ "
            . number_format($usdt, 4, '.', '') . " USDT (Binance)"
            . $refLine,
            strtoupper($paymentSource) === 'WHITEPAY' ? 'WHITEPAY' : 'BILLING'
        );
        if (is_file(__DIR__ . '/_accounting.php')) {
            require_once __DIR__ . '/_accounting.php';
            accounting_notify_income($db, $userId, $paymentSource, $txRef);
        }
        if ($resumed > 0) {
            tg_notify("▶️ Авто-старт після поповнення\nuser: {$email}\nstrategies resumed: {$resumed}");
        }
        return [
            'ok' => true,
            'amount_uah' => $uah,
            'amount_usdt' => $usdt,
            'fx_rate' => $fx,
            'resumed_strategies' => $resumed,
            'ledger_id' => (int)($dep['ledger_id'] ?? 0),
        ];
    }

    // --- Legacy subscription +30d ---
    billing_apply_referral_reward($db, $userId, $referralAmountUsdt, $paymentSource);

    tg_notify("💰 {$label} — оплата підтверджена\nuser: {$email}\n+30 днів" . $refLine);

    $sql = "UPDATE users
            SET billing_status='active',
                paid_until = IF(
                    paid_until IS NOT NULL AND paid_until > NOW(),
                    DATE_ADD(paid_until, INTERVAL 30 DAY),
                    DATE_ADD(NOW(), INTERVAL 30 DAY)
                )
            WHERE id=" . (int) $userId . ' LIMIT 1';

    if (!$db->query($sql)) {
        return ['ok' => false, 'error' => 'db_update'];
    }

    $puRes = $db->query('SELECT paid_until FROM users WHERE id=' . (int) $userId . ' LIMIT 1');
    $paidUntil = ($puRes && ($pr = $puRes->fetch_assoc())) ? (string) ($pr['paid_until'] ?? '') : '';

    billing_insert_journal(
        $db,
        $userId,
        "💳 {$label} — підписка +30 днів (500 UAH)\nuser: {$email}" . $refLine,
        strtoupper($paymentSource) === 'WHITEPAY' ? 'WHITEPAY' : 'BILLING'
    );

    if (is_file(__DIR__ . '/_accounting.php')) {
        require_once __DIR__ . '/_accounting.php';
        accounting_notify_income($db, $userId, $paymentSource, $txRef);
    }

    $resumed = billing_resume_strategies_after_renewal($db, $userId);
    if ($resumed > 0) {
        tg_notify("▶️ Авто-старт після оплати\nuser: {$email}\nstrategies resumed: {$resumed}");
        billing_insert_journal(
            $db,
            $userId,
            "▶️ Після оплати автоматично знято паузу з {$resumed} стратегій(ї) (були на паузі через EXPIRED).",
            'BILLING'
        );
    }

    return ['ok' => true, 'paid_until' => $paidUntil, 'resumed_strategies' => $resumed];
}

function billing_insert_journal(mysqli $db, int $userId, string $message, string $eventType = 'BILLING'): void
{
    $eventType = preg_replace('/[^A-Z0-9_]/', '', strtoupper($eventType)) ?: 'BILLING';
    $q = $db->query(
        'SELECT id, symbol FROM strategies WHERE (owner_id=' . (int) $userId .
        ' OR (owner_id IS NULL AND user_id=' . (int) $userId . ')) ORDER BY id ASC LIMIT 1'
    );
    $row = $q ? $q->fetch_assoc() : null;
    if (!$row) {
        return;
    }
    $sid = (int) ($row['id'] ?? 0);
    $sym = trim((string) ($row['symbol'] ?? ''));
    if ($sid <= 0 || $sym === '') {
        return;
    }
    $msgEsc = $db->real_escape_string($message);
    $symEsc = $db->real_escape_string($sym);
    $db->query(
        "INSERT INTO engine_journal (strategy_id, symbol, event_type, level_idx, side, message) VALUES (" .
        $sid . ", '$symEsc', '$eventType', NULL, NULL, '$msgEsc')"
    );
}

function billing_apply_referral_reward(mysqli $db, int $userId, float $amountUsdt, string $paymentSource): void
{
    // Legacy only — percent model uses referral_deposit_accruals via deposit.
    if (function_exists('billing_percent_mode_active') && billing_percent_mode_active($db)) {
        return;
    }
    $paymentSource = $db->real_escape_string(substr($paymentSource, 0, 32));
    $res = $db->query(
        'SELECT referred_by_user_id FROM users WHERE id=' . (int) $userId . ' LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || empty($row['referred_by_user_id'])) {
        return;
    }

    $referrer = (int) $row['referred_by_user_id'];
    $check = $db->query(
        "SELECT id FROM referral_rewards
         WHERE referred_user_id=" . (int) $userId . "
           AND payment_amount_usdt=" . (float) $amountUsdt . "
           AND payment_source='{$paymentSource}'
           AND created_at > DATE_SUB(NOW(), INTERVAL 35 DAY)
         LIMIT 1"
    );
    if ($check && $check->num_rows > 0) {
        return;
    }
    $reward = round($amountUsdt * 0.20, 8);
    $db->query(
        "INSERT INTO referral_rewards
         (referrer_user_id, referred_user_id, payment_source, payment_amount_usdt, reward_percent, reward_usdt, status)
         VALUES
         ({$referrer}, " . (int) $userId . ", '{$paymentSource}', " . (float) $amountUsdt . ", 20.00, {$reward}, 'pending')"
    );
}
