<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_user_keys.php';
require_once __DIR__ . '/_onboarding.php';

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'status' => 'DB_ERROR']);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'status' => 'UNAUTHORIZED']);
  exit;
}

[$apiKey, $apiSecret] = user_keys($mysqli, $uid);

if (!$apiKey || !$apiSecret) {
  strim_ob_record($mysqli, $uid, 'api', 'NO_API');
  echo json_encode(['ok' => true, 'status' => 'NO_API']);
  exit;
}

$ts = (int) floor(microtime(true) * 1000);
$query = 'timestamp=' . $ts;
$signature = hash_hmac('sha256', $query, $apiSecret);

$ch = curl_init('https://api.binance.com/api/v3/account?' . $query . '&signature=' . $signature);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 15,
  CURLOPT_HTTPHEADER => [
    'X-MBX-APIKEY: ' . $apiKey,
  ],
]);

$body = curl_exec($ch);
$err  = curl_error($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
  strim_ob_record($mysqli, $uid, 'api', 'NETWORK_ERROR');
  echo json_encode([
    'ok' => false,
    'status' => 'NETWORK_ERROR',
    'error' => $err,
  ]);
  exit;
}

$data = json_decode((string)$body, true);

if ($http === 200 && is_array($data)) {
  strim_ob_record($mysqli, $uid, 'api', 'CONNECTED');
  echo json_encode([
    'ok' => true,
    'status' => 'CONNECTED',
  ]);
  exit;
}

$msg = is_array($data) ? ($data['msg'] ?? 'unknown') : 'non_json_response';

if ($http === 401 || $http === 400) {
  strim_ob_record($mysqli, $uid, 'api', 'INVALID');
  echo json_encode([
    'ok' => true,
    'status' => 'INVALID',
    'error' => $msg,
  ]);
  exit;
}

strim_ob_record($mysqli, $uid, 'api', 'API_ERROR');
echo json_encode([
  'ok' => false,
  'status' => 'API_ERROR',
  'http' => $http,
  'error' => $msg,
]);
