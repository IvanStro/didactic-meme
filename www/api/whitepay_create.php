<?php
declare(strict_types=1);
/**
 * Створення оплати WhitePay:
 * — офіційний Crypto acquiring: POST {WHITEPAY_API_BASE_URL}/private-api/crypto-orders/{slug}
 *   (@see https://docs.whitepay.com/NIAMpzZRbwAoMUawtJRH/http-api/crypto-acquiring );
 * — або повний WHITEPAY_API_CREATE_URL (legacy);
 * — без токена / без API: редирект на hosted-сторінку.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_whitepay_config.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();
require_once __DIR__ . '/_whitepay_dotenv.php';
strim_whitepay_maybe_load_home_env();

function whitepay_json_error(string $code, int $http = 400, array $extra = []): void
{
    http_response_code($http);
    $out = array_merge(['ok' => false, 'error' => $code], $extra);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Текст помилки з відповіді WhitePay (422 тощо) для діагностики. */
function whitepay_normalize_upstream_error(array $j, string $raw): string
{
    if (!empty($j['title']) && is_string($j['title'])) {
        $t = trim($j['title']);
        if ($t !== '') {
            return $t;
        }
    }

    foreach (['message', 'error', 'detail'] as $k) {
        if (!empty($j[$k]) && is_string($j[$k])) {
            return trim($j[$k]);
        }
    }

    if (!empty($j['error']) && is_array($j['error'])) {
        foreach (['message', 'detail', 'description', 'title'] as $k) {
            if (!empty($j['error'][$k]) && is_string($j['error'][$k])) {
                return trim($j['error'][$k]);
            }
        }
        $nested = whitepay_normalize_upstream_error($j['error'], '');
        if ($nested !== '') {
            return $nested;
        }
    }

    if (!empty($j['errors']) && is_array($j['errors'])) {
        $errs = $j['errors'];
        $errorsIsList = $errs !== [] && array_keys($errs) === range(0, count($errs) - 1);
        if ($errorsIsList) {
            $parts = [];
            foreach ($errs as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                } elseif (is_array($item)) {
                    $m = $item['message'] ?? $item['detail'] ?? '';
                    $f = $item['field'] ?? $item['property'] ?? $item['path'] ?? '';
                    if (is_string($m) && $m !== '') {
                        $parts[] = ($f !== '' ? $f . ': ' : '') . $m;
                    }
                }
            }
            if ($parts !== []) {
                return implode('; ', array_slice($parts, 0, 8));
            }
        }

        $parts = [];
        foreach ($j['errors'] as $field => $msgs) {
            if (is_int($field) || is_numeric($field)) {
                continue;
            }
            if (is_array($msgs)) {
                foreach ($msgs as $m) {
                    if (is_string($m)) {
                        $parts[] = (string) $field . ': ' . $m;
                    }
                }
            } elseif (is_string($msgs)) {
                $parts[] = (string) $field . ': ' . $msgs;
            }
        }
        if ($parts !== []) {
            return implode('; ', array_slice($parts, 0, 6));
        }
    }

    if (isset($j['data']) && is_array($j['data'])) {
        $n = whitepay_normalize_upstream_error($j['data'], '');
        if ($n !== '') {
            return $n;
        }
    }

    $t = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');
    if ($t !== '' && strlen($t) < 600) {
        return substr($t, 0, 400);
    }

    if ($j !== []) {
        $enc = (string) json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($enc !== '' && $enc !== '[]') {
            return substr($enc, 0, 420);
        }
    }

    return '';
}

function whitepay_ensure_orders_table(mysqli $db): void
{
    $db->query(
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
}

function whitepay_http_post_json(string $url, string $token, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return [0, '', 1, 'json_encode'];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$http, (string) $resp, $errno, $err];
}

