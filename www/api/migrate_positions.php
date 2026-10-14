<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
header('Content-Type: text/plain; charset=UTF-8');

require __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();

$mysqli = @new mysqli(
  $_ENV['DB_HOST'] ?? getenv('DB_HOST'),
  $_ENV['DB_USER'] ?? getenv('DB_USER'),
  $_ENV['DB_PASS'] ?? getenv('DB_PASS'),
  $_ENV['DB_NAME'] ?? getenv('DB_NAME')
);
if ($mysqli->connect_errno) die("CONNECT ERROR: {$mysqli->connect_errno} {$mysqli->connect_error}\n");
$mysqli->set_charset('utf8mb4');

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS positions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  strategy_id BIGINT NOT NULL,
  qty DECIMAL(18,8) NOT NULL DEFAULT 0,
  invested_usdt DECIMAL(18,8) NOT NULL DEFAULT 0,
  avg_price DECIMAL(18,8) NOT NULL DEFAULT 0,
  last_buy DECIMAL(18,8) NOT NULL DEFAULT 0,
  next_ptr INT NOT NULL DEFAULT 0,
  last_peak DECIMAL(18,8) NOT NULL DEFAULT 0,
  state ENUM('empty','open') NOT NULL DEFAULT 'empty',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_strategy (strategy_id),
  CONSTRAINT fk_pos_strategy FOREIGN KEY (strategy_id)
    REFERENCES strategies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sub_positions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  position_id BIGINT NOT NULL,
  level_idx INT NOT NULL,
  qty DECIMAL(18,8) NOT NULL,
  buy_price DECIMAL(18,8) NOT NULL,
  tp DECIMAL(10,6) NULL,
  cb DECIMAL(10,6) NULL,
  closed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pos (position_id),
  CONSTRAINT fk_subpos_pos FOREIGN KEY (position_id)
    REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

if (!$mysqli->multi_query($sql)) { die("MIGRATE ERROR: ".$mysqli->error."\n"); }
do { /* flush results */ } while ($mysqli->more_results() && $mysqli->next_result());
echo "MIGRATE POSITIONS OK\n";
