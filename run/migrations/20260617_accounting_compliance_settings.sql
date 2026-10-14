-- Налаштування податкової звітності (кабінет бухгалтера)
CREATE TABLE IF NOT EXISTS accounting_compliance_settings (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  settings_json JSON NOT NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
