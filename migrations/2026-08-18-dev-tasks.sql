CREATE TABLE IF NOT EXISTS dev_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_by INT UNSIGNED NOT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dev_tasks_status_priority (status, priority, updated_at),
    INDEX idx_dev_tasks_created_by (created_by)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dev_task_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    author_name VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    image_filename VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dev_task_messages_task (task_id, created_at, id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
