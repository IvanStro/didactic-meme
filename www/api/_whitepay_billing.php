<?php
declare(strict_types=1);

require_once __DIR__ . '/_billing_extend.php';

/**
 * Підтвердження WhitePay → депозит (percent) або +30д (legacy subscription).
 *
 * @return array{ok:bool, error?:string}
 */
function whitepay_extend_subscription(mysqli $db, int $userId, string $txRef = '', ?float $amountUah = null): array
{
    return billing_extend_subscription_30d($db, $userId, 'whitepay', $txRef, 10.0, $amountUah);
}

/**
 * Рядок у engine_journal для кабінету (журнал прив’язаний до strategies; strategy_id=0 не показується).
 */
function whitepay_insert_billing_journal(mysqli $db, int $userId, string $message): void
{
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
        $sid . ", '$symEsc', 'WHITEPAY', NULL, NULL, '$msgEsc')"
    );
}

function whitepay_apply_referral_reward(mysqli $db, int $userId, float $amountUsdt): void
{
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
           AND payment_source='whitepay'
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
            (" . (int) $referrer . ', ' . (int) $userId . ", 'whitepay', " . (float) $amountUsdt . ', 20.00, ' . (float) $reward . ", 'pending')"
    );
}
