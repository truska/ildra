CREATE TABLE IF NOT EXISTS auth_throttle (
    scope VARCHAR(32) NOT NULL,
    subject_kind VARCHAR(8) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject_kind, subject_hash),
    INDEX idx_auth_throttle_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
