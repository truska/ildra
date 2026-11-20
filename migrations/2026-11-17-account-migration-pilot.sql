-- Additive migration pilot tables. No account is enrolled and no email is sent.
CREATE TABLE IF NOT EXISTS migration_campaigns (
 id INT UNSIGNED PRIMARY KEY,
 subject VARCHAR(255) NOT NULL DEFAULT '', body_html LONGTEXT NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS migration_enrolments (
 user_id INT UNSIGNED PRIMARY KEY, person_id INT UNSIGNED NOT NULL,
 current_step VARCHAR(20) NOT NULL DEFAULT 'password',
 password_setup_required TINYINT(1) NOT NULL DEFAULT 1,
 started_at DATETIME NULL, password_set_at DATETIME NULL,
 user_confirmed_at DATETIME NULL, person_confirmed_at DATETIME NULL,
 user_preferences_json TEXT NULL, person_preferences_json TEXT NULL,
 horses_status VARCHAR(20) NOT NULL DEFAULT 'deferred',
 shares_status VARCHAR(20) NOT NULL DEFAULT 'deferred',
 completed_at DATETIME NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS migration_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL, email VARCHAR(255) NOT NULL,
 credential_stamp CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
 used_at DATETIME NULL, revoked_at DATETIME NULL,
 delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending', sent_at DATETIME NULL,
 campaign_revision INT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_migration_token (token_hash), INDEX idx_migration_user (user_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
