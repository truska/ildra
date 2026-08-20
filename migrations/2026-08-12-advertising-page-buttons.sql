-- ILDRA live migration: 12 August 2026
-- Adds Advertising, optional CMS page buttons, current configured content,
-- the Advertising admin-menu item, and the agreed global horse placeholder.
-- Designed for MySQL/MariaDB. Take a database backup before running.

CREATE TABLE IF NOT EXISTS advertising (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    url VARCHAR(1000) DEFAULT NULL,
    link_target VARCHAR(16) NOT NULL DEFAULT '_blank',
    start_date DATE DEFAULT NULL,
    finish_date DATE DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 100,
    show_on_web TINYINT(1) NOT NULL DEFAULT 1,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_advertising_web (show_on_web, archived, start_date, finish_date, display_order)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Rerunnable column additions for existing installations.
DROP PROCEDURE IF EXISTS ildra_add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE ildra_add_column_if_missing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN alter_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @ildra_alter_sql = alter_sql;
        PREPARE ildra_stmt FROM @ildra_alter_sql;
        EXECUTE ildra_stmt;
        DEALLOCATE PREPARE ildra_stmt;
    END IF;
END$$
DELIMITER ;

CALL ildra_add_column_if_missing(
    'advertising',
    'link_target',
    'ALTER TABLE advertising ADD COLUMN link_target VARCHAR(16) NOT NULL DEFAULT ''_blank'' AFTER url'
);

CALL ildra_add_column_if_missing(
    'pages',
    'button_name',
    'ALTER TABLE pages ADD COLUMN button_name VARCHAR(150) DEFAULT NULL AFTER body_html'
);
CALL ildra_add_column_if_missing(
    'pages',
    'button_title',
    'ALTER TABLE pages ADD COLUMN button_title VARCHAR(255) DEFAULT NULL AFTER button_name'
);
CALL ildra_add_column_if_missing(
    'pages',
    'button_url',
    'ALTER TABLE pages ADD COLUMN button_url VARCHAR(1000) DEFAULT NULL AFTER button_title'
);
CALL ildra_add_column_if_missing(
    'pages',
    'button_target',
    'ALTER TABLE pages ADD COLUMN button_target VARCHAR(16) NOT NULL DEFAULT ''_self'' AFTER button_url'
);

DROP PROCEDURE IF EXISTS ildra_add_column_if_missing;

START TRANSACTION;

-- Register Advertising in an existing CMS admin menu without duplicating it.
INSERT INTO admin_menu_items
    (menu_key, label, href, parent_id, display_order, is_active, required_roles, is_system)
SELECT
    'advertising', 'Advertising', 'advertising.php', NULL, 30, 1, 'superadmin,admin', 1
WHERE NOT EXISTS (
    SELECT 1 FROM admin_menu_items WHERE menu_key = 'advertising'
);

-- Current development Advertising records. Match by name so reruns do not duplicate them.
INSERT INTO advertising
    (name, title, image, url, link_target, start_date, finish_date, display_order, show_on_web, archived, created_at, updated_at)
SELECT 'Baileys', 'Baileys Horse Feeds', 'baileys.png', 'https://www.baileyshorsefeeds.co.uk/', '_blank', NULL, NULL, 10, 1, 0, '2026-08-12 15:44:34', '2026-08-12 15:44:34'
WHERE NOT EXISTS (SELECT 1 FROM advertising WHERE name = 'Baileys');

INSERT INTO advertising
    (name, title, image, url, link_target, start_date, finish_date, display_order, show_on_web, archived, created_at, updated_at)
SELECT 'St Patrick''s 2026', 'St. Patrick''s Coast Ride 2026', 'st-patrick-s-2026.png', 'https://stpatrickscoast.com/', '_blank', NULL, '2026-09-06', 100, 1, 0, '2026-08-12 15:55:31', '2026-08-12 15:55:31'
WHERE NOT EXISTS (SELECT 1 FROM advertising WHERE name = 'St Patrick''s 2026');

-- Because name is not a unique database key, explicitly update matching records.
UPDATE advertising
SET title = 'Baileys Horse Feeds',
    image = 'baileys.png',
    url = 'https://www.baileyshorsefeeds.co.uk/',
    link_target = '_blank',
    start_date = NULL,
    finish_date = NULL,
    display_order = 10,
    show_on_web = 1,
    archived = 0
WHERE name = 'Baileys';

UPDATE advertising
SET title = 'St. Patrick''s Coast Ride 2026',
    image = 'st-patrick-s-2026.png',
    url = 'https://stpatrickscoast.com/',
    link_target = '_blank',
    start_date = NULL,
    finish_date = '2026-09-06',
    display_order = 100,
    show_on_web = 1,
    archived = 0
WHERE name = 'St Patrick''s 2026';

-- Current optional button configured on the Rules CMS page.
UPDATE pages
SET button_name = 'Rules',
    button_title = 'ILDRA Rules',
    button_url = 'https://enduranceridingireland.com/filestore/file/ILDRA-Rules-4th-Edition-2015-v2-final.pdf',
    button_target = '_blank'
WHERE slug = 'rules';

-- AGREED DATA CONVERSION: horse ID 1 becomes the global entry-only placeholder.
-- This overwrites the former horse record at ID 1. Remove this UPDATE if live ID 1
-- must not be converted, but the deployed event-entry code currently expects ID 1.
UPDATE horses
SET name = 'Not Registered',
    dob = NULL,
    year_of_birth = NULL,
    breed = NULL,
    colour = NULL,
    qualification_id = NULL,
    passport_issuer = NULL,
    passport_number = NULL,
    sex = NULL,
    height_cm = NULL,
    flu_vac_date = NULL,
    is_archived = 0,
    updated_at = NOW()
WHERE id = 1;

COMMIT;

-- Verification
SELECT id, name, image, link_target, start_date, finish_date, display_order, show_on_web, archived
FROM advertising
ORDER BY display_order, id;

SELECT id, slug, button_name, button_title, button_url, button_target
FROM pages
WHERE button_name IS NOT NULL OR button_url IS NOT NULL
ORDER BY id;

SELECT id, owner_user_id, name, is_archived
FROM horses
WHERE id = 1;