/** @return array{pay_url:?string, invoice_id:?string} */
function whitepay_parse_create_response(array $j): array
{
    $payUrl = null;
    $invoiceId = null;

    if (isset($j['order']) && is_array($j['order'])) {
        $o = $j['order'];
        if (!empty($o['acquiring_url']) && is_string($o['acquiring_url'])) {
            $payUrl = $o['acquiring_url'];
        }
        if (!empty($o['id'])) {
            $invoiceId = (string) $o['id'];
        }
    }

    foreach (['pay_url', 'payment_url', 'url', 'checkout_url', 'link', 'invoice_url', 'acquiring_url'] as $k) {
        if (!empty($j[$k]) && is_string($j[$k])) {
            $payUrl = $j[$k];
            break;
        }
    }
    if ($payUrl === null && isset($j['data']) && is_array($j['data'])) {
        foreach (['pay_url', 'payment_url', 'url', 'checkout_url', 'link', 'invoice_url'] as $k) {
            if (!empty($j['data'][$k]) && is_string($j['data'][$k])) {
                $payUrl = $j['data'][$k];
                break;
            }
        }
    }

    if ($invoiceId === null) {
        foreach (['id', 'invoice_id', 'order_id', 'payment_id'] as $k) {
            if (!empty($j[$k])) {
                $invoiceId = (string) $j[$k];
                break;
            }
        }
    }
    if ($invoiceId === null && isset($j['data']) && is_array($j['data'])) {
        foreach (['id', 'invoice_id', 'order_id', 'payment_id'] as $k) {
            if (!empty($j['data'][$k])) {
                $invoiceId = (string) $j['data'][$k];
                break;
            }
        }
    }

    return ['pay_url' => $payUrl, 'invoice_id' => $invoiceId];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    whitepay_json_error('method', 405);
}

$mysqli = new mysqli(
    $_ENV['DB_HOST'] ?? '',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? ''
);
if ($mysqli->connect_errno) {
    whitepay_json_error('db_connect', 500);
}
$mysqli->set_charset('utf8mb4');
whitepay_ensure_orders_table($mysqli);

$uid = current_user_id($mysqli);
$guestEmail = '';

$raw = (string) file_get_contents('php://input');
if ($raw !== '') {
    $in = json_decode($raw, true);
    if (is_array($in) && !empty($in['email'])) {
        $guestEmail = trim((string) $in['email']);
    }
}

if ($uid <= 0) {
    if ($guestEmail === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        whitepay_json_error('login_required', 401);
    }
    $emEsc = $mysqli->real_escape_string($guestEmail);
    $q = $mysqli->query("SELECT id FROM users WHERE email='$emEsc' LIMIT 1");
    $row = $q ? $q->fetch_assoc() : null;
    if (!$row) {
        whitepay_json_error('user_not_found', 404);
    }
    $uid = (int) $row['id'];
}

$token = whitepay_access_token();
$slug = whitepay_slug();
$legacyApiUrl = whitepay_api_create_url();
$cryptoApiUrl = ($legacyApiUrl === '') ? whitepay_crypto_order_create_endpoint(whitepay_crypto_api_slug()) : '';

if ($slug === '') {
    whitepay_json_error('whitepay_not_configured', 503);
}

if ($legacyApiUrl !== '' && $token === '') {
    whitepay_json_error('whitepay_not_configured', 503);
}

if ($legacyApiUrl === '' && $token !== '' && $cryptoApiUrl === '') {
    whitepay_json_error('whitepay_not_configured', 503);
}

$uRes = $mysqli->query(
    'SELECT email, ref_code FROM users WHERE id=' . (int) $uid . ' LIMIT 1'
);
$uRow = $uRes ? $uRes->fetch_assoc() : null;
$email = $uRow ? trim((string) ($uRow['email'] ?? '')) : '';
if ($email === '') {
    whitepay_json_error('no_email', 400);
}

