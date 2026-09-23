ALTER TABLE misc_payment_requests
    ADD COLUMN IF NOT EXISTS event_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS booking_item_id INT UNSIGNED DEFAULT NULL,
    ADD INDEX IF NOT EXISTS idx_misc_payment_event (event_id),
    ADD INDEX IF NOT EXISTS idx_misc_payment_entry (booking_item_id);
