CREATE TABLE IF NOT EXISTS accounting_privat_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  doc_kind VARCHAR(32) NOT NULL DEFAULT '',
  privat_ref VARCHAR(128) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  period_key VARCHAR(32) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  amount_uah DECIMAL(14,2) NULL,
  response_json MEDIUMTEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_acc_privat_kind (doc_kind, created_at),
  KEY idx_acc_privat_period (period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
