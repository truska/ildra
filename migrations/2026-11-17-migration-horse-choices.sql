-- Per-horse onboarding decisions, distinct from paid logbook purchases.
CREATE TABLE IF NOT EXISTS migration_horse_choices (
 user_id INT UNSIGNED NOT NULL, horse_id INT UNSIGNED NOT NULL,
 choice VARCHAR(20) NOT NULL, confirmed_at DATETIME NOT NULL,
 record_hash CHAR(64) NOT NULL,
 logbook_type_id INT UNSIGNED NULL, logbook_year SMALLINT UNSIGNED NULL,
 logbook_price DECIMAL(10,2) NULL, basket_item_id VARCHAR(80) NULL,
 PRIMARY KEY (user_id,horse_id), INDEX idx_migration_choice_horse (horse_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
