-- Venue links and images are retained in every lifecycle state.
ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS archive_state ENUM('active','archived','hidden') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS is_draft TINYINT(1) NOT NULL DEFAULT 0;
