CREATE TABLE IF NOT EXISTS news_articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_type VARCHAR(30) NOT NULL DEFAULT 'news',
    event_id INT UNSIGNED NULL DEFAULT NULL,
    headline VARCHAR(255) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    subheading TEXT DEFAULT NULL,
    body_html MEDIUMTEXT DEFAULT NULL,
    results_html MEDIUMTEXT DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_news_published (is_published, published_at),
    INDEX idx_news_event (event_id, article_type),
    UNIQUE KEY uniq_ride_report_event (event_id, article_type)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE news_articles
    ADD COLUMN IF NOT EXISTS article_type VARCHAR(30) NOT NULL DEFAULT 'news' AFTER id,
    ADD COLUMN IF NOT EXISTS event_id INT UNSIGNED NULL DEFAULT NULL AFTER article_type,
    ADD COLUMN IF NOT EXISTS results_html MEDIUMTEXT DEFAULT NULL AFTER body_html,
    ADD INDEX IF NOT EXISTS idx_news_event (event_id, article_type),
    ADD UNIQUE INDEX IF NOT EXISTS uniq_ride_report_event (event_id, article_type);

CREATE TABLE IF NOT EXISTS news_content_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id INT UNSIGNED NOT NULL,
    subheading VARCHAR(255) DEFAULT NULL,
    body_html MEDIUMTEXT DEFAULT NULL,
    layout ENUM('auto','image_left','image_right','text_only') NOT NULL DEFAULT 'auto',
    display_order INT NOT NULL DEFAULT 100,
    show_on_web TINYINT(1) NOT NULL DEFAULT 1,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_news_sections (news_id, archived, show_on_web, display_order)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The News menu/list remains an ordinary page, so its introduction, menu group
-- and menu position continue to be managed with all other pages.
INSERT INTO page_content_elements
    (page_id, name, heading, anchor_slug, body_html, content_type, layout, display_order, show_on_web, archived)
SELECT p.id, 'News list', NULL, 'news-list', NULL, 'news_list', 'text_only', 100, 1, 0
FROM pages p
WHERE p.slug = 'news-updates'
  AND NOT EXISTS (
      SELECT 1 FROM page_content_elements e
      WHERE e.page_id = p.id AND e.content_type = 'news_list'
  );
