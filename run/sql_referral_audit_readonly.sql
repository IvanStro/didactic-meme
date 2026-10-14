-- Read-only referral and withdrawals reconciliation audit
-- Usage example:
-- mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < run/sql_referral_audit_readonly.sql

SELECT 'A_total_rewards' AS check_name,
       ROUND(COALESCE(SUM(reward_usdt),0), 8) AS value_usdt
FROM referral_rewards;

SELECT 'B_pending_rewards' AS check_name,
       ROUND(COALESCE(SUM(reward_usdt),0), 8) AS value_usdt
FROM referral_rewards
WHERE status='pending';

SELECT 'C_paid_withdrawals' AS check_name,
       ROUND(COALESCE(SUM(amount_usdt),0), 8) AS value_usdt
FROM referral_withdrawals
WHERE status='paid';

SELECT 'D_pending_withdrawals' AS check_name,
       ROUND(COALESCE(SUM(amount_usdt),0), 8) AS value_usdt
FROM referral_withdrawals
WHERE status='pending';

-- Potential duplicate referral rewards for same referred user and payment amount.
SELECT referred_user_id,
       payment_amount_usdt,
       COUNT(*) AS rows_count,
       ROUND(SUM(reward_usdt), 8) AS reward_sum
FROM referral_rewards
GROUP BY referred_user_id, payment_amount_usdt
HAVING COUNT(*) > 1
ORDER BY rows_count DESC, referred_user_id ASC;

-- Per-user reconciliation snapshot for admin dashboard cross-check.
SELECT
  u.id AS user_id,
  u.email,
  ROUND(COALESCE(SUM(CASE WHEN rr.status='pending' THEN rr.reward_usdt ELSE 0 END),0), 8) AS pending_rewards,
  ROUND(COALESCE(SUM(CASE WHEN w.status='paid' THEN w.amount_usdt ELSE 0 END),0), 8) AS paid_withdrawals,
  ROUND(
    COALESCE(SUM(CASE WHEN rr.status='pending' THEN rr.reward_usdt ELSE 0 END),0)
    - COALESCE(SUM(CASE WHEN w.status='pending' THEN w.amount_usdt ELSE 0 END),0)
  , 8) AS pending_minus_pending_withdraw
FROM users u
LEFT JOIN referral_rewards rr ON rr.referrer_user_id = u.id
LEFT JOIN referral_withdrawals w ON w.user_id = u.id
GROUP BY u.id, u.email
ORDER BY u.id ASC;
