-- Third-party events reuse the calendar but take entries on an external website.
ALTER TABLE events ADD COLUMN IF NOT EXISTS external_entry_url VARCHAR(2048) NULL DEFAULT NULL;
