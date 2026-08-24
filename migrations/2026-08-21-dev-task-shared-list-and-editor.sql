ALTER TABLE dev_tasks
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED DEFAULT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS task_notes MEDIUMTEXT NOT NULL AFTER updated_by,
    ADD INDEX IF NOT EXISTS idx_dev_tasks_updated_by (updated_by);

UPDATE dev_tasks t
LEFT JOIN dev_task_messages m ON m.id = (
    SELECT m2.id FROM dev_task_messages m2
    WHERE m2.task_id=t.id
    ORDER BY m2.created_at DESC, m2.id DESC LIMIT 1
)
SET t.updated_by = COALESCE(m.user_id, t.created_by)
WHERE t.updated_by IS NULL;
