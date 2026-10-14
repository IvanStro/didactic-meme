CREATE TABLE IF NOT EXISTS legal_acceptances (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 document_version VARCHAR(40) NOT NULL,
 documents_json LONGTEXT NOT NULL,
 context VARCHAR(32) NOT NULL,
 accepted_at DATETIME NOT NULL,
 KEY user_version (user_id, document_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consumer_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_ref VARCHAR(32) NOT NULL UNIQUE,
 user_id INT NULL,
 full_name VARCHAR(180) NOT NULL,
 email VARCHAR(254) NOT NULL,
 category VARCHAR(32) NOT NULL,
 body TEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'new',
 resolution TEXT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY state_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
