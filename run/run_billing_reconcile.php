<?php
declare(strict_types=1);
/**
 * Read-only billing + referral reconciliation report.
 *   php run/run_billing_reconcile.php
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$report = ['ok' => true, 'checked_at' => gmdate('c'), 'totals' => [], 'dup_rewards' => [], 'users' => []];

$queries = [
    'total_rewards' => "SELECT ROUND(COALESCE(SUM(reward_usdt),0),8) v FROM referral_rewards",
    'pending_rewards' => "SELECT ROUND(COALESCE(SUM(reward_usdt),0),8) v FROM referral_rewards WHERE status='pending'",
    'paid_withdrawals' => "SELECT ROUND(COALESCE(SUM(amount_usdt),0),8) v FROM referral_withdrawals WHERE status='paid'",
    'pending_withdrawals' => "SELECT ROUND(COALESCE(SUM(amount_usdt),0),8) v FROM referral_withdrawals WHERE status='pending'",
];

foreach ($queries as $key => $sql) {
    $r = $m->query($sql);
    $report['totals'][$key] = $r ? (float)($r->fetch_assoc()['v'] ?? 0) : null;
}

$r = $m->query("
    SELECT referred_user_id, payment_amount_usdt, COUNT(*) c, ROUND(SUM(reward_usdt),8) reward_sum
    FROM referral_rewards
    WHERE status = 'pending'
    GROUP BY referred_user_id, payment_amount_usdt
    HAVING c > 1
    ORDER BY c DESC LIMIT 20
");
while ($r && ($row = $r->fetch_assoc())) {
    $report['dup_rewards'][] = $row;
}

$r = $m->query("
    SELECT u.id, u.email,
      ROUND(COALESCE(SUM(CASE WHEN rr.status='pending' THEN rr.reward_usdt ELSE 0 END),0),8) pending_rewards,
      ROUND(COALESCE(SUM(CASE WHEN w.status='pending' THEN w.amount_usdt ELSE 0 END),0),8) pending_withdraw
    FROM users u
    LEFT JOIN referral_rewards rr ON rr.referrer_user_id = u.id
    LEFT JOIN referral_withdrawals w ON w.user_id = u.id
    GROUP BY u.id, u.email
    HAVING pending_rewards > 0 OR pending_withdraw > 0
    ORDER BY pending_rewards DESC
    LIMIT 50
");
while ($r && ($row = $r->fetch_assoc())) {
    $report['users'][] = $row;
}

$report['issues'] = count($report['dup_rewards']);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($report['issues'] > 0 ? 1 : 0);
