ALTER TABLE dev_tasks
    MODIFY COLUMN status ENUM('open','completed','future','closed') NOT NULL DEFAULT 'open';
