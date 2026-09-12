-- Weekly Ride Notice automation: 12 September 2026
-- The scheduler creates the upcoming Monday campaign at runtime; this migration
-- installs its editable system template on environments using migrations.
INSERT INTO email_campaign_templates
    (template_key, name, category, renderer_key, audience_preset, subject_template, html_template, text_template, is_system, is_active)
VALUES
    ('weekly_ride_notice', 'Weekly Ride Notice', 'ride_notice', 'freeform', 'all_users', 'Ride Notice - {{current_date}}', '{{message}}', '{{message}}', 1, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    category = VALUES(category),
    renderer_key = VALUES(renderer_key),
    audience_preset = VALUES(audience_preset),
    is_system = VALUES(is_system);
