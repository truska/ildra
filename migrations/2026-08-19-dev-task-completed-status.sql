ALTER TABLE dev_tasks
    MODIFY COLUMN status ENUM('open','completed','closed') NOT NULL DEFAULT 'open';
