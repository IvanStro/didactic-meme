<?php
declare(strict_types=1);
/**
 * Чи підтверджена оплата WhitePay (webhook → whitepay_orders.status=paid + paid_until).
 * Для Google Ads: конверсію відправляти лише при confirmed=true.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_whitepay_dotenv.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();
strim_whitepay_maybe_load_home_env();

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_google_ads_config.php';

$mysqli = @new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$windowHours = 72;
$uRes = $mysqli->query(
    'SELECT paid_until, billing_status FROM users WHERE id=' . (int) $uid . ' LIMIT 1'
);
$uRow = $uRes ? $uRes->fetch_assoc() : null;
$paidUntil = $uRow['paid_until'] ?? null;
$paidActive = $paidUntil && strtotime((string) $paidUntil) > time();

$ordRes = $mysqli->query(
    "SELECT external_id, whitepay_invoice_id, paid_at, status
     FROM whitepay_orders
     WHERE user_id=" . (int) $uid . "
       AND status='paid'
       AND paid_at IS NOT NULL
       AND paid_at > DATE_SUB(NOW(), INTERVAL {$windowHours} HOUR)
     ORDER BY paid_at DESC
     LIMIT 1"
);
$ord = $ordRes ? $ordRes->fetch_assoc() : null;

$pendingRes = $mysqli->query(
    "SELECT external_id, status, created_at
     FROM whitepay_orders
     WHERE user_id=" . (int) $uid . "
       AND status NOT IN ('paid','failed','cancelled')
       AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)
     ORDER BY id DESC
     LIMIT 1"
);
$pending = $pendingRes ? $pendingRes->fetch_assoc() : null;

$confirmed = $ord !== null && $paidActive;
$transactionId = '';
if ($ord) {
    $transactionId = (string) ($ord['external_id'] ?? '');
    if ($transactionId === '' && !empty($ord['whitepay_invoice_id'])) {
        $transactionId = (string) $ord['whitepay_invoice_id'];
    }
}

$mysqli->close();

echo json_encode([
    'ok' => true,
    'confirmed' => $confirmed,
    'pending_webhook' => !$confirmed && $pending !== null,
    'paid_until' => $paidUntil,
    'transaction_id' => $transactionId,
    'purchase_value_uah' => google_ads_purchase_value_uah(),
    'send_to' => google_ads_purchase_send_to(),
    'ads_id' => google_ads_id(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
