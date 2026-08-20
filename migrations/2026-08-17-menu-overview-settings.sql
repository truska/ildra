-- Optional Overview link shown above each top-level dropdown menu.
-- The application defaults to shown until an administrator saves these settings.

INSERT IGNORE INTO site_settings (setting_key, setting_value, updated_at) VALUES
    ('menu_overview_about-ildra', '1', NOW()),
    ('menu_overview_about-endurance', '1', NOW()),
    ('menu_overview_events', '1', NOW()),
    ('menu_overview_news', '1', NOW()),
    ('menu_overview_contact', '1', NOW());
