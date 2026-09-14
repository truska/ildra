-- Optional deployment migration. The application also creates and seeds this table safely on first use.
CREATE TABLE IF NOT EXISTS admin_action_restrictions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_key VARCHAR(100) NOT NULL UNIQUE,
    page_key VARCHAR(64) NOT NULL,
    label VARCHAR(150) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_restricted TINYINT(1) NOT NULL DEFAULT 0,
    allowed_roles VARCHAR(100) NOT NULL DEFAULT '',
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_action_page_order (page_key, display_order)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_action_restrictions
    (action_key, page_key, label, description, is_restricted, allowed_roles, display_order)
VALUES
    ('finance.create_payout', 'finance', 'Create Stripe payout', 'Move an event balance from Stripe to the nominated account.', 1, 'admin', 10),
    ('finance.adjust_balance', 'finance', 'Adjust account balance', 'Manually credit or debit a user account balance.', 0, '', 20),
    ('finance.create_misc_payment', 'finance', 'Send miscellaneous payment request', 'Email a recipient a one-off Stripe payment request.', 1, 'admin', 25),
    ('memberships.change_logbook_rate', 'memberships', 'Change horse logbook rate', 'Change the annual horse logbook price or status.', 1, 'admin', 30),
    ('people.allocate_membership', 'people', 'Allocate membership', 'Grant an administrator-allocated membership.', 1, 'admin', 50),
    ('events.delete', 'events', 'Delete event', 'Permanently delete an event and its associated data.', 0, '', 80);
