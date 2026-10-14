-- Вхідні банківські платежі (Privat Autoclient API) для автоматизації підписки
CREATE TABLE IF NOT EXISTS bank_incoming_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  privat_ref VARCHAR(64) NOT NULL,
  privat_refn VARCHAR(16) NOT NULL DEFAULT '1',
  acc VARCHAR(34) NOT NULL DEFAULT '',
  amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
  ccy VARCHAR(8) NOT NULL DEFAULT 'UAH',
  osnd VARCHAR(512) NOT NULL DEFAULT '',
  trantype CHAR(1) NOT NULL DEFAULT '',
  pr_pr VARCHAR(8) NOT NULL DEFAULT '',
  tran_at DATETIME NULL,
  payer_name VARCHAR(255) NOT NULL DEFAULT '',
  matched_user_id INT UNSIGNED NULL,
  status ENUM('new','matched','applied','ignored','ambiguous') NOT NULL DEFAULT 'new',
  match_note VARCHAR(255) NOT NULL DEFAULT '',
  applied_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bank_ref (privat_ref, privat_refn),
  KEY idx_bank_status (status, created_at),
  KEY idx_bank_user (matched_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
