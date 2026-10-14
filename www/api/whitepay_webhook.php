<?php
declare(strict_types=1);
/**
 * WhitePay webhook (PUBLIC). URL для CRM → вкладка «Вебхуки» платіжної сторінки.
 */
if (!defined('ALLOW_UNAUTHENTICATED')) {
    define('ALLOW_UNAUTHENTICATED', true);
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_whitepay_config.php';
require_once __DIR__ . '/_whitepay_billing.php';
require_once __DIR__ . '/_admin_audit.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();
require_once __DIR__ . '/_whitepay_dotenv.php';
strim_whitepay_maybe_load_home_env();

$raw = (string) file_get_contents('php://input');
if ($raw === '') {
    echo json_encode(['ok' => true, 'note' => 'empty'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo json_encode(['ok' => true, 'note' => 'bad json'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($payload !== [] && array_keys($payload) === range(0, count($payload) - 1) && count($payload) === 1 && is_array($payload[0])) {
    $payload = $payload[0];
}

$expected = whitepay_webhook_token();
if ($expected === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'WHITEPAY_WEBHOOK_TOKEN missing'], JSON_UNESCAPED_UNICODE);
    exit;
}
$got = '';
foreach (
    [
        'HTTP_X_WEBHOOK_TOKEN',
        'HTTP_X_WHITEPAY_WEBHOOK_TOKEN',
        'HTTP_AUTHORIZATION',
    ] as $hk
) {
    if (!empty($_SERVER[$hk])) {
        $got = (string) $_SERVER[$hk];
        break;
    }
}
if ($got === '' && isset($payload['webhook_token'])) {
    $got = (string) $payload['webhook_token'];
}
if (stripos($got, 'Bearer ') === 0) {
    $got = trim(substr($got, 7));
}
if ($got === '' || !hash_equals($expected, $got)) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_webhook.log',
        date('c') . " bad token hdr=" . substr((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 0, 20) . " len=" . strlen($raw) . "\n",
        FILE_APPEND
    );
    echo json_encode(['ok' => true, 'note' => 'bad token'], JSON_UNESCAPED_UNICODE);
    exit;
}

@file_put_contents(
    dirname(__DIR__, 2) . '/logs/whitepay_webhook.log',
    date('c') . ' recv keys=' . implode(',', array_slice(array_keys($payload), 0, 20)) . ' len=' . strlen($raw) . "\n",
    FILE_APPEND
);

$allowedIp = whitepay_env('WHITEPAY_WEBHOOK_IP', '');
if ($allowedIp !== '') {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && $remote !== $allowedIp && strpos($allowedIp, '/') === false) {
        echo json_encode(['ok' => true, 'note' => 'ip skip'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function whitepay_flatten_status(array $p): string
{
    foreach (['status', 'state', 'payment_status', 'invoice_status'] as $k) {
        if (!isset($p[$k])) {
            continue;
        }
        $v = $p[$k];
        if (is_string($v)) {
            return strtolower(trim($v));
        }
        if (is_int($v) || is_float($v)) {
            return strtolower(trim((string) $v));
        }
    }
    if (isset($p['data']) && is_array($p['data'])) {
        foreach (['status', 'state', 'payment_status', 'invoice_status'] as $k) {
            if (!isset($p['data'][$k])) {
                continue;
            }
            $v = $p['data'][$k];
            if (is_string($v)) {
                return strtolower(trim($v));
            }
            if (is_int($v) || is_float($v)) {
                return strtolower(trim((string) $v));
            }
        }
    }
    if (isset($p['order']) && is_array($p['order'])) {
        foreach (['status', 'state'] as $k) {
            if (!empty($p['order'][$k]) && is_string($p['order'][$k])) {
                return strtolower(trim($p['order'][$k]));
            }
        }
    }
    return '';
}

/** Усі знайдені «статусні» рядки по дереву (для різних форматів вебхука). */
function whitepay_collect_status_strings(array $p, int $depth = 0): array
{
    if ($depth > 14) {
        return [];
    }
    $out = [];
    foreach (['status', 'state', 'payment_status', 'invoice_status'] as $k) {
        if (!isset($p[$k])) {
            continue;
        }
        $v = $p[$k];
        if (is_string($v)) {
            $out[] = strtolower(trim($v));
        } elseif (is_int($v) || is_float($v)) {
            $out[] = strtolower(trim((string) $v));
        }
    }
    foreach ($p as $v) {
        if (is_array($v)) {
            $out = array_merge($out, whitepay_collect_status_strings($v, $depth + 1));
        }
    }
    return $out;
}

function whitepay_top_event_string(array $p): string
{
    $parts = [];
    foreach (['event', 'type', 'event_type', 'action'] as $k) {
        if (!empty($p[$k]) && is_string($p[$k])) {
            $parts[] = strtolower(trim($p[$k]));
        }
    }
    return implode(' ', $parts);
}

function whitepay_flatten_external_id(array $p): string
{
    $pick = static function (?array $a): string {
        if ($a === null) {
            return '';
        }
        foreach (['external_order_id', 'external_id', 'merchant_order_id', 'client_order_id'] as $k) {
            if (!empty($a[$k]) && is_string($a[$k])) {
                return trim($a[$k]);
            }
        }
        return '';
    };

    $x = $pick($p['order'] ?? null);
    if ($x !== '') {
        return $x;
    }
    if (isset($p['data']) && is_array($p['data'])) {
        $d = $p['data'];
        $x = $pick($d['order'] ?? null);
        if ($x !== '') {
            return $x;
        }
        $x = $pick($d);
        if ($x !== '') {
            return $x;
        }
    }
    foreach (['external_id', 'external_order_id', 'merchant_order_id', 'client_order_id'] as $k) {
        if (!empty($p[$k]) && is_string($p[$k])) {
            return trim($p[$k]);
        }
    }
    if (isset($p['data']) && is_array($p['data'])) {
        foreach (['external_id', 'external_order_id', 'merchant_order_id', 'client_order_id'] as $k) {
            if (!empty($p['data'][$k]) && is_string($p['data'][$k])) {
                return trim($p['data'][$k]);
            }
        }
    }
    if (!empty($p['order_id']) && is_string($p['order_id'])) {
        return trim($p['order_id']);
    }
    if (isset($p['data']['order_id']) && is_string($p['data']['order_id'])) {
        return trim($p['data']['order_id']);
    }
    return '';
}

function whitepay_is_paid_status(string $status): bool
{
    if ($status === '') {
        return false;
    }
    $paid = [
        'paid', 'completed', 'complete', 'success', 'successful', 'confirmed',
        'done', 'finished', 'settled',
    ];
    if (in_array($status, $paid, true)) {
        return true;
    }
    foreach (['completed', 'complete', 'success', 'successful', 'confirmed', 'settled', 'finished'] as $w) {
        if (strpos($status, $w) !== false) {
            return true;
        }
    }
    if (preg_match('/\bpaid\b/', $status) === 1) {
        return true;
    }
    /** WhitePay crypto order terminal statuses */
    if (strpos($status, 'fulfilled') !== false) {
        return true;
    }
    return false;
}

function whitepay_payload_indicates_paid(array $p): bool
{
    $flat = whitepay_flatten_status($p);
    if (whitepay_is_paid_status($flat)) {
        return true;
    }
    foreach (whitepay_collect_status_strings($p) as $st) {
        if (whitepay_is_paid_status($st)) {
            return true;
        }
    }
    $ev = whitepay_top_event_string($p);
    if ($ev !== '' && strpos($ev, 'unpaid') === false) {
        foreach (
            [
                'invoice.paid', 'payment.paid', 'payment_paid', 'payment_success', 'payment_succeeded',
                'order.complete', 'order_completed', 'invoice.completed', 'payment.completed',
                'crypto_order', 'invoice_paid',
            ] as $needle
        ) {
            if (strpos($ev, $needle) !== false) {
                return true;
            }
        }
        if (preg_match('/\.(paid|completed|succeeded|fulfilled)\b/', $ev) === 1
            && !preg_match('/\b(fail|failed|declin|cancel|void)\b/', $ev)) {
            return true;
        }
    }
    return false;
}

function whitepay_deep_find_email(array $p, int $depth = 0): string
{
    if ($depth > 14) {
        return '';
    }
    foreach (['email', 'payer_email', 'customer_email', 'payer_email_address', 'payerEmail', 'customerEmail', 'user_email'] as $k) {
        if (!empty($p[$k]) && is_string($p[$k])) {
            $e = trim($p[$k]);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return $e;
            }
        }
    }
    foreach ($p as $v) {
        if (is_array($v)) {
            $e = whitepay_deep_find_email($v, $depth + 1);
            if ($e !== '') {
                return $e;
            }
        }
    }
    return '';
}

/** Ідентифікатор платежу / інвойсу в CRM (для ідемпотентності). */
function whitepay_deep_find_invoice_id(array $p, int $depth = 0): string
{
    if ($depth > 14) {
        return '';
    }
    if (isset($p['order']) && is_array($p['order'])) {
        $oid = $p['order']['id'] ?? null;
        if ($oid !== null && $oid !== '') {
            $v = trim((string) $oid);
            if (strlen($v) >= 6) {
                return $v;
            }
        }
    }
    foreach (['invoice_id', 'payment_id', 'uuid', 'invoiceId', 'paymentId', 'transaction_id'] as $k) {
        if (!empty($p[$k])) {
            $v = trim((string) $p[$k]);
            if (strlen($v) >= 6) {
                return $v;
            }
        }
    }
    foreach ($p as $v) {
        if (is_array($v)) {
            $x = whitepay_deep_find_invoice_id($v, $depth + 1);
            if ($x !== '') {
                return $x;
            }
        }
    }
    return '';
}

function whitepay_user_id_from_external(string $externalId): int
{
    if (preg_match('/strim-u(\d+)-/i', $externalId, $m)) {
        return (int) $m[1];
    }
    return 0;
}

$invId = whitepay_deep_find_invoice_id($payload);
$externalId = whitepay_flatten_external_id($payload);

if (!whitepay_payload_indicates_paid($payload)) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_webhook.log',
        date('c') . ' ignored_not_paid st=' . whitepay_flatten_status($payload) . ' ev=' . whitepay_top_event_string($payload) . ' raw=' . substr($raw, 0, 900) . "\n",
        FILE_APPEND
    );
    echo json_encode([
        'ok'      => true,
        'note'    => 'ignored_not_paid',
        'status'  => whitepay_flatten_status($payload),
        'event'   => whitepay_top_event_string($payload),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = new mysqli(
    $_ENV['DB_HOST'] ?? '',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? ''
);
if ($mysqli->connect_errno) {
    echo json_encode(['ok' => true, 'note' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$mysqli->query(
    "CREATE TABLE IF NOT EXISTS whitepay_orders (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT NOT NULL,
      external_id VARCHAR(64) NOT NULL,
      amount_uah DECIMAL(10,2) NOT NULL DEFAULT 500.00,
      currency VARCHAR(8) NOT NULL DEFAULT 'UAH',
      status VARCHAR(24) NOT NULL DEFAULT 'pending',
      whitepay_invoice_id VARCHAR(128) DEFAULT NULL,
      pay_url TEXT DEFAULT NULL,
      raw_create_json MEDIUMTEXT DEFAULT NULL,
      raw_webhook_json MEDIUMTEXT DEFAULT NULL,
      paid_at DATETIME DEFAULT NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_external_id (external_id),
      KEY idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
);

$invEsc = $invId !== '' ? $mysqli->real_escape_string($invId) : '';

if ($invEsc !== '') {
    $dupPaid = $mysqli->query(
        "SELECT id FROM whitepay_orders WHERE whitepay_invoice_id='$invEsc' AND status='paid' LIMIT 1"
    );
    if ($dupPaid && $dupPaid->num_rows > 0) {
        echo json_encode(['ok' => true, 'note' => 'already_paid_invoice'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($externalId === '') {
    $externalId = $invId !== ''
        ? ('wp-inv-' . substr(preg_replace('/[^a-zA-Z0-9_.-]/', '', $invId), 0, 48))
        : ('strim-wp-' . substr(hash('sha256', $raw), 0, 28));
}

$extEsc = $mysqli->real_escape_string($externalId);
$rawEsc = $mysqli->real_escape_string(substr($raw, 0, 65000));

$orderRow = null;
$ord = $mysqli->query("SELECT id, user_id, status, whitepay_invoice_id, amount_uah FROM whitepay_orders WHERE external_id='$extEsc' LIMIT 1");
if ($ord && ($row = $ord->fetch_assoc())) {
    $orderRow = $row;
}

if (!$orderRow && $invEsc !== '') {
    $ord2 = $mysqli->query(
        "SELECT id, user_id, status, external_id, whitepay_invoice_id, amount_uah FROM whitepay_orders WHERE whitepay_invoice_id='$invEsc' LIMIT 1"
    );
    if ($ord2 && ($row = $ord2->fetch_assoc())) {
        $orderRow = $row;
        $externalId = (string) ($row['external_id'] ?? $externalId);
        $extEsc = $mysqli->real_escape_string($externalId);
    }
}

$userId = $orderRow ? (int) $orderRow['user_id'] : whitepay_user_id_from_external($externalId);

if ($userId <= 0) {
    $email = whitepay_deep_find_email($payload);
    if ($email !== '') {
        $emEsc = $mysqli->real_escape_string($email);
        $uq = $mysqli->query("SELECT id FROM users WHERE email='$emEsc' LIMIT 1");
        $ur = $uq ? $uq->fetch_assoc() : null;
        if ($ur) {
            $userId = (int) $ur['id'];
        }
    }
}

if ($userId <= 0) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_webhook.log',
        date('c') . " no user ext=$externalId inv=$invId raw=" . substr($raw, 0, 1500) . "\n",
        FILE_APPEND
    );
    echo json_encode(['ok' => true, 'note' => 'no user'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$orderRow) {
    $pend = $mysqli->query(
        'SELECT id, user_id, status, external_id, amount_uah FROM whitepay_orders
         WHERE user_id=' . (int) $userId . " AND status IN ('pending','expired')
           AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
         ORDER BY id DESC LIMIT 1"
    );
    if ($pend && ($row = $pend->fetch_assoc())) {
        $orderRow = $row;
        $externalId = (string) $row['external_id'];
        $extEsc = $mysqli->real_escape_string($externalId);
    }
}

if ($orderRow && ($orderRow['status'] ?? '') === 'paid') {
    echo json_encode(['ok' => true, 'note' => 'already_paid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$creditUah = whitepay_amount_uah();
if ($orderRow && isset($orderRow['amount_uah']) && (float)$orderRow['amount_uah'] > 0) {
    $creditUah = (float) $orderRow['amount_uah'];
}

if ($orderRow) {
    $update = "UPDATE whitepay_orders SET status='paid', paid_at=NOW(), raw_webhook_json='$rawEsc'";
    if ($invEsc !== '') {
        $update .= ", whitepay_invoice_id=IF(whitepay_invoice_id IS NULL OR whitepay_invoice_id='', '$invEsc', whitepay_invoice_id)";
    }
    $update .= " WHERE external_id='$extEsc' LIMIT 1";
    $mysqli->query($update);
} else {
    $invSql = $invEsc !== '' ? "'$invEsc'" : 'NULL';
    $mysqli->query(
        "INSERT INTO whitepay_orders (user_id, external_id, amount_uah, status, whitepay_invoice_id, raw_webhook_json, paid_at)
         VALUES (" . (int) $userId . ", '$extEsc', " . (float) $creditUah . ", 'paid', $invSql, '$rawEsc', NOW())"
    );
}

$bill = whitepay_extend_subscription($mysqli, $userId, $externalId, $creditUah);

$actor = 0;
$adm = $mysqli->query('SELECT id FROM users WHERE is_admin=1 ORDER BY id ASC LIMIT 1');
if ($adm && ($ar = $adm->fetch_assoc())) {
    $actor = (int) ($ar['id'] ?? 0);
}
if ($actor <= 0) {
    $actor = $userId;
}
admin_audit_log(
    $mysqli,
    $actor,
    'whitepay_webhook_paid',
    $userId,
    [
        'external_id' => $externalId,
        'invoice_key' => $invId,
        'billing_ok'  => (bool) ($bill['ok'] ?? false),
    ],
    !empty($bill['ok']) ? 'ok' : 'billing_error'
);

echo json_encode([
    'ok'      => true,
    'billing' => $bill,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
