-- Company profile settings, footer social links, partner logo links and
-- CMS-managed account introduction modals.
-- Safe to run more than once.

INSERT IGNORE INTO site_settings (setting_key, setting_value, updated_at) VALUES
    ('company_name', 'Irish Long Distance Riding Association Ltd.', NOW()),
    ('company_short_name', 'ILDRA', NOW()),
    ('company_contact_email', '', NOW()),
    ('company_webmaster_email', 'webmaster@enduranceridingirland.com', NOW()),
    ('company_facebook_url', 'https://www.facebook.com/EnduranceRidingIreland', NOW()),
    ('company_website_url', 'https://enduranceridingireland.com', NOW()),
    ('company_address', '', NOW()),
    ('company_postcode', '', NOW());

CREATE TABLE IF NOT EXISTS company_socials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    platform VARCHAR(50) NOT NULL DEFAULT 'website',
    label VARCHAR(120) NOT NULL DEFAULT '',
    url VARCHAR(500) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_socials_display (is_active, display_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO company_socials (platform, label, url, display_order, is_active)
SELECT
    'facebook',
    'Endurance Riding Ireland',
    COALESCE(
        (SELECT setting_value FROM site_settings WHERE setting_key = 'company_facebook_url' LIMIT 1),
        'https://www.facebook.com/EnduranceRidingIreland'
    ),
    10,
    1
WHERE NOT EXISTS (SELECT 1 FROM company_socials LIMIT 1);

CREATE TABLE IF NOT EXISTS company_affiliates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL DEFAULT '',
    asset_id INT UNSIGNED DEFAULT NULL,
    logo_url VARCHAR(500) NOT NULL,
    website_url VARCHAR(500) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_affiliates_display (is_active, display_order, id),
    INDEX idx_company_affiliates_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE company_affiliates
    ADD COLUMN IF NOT EXISTS asset_id INT UNSIGNED DEFAULT NULL AFTER name;

INSERT INTO company_affiliates (name, asset_id, logo_url, website_url, display_order, is_active)
SELECT
    'St. Patrick''s Coast Endurance Ride',
    NULL,
    '/filestore/images/affiliate-placeholder.svg',
    'https://stpatrickscoast.com/',
    10,
    1
WHERE NOT EXISTS (SELECT 1 FROM company_affiliates LIMIT 1);

UPDATE company_affiliates
SET logo_url = '/filestore/images/affiliate-placeholder.svg'
WHERE logo_url = 'https://stpatrickscoast.com/filestore/images/logos/StPatricksCoast-logo.png';

CREATE TABLE IF NOT EXISTS account_intro_modals (
    view_key VARCHAR(30) PRIMARY KEY,
    heading VARCHAR(200) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO account_intro_modals (view_key, heading, body_html, is_active) VALUES
    (
        'people',
        'Adding People',
        '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Add people here so their details can be selected when completing entries and membership forms.</p>',
        1
    ),
    (
        'horses',
        'Adding Horses',
        '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Add horses here to manage their details, registrations and logbooks.</p>',
        1
    ),
    (
        'shares',
        'Managing Shares',
        '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Shares allow approved account holders to select people or horses without changing their private details.</p>',
        1
    ),
    (
        'security',
        'Account Security',
        '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Use this area to manage your password and additional sign-in security.</p>',
        1
    );

INSERT IGNORE INTO admin_menu_items
    (menu_key, label, href, icon_class, parent_id, display_order, is_active, required_roles, is_system)
VALUES (
    'help_accounts',
    'Account Help',
    'account_intros.php',
    'fa-solid fa-circle-info',
    NULL,
    150,
    1,
    'superadmin,admin',
    1
);

UPDATE admin_menu_items
SET parent_id = NULL,
    label = 'Account Help',
    href = 'account_intros.php',
    icon_class = 'fa-solid fa-circle-info',
    required_roles = 'superadmin,admin'
WHERE menu_key = 'help_accounts';
