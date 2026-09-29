-- Rich public introduction for non-Ride events.
ALTER TABLE events ADD COLUMN IF NOT EXISTS details_html MEDIUMTEXT NULL DEFAULT NULL AFTER description;
