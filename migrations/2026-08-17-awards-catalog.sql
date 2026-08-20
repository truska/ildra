-- Native Awards CMS tables and one-time import from the preserved legacy tables.

CREATE TABLE IF NOT EXISTS award_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_award_id INT UNSIGNED DEFAULT NULL UNIQUE,
    name VARCHAR(180) NOT NULL,
    description_html MEDIUMTEXT DEFAULT NULL,
    image_asset_id INT UNSIGNED DEFAULT NULL,
    legacy_image_filename VARCHAR(255) DEFAULT NULL,
    legacy_type VARCHAR(10) DEFAULT NULL,
    legacy_branch INT DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 100,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_awards_display (is_archived, is_published, display_order)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS award_winners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id INT UNSIGNED NOT NULL,
    award_year INT NOT NULL,
    winner_name VARCHAR(255) NOT NULL,
    source_winner_id INT UNSIGNED DEFAULT NULL UNIQUE,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_award_winners (award_id, is_archived, is_published, award_year)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO award_catalog
    (source_award_id, name, description_html, legacy_image_filename, legacy_type, legacy_branch, display_order, is_published, is_archived)
SELECT id, COALESCE(NULLIF(title, ''), name), text, NULLIF(image, ''), type, branch, `order`,
       IF(showonweb = 'Yes', 1, 0), archived
FROM `x-source-awards`;

INSERT IGNORE INTO award_winners
    (award_id, award_year, winner_name, source_winner_id, is_published, is_archived)
SELECT a.id, w.year, w.winner, w.id, IF(w.showonweb = 'Yes', 1, 0), w.archived
FROM `x-source-awardwinners` w
JOIN award_catalog a ON a.source_award_id = w.award;
