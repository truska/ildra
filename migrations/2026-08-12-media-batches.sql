-- Reusable image batches for banners, galleries and content records.
CREATE TABLE IF NOT EXISTS media_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purpose VARCHAR(80) NOT NULL,
    owner_type VARCHAR(80) NOT NULL,
    owner_id INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(150) NOT NULL,
    section VARCHAR(80) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_media_batch_owner (purpose, owner_type, owner_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_batch_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    title VARCHAR(255) DEFAULT NULL,
    alt_text VARCHAR(255) DEFAULT NULL,
    caption TEXT DEFAULT NULL,
    available_sizes VARCHAR(100) DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 100,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_media_batch_images (batch_id, archived, display_order),
    CONSTRAINT fk_media_batch_images_batch FOREIGN KEY (batch_id) REFERENCES media_batches(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO media_batches (purpose, owner_type, owner_id, name, section)
SELECT 'site_header', 'site', 0, 'Site header banners', 'banners'
WHERE NOT EXISTS (
    SELECT 1 FROM media_batches WHERE purpose='site_header' AND owner_type='site' AND owner_id=0
);
