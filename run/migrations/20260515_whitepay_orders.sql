-- WhitePay: локальні замовлення + ідемпотентність webhook
CREATE TABLE IF NOT EXISTS whitepay_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  amount_uah DECIMAL(10,2) NOT NULL DEFAULT 500.00,
  currency VARCHAR(8) NOT NULL DEFAULT 'UAH',
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  whitepay_invoice_id VARCHAR(128) DEFAULT NULL,
  pay_url TEXT DEFAULT NULL,
  raw_create_json MEDIUMTEXT DEFAULT NULL,
  raw_webhook_json MEDIUMTEXT DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_external_id (external_id),
  KEY idx_user_status (user_id, status),
  KEY idx_whitepay_invoice (whitepay_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
