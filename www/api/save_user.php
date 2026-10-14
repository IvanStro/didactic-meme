<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../auth/_session_user.php';
require_once __DIR__.'/_user_keys.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2));
$dotenv->safeLoad();

function enc(string $plaintext, string $key): string {
  $iv = random_bytes(16);
  $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256',$key,true), OPENSSL_RAW_DATA, $iv);
  return base64_encode($iv.$cipher);
}

// SAVE_USER_SESSION_UPDATE_V1
// This endpoint updates ONLY the current authenticated user (no INSERT).
// API keys are updated ONLY if explicitly provided.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Use POST with JSON body']); exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
if(!is_array($data)){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if($mysqli->connect_errno){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$mysqli->connect_error]); exit;
}
$mysqli->set_charset('utf8mb4');

// Auth required
$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit;
}

// Allowed fields (profile)
$full_name = trim((string)($data['full_name'] ?? ''));
$phone     = trim((string)($data['phone'] ?? ''));
$nickname  = trim((string)($data['nickname'] ?? ''));
$exchange  = strtolower(trim((string)($data['exchange'] ?? 'binance')));
$testnet   = !empty($data['testnet']) ? 1 : 0;

// Optional keys (only update if provided non-empty)
$api_key    = trim((string)($data['api_key'] ?? ''));
$api_secret = trim((string)($data['api_secret'] ?? ''));

// Basic validation (do not require ALL fields)
if ($nickname === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'nickname required']); exit;
}
if ($exchange === '') $exchange = 'binance';

// Ensure nickname is unique across other users (email uniqueness is handled elsewhere)
$nickEsc = $mysqli->real_escape_string($nickname);
$chk = $mysqli->query("SELECT id FROM users WHERE nickname='$nickEsc' AND id<>$uid LIMIT 1");
if ($chk && $chk->fetch_assoc()) {
  http_response_code(409);
  echo json_encode(['ok'=>false,'error'=>'nickname already used']); exit;
}

// Update base profile fields
$stmt = $mysqli->prepare("UPDATE users SET full_name=?, phone=?, nickname=?, exchange=?, testnet=? WHERE id=?");
$stmt->bind_param('ssssii', $full_name, $phone, $nickname, $exchange, $testnet, $uid);
if(!$stmt->execute()){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB update: '.$stmt->error]); exit;
}

// Update keys only if explicitly provided
if ($api_key !== '' || $api_secret !== '') {
  if ($api_key === '' || $api_secret === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Both api_key and api_secret required to update keys']); exit;
  }
  try {
    $k = strim_master_key(true);
  } catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'master_key_misconfigured']); exit;
  }
  $api_key_enc    = enc($api_key, $k);
  $api_secret_enc = enc($api_secret, $k);

  $stmt2 = $mysqli->prepare("UPDATE users SET api_key_enc=?, api_secret_enc=? WHERE id=?");
  $stmt2->bind_param('ssi', $api_key_enc, $api_secret_enc, $uid);
  if(!$stmt2->execute()){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB key update: '.$stmt2->error]); exit;
  }
}

echo json_encode(['ok'=>true,'user_id'=>$uid], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
