<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';
require_once __DIR__ . '/_tg_notify.php';
require_once __DIR__ . '/_accounting.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_billing_wallet.php';
require_once __DIR__ . '/_billing_extend.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db connect error']);
    exit;
}

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}
$admRes = $mysqli->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
require_csrf_or_403();

function applyReferralReward(mysqli $mysqli, int $user_id, float $amount_usdt): void
{
    $res = $mysqli->query(
        "SELECT referred_by_user_id FROM users WHERE id=" . (int)$user_id . " LIMIT 1"
    );
    $row = $res ? $res->fetch_assoc() : null;

    if (!$row || empty($row['referred_by_user_id'])) {
        return;
    }

    $referrer = (int)$row['referred_by_user_id'];

    // One accrual per referred user per billing window (avoids double-click + allows ~monthly renewals).
    $check = $mysqli->query(
        "SELECT id
         FROM referral_rewards
         WHERE referred_user_id=" . (int)$user_id . "
           AND payment_amount_usdt=" . (float)$amount_usdt . "
           AND payment_source='manual'
           AND created_at > DATE_SUB(NOW(), INTERVAL 35 DAY)
         LIMIT 1"
    );

    if ($check && $check->num_rows > 0) {
        return;
    }

    $reward = round($amount_usdt * 0.20, 8);

    $sql = "INSERT INTO referral_rewards
            (referrer_user_id, referred_user_id, payment_source, payment_amount_usdt, reward_percent, reward_usdt, status)
            VALUES
            (" . (int)$referrer . ", " . (int)$user_id . ", 'manual', " . (float)$amount_usdt . ", 20.00, " . (float)$reward . ", 'pending')";

    $mysqli->query($sql);
}

$user_id = (int)($_POST['user_id'] ?? 0);
$action  = trim((string)($_POST['action'] ?? ''));

if ($user_id <= 0 || $action === '') {
    admin_audit_log($mysqli, $uid, 'billing_invalid', $user_id, ['action' => $action], 'invalid_input');
    echo json_encode(['ok' => false, 'error' => 'invalid input']);
    exit;
}

