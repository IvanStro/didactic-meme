<?php
/**
 * One-off: insert missing referral_rewards for users who already have paid access
 * but never got a reward row (e.g. add30 before applyReferralReward existed).
 *
 * Usage:
 *   php run/backfill_referral_rewards.php           # dry-run
 *   php run/backfill_referral_rewards.php --apply   # write rows
 */
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$apply = in_array('--apply', $argv ?? [], true);

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  fwrite(STDERR, "DB connect failed\n");
  exit(1);
}
$mysqli->set_charset('utf8mb4');

$sql = "
  SELECT u.id AS referred_id, u.email, u.referred_by_user_id AS referrer_id,
         u.billing_status, u.paid_until
  FROM users u
  WHERE u.referred_by_user_id IS NOT NULL
    AND u.referred_by_user_id > 0
    AND (
      (u.billing_status = 'active' AND u.paid_until IS NOT NULL AND u.paid_until > NOW())
      OR u.billing_status = 'active'
    )
    AND NOT EXISTS (
      SELECT 1 FROM referral_rewards rr
      WHERE rr.referred_user_id = u.id
        AND rr.payment_amount_usdt = 10
        AND rr.payment_source = 'manual'
    )
  ORDER BY u.id
";

$res = $mysqli->query($sql);
if (!$res) {
  fwrite(STDERR, $mysqli->error . "\n");
  exit(1);
}

$n = 0;
while ($row = $res->fetch_assoc()) {
  $n++;
  $rid = (int)$row['referred_id'];
  $ref = (int)$row['referrer_id'];
  $line = sprintf(
    "referred_id=%d email=%s referrer_id=%d billing=%s paid_until=%s\n",
    $rid,
    (string)$row['email'],
    $ref,
    (string)($row['billing_status'] ?? ''),
    (string)($row['paid_until'] ?? '')
  );
  echo $line;

  if ($apply) {
    $reward = 2.0;
    $stmt = $mysqli->prepare(
      "INSERT INTO referral_rewards
        (referrer_user_id, referred_user_id, payment_source, payment_amount_usdt, reward_percent, reward_usdt, status)
       VALUES (?, ?, 'manual', 10, 20.00, ?, 'pending')"
    );
    if ($stmt) {
      $stmt->bind_param('iid', $ref, $rid, $reward);
      $stmt->execute();
      $stmt->close();
    }
  }
}

echo $apply ? "Applied rows: $n\n" : "Dry-run candidates: $n (pass --apply to insert)\n";
