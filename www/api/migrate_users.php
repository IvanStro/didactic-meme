<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
header('Content-Type: text/plain; charset=UTF-8');

require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();

$mysqli = @new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) die("CONNECT ERROR: {$mysqli->connect_errno} {$mysqli->connect_error}\n");
$mysqli->set_charset('utf8mb4');

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  full_name   VARCHAR(255) NOT NULL,
  email       VARCHAR(255) NOT NULL,
  phone       VARCHAR(64)  NOT NULL,
  nickname    VARCHAR(128) NOT NULL,
  exchange    VARCHAR(32)  NOT NULL DEFAULT 'binance',
  api_key_enc     TEXT NOT NULL,
  api_secret_enc  TEXT NOT NULL,
  testnet      TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

if (!$mysqli->multi_query($sql)) { die("MIGRATE ERROR: ".$mysqli->error."\n"); }
do { /* flush */ } while ($mysqli->more_results() && $mysqli->next_result());
echo "MIGRATE USERS OK\n";
