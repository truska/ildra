ALTER TABLE news_articles
    ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED DEFAULT NULL AFTER published_at,
    ADD INDEX IF NOT EXISTS idx_news_creator (created_by_user_id);
