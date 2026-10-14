-- Повідомлення з форми підтримки (дашборд). Запустіть на проді один раз.
CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `telegram_username` VARCHAR(128) DEFAULT NULL,
  `tg_chat_id` BIGINT DEFAULT NULL,
  `tg_linked` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `client_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
