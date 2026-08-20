ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS internal_notes_html LONGTEXT NULL AFTER notes;
