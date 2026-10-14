<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api/_email_verify.php';

$email = trim((string) ($_POST['email'] ?? $_GET['email'] ?? ''));
if ($email === '') {
    echo json_encode(['ok' => false, 'error' => 'invalid_email', 'message' => email_verify_error_message('invalid_email')], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$res = email_verify_send_code($mysqli, $email);
$err = (string) ($res['error'] ?? '');
$payload = [
    'ok' => !empty($res['ok']),
    'error' => $err !== '' ? $err : null,
    'message' => !empty($res['ok'])
        ? 'Код надіслано на ' . email_verify_normalize_email($email) . '. Перевірте пошту (і папку «Спам»).'
        : email_verify_error_message($err !== '' ? $err : 'unknown'),
];
if (isset($res['retry_sec'])) {
    $payload['retry_sec'] = (int) $res['retry_sec'];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
