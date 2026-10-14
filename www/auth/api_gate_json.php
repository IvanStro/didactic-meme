<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_session_user.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

// .env
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

// DB
$host = $_ENV['DB_HOST'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
  echo json_encode(['ok'=>false,'error'=>'db_connect_failed'], JSON_UNESCAPED_UNICODE);
  exit;
}
$mysqli->set_charset('utf8mb4');

// TTL сесії: зробимо людський (24 години), щоб API не “випадав” через 5 хв
$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

// load user
$res = $mysqli->query("SELECT id, telegram, tg_chat_id, tg_enabled FROM users WHERE id=".(int)$uid." LIMIT 1");
if (!$res || !($u = $res->fetch_assoc())) {
  echo json_encode(['ok'=>false,'error'=>'user_not_found'], JSON_UNESCAPED_UNICODE);
  exit;
}

// This is what API endpoints will use
$auth_user = $u;
