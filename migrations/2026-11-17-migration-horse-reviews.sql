-- Per-account review evidence for owned horses and no purchases or sharing permissions.
CREATE TABLE IF NOT EXISTS migration_horse_reviews (
 user_id INT UNSIGNED NOT NULL, horse_id INT UNSIGNED NOT NULL,
 confirmed_at DATETIME NOT NULL, record_hash CHAR(64) NOT NULL,
 PRIMARY KEY (user_id,horse_id), INDEX idx_migration_review_horse (horse_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
