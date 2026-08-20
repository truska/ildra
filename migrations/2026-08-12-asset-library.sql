-- ILDRA reusable document and image library: 12 August 2026
-- MySQL/MariaDB. Files are deployed separately under filestore.

CREATE TABLE IF NOT EXISTS asset_library (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    asset_type ENUM('pdf','image') NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size BIGINT UNSIGNED DEFAULT NULL,
    width_lg INT UNSIGNED DEFAULT 1200,
    width_md INT UNSIGNED DEFAULT 600,
    width_sm INT UNSIGNED DEFAULT 300,
    width_xs INT UNSIGNED DEFAULT 150,
    available_sizes VARCHAR(100) DEFAULT NULL,
    show_in_selectors TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 100,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_library_selector (show_in_selectors, archived, asset_type, display_order),
    INDEX idx_asset_library_category (category)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- A page button may reference a library item instead of storing a manual URL.
DROP PROCEDURE IF EXISTS ildra_add_asset_button_column;
DELIMITER $$
CREATE PROCEDURE ildra_add_asset_button_column()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'button_asset_id'
    ) THEN
        ALTER TABLE pages ADD COLUMN button_asset_id INT UNSIGNED DEFAULT NULL AFTER button_url;
    END IF;
END$$
DELIMITER ;
CALL ildra_add_asset_button_column();
DROP PROCEDURE IF EXISTS ildra_add_asset_button_column;

INSERT INTO admin_menu_items
    (menu_key, label, href, parent_id, display_order, is_active, required_roles, is_system)
SELECT 'asset_library', 'Document & Image Library', 'asset_library.php', NULL, 40, 1, 'superadmin,admin', 1
WHERE NOT EXISTS (SELECT 1 FROM admin_menu_items WHERE menu_key = 'asset_library');

SELECT id, name, asset_type, category, filename, available_sizes, show_in_selectors, archived
FROM asset_library ORDER BY display_order, name;