if ($action === 'add500' || $action === 'add30') {
    // Percent model: +500 UAH → USDT @ Binance. Legacy add30 still maps here when percent active.
    if (billing_percent_mode_active($mysqli)) {
        $txid = 'admin_add500_' . (int)$user_id . '_' . date('Ymd_His');
        $cred = billing_wallet_credit_uah_pack($mysqli, $user_id, BILLING_MIN_DEPOSIT_UAH, 'admin_credit', $txid);
        $ok = !empty($cred['ok']);
        $resumed = (int)($cred['resumed_strategies'] ?? 0);
        $uRes = $mysqli->query("SELECT email FROM users WHERE id=" . (int)$user_id . " LIMIT 1");
        $email = ($uRes && ($ur = $uRes->fetch_assoc())) ? (string)($ur['email'] ?? '-') : '-';
        if ($ok) {
            tg_notify(
                "💰 ADMIN +500 UAH\nuser: {$email}\n≈ "
                . number_format((float)($cred['amount_usdt'] ?? 0), 4, '.', '')
                . " USDT (fx " . number_format((float)($cred['fx_rate'] ?? 0), 4, '.', '') . ")"
            );
            $sheetPush = accounting_notify_income($mysqli, $user_id, 'manual', $txid);
            $sheetPush = [
                'enabled' => true,
                'ok' => !empty($sheetPush['pushed']),
                'detail' => (string)($sheetPush['detail'] ?? ''),
            ];
        } else {
            $sheetPush = ['enabled' => false, 'ok' => false, 'detail' => (string)($cred['error'] ?? 'fail')];
        }
        admin_audit_log($mysqli, $uid, 'billing_add500', $user_id, [
            'action' => 'add500',
            'amount_uah' => BILLING_MIN_DEPOSIT_UAH,
            'amount_usdt' => $cred['amount_usdt'] ?? null,
            'fx_rate' => $cred['fx_rate'] ?? null,
            'resumed_strategies' => $resumed,
            'g_sheets' => $sheetPush,
            'error' => $cred['error'] ?? null,
        ], $ok ? 'ok' : 'error');
        echo json_encode([
            'ok' => $ok,
            'action' => 'add500',
            'amount_usdt' => $cred['amount_usdt'] ?? null,
            'fx_rate' => $cred['fx_rate'] ?? null,
            'resumed_strategies' => $resumed,
            'error' => $ok ? null : ($cred['error'] ?? 'fail'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    applyReferralReward($mysqli, $user_id, 10.0);

  // TELEGRAM PAYMENT NOTIFY
  $uRes = $mysqli->query("SELECT email, nickname, telegram, ref_code FROM users WHERE id=".(int)$user_id." LIMIT 1");
  $uRow = $uRes ? $uRes->fetch_assoc() : null;
  $email = $uRow['email'] ?? '-';

  tg_notify("💰 PAYMENT CONFIRMED\nuser: ".$email."\namount: 10 USDT\nplan: monthly");


    $sql = "UPDATE users
            SET billing_status='active',
                paid_until = IF(
                    paid_until IS NOT NULL AND paid_until > NOW(),
                    DATE_ADD(paid_until, INTERVAL 30 DAY),
                    DATE_ADD(NOW(), INTERVAL 30 DAY)
                )
            WHERE id=" . (int)$user_id;

    $ok = $mysqli->query($sql);

    $resumed = 0;
    if ($ok) {
        $resumed = billing_resume_strategies_after_renewal($mysqli, $user_id);
    }

    $sheetPush = ['enabled' => false, 'ok' => false, 'detail' => 'skipped'];
    if ($ok) {
        $txid = 'admin_add30_' . (int)$user_id . '_' . date('Ymd_His');
        $sheetPush = accounting_notify_income($mysqli, $user_id, 'manual', $txid);
        $sheetPush = [
            'enabled' => true,
            'ok' => !empty($sheetPush['pushed']),
            'detail' => (string)($sheetPush['detail'] ?? ''),
        ];
    }

    admin_audit_log($mysqli, $uid, 'billing_add30', $user_id, [
      'action' => 'add30',
      'amount_usdt' => 10.0,
      'resumed_strategies' => $resumed,
      'g_sheets' => $sheetPush,
    ], $ok ? 'ok' : 'db_error');
    echo json_encode(['ok' => (bool)$ok, 'action' => 'add30', 'resumed_strategies' => $resumed]);
    exit;
}

if ($action === 'trial') {
    $sql = "UPDATE users
            SET billing_status='trial',
                trial_until = DATE_ADD(NOW(), INTERVAL 3 DAY)
            WHERE id=" . (int)$user_id;

    $ok = $mysqli->query($sql);
    $resumed = 0;
    if ($ok) {
        $resumed = billing_resume_strategies_after_renewal($mysqli, $user_id);
    }
    admin_audit_log($mysqli, $uid, 'billing_trial', $user_id, [
        'action' => 'trial',
        'resumed_strategies' => $resumed,
    ], $ok ? 'ok' : 'db_error');
    echo json_encode(['ok' => (bool)$ok, 'action' => 'trial', 'resumed_strategies' => $resumed]);
    exit;
}

if ($action === 'block') {
    $sql = "UPDATE users
            SET billing_status='blocked'
            WHERE id=" . (int)$user_id;

    $ok = $mysqli->query($sql);
    admin_audit_log($mysqli, $uid, 'billing_block', $user_id, ['action' => 'block'], $ok ? 'ok' : 'db_error');
    echo json_encode(['ok' => (bool)$ok, 'action' => 'block']);
    exit;
}

if ($action === 'set_max_bots') {
    $raw = trim((string)($_POST['max'] ?? $_POST['value'] ?? ''));
    $clear = ($raw === '' || $raw === '0' || strtolower($raw) === 'default' || strtolower($raw) === 'reset');
    $maxVal = $clear ? null : (int)$raw;
    if (!$clear && ($maxVal < 1 || $maxVal > 100)) {
        admin_audit_log($mysqli, $uid, 'billing_set_max_bots', $user_id, ['max' => $raw], 'invalid_max');
        echo json_encode(['ok' => false, 'error' => 'max must be 1..100 or empty to reset']);
        exit;
    }
    $ok = billing_set_max_running_override($mysqli, $user_id, $maxVal);
    $effective = billing_max_running_strategies($mysqli, $user_id);
    $override = billing_get_max_running_override($mysqli, $user_id);
    admin_audit_log($mysqli, $uid, 'billing_set_max_bots', $user_id, [
        'action' => 'set_max_bots',
        'requested' => $clear ? null : $maxVal,
        'override' => $override,
        'effective' => $effective,
    ], $ok ? 'ok' : 'db_error');
    echo json_encode([
        'ok' => (bool)$ok,
        'action' => 'set_max_bots',
        'override' => $override,
        'effective' => $effective,
    ]);
    exit;
}

admin_audit_log($mysqli, $uid, 'billing_unknown', $user_id, ['action' => $action], 'unknown_action');
echo json_encode(['ok' => false, 'error' => 'unknown action']);
