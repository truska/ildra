ALTER TABLE dev_tasks
    ADD COLUMN IF NOT EXISTS next_action_by INT UNSIGNED DEFAULT NULL AFTER created_by;

ALTER TABLE dev_tasks
    ADD INDEX IF NOT EXISTS idx_dev_tasks_next_action_by (next_action_by);
