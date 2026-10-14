<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
header('Content-Type: text/plain; charset=UTF-8');

require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2));
$dotenv->safeLoad();

$mysqli = @new mysqli(
  $_ENV['DB_HOST'] ?? getenv('DB_HOST'),
  $_ENV['DB_USER'] ?? getenv('DB_USER'),
  $_ENV['DB_PASS'] ?? getenv('DB_PASS'),
  $_ENV['DB_NAME'] ?? getenv('DB_NAME')
);
if ($mysqli->connect_errno) { die("CONNECT ERROR: {$mysqli->connect_errno} {$mysqli->connect_error}\n"); }
$mysqli->set_charset('utf8mb4');

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS strategies (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL DEFAULT 1,
  symbol VARCHAR(32) DEFAULT 'BTCUSDT',
  mode ENUM('whole','subbin') DEFAULT 'subbin',
  fba DECIMAL(18,8) DEFAULT 50,
  tp_whole DECIMAL(10,6) NULL,
  cb_whole DECIMAL(10,6) NULL,
  global_cb DECIMAL(10,6) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS martingale_levels (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  strategy_id BIGINT NOT NULL,
  idx INT NOT NULL,
  drop_pct DECIMAL(10,6) NULL,
  mult DECIMAL(18,8) NULL,
  tp_sub DECIMAL(10,6) NULL,
  cb_sub DECIMAL(10,6) NULL,
  UNIQUE KEY uniq_level (strategy_id, idx),
  CONSTRAINT fk_levels_strategy FOREIGN KEY (strategy_id)
    REFERENCES strategies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

if (!$mysqli->multi_query($sql)) { die("MIGRATE ERROR: ".$mysqli->error."\n"); }
do {;} while ($mysqli->more_results() && $mysqli->next_result());
echo "MIGRATE OK\n";
