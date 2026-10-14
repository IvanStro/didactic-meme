<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__,2))->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], 
$_ENV['DB_PASS'], $_ENV['DB_NAME']);

$uid = current_user_id($mysqli);

$token = bin2hex(random_bytes(6));

$mysqli->query("
  UPDATE users SET tg_token='".$mysqli->real_escape_string($token)."'
  WHERE id=$uid
");

echo json_encode(['ok'=>true,'token'=>$token]);
