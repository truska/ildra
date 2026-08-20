ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS show_in_footer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published;

UPDATE pages
SET show_in_footer = 1,
    nav_group = 'not-on-menu'
WHERE slug = 'policies';
