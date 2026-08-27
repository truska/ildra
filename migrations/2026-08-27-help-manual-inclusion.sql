ALTER TABLE help_articles
    ADD COLUMN IF NOT EXISTS include_in_user_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER is_global,
    ADD COLUMN IF NOT EXISTS include_in_admin_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER include_in_user_manual;

-- Existing help attached to admin page groups belongs in the initial Admin Manual.
UPDATE help_articles a
JOIN help_groups g ON g.id = a.group_id
SET a.include_in_admin_manual = 1
WHERE g.path_patterns LIKE '%/admin/%';
