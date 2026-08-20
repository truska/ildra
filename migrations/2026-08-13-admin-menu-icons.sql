ALTER TABLE admin_menu_items
    ADD COLUMN IF NOT EXISTS icon_class VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-circle' AFTER href;

UPDATE admin_menu_items SET icon_class = 'fa-solid fa-table' WHERE menu_key = 'table_formats' AND icon_class = 'fa-solid fa-circle';
UPDATE admin_menu_items SET icon_class = 'fa-solid fa-screwdriver-wrench' WHERE menu_key = 'admin' AND icon_class = 'fa-solid fa-circle';
