-- Add the Manager role below Admin and above Organiser.
-- Manager initially has the same CMS access as Admin. Sensitive capabilities
-- can be separated into Admin-only permissions in a later migration/release.

INSERT INTO roles (name, level)
SELECT 'manager', 4
WHERE NOT EXISTS (
    SELECT 1
    FROM roles
    WHERE LOWER(name) = 'manager'
);

UPDATE roles
SET level = 4
WHERE LOWER(name) = 'manager';

-- Include Manager anywhere the configurable admin menu currently grants the
-- standard Superadmin/Admin/Organiser set.
UPDATE admin_menu_items
SET required_roles = CASE required_roles
    WHEN 'superadmin,admin' THEN 'superadmin,admin,manager'
    WHEN 'superadmin,admin,organiser' THEN 'superadmin,admin,manager,organiser'
    ELSE required_roles
END
WHERE required_roles IN ('superadmin,admin', 'superadmin,admin,organiser');

-- Verification output retained in SQL-client logs.
SELECT id, name, level
FROM roles
ORDER BY level DESC, name;
