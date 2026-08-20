CREATE TABLE IF NOT EXISTS page_content_elements (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id INT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 heading VARCHAR(255) DEFAULT NULL,
 anchor_slug VARCHAR(180) DEFAULT NULL,
 body_html MEDIUMTEXT DEFAULT NULL,
 layout ENUM('auto','image_left','image_right','text_only') NOT NULL DEFAULT 'auto',
 display_order INT NOT NULL DEFAULT 100,
 show_on_web TINYINT(1) NOT NULL DEFAULT 1,
 archived TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_page_elements (page_id, archived, show_on_web, display_order)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS ildra_add_page_element_anchor;
DELIMITER $$
CREATE PROCEDURE ildra_add_page_element_anchor()
BEGIN
 IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='page_content_elements' AND COLUMN_NAME='anchor_slug') THEN
  ALTER TABLE page_content_elements ADD COLUMN anchor_slug VARCHAR(180) DEFAULT NULL AFTER heading;
 END IF;
END$$
DELIMITER ;
CALL ildra_add_page_element_anchor();
DROP PROCEDURE IF EXISTS ildra_add_page_element_anchor;

-- Ride Types content is split into records on development. Its content should be
-- migrated with the development database/content deployment, rather than repeated
-- here as a hard-coded snapshot that could overwrite later CMS edits.
