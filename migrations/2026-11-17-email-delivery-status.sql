-- Independent email observability tables. Existing email_log.status remains the send result.
CREATE TABLE IF NOT EXISTS email_delivery_transport (
    email_log_id INT UNSIGNED NOT NULL PRIMARY KEY,
    provider_message_id VARCHAR(190) DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    smtp_response TEXT DEFAULT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_provider_message (provider_message_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_delivery_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_key CHAR(64) NOT NULL,
    email_log_id INT UNSIGNED DEFAULT NULL,
    provider_message_id VARCHAR(190) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    detail TEXT DEFAULT NULL,
    occurred_at DATETIME NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_event (event_key),
    INDEX idx_delivery_log_time (email_log_id,occurred_at),
    INDEX idx_delivery_event_message (provider_message_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
