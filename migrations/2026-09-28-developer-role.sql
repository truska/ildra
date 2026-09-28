-- Developer is the technical-owner role, above SuperAdmin.
-- Existing accounts are unchanged; assign this role from Admin > Users after
-- applying the migration.
INSERT INTO roles (name, level)
SELECT 'developer', 7
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(name) = 'developer'
);

UPDATE roles SET level = 7 WHERE LOWER(name) = 'developer';

SELECT id, name, level FROM roles ORDER BY level DESC, name;
