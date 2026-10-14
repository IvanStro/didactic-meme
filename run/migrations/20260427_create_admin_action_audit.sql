-- Admin action audit log for billing/withdraw operations
CREATE TABLE IF NOT EXISTS `admin_action_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint unsigned NOT NULL,
  `action_type` varchar(64) NOT NULL,
  `target_user_id` bigint unsigned NOT NULL DEFAULT 0,
  `payload_json` json NOT NULL,
  `result_status` varchar(32) NOT NULL DEFAULT 'ok',
  `ip` varchar(64) DEFAULT NULL,
  `ua` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor_created` (`actor_user_id`,`created_at`),
  KEY `idx_target_created` (`target_user_id`,`created_at`),
  KEY `idx_action_created` (`action_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
