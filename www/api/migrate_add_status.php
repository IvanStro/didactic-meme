<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
header('Content-Type: text/plain; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();
$mysqli = @new mysqli($_ENV['DB_HOST']??getenv('DB_HOST'), $_ENV['DB_USER']??getenv('DB_USER'), $_ENV['DB_PASS']??getenv('DB_PASS'), $_ENV['DB_NAME']??getenv('DB_NAME'));
if($mysqli->connect_errno) die("CONNECT ERROR: {$mysqli->connect_errno} {$mysqli->connect_error}\n");
$mysqli->set_charset('utf8mb4');
$sql = "ALTER TABLE strategies ADD COLUMN status ENUM('paused','running') NOT NULL DEFAULT 'paused'";
if(!$mysqli->query($sql)) { if(strpos($mysqli->error,'Duplicate column')===false) die('ALTER ERROR: '.$mysqli->error."\n"); }
echo "MIGRATE STATUS OK\n";
