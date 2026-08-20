ALTER TABLE event_ride_notes
    ADD COLUMN IF NOT EXISTS pdf_filename VARCHAR(255) NULL AFTER ctr_notes_html,
    ADD COLUMN IF NOT EXISTS pdf_original_filename VARCHAR(255) NULL AFTER pdf_filename;