$amount = whitepay_amount_uah();
$rawIn = file_get_contents('php://input');
$inBody = is_string($rawIn) && $rawIn !== '' ? json_decode($rawIn, true) : null;
if (is_array($inBody) && isset($inBody['amount_uah'])) {
    $reqAmt = (float) $inBody['amount_uah'];
    if ($reqAmt + 0.001 < 500.0) {
        whitepay_json_error('min_deposit_uah', 400, ['min_uah' => 500]);
    }
    if ($reqAmt > 0 && $reqAmt <= 9999999) {
        $amount = round($reqAmt, 2);
    }
} elseif (isset($_GET['amount_uah']) || isset($_POST['amount_uah'])) {
    $reqAmt = (float) ($_GET['amount_uah'] ?? $_POST['amount_uah'] ?? 0);
    if ($reqAmt + 0.001 < 500.0) {
        whitepay_json_error('min_deposit_uah', 400, ['min_uah' => 500]);
    }
    if ($reqAmt > 0 && $reqAmt <= 9999999) {
        $amount = round($reqAmt, 2);
    }
}
require_once __DIR__.'/_legal.php';
strim_legal_require_json($mysqli,(int)$uid);
$externalId = whitepay_build_external_id($uid);
$externalForApi = whitepay_external_order_id_for_api($externalId);

/** Режим без REST API: публічна сторінка WhitePay. Webhook зіставляє користувача по email. */
if ($legacyApiUrl === '' && $token === '') {
    $hostedBase = whitepay_hosted_pay_url();
    if ($hostedBase === '') {
        whitepay_json_error('hosted_pay_url_missing', 503);
    }
    $q = ['email' => $email];
    foreach (['amount', 'sum', 'value'] as $qk) {
        $q[$qk] = (string) (int) round($amount);
    }
    $payUrl = $hostedBase . (strpos($hostedBase, '?') !== false ? '&' : '?') . http_build_query($q);

    $extEsc = $mysqli->real_escape_string($externalId);
    $urlEsc = $mysqli->real_escape_string($payUrl);
    $mysqli->query(
        "INSERT INTO whitepay_orders (user_id, external_id, amount_uah, currency, status, pay_url, raw_create_json)
         VALUES (" . (int) $uid . ", '$extEsc', " . (float) $amount . ", 'UAH', 'pending', '$urlEsc', '\"hosted\"')"
    );

    echo json_encode([
        'ok'            => true,
        'pay_url'       => $payUrl,
        'external_id'   => $externalId,
        'amount_uah'    => (int) round($amount),
        'mode'          => 'hosted',
        'hosted_notice' => 'На сторінці WhitePay введіть суму ' . (int) round($amount) . ' грн якщо поле не підставилось автоматично.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$postUrl = $legacyApiUrl !== '' ? $legacyApiUrl : $cryptoApiUrl;
if ($postUrl === '') {
    whitepay_json_error('whitepay_not_configured', 503);
}

if ($legacyApiUrl !== '') {
    $body = [
        'payment_page'        => $slug,
        'payment_page_slug'   => $slug,
        'slug'                => $slug,
        'amount'              => (string) (int) round($amount),
        'currency'            => 'UAH',
        'email'               => $email,
        'external_id'         => $externalId,
        'order_id'            => $externalId,
        'success_url'         => whitepay_success_url(),
        'fail_url'            => whitepay_fail_url(),
        'redirect_url'        => whitepay_success_url(),
        'redirect_on_success' => whitepay_success_url(),
        'redirect_on_fail'    => whitepay_fail_url(),
        'description'         => 'StrIM subscription 30 days',
        'comment'             => 'StrIM ref ' . (string) ($uRow['ref_code'] ?? ''),
    ];
    $apiMode = 'legacy_custom_url';
} else {
    $body = [
        'amount'            => whitepay_amount_string_for_api($amount),
        'currency'          => whitepay_invoice_currency(),
        'external_order_id' => $externalForApi,
        'email'             => $email,
        'description'       => 'StrIM subscription 30 days',
    ];
    if (!whitepay_crypto_omit_redirect_links()) {
        $body['successful_link'] = whitepay_success_url();
        $body['failure_link']    = whitepay_fail_url();
    }
    $apiMode = 'crypto_acquiring';
}

[$http, $resp, $errno, $err] = whitepay_http_post_json($postUrl, $token, $body);

if ($errno) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_create.log',
        date('c') . " curl errno=$errno err=$err\n",
        FILE_APPEND
    );
    whitepay_json_error('network', 502);
}

$j = json_decode($resp, true);

if ($http === 422 && $legacyApiUrl === '' && whitepay_crypto_retry_minimal_on_422()) {
    $minimalBody = [
        'amount'            => (string) (int) round($amount),
        'currency'          => whitepay_invoice_currency(),
        'external_order_id' => $externalForApi,
    ];
    [$http2, $resp2, $errno2, $err2] = whitepay_http_post_json($postUrl, $token, $minimalBody);
    if ($errno2 === 0) {
        if ($http2 < 400) {
            @file_put_contents(
                dirname(__DIR__, 2) . '/logs/whitepay_create.log',
                date('c') . " crypto 422 retry minimal OK postUrl=$postUrl\n",
                FILE_APPEND
            );
            $http = $http2;
            $resp = $resp2;
            $body = $minimalBody;
            $j = json_decode($resp, true);
        } else {
            @file_put_contents(
                dirname(__DIR__, 2) . '/logs/whitepay_create.log',
                date('c') . " crypto 422 retry minimal failed http=$http2 resp=" . substr($resp2, 0, 2000) . "\n",
                FILE_APPEND
            );
            $http = $http2;
            $resp = $resp2;
            $body = $minimalBody;
            $j = json_decode($resp2, true);
        }
    }
}

if ($http >= 400) {
    $upstream = '';
    if (is_array($j)) {
        $upstream = whitepay_normalize_upstream_error($j, $resp);
    }
    if ($upstream === '' && trim($resp) !== '') {
        $upstream = whitepay_normalize_upstream_error([], $resp);
    }
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_create.log',
        date('c') . " http=$http postUrl=" . $postUrl . " body=" . substr((string) json_encode($body, JSON_UNESCAPED_UNICODE), 0, 800) . " resp=" . substr($resp, 0, 4000) . "\n",
        FILE_APPEND
    );
    $extra = $upstream !== '' ? ['whitepay_detail' => $upstream] : [];
    whitepay_json_error('whitepay_http_' . $http, 502, $extra);
}

