<?php
declare(strict_types=1); header('Content-Type: text/plain; charset=UTF-8');
require __DIR__.'/../vendor/autoload.php'; $dotenv=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();
$m=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']); if($m->connect_errno) die("DB ".$m->connect_error."\n"); $m->set_charset('utf8mb4');
$sql=<<<SQL
CREATE TABLE IF NOT EXISTS history(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  strategy_id BIGINT NOT NULL,
  event_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  event_type VARCHAR(32) NOT NULL,
  details TEXT NOT NULL,
  price DECIMAL(18,8) NULL,
  symbol VARCHAR(32) NULL,
  INDEX idx_sid_time (strategy_id,event_time),
  CONSTRAINT fk_hist_strategy FOREIGN KEY (strategy_id) REFERENCES strategies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
if(!$m->multi_query($sql)) die("MIGRATE ERROR: ".$m->error."\n"); do{}while($m->more_results()&&$m->next_result()); echo "MIGRATE HISTORY OK\n";
