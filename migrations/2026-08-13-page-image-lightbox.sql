ALTER TABLE media_batch_images
    ADD COLUMN IF NOT EXISTS lightbox_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER display_order;
