-- Restore the agreed global entry-only horse after a testing data reset.
-- Owner 0 denotes a system option; never overwrite an existing horse at ID 1.
INSERT INTO horses (id, owner_user_id, name, is_archived)
SELECT 1, 0, 'Not Registered', 0
WHERE NOT EXISTS (SELECT 1 FROM horses WHERE id = 1);
