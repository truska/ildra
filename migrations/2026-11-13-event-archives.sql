-- Non-destructive event lifecycle; related records are always retained.
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS archive_state ENUM('active','archived','hidden') NOT NULL DEFAULT 'active';
