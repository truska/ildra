-- Dynamic event entry form foundations. The application performs these checks
-- defensively too, so upgrades can be deployed before this migration is run.
ALTER TABLE event_types ADD COLUMN form_profile VARCHAR(32) NOT NULL DEFAULT 'ride';
ALTER TABLE event_types ADD COLUMN default_attendee_limit INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE events ADD COLUMN attendee_limit INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE entry_components ADD COLUMN component_scope VARCHAR(20) NOT NULL DEFAULT 'booking';

-- Suggested initial types (only run these INSERTs where their names do not exist).
-- Dinner uses the attendees profile and can have per-attendee choice components.
INSERT INTO event_types (name, form_profile, default_attendee_limit, quick_view_fields)
SELECT 'Dinner', 'attendees', 0, '[]'
WHERE NOT EXISTS (SELECT 1 FROM event_types existing_type WHERE existing_type.name = 'Dinner');

INSERT INTO event_types (name, form_profile, default_attendee_limit, quick_view_fields)
SELECT 'First Aid', 'attendees', 0, '[]'
WHERE NOT EXISTS (SELECT 1 FROM event_types existing_type WHERE existing_type.name = 'First Aid');

UPDATE event_types SET form_profile = 'attendees' WHERE name = 'Training';
