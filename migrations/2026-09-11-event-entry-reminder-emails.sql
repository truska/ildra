-- Automated event-entry reminders: 11 September 2026
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS send_reminder_emails TINYINT(1) NOT NULL DEFAULT 0 AFTER entry_close_at;

UPDATE email_campaign_templates
SET category = 'ride_notice',
    audience_preset = CASE template_key
        WHEN 'entries_open_members' THEN 'all_members'
        WHEN 'entries_open_non_members' THEN 'non_members'
        ELSE 'all_users'
    END
WHERE template_key IN ('entries_open_members', 'entries_open_non_members', 'entries_closing');
