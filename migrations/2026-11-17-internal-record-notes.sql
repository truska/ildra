-- Internal append-only notes survive normal record archiving. No cascading foreign keys.
CREATE TABLE IF NOT EXISTS record_notes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 created_at DATETIME NOT NULL,
 record_type VARCHAR(30) NOT NULL, record_id INT UNSIGNED NOT NULL,
 email VARCHAR(255) NOT NULL, action VARCHAR(80) NOT NULL,
 note TEXT NOT NULL, author_kind VARCHAR(20) NOT NULL,
 actor_user_id INT UNSIGNED NULL, event_key CHAR(32) NOT NULL,
 metadata_json TEXT NULL,
 INDEX idx_record_notes_record (record_type,record_id,id),
 INDEX idx_record_notes_email (email), INDEX idx_record_notes_action (action,created_at),
 INDEX idx_record_notes_event (event_key)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS migration_page_copy (
 id INT UNSIGNED PRIMARY KEY,
 introduction TEXT NOT NULL, closure_explanation TEXT NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
