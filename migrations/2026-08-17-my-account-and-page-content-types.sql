-- My Account help content and database-backed page content section types.
-- Safe to run more than once.

ALTER TABLE page_content_elements
    ADD COLUMN IF NOT EXISTS content_type VARCHAR(50) NOT NULL DEFAULT 'rich_text' AFTER body_html;

ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS menu_divider_below TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_footer;

INSERT IGNORE INTO account_intro_modals (view_key, heading, body_html, is_active) VALUES
(
    'my-account',
    'My Account',
    '<p>Use this page to update your website login details, change your password and manage authenticator app access. These details belong to your user account and are separate from people and membership records.</p>',
    1
);
