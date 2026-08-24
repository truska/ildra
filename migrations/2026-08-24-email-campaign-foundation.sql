-- Email campaign foundation: templates, scheduled campaigns, recipient snapshots,
-- consent preferences, batching and open tracking.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS general_email_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ride_notice_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS renewal_reminder_opt_in TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE people
    ADD COLUMN IF NOT EXISTS general_email_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ride_notice_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS renewal_reminder_opt_in TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS email_campaign_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    category ENUM('operational','general','ride_notice','renewal_reminder') NOT NULL DEFAULT 'general',
    renderer_key VARCHAR(80) NOT NULL DEFAULT 'freeform',
    audience_preset VARCHAR(80) NOT NULL DEFAULT 'all_users',
    intro_html LONGTEXT NULL,
    outro_html LONGTEXT NULL,
    subject_template VARCHAR(255) NOT NULL,
    html_template LONGTEXT NOT NULL,
    text_template LONGTEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    campaign_type VARCHAR(80) NOT NULL DEFAULT 'announcement',
    category ENUM('operational','general','ride_notice','renewal_reminder') NOT NULL DEFAULT 'general',
    template_id INT UNSIGNED NULL,
    audience_preset VARCHAR(80) NOT NULL DEFAULT 'all_users',
    event_id INT UNSIGNED NULL,
    membership_year SMALLINT UNSIGNED NULL,
    renderer_key VARCHAR(80) NOT NULL DEFAULT 'freeform',
    intro_html LONGTEXT NULL,
    outro_html LONGTEXT NULL,
    address_strategy ENUM('person_first','account_only','person_only') NOT NULL DEFAULT 'person_first',
    subject_template VARCHAR(255) NOT NULL,
    html_template LONGTEXT NOT NULL,
    text_template LONGTEXT NULL,
    status ENUM('draft','scheduled','sending','sent','paused','cancelled','failed') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL COMMENT 'Europe/London local time',
    batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    live_send_approved TINYINT(1) NOT NULL DEFAULT 0,
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    opened_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED NULL,
    approved_by_user_id INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_campaign_due (status, scheduled_at),
    INDEX idx_campaign_event (event_id),
    INDEX idx_campaign_template (template_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    person_id INT UNSIGNED NULL,
    email VARCHAR(255) NOT NULL,
    email_normalized VARCHAR(255) NOT NULL,
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    merge_json LONGTEXT NULL,
    tracking_token CHAR(40) NOT NULL,
    unsubscribe_token CHAR(40) NOT NULL,
    status ENUM('pending','sending','sent','failed','skipped','unsubscribed') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    email_log_id INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    first_opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_email (campaign_id, email_normalized),
    UNIQUE KEY uniq_campaign_tracking (tracking_token),
    UNIQUE KEY uniq_campaign_unsubscribe (unsubscribe_token),
    INDEX idx_campaign_recipient_status (campaign_id, status),
    INDEX idx_campaign_recipient_user (user_id),
    INDEX idx_campaign_recipient_person (person_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_limited_tests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NULL,
    email VARCHAR(255) NOT NULL,
    category ENUM('operational','general','ride_notice','renewal_reminder') NOT NULL,
    merge_json LONGTEXT NOT NULL,
    tracking_token CHAR(40) NOT NULL,
    unsubscribe_token CHAR(40) NOT NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
    email_log_id INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    first_opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_limited_tracking (tracking_token),
    UNIQUE KEY uniq_limited_unsubscribe (unsubscribe_token),
    INDEX idx_limited_campaign (campaign_id),
    INDEX idx_limited_user (user_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE email_campaign_templates
    MODIFY category ENUM('operational','general','ride_notice','renewal_reminder') NOT NULL DEFAULT 'general';
ALTER TABLE email_campaigns
    MODIFY category ENUM('operational','general','ride_notice','renewal_reminder') NOT NULL DEFAULT 'general';
ALTER TABLE email_campaign_limited_tests
    MODIFY category ENUM('operational','general','ride_notice','renewal_reminder') NOT NULL DEFAULT 'general';

ALTER TABLE email_campaign_templates
    ADD COLUMN IF NOT EXISTS renderer_key VARCHAR(80) NOT NULL DEFAULT 'freeform',
    ADD COLUMN IF NOT EXISTS audience_preset VARCHAR(80) NOT NULL DEFAULT 'all_users',
    ADD COLUMN IF NOT EXISTS intro_html LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS outro_html LONGTEXT NULL;
ALTER TABLE email_campaigns
    ADD COLUMN IF NOT EXISTS renderer_key VARCHAR(80) NOT NULL DEFAULT 'freeform',
    ADD COLUMN IF NOT EXISTS intro_html LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS outro_html LONGTEXT NULL;

INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES
    ('campaign_live_sending_enabled', '0', NOW()),
    ('campaign_default_batch_size', '25', NOW()),
    ('campaign_public_base_url', '', NOW())
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO email_campaign_templates
    (template_key, name, category, subject_template, html_template, text_template, is_system, is_active)
VALUES
    ('ride_notes', 'Ride Notes', 'operational', 'Ride Notes: {{event_title}}', '<h2>Ride Notes</h2><p>Hello {{first_name}},</p><p>Important information for <strong>{{event_title}}</strong> on {{event_date}} is now available.</p><p><a href="{{ride_notes_url}}">View Ride Notes</a></p>', 'Ride Notes\n\nHello {{first_name}},\n\nImportant information for {{event_title}} on {{event_date}} is now available.\n\nView Ride Notes: {{ride_notes_url}}', 1, 1),
    ('entries_open_members', 'Ride Entries Open - Members', 'general', 'Member entries open: {{event_title}}', '<h2>Member entries are open</h2><p>Hello {{first_name}},</p><p>Member entries are now open for <strong>{{event_title}}</strong> on {{event_date}}.</p><p><a href="{{event_url}}">View ride and enter</a></p>', 'Hello {{first_name}},\n\nMember entries are now open for {{event_title}} on {{event_date}}.\n\n{{event_url}}', 1, 1),
    ('entries_open_non_members', 'Ride Entries Open - Non-Members', 'general', 'Entries open: {{event_title}}', '<h2>Entries are open</h2><p>Hello {{first_name}},</p><p>Entries are now open for <strong>{{event_title}}</strong> on {{event_date}}.</p><p><a href="{{event_url}}">View ride and enter</a></p>', 'Hello {{first_name}},\n\nEntries are now open for {{event_title}} on {{event_date}}.\n\n{{event_url}}', 1, 1),
    ('entries_closing', 'Ride Entries Closing', 'general', 'Entries closing soon: {{event_title}}', '<h2>Entries close soon</h2><p>Hello {{first_name}},</p><p>Entries for <strong>{{event_title}}</strong> close on {{entry_close_date}}.</p><p><a href="{{event_url}}">View ride and enter</a></p>', 'Hello {{first_name}},\n\nEntries for {{event_title}} close on {{entry_close_date}}.\n\n{{event_url}}', 1, 1),
    ('membership_renewal', 'Annual Membership Renewal', 'renewal_reminder', 'Membership renewal for {{membership_year}}', '<h2>Membership renewal</h2><p>Hello {{first_name}},</p><p>Membership renewal for {{membership_year}} is now available.</p><p><a href="{{membership_url}}">Renew membership</a></p>', 'Hello {{first_name}},\n\nMembership renewal for {{membership_year}} is now available.\n\n{{membership_url}}', 1, 1),
    ('logbook_renewal', 'Horse Logbook Renewal', 'renewal_reminder', 'Horse logbook registration for {{membership_year}}', '<h2>Horse logbook registration</h2><p>Hello {{first_name}},</p><p>Horse logbook registration for {{membership_year}} is now available.</p><p><a href="{{logbook_url}}">Register a horse logbook</a></p>', 'Hello {{first_name}},\n\nHorse logbook registration for {{membership_year}} is now available.\n\n{{logbook_url}}', 1, 1),
    ('ride_notice', 'Weekly Ride Notice', 'ride_notice', 'Ride Notice - {{current_date}}', '<h2>Ride Notice</h2><p>Hello {{first_name}},</p><p>{{message}}</p>', 'Hello {{first_name}},\n\n{{message}}', 1, 1),
    ('announcement', 'General Announcement', 'general', '{{campaign_name}}', '<p>Hello {{first_name}},</p><p>{{message}}</p>', 'Hello {{first_name}},\n\n{{message}}', 1, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), category = VALUES(category), is_active = VALUES(is_active);

UPDATE email_campaign_templates
SET renderer_key='membership_renewal', audience_preset='expired_members',
    intro_html=COALESCE(NULLIF(intro_html,''),'<p>Your membership is ready to renew.</p>'),
    outro_html=COALESCE(outro_html,'')
WHERE template_key='membership_renewal';
