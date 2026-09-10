ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_tester TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE dev_tasks
    ADD COLUMN IF NOT EXISTS next_action_group VARCHAR(32) DEFAULT NULL AFTER next_action_by,
    ADD INDEX IF NOT EXISTS idx_dev_tasks_next_action_group (next_action_group);
