-- Retain deleted built-in rows so automatic menu seeding does not recreate them.
ALTER TABLE admin_menu_items
    ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0;

-- Initial Finance visibility; later changes can be managed through Admin Menu.
UPDATE admin_menu_items
SET required_roles = 'developer,superadmin,admin'
WHERE menu_key = 'finance';

UPDATE admin_menu_items
SET required_roles = 'developer,superadmin'
WHERE menu_key = 'menu';
