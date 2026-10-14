-- V2 фаза 5: черга сигналів (canary exec path)
CREATE TABLE IF NOT EXISTS signal_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  strategy_id INT UNSIGNED NOT NULL,
  symbol VARCHAR(32) NOT NULL,
  signal_name VARCHAR(64) NOT NULL,
  price DECIMAL(24,12) NOT NULL DEFAULT 0,
  payload_json JSON NULL,
  dedupe_key VARCHAR(128) NOT NULL DEFAULT '',
  status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  worker_id VARCHAR(64) NULL,
  locked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_signal_queue_status_avail (status, available_at),
  KEY idx_signal_queue_strategy (strategy_id, status),
  KEY idx_signal_queue_dedupe (dedupe_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
