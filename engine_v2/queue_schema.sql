CREATE TABLE IF NOT EXISTS job_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_type VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    strategy_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(32) NOT NULL,
    payload_json LONGTEXT NULL,
    status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 
'queued',
    attempts INT NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    worker_id VARCHAR(64) NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE 
CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_available (status, available_at, id),
    KEY idx_user_strategy (user_id, strategy_id),
    KEY idx_symbol_status (symbol, status),
    KEY idx_worker (worker_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
