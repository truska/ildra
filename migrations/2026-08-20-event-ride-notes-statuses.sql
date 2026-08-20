ALTER TABLE event_ride_notes
    MODIFY COLUMN status ENUM('draft','complete','published','hidden') NOT NULL DEFAULT 'draft';

UPDATE event_ride_notes SET status = 'published' WHERE status = 'complete';

ALTER TABLE event_ride_notes
    MODIFY COLUMN status ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft';
