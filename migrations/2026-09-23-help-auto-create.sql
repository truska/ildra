ALTER TABLE help_articles
    ADD COLUMN IF NOT EXISTS to_be_created TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published,
    ADD INDEX IF NOT EXISTS idx_help_to_be_created (to_be_created);
