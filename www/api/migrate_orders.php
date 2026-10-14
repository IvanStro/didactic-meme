<?php
declare(strict_types=1); header('Content-Type: text/plain; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php'; $d=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $d->safeLoad();
$m=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']); if($m->connect_errno) die($m->connect_error);
$m->set_charset('utf8mb4');
$sql=<<<SQL
CREATE TABLE IF NOT EXISTS orders(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  strategy_id BIGINT NOT NULL,
  symbol VARCHAR(32) NOT NULL,
  side ENUM('BUY','SELL') NOT NULL,
  type VARCHAR(16) NOT NULL,
  binance_order_id BIGINT NOT NULL,
  status VARCHAR(16) NOT NULL,
  executed_qty DECIMAL(18,8) DEFAULT 0,
  cumm_quote DECIMAL(18,8) DEFAULT 0,
  price DECIMAL(18,8) DEFAULT 0,
  ts BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sid (strategy_id), INDEX idx_sym (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fills(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT NOT NULL,
  price DECIMAL(18,8) NOT NULL,
  qty DECIMAL(18,8) NOT NULL,
  commission DECIMAL(18,8) DEFAULT 0,
  commission_asset VARCHAR(16) DEFAULT NULL,
  INDEX idx_order (order_id),
  CONSTRAINT fk_fills_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
if(!$m->multi_query($sql)) die("MIGRATE ERROR: ".$m->error."\n"); do{}while($m->more_results()&&$m->next_result()); echo "MIGRATE ORDERS OK\n";
