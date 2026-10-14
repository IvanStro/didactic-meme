-- Profit reinvest toggle: when OFF, accumulate positive realized PnL
-- from that moment and exclude it from bot buy budget.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `profit_reinvest` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=use realized profit for buys; 0=reserve since OFF' AFTER `wallet_activated_at`,
  ADD COLUMN IF NOT EXISTS `profit_reserve_usdt` DECIMAL(18,8) NOT NULL DEFAULT 0
    COMMENT 'Cached reserved positive realized while reinvest OFF' AFTER `profit_reinvest`,
  ADD COLUMN IF NOT EXISTS `profit_reserve_since` DATETIME NULL DEFAULT NULL
    COMMENT 'When reinvest was turned OFF; NULL if ON' AFTER `profit_reserve_usdt`;
