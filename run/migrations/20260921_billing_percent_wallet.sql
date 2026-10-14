-- Billing percent model: wallet balance + ledger + referral accrual
-- Apply: php run/apply_billing_percent_migration.php

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS balance_usdt DECIMAL(18,8) NOT NULL DEFAULT 0 AFTER paid_until,
  ADD COLUMN IF NOT EXISTS fee_shortfall_usdt DECIMAL(18,8) NOT NULL DEFAULT 0 AFTER balance_usdt,
  ADD COLUMN IF NOT EXISTS wallet_activated_at DATETIME NULL DEFAULT NULL AFTER fee_shortfall_usdt;

-- Some MySQL/MariaDB hosts lack IF NOT EXISTS on ADD COLUMN — apply script handles that.

CREATE TABLE IF NOT EXISTS billing_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_type VARCHAR(32) NOT NULL,
  amount_uah DECIMAL(18,2) NULL DEFAULT NULL,
  fx_rate DECIMAL(18,8) NULL DEFAULT NULL,
  amount_usdt DECIMAL(18,8) NOT NULL,
  balance_after_usdt DECIMAL(18,8) NOT NULL,
  trade_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
  payment_ref VARCHAR(191) NULL DEFAULT NULL,
  referral_accrual_id BIGINT UNSIGNED NULL DEFAULT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bl_user_created (user_id, created_at),
  KEY idx_bl_type (entry_type),
  UNIQUE KEY uq_bl_trade_fee (trade_ledger_id, entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_deposit_accruals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_user_id INT UNSIGNED NOT NULL,
  referred_user_id INT UNSIGNED NOT NULL,
  deposit_ledger_id BIGINT UNSIGNED NOT NULL,
  deposit_usdt DECIMAL(18,8) NOT NULL,
  reward_percent DECIMAL(8,2) NOT NULL DEFAULT 20.00,
  reward_usdt DECIMAL(18,8) NOT NULL,
  status ENUM('pending','settled','cancelled') NOT NULL DEFAULT 'pending',
  settled_at DATETIME NULL DEFAULT NULL,
  settle_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rda_deposit (deposit_ledger_id),
  KEY idx_rda_referrer_status (referrer_user_id, status),
  KEY idx_rda_pending (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
