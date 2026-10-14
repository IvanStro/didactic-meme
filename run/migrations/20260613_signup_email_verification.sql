-- Підтвердження email при реєстрації (код на пошту)
CREATE TABLE IF NOT EXISTS signup_email_verifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    used_at DATETIME NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    KEY idx_email_created (email, created_at),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
