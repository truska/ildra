ALTER TABLE news_articles
    ADD COLUMN IF NOT EXISTS facebook_gallery_url VARCHAR(1000) DEFAULT NULL AFTER results_html;
