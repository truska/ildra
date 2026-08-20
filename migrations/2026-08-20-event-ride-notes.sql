CREATE TABLE IF NOT EXISTS event_ride_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    status ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft',
    intro_html MEDIUMTEXT NULL,
    ride_notes_html MEDIUMTEXT NULL,
    ctr_notes_html MEDIUMTEXT NULL,
    completed_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_ride_notes_event (event_id),
    INDEX idx_event_ride_notes_status (status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
