<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();

$mysqli = @new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$mysqli->connect_error]); exit; }
$mysqli->set_charset('utf8mb4');

$res = $mysqli->query("SELECT COUNT(*) AS c FROM users");
$row = $res ? $res->fetch_assoc() : ['c'=>0];
echo json_encode(['ok'=>true,'count'=>(int)$row['c']]);
