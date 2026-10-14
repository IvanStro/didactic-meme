<?php

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_session_user.php';
require_once __DIR__.'/../api/_user_keys.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

/**
 * Шифрування (сумісне з PHP 5), у парі з dec() з www/api/_user_keys.php
 */
function enc($plaintext, $key) {
  $iv = openssl_random_pseudo_bytes(16);
  $cipher = openssl_encrypt(
    $plaintext,
    'AES-256-CBC',
    hash('sha256', $key, true),
    OPENSSL_RAW_DATA,
    $iv
  );
  return base64_encode($iv.$cipher);
}

$exchange = 'binance';
if (isset($_POST['exchange'])) {
  $exchange = strtolower(trim((string)$_POST['exchange']));
}

$api_key = '';
if (isset($_POST['api_key'])) {
  $api_key = trim((string)$_POST['api_key']);
}

$api_sec = '';
if (isset($_POST['api_secret'])) {
  $api_sec = trim((string)$_POST['api_secret']);
}

$next = '/dashboard_saas.html';
if (isset($_POST['next'])) {
  $cand = (string)$_POST['next'];
  if (str_starts_with($cand, '/') && !str_starts_with($cand, '//') && !preg_match('~^https?://~i', $cand)) {
    $next = $cand;
  }
}

$mysqli = new mysqli(
  $_ENV['DB_HOST'],
  $_ENV['DB_USER'],
  $_ENV['DB_PASS'],
  $_ENV['DB_NAME']
);
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  header('Location: /auth/login.php?next=/dashboard_saas.html');
  exit;
}

if ($api_key === '' || $api_sec === '') {
  header('Location: /dashboard_saas.html');
  exit;
}

require_once __DIR__ . '/../api/_binance_permissions.php';
$permissionCheck = strim_binance_permissions($api_key,$api_sec);
if (!$permissionCheck['ok']) { header('Location: /auth/connect_exchange.php?msg='.urlencode($permissionCheck['error'])); exit; }
try {
  $masterKey = strim_master_key(true);
} catch (Throwable $e) {
  header('Location: /auth/connect_exchange.php?msg=' . urlencode('MASTER_KEY misconfigured'));
  exit;
}

$api_key_enc    = enc($api_key,  $masterKey);
$api_secret_enc = enc($api_sec,  $masterKey);

$stmt = $mysqli->prepare(
  "UPDATE users
   SET exchange = ?, api_key_enc = ?, api_secret_enc = ?
   WHERE id = ? LIMIT 1"
);

if ($stmt) {
  $stmt->bind_param('sssi', $exchange, $api_key_enc, $api_secret_enc, $uid);
  $stmt->execute();
}

header('Location: '.$next);
exit;
