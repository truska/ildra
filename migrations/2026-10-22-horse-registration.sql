-- Preserve existing horse details while adding the named owner for logbooks.
ALTER TABLE horses ADD COLUMN IF NOT EXISTS owner_name VARCHAR(255) NULL DEFAULT NULL;

-- Use lookup rows so None and Other remain valid mandatory selections.
INSERT IGNORE INTO horse_qualifications (name) VALUES ('None'), ('Other');
