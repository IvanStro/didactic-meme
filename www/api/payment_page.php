<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_whitepay_dotenv.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();
strim_whitepay_maybe_load_home_env();

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_google_ads_config.php';

$page = defined('STRIM_PAYMENT_PAGE') ? (string) STRIM_PAYMENT_PAGE : '';
if ($page !== 'wait' && $page !== 'success') {
    http_response_code(404);
    exit;
}

$mysqli = @new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo 'Помилка сервера';
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    $next = $page === 'success' ? '/payment/success.php' : '/payment/wait.php';
    if (!empty($_GET['tx'])) {
        $next .= '?tx=' . rawurlencode((string) $_GET['tx']);
    }
    header('Location: /auth/login.php?next=' . rawurlencode($next));
    exit;
}

function payment_page_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function payment_page_confirmed_order(mysqli $db, int $uid, ?string $tx): ?array
{
    $uRes = $db->query('SELECT paid_until FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
    $uRow = $uRes ? $uRes->fetch_assoc() : null;
    if (!$uRow || empty($uRow['paid_until']) || strtotime((string) $uRow['paid_until']) <= time()) {
        return null;
    }

    $txEsc = $tx !== null && $tx !== '' ? $db->real_escape_string($tx) : '';
    $where = "user_id=" . (int) $uid . " AND status='paid' AND paid_at IS NOT NULL";
    if ($txEsc !== '') {
        $where .= " AND external_id='$txEsc'";
    } else {
        $where .= ' AND paid_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)';
    }

    $q = $db->query(
        "SELECT external_id, whitepay_invoice_id FROM whitepay_orders WHERE {$where} ORDER BY paid_at DESC LIMIT 1"
    );
    $row = $q ? $q->fetch_assoc() : null;
    if (!$row || empty($row['external_id'])) {
        return null;
    }

    return [
        'external_id' => (string) $row['external_id'],
        'paid_until' => (string) $uRow['paid_until'],
    ];
}

function payment_page_gtag_head(): void
{
    $adsId = payment_page_h(google_ads_id());
    echo <<<HTML
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('consent', 'default', {
      ad_storage: 'denied',
      analytics_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      functionality_storage: 'granted',
      security_storage: 'granted',
      wait_for_update: 500
    });
    gtag('set', 'ads_data_redaction', true);
    gtag('js', new Date());
  </script>
  
  <script>
    gtag('config', '{$adsId}');
    (function () {
      try {
        var c = localStorage.getItem('strim_consent_v2');
        if (c === 'all') {
          gtag('consent', 'update', {
            ad_storage: 'granted',
            analytics_storage: 'granted',
            ad_user_data: 'granted',
            ad_personalization: 'granted'
          });
          gtag('set', 'ads_data_redaction', false);
        }
      } catch (e) { /* ignore */ }
    })();
  </script>
  <link rel="stylesheet" href="/assets/css/strim-cookie-consent.css?v=20260923legal">

HTML;
}

if ($page === 'wait') {
    $mysqli->close();
    $title = 'Підтвердження оплати — StrIM';
    require __DIR__ . '/payment_page_wait.inc.php';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$tx = isset($_GET['tx']) ? trim((string) $_GET['tx']) : '';
$order = payment_page_confirmed_order($mysqli, $uid, $tx !== '' ? $tx : null);
$mysqli->close();

if ($order === null) {
    header('Location: /payment/wait.php');
    exit;
}

$transactionId = $order['external_id'];
$paidUntil = $order['paid_until'];
$sendTo = google_ads_purchase_send_to();
$value = google_ads_purchase_value_uah();
$sessionKey = 'strim_gads_conv_' . hash('sha256', $transactionId);
$fireConversion = $sendTo !== '' && empty($_SESSION[$sessionKey]);
if ($fireConversion) {
    $_SESSION[$sessionKey] = 1;
}

$title = 'Оплату підтверджено — StrIM';
require __DIR__ . '/payment_page_success.inc.php';
