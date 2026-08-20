ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS destination_page_id INT UNSIGNED NULL DEFAULT NULL AFTER nav_group,
    ADD INDEX IF NOT EXISTS idx_pages_destination_page_id (destination_page_id);