if (!is_array($j)) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_create.log',
        date('c') . " bad json http=$http body=" . substr($resp, 0, 2000) . "\n",
        FILE_APPEND
    );
    whitepay_json_error('bad_response', 502);
}

$parsed = whitepay_parse_create_response($j);
if ($parsed['pay_url'] === null || $parsed['pay_url'] === '') {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/whitepay_create.log',
        date('c') . " no pay_url http=$http body=" . substr($resp, 0, 2000) . "\n",
        FILE_APPEND
    );
    whitepay_json_error('no_pay_url', 502);
}

$extEsc = $mysqli->real_escape_string($externalId);
$invEsc = $mysqli->real_escape_string((string) ($parsed['invoice_id'] ?? ''));
$urlEsc = $mysqli->real_escape_string($parsed['pay_url']);
$rawEsc = $mysqli->real_escape_string(substr($resp, 0, 65000));

$mysqli->query(
    "INSERT INTO whitepay_orders (user_id, external_id, amount_uah, currency, status, whitepay_invoice_id, pay_url, raw_create_json)
     VALUES (" . (int) $uid . ", '$extEsc', " . (float) $amount . ", 'UAH', 'pending', " .
    ($invEsc !== '' ? "'$invEsc'" : 'NULL') . ", '$urlEsc', '$rawEsc')"
);

echo json_encode([
    'ok'          => true,
    'pay_url'     => $parsed['pay_url'],
    'external_id' => $externalId,
    'amount_uah'  => (int) round($amount),
    'mode'        => $apiMode,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
