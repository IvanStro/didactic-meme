<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_user_keys.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

function enc(string $plaintext, string $key): string {
  $iv = random_bytes(16);
  $cipher = openssl_encrypt(
    $plaintext,
    'AES-256-CBC',
    hash('sha256', $key, true),
    OPENSSL_RAW_DATA,
    $iv
  );
  return base64_encode($iv . $cipher);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_connect']);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$api = trim((string)($_POST['api_key'] ?? ''));
$sec = trim((string)($_POST['api_secret'] ?? ''));

if ($api === '' || $sec === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'empty_keys']);
  exit;
}

require_once __DIR__ . '/_binance_permissions.php';
$permissionCheck = strim_binance_permissions($api,$sec);
if (!$permissionCheck['ok']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$permissionCheck['error']],JSON_UNESCAPED_UNICODE); exit; }
try {
  $masterKey = strim_master_key(true);
} catch (Throwable $e) {
  http_response_code(503);
  echo json_encode(['ok' => false, 'error' => 'master_key_misconfigured']);
  exit;
}
$apiEnc = enc($api, $masterKey);
$secEnc = enc($sec, $masterKey);

$stmt = $mysqli->prepare("UPDATE users SET api_key_enc=?, api_secret_enc=? WHERE id=?");
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
  exit;
}

$stmt->bind_param('ssi', $apiEnc, $secEnc, $uid);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'update_failed', 'details' => $stmt->error]);
  exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
