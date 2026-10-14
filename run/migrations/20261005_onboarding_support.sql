CREATE TABLE IF NOT EXISTS strim_onboarding_state (
 user_id BIGINT NOT NULL PRIMARY KEY,
 choice VARCHAR(20) NULL,
 barrier VARCHAR(32) NULL,
 first_seen_at DATETIME NOT NULL,
 last_seen_at DATETIME NOT NULL,
 choice_at DATETIME NULL,
 help_requested_at DATETIME NULL,
 api_status VARCHAR(24) NULL,
 api_checked_at DATETIME NULL,
 api_verified_at DATETIME NULL,
 tg_linked_at DATETIME NULL,
 CONSTRAINT strim_ob_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS strim_support_replies (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NULL,
 user_id BIGINT NOT NULL,
 author_user_id BIGINT NULL,
 body TEXT NOT NULL,
 source VARCHAR(16) NOT NULL,
 source_key VARCHAR(96) NOT NULL,
 created_at DATETIME NOT NULL,
 read_at DATETIME NULL,
 notification_channel VARCHAR(16) NULL,
 notification_status VARCHAR(16) NULL,
 UNIQUE KEY reply_source_key (source_key),
 KEY user_reply (user_id,id),
 KEY user_unread (user_id,read_at),
 KEY ticket_reply (ticket_id,id),
 CONSTRAINT strim_reply_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT strim_reply_ticket FOREIGN KEY(ticket_id) REFERENCES support_messages(id) ON DELETE SET NULL,
 CONSTRAINT strim_reply_author FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
