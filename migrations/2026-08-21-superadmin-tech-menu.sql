-- Add the Superadmin-only Tech menu and its initial technical tools.
-- Run after admin_menu_items exists. This only creates/updates menu metadata;
-- it does not create storage folders or change live email settings.

INSERT INTO admin_menu_items
    (menu_key, label, href, icon_class, parent_id, display_order, is_active, required_roles, is_system)
VALUES
    ('tech', 'Tech', 'tech.php', 'fa-solid fa-screwdriver-wrench', NULL, 250, 1, 'superadmin', 1),
    ('tech_email', 'Live Email Settings', 'email.php?view=settings', 'fa-solid fa-server', NULL, 260, 1, 'superadmin', 1),
    ('image_folders', 'Storage Folders', 'image_folders.php', 'fa-solid fa-folder-tree', NULL, 270, 1, 'superadmin', 1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    href = VALUES(href),
    icon_class = VALUES(icon_class),
    display_order = VALUES(display_order),
    is_active = VALUES(is_active),
    required_roles = VALUES(required_roles),
    is_system = VALUES(is_system);

UPDATE admin_menu_items child
JOIN admin_menu_items tech ON tech.menu_key = 'tech'
SET child.parent_id = tech.id
WHERE child.menu_key IN ('tech_email', 'image_folders');

-- Verification output retained in SQL-client logs.
SELECT menu_key, label, href, parent_id, display_order, required_roles, is_system
FROM admin_menu_items
WHERE menu_key IN ('tech', 'tech_email', 'image_folders')
ORDER BY display_order, menu_key;
