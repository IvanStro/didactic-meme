<?php
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__,2))->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], 
$_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$telegramId = (int)($_POST['telegram_id'] ?? 0);
$token = $_POST['token'] ?? '';
$username = $_POST['username'] ?? '';

if(!$telegramId || !$token){
  echo json_encode(['ok'=>false]);
  exit;
}

// тут ми знайдемо user_id по token
$res = $mysqli->query("SELECT id FROM users WHERE 
tg_token='".$mysqli->real_escape_string($token)."' LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;

if(!$row){
  echo json_encode(['ok'=>false,'error'=>'token_not_found']);
  exit;
}

$userId = (int)$row['id'];

$mysqli->query("
  INSERT INTO user_telegram (user_id, telegram_id, username)
  VALUES ($userId, $telegramId, 
'".$mysqli->real_escape_string($username)."')
  ON DUPLICATE KEY UPDATE
    telegram_id=VALUES(telegram_id),
    username=VALUES(username)
");

echo json_encode(['ok'=>true]);
