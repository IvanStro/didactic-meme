<?php

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_session_user.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$reqUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$parts  = parse_url($reqUri);
$path   = '/';
if (is_array($parts) && isset($parts['path']) && $parts['path'] !== '') {
  $path = $parts['path'];
}

$ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
$sid = isset($_COOKIE['sid']) ? $_COOKIE['sid'] : '';

$now = time();
$TTL = auth_session_ttl_sec();

// Для цих шляхів обов'язково потрібна авторизація
$needAuth = ($path === '/' || $path === '/index.html' || $path === '/dashboard.php');

$mysqli = new mysqli(
  $_ENV['DB_HOST'],
  $_ENV['DB_USER'],
  $_ENV['DB_PASS'],
  $_ENV['DB_NAME']
);
$mysqli->set_charset('utf8mb4');

$ok  = false;
$uid = 0;

if ($sid !== '' && $ip !== '') {
  $tokEsc = $mysqli->real_escape_string($sid);
  $ipEsc  = $mysqli->real_escape_string($ip);

  $sql = "SELECT user_id, UNIX_TIMESTAMP(last_seen) AS ls
          FROM sessions_auth
          WHERE token='$tokEsc' AND ip='$ipEsc'
          LIMIT 1";
  $res = $mysqli->query($sql);

  if ($res && ($row = $res->fetch_assoc())) {
    $uid = isset($row['user_id']) ? (int)$row['user_id'] : 0;
    $ls  = isset($row['ls']) ? (int)$row['ls'] : 0;

    if ($uid > 0 && ($now - $ls) <= $TTL) {
      $ok = true;
      $updateSql = "UPDATE sessions_auth
                    SET last_seen = NOW(), ua = '".$mysqli->real_escape_string($ua)."'
                    WHERE token = '$tokEsc'";
      $mysqli->query($updateSql);
    }
  }
}

// Якщо шлях не вимагає авторизації — пропускаємо далі (для статичних файлів / api)
if (!$needAuth) {
  return false;
}

// Якщо сесія не валідна — на логін
if (!$ok) {
  header('X-Robots-Tag: noindex, nofollow');
  header('Location: /auth/login.php?next='.urlencode($path === '/dashboard.php' || $path === '/dashboard' ? '/dashboard.php' : $path));
  exit;
}

// API-ключі вводяться лише в кабінеті (не блокуємо вхід без ключів).
header('Location: /dashboard_saas.html?v=20261012_reinvest');
exit;
