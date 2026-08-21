-- Support direct Award image uploads from admin/award_edit.php.
--
-- Before deploying, create and test these upload directories with the same
-- shared-write permissions used by the existing image sections:
--   filestore/images/awards/original
--   filestore/images/awards/lg
--   filestore/images/awards/md
--   filestore/images/awards/sm
--   filestore/images/awards/xs
--
-- Existing files under awards/originals are legacy manual uploads. They are
-- intentionally not moved or associated with awards in this migration.

ALTER TABLE award_catalog
    ADD COLUMN IF NOT EXISTS legacy_image_filename VARCHAR(255) DEFAULT NULL AFTER image_asset_id;

-- Verification output retained in SQL-client logs.
SELECT id, name, image_asset_id, legacy_image_filename, is_published, is_archived
FROM award_catalog
ORDER BY display_order, name;
