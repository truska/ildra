-- Pending balances and verified email claims; no account balances are changed by this migration.
CREATE TABLE IF NOT EXISTS legacy_balance_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_system VARCHAR(64) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    imported_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY legacy_import_file (source_system,file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id INT UNSIGNED NOT NULL,
    source_system VARCHAR(64) NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL,
    source_name VARCHAR(255) NULL,
    source_balance DECIMAL(12,2) NOT NULL,
    opening_amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'gbp',
    allocated_user_id INT UNSIGNED NULL,
    finance_transaction_id INT UNSIGNED NULL,
    allocated_by INT UNSIGNED NULL,
    allocation_method VARCHAR(16) NULL,
    allocated_at DATETIME NULL,
    notification_seen_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY legacy_source_record (source_system,source_id),
    UNIQUE KEY legacy_finance_transaction (finance_transaction_id),
    INDEX legacy_pending_email (email,allocated_user_id),
    INDEX legacy_allocated_user (allocated_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_balance_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NULL,
    expires_at DATETIME NULL,
    sent_at DATETIME NULL,
    verified_at DATETIME NULL,
    UNIQUE KEY legacy_verified_user_email (user_id,email),
    UNIQUE KEY legacy_verification_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
