<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/bookings_store.php';

const NAV_GROUPS = [
    'home' => 'Home',
    'about-ildra' => 'About ILDRA',
    'about-endurance' => 'About Endurance',
    'events' => 'Events',
    'news' => 'News',
    'faqs' => 'FAQs',
    'contact' => 'Contact',
];

function defaultMembershipTypes(): array
{
    return [
        [
            'id' => 501,
            'name' => 'Adult Annual',
            'description' => 'Standard adult membership for the season.',
            'sale_starts' => date('Y-01-01'),
            'sale_ends' => date('Y-12-31'),
            'membership_starts' => date('Y-01-15'),
            'membership_ends' => date('Y-12-15'),
            'cost' => '50.00',
            'type' => 'senior',
            'status' => 'published',
        ],
        [
            'id' => 502,
            'name' => 'Junior Annual',
            'description' => 'Under 18 membership.',
            'sale_starts' => date('Y-01-01'),
            'sale_ends' => date('Y-12-31'),
            'membership_starts' => date('Y-01-15'),
            'membership_ends' => date('Y-12-15'),
            'cost' => '25.00',
            'type' => 'junior',
            'status' => 'published',
        ],
    ];
}

function defaultMemberships(): array
{
    return [
        [
            'id' => 9001,
            'user_id' => 0,
            'user_email' => 'member@example.com',
            'membership_type_id' => 501,
            'membership_name' => 'Adult Annual',
            'status' => 'active',
            'amount' => '50.00',
            'starts_at' => date('Y-01-15'),
            'ends_at' => date('Y-12-15'),
            'purchased_at' => date('Y-m-d'),
        ],
        [
            'id' => 9002,
            'user_id' => 0,
            'user_email' => 'expired@example.com',
            'membership_type_id' => 502,
            'membership_name' => 'Junior Annual',
            'status' => 'expired',
            'amount' => '25.00',
            'starts_at' => date('Y-01-15', strtotime('-1 year')),
            'ends_at' => date('Y-12-15', strtotime('-1 year')),
            'purchased_at' => date('Y-m-d', strtotime('-1 year')),
        ],
    ];
}

function defaultSiteSettings(): array
{
    return [
        'hero_title' => 'Endurance Riding Ireland LTD',
        'hero_subtitle' => 'Recognised by Horse Sport Ireland',
        'hero_tagline' => 'Home for Endurance Riding in Ireland',
        'hero_cta_label' => 'Become a member',
        'hero_cta_url' => '#contact',
        'welcome_title' => 'Welcome to ILDRA',
        'welcome_body' => "The Irish Long Distance Riding Association (1990) runs rides across Ireland for members and newcomers. Distances range from pleasure rides through competitive trail rides for experienced horses and riders.",
        'sponsor_image_url' => 'https://placehold.co/640x140/216c22/ffffff?text=Sponsor+Banner',
        'background_image_url' => 'https://placehold.co/1600x900/eff7ec/216c22?text=Endurance+Riding+Ireland',
        'basket_timeout_seconds' => 900, // default 15 minutes
        // "Remember me" login cookie duration (seconds). Used when a user ticks "Keep me signed in".
        'remember_me_ttl_seconds' => 2592000, // default 30 days
        'admin_manual_filename' => '',
        'auth_app_login_enabled' => '0',
    ];
}

/**
 * Ensure site_settings exists as key/value table; migrate legacy row-based structure if present.
 */
function ensureSiteSettingsTable(PDO $pdo): void
{
    $hasKv = false;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'site_settings'
              AND COLUMN_NAME = 'setting_key'
        ");
        $stmt->execute();
        $hasKv = ((int)$stmt->fetchColumn()) > 0;
    } catch (PDOException $e) {
        // continue
    }

    if ($hasKv) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(191) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        return;
    }

    // Pull any legacy row data before rebuilding
    $legacy = [];
    try {
        $legacy = $pdo->query("SELECT * FROM site_settings LIMIT 1")->fetch() ?: [];
    } catch (PDOException $e) {
        // ignore
    }

    // Rebuild as key/value
    $pdo->exec("DROP TABLE IF EXISTS site_settings");
    $pdo->exec("
        CREATE TABLE site_settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $seed = array_merge(defaultSiteSettings(), $legacy);
    $insert = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())");
    foreach ($seed as $k => $v) {
        $insert->execute([':k' => $k, ':v' => (string)$v]);
    }
}

function getSiteSettings(?PDO $pdo): array
{
    if (!$pdo) {
        return defaultSiteSettings();
    }

    try {
        ensureSiteSettingsTable($pdo);
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
        $rows = $stmt ? $stmt->fetchAll() : [];
        $settings = defaultSiteSettings();
        foreach ($rows as $row) {
            $key = $row['setting_key'] ?? '';
            if ($key === '') {
                continue;
            }
            $settings[$key] = $row['setting_value'];
        }
        $settings['basket_timeout_seconds'] = max(300, (int)($settings['basket_timeout_seconds'] ?? 900));
        $settings['remember_me_ttl_seconds'] = (int)($settings['remember_me_ttl_seconds'] ?? (30 * 86400));
        $settings['auth_app_login_enabled'] = !empty($settings['auth_app_login_enabled']) && (string)$settings['auth_app_login_enabled'] !== '0' ? '1' : '0';
        return $settings;
    } catch (PDOException $e) {
        return defaultSiteSettings();
    }
}

function saveSiteSettings(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    // Start with current settings (or defaults) so unchanged fields persist across pages.
    $base = getSiteSettings($pdo);
    $settings = array_merge(defaultSiteSettings(), $base);
    foreach (array_keys(defaultSiteSettings()) as $key) {
        if (array_key_exists($key, $data)) {
            $settings[$key] = trim((string)$data[$key]);
        }
    }
    // normalise basket timeout to integer seconds (minimum 5 minutes to avoid instant expiry)
    $settings['basket_timeout_seconds'] = max(300, (int)$settings['basket_timeout_seconds']);
    // normalise remember-me TTL: allow disabling by setting to 0; otherwise clamp 1 hour..1 year
    $rememberTtl = (int)($settings['remember_me_ttl_seconds'] ?? (30 * 86400));
    if ($rememberTtl !== 0) {
        $rememberTtl = max(3600, min(31536000, $rememberTtl));
    }
    $settings['remember_me_ttl_seconds'] = $rememberTtl;
    $settings['auth_app_login_enabled'] = !empty($settings['auth_app_login_enabled']) && (string)$settings['auth_app_login_enabled'] !== '0' ? '1' : '0';

    try {
        ensureSiteSettingsTable($pdo);
        $stmt = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())");
        foreach ($settings as $key => $value) {
            $stmt->execute([':k' => $key, ':v' => (string)$value]);
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save site settings.'];
        return false;
    }
}

function defaultPages(): array
{
    return [
        ['id' => 0, 'title' => 'Who are we?', 'slug' => 'who-we-are', 'nav_group' => 'about-ildra', 'excerpt' => 'Meet the team and our mission.', 'body_html' => 'ILDRA (Irish Long Distance Riding Association - 1990) organises pleasure, training and endurance rides across Ireland.', 'is_published' => 1, 'display_order' => 1],
        ['id' => 0, 'title' => 'Membership', 'slug' => 'membership', 'nav_group' => 'about-ildra', 'excerpt' => 'Member benefits and how to join.', 'body_html' => 'Members enjoy access to organised rides, training days and discounts with our partners.', 'is_published' => 1, 'display_order' => 2],
        ['id' => 0, 'title' => 'Committees', 'slug' => 'committees', 'nav_group' => 'about-ildra', 'excerpt' => 'Our volunteer leadership.', 'body_html' => 'Regional and discipline committees keep the sport welcoming and safe.', 'is_published' => 1, 'display_order' => 3],
        ['id' => 0, 'title' => 'Rules', 'slug' => 'rules', 'nav_group' => 'about-ildra', 'excerpt' => 'Ride and welfare rules.', 'body_html' => 'We follow Horse Sport Ireland and FEI-aligned rules to protect horses and riders.', 'is_published' => 1, 'display_order' => 4],
        ['id' => 0, 'title' => 'Policies', 'slug' => 'policies', 'nav_group' => 'about-ildra', 'excerpt' => 'Welfare, safeguarding and sport integrity.', 'body_html' => 'ILDRA follows Horse Sport Ireland guidance on safeguarding, welfare and safe sport.', 'is_published' => 1, 'display_order' => 5],
        ['id' => 0, 'title' => 'Awards & Recognition', 'slug' => 'awards', 'nav_group' => 'about-ildra', 'excerpt' => 'Annual awards and milestones.', 'body_html' => 'From Shamrock Awards to the 100 Mile High Club, we celebrate riders and volunteers.', 'is_published' => 1, 'display_order' => 6],
        ['id' => 0, 'title' => 'ILDRA Clothing', 'slug' => 'ildra-clothing', 'nav_group' => 'about-ildra', 'excerpt' => 'Club kit and merchandise.', 'body_html' => 'Order branded clothing and merchandise to represent ILDRA at events.', 'is_published' => 1, 'display_order' => 7],
        ['id' => 0, 'title' => 'What is endurance riding?', 'slug' => 'what-is-endurance', 'nav_group' => 'about-endurance', 'excerpt' => 'How the sport works.', 'body_html' => 'Endurance riding tests horse fitness and rider planning over marked distances with veterinary checks.', 'is_published' => 1, 'display_order' => 1],
        ['id' => 0, 'title' => 'Training rides', 'slug' => 'training-rides', 'nav_group' => 'about-endurance', 'excerpt' => 'Introductory CTRs and ERs.', 'body_html' => 'CTR distances start around 20 miles with higher levels for experienced partnerships.', 'is_published' => 1, 'display_order' => 2],
        ['id' => 0, 'title' => 'Horse welfare', 'slug' => 'horse-welfare', 'nav_group' => 'about-endurance', 'excerpt' => 'Vet checks and welfare standards.', 'body_html' => 'Every ride includes vet inspections and heart-rate thresholds to protect horses.', 'is_published' => 1, 'display_order' => 3],
        ['id' => 0, 'title' => 'Ride calendar', 'slug' => 'ride-calendar', 'nav_group' => 'events', 'excerpt' => 'Planned CTRs and ERs for the season.', 'body_html' => 'The ILDRA calendar lists provincial and national rides with entry details.', 'is_published' => 1, 'display_order' => 1],
        ['id' => 0, 'title' => 'How to enter', 'slug' => 'how-to-enter', 'nav_group' => 'events', 'excerpt' => 'Entry steps for riders and crews.', 'body_html' => 'Create an account, submit your entry form, and review ride rules before attending.', 'is_published' => 1, 'display_order' => 2],
        ['id' => 0, 'title' => 'News & updates', 'slug' => 'news-updates', 'nav_group' => 'news', 'excerpt' => 'Club notices, press and stories.', 'body_html' => 'Catch up on ride reports, committee notices, and partner announcements.', 'is_published' => 1, 'display_order' => 1],
        ['id' => 0, 'title' => 'Press & media', 'slug' => 'press-media', 'nav_group' => 'news', 'excerpt' => 'Logos and media contact.', 'body_html' => 'Media requests and brand assets for covering ILDRA events.', 'is_published' => 1, 'display_order' => 2],
        ['id' => 0, 'title' => 'Contact ILDRA', 'slug' => 'contact-ildra', 'nav_group' => 'contact', 'excerpt' => 'Get in touch with the team.', 'body_html' => 'Reach the committee for membership, events, safeguarding or volunteering queries.', 'is_published' => 1, 'display_order' => 1],
        ['id' => 0, 'title' => 'Partner with us', 'slug' => 'partner-with-us', 'nav_group' => 'contact', 'excerpt' => 'Sponsorship and partnerships.', 'body_html' => 'Talk to us about sponsoring rides, awards, or youth development.', 'is_published' => 1, 'display_order' => 2],
    ];
}

function ensurePageButtonColumns(PDO $pdo): void
{
    $columns = [
        'button_name' => "ALTER TABLE pages ADD COLUMN button_name VARCHAR(150) DEFAULT NULL AFTER body_html",
        'button_title' => "ALTER TABLE pages ADD COLUMN button_title VARCHAR(255) DEFAULT NULL AFTER button_name",
        'button_url' => "ALTER TABLE pages ADD COLUMN button_url VARCHAR(1000) DEFAULT NULL AFTER button_title",
        'button_target' => "ALTER TABLE pages ADD COLUMN button_target VARCHAR(16) NOT NULL DEFAULT '_self' AFTER button_url",
    ];
    foreach ($columns as $column => $sql) {
        if (!table_column_exists($pdo, 'pages', $column)) {
            $pdo->exec($sql);
        }
    }
}

function fetchPages(?PDO $pdo, bool $publishedOnly = false): array
{
    if (!$pdo) {
        return defaultPages();
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(180) NOT NULL UNIQUE,
                nav_group VARCHAR(80) NOT NULL DEFAULT 'home',
                excerpt TEXT,
                body_html MEDIUMTEXT,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                display_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        ensurePageButtonColumns($pdo);

        $sql = "SELECT * FROM pages";
        $params = [];
        if ($publishedOnly) {
            $sql .= " WHERE is_published = 1";
        }
        $sql .= " ORDER BY nav_group, display_order, title";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pages = $stmt->fetchAll();

        if (!$pages) {
            $defaults = defaultPages();
            $insert = $pdo->prepare("
                INSERT INTO pages (title, slug, nav_group, excerpt, body_html, is_published, display_order, created_at, updated_at)
                VALUES (:title, :slug, :nav_group, :excerpt, :body_html, :is_published, :display_order, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    nav_group = VALUES(nav_group),
                    excerpt = VALUES(excerpt),
                    body_html = VALUES(body_html),
                    is_published = VALUES(is_published),
                    display_order = VALUES(display_order)
            ");
            foreach ($defaults as $page) {
                $insert->execute([
                    ':title' => $page['title'],
                    ':slug' => $page['slug'],
                    ':nav_group' => $page['nav_group'],
                    ':excerpt' => $page['excerpt'],
                    ':body_html' => $page['body_html'],
                    ':is_published' => $page['is_published'],
                    ':display_order' => $page['display_order'],
                ]);
            }
            // Re-run query to return DB-backed rows
            $stmt->execute($params);
            $pages = $stmt->fetchAll();
        }

        return $pages;
    } catch (PDOException $e) {
        return defaultPages();
    }
}

function fetchPageById(?PDO $pdo, int $id): ?array
{
    if (!$pdo || $id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function fetchPageBySlug(?PDO $pdo, string $slug, bool $publishedOnly = true): ?array
{
    if (!$pdo) {
        return null;
    }
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    try {
        $sql = "SELECT * FROM pages WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($publishedOnly) {
            $sql .= " AND is_published = 1";
        }
        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function savePage(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    $pageId = isset($data['page_id']) ? (int)$data['page_id'] : 0;
    $title = trim((string)($data['title'] ?? ''));
    $slug = strtolower(trim((string)($data['slug'] ?? '')));
    $navGroup = $data['nav_group'] ?? 'home';
    $excerpt = trim((string)($data['excerpt'] ?? ''));
    $body = trim((string)($data['body_html'] ?? ''));
    $isPublished = isset($data['is_published']) ? 1 : 0;
    $displayOrder = (int)($data['display_order'] ?? 0);
    $buttonName = trim((string)($data['button_name'] ?? ''));
    $buttonTitle = trim((string)($data['button_title'] ?? ''));
    $buttonUrl = trim((string)($data['button_url'] ?? ''));
    $buttonTarget = (string)($data['button_target'] ?? '_self');
    if (!in_array($buttonTarget, ['_self', '_blank'], true)) {
        $buttonTarget = '_self';
    }

    if ($title === '' || $slug === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Title and slug are required for pages.'];
        return false;
    }

    if (!isset(NAV_GROUPS[$navGroup])) {
        $navGroup = 'home';
    }
    if (($buttonName === '') !== ($buttonUrl === '')) {
        $alerts[] = ['type' => 'danger', 'message' => 'The content button requires both a label and destination URL.'];
        return false;
    }
    if ($buttonUrl !== '' && preg_match('~^(?:javascript|data):~i', $buttonUrl)) {
        $alerts[] = ['type' => 'danger', 'message' => 'The content button destination is not allowed.'];
        return false;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(180) NOT NULL UNIQUE,
                nav_group VARCHAR(80) NOT NULL DEFAULT 'home',
                excerpt TEXT,
                body_html MEDIUMTEXT,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                display_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        ensurePageButtonColumns($pdo);

        if ($pageId > 0) {
            $stmt = $pdo->prepare("
                UPDATE pages SET
                    title = :title,
                    slug = :slug,
                    nav_group = :nav_group,
                    excerpt = :excerpt,
                    body_html = :body_html,
                    button_name = :button_name,
                    button_title = :button_title,
                    button_url = :button_url,
                    button_target = :button_target,
                    is_published = :is_published,
                    display_order = :display_order,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':nav_group' => $navGroup,
                ':excerpt' => $excerpt,
                ':body_html' => $body,
                ':button_name' => $buttonName !== '' ? $buttonName : null,
                ':button_title' => $buttonTitle !== '' ? $buttonTitle : null,
                ':button_url' => $buttonUrl !== '' ? $buttonUrl : null,
                ':button_target' => $buttonTarget,
                ':is_published' => $isPublished,
                ':display_order' => $displayOrder,
                ':id' => $pageId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pages (title, slug, nav_group, excerpt, body_html, button_name, button_title, button_url, button_target, is_published, display_order, created_at, updated_at)
                VALUES (:title, :slug, :nav_group, :excerpt, :body_html, :button_name, :button_title, :button_url, :button_target, :is_published, :display_order, NOW(), NOW())
            ");
            $stmt->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':nav_group' => $navGroup,
                ':excerpt' => $excerpt,
                ':body_html' => $body,
                ':button_name' => $buttonName !== '' ? $buttonName : null,
                ':button_title' => $buttonTitle !== '' ? $buttonTitle : null,
                ':button_url' => $buttonUrl !== '' ? $buttonUrl : null,
                ':button_target' => $buttonTarget,
                ':is_published' => $isPublished,
                ':display_order' => $displayOrder,
            ]);
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save page. Slugs must be unique.'];
        return false;
    }
}

function deletePage(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM pages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete page.'];
        return false;
    }
}

function defaultEventTypes(): array
{
    return [
        ['id' => 1, 'name' => 'Ride', 'quick_view_fields' => ['class_label', 'rider_name', 'horse_name']],
        ['id' => 2, 'name' => 'Awards', 'quick_view_fields' => ['class_label']],
        ['id' => 3, 'name' => 'Training', 'quick_view_fields' => ['class_label']],
    ];
}

function fetchEventTypes(?PDO $pdo): array
{
    if (!$pdo) {
        return defaultEventTypes();
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS event_types (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL UNIQUE,
                quick_view_fields JSON NULL,
                default_pricing_scheme_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        if (!table_column_exists($pdo, 'event_types', 'default_pricing_scheme_id')) {
            try {
                $pdo->exec("ALTER TABLE event_types ADD COLUMN default_pricing_scheme_id INT UNSIGNED NULL DEFAULT NULL");
            } catch (PDOException $e) {
                // ignore (column may already exist)
            }
        }
        if (!table_index_on_column_exists($pdo, 'event_types', 'default_pricing_scheme_id')) {
            try {
                if (table_index_count($pdo, 'event_types') < 64) {
                    $pdo->exec("ALTER TABLE event_types ADD INDEX idx_event_types_default_scheme (default_pricing_scheme_id)");
                }
            } catch (PDOException $e) {
                // ignore
            }
        }

        foreach (defaultEventTypes() as $type) {
            $stmt = $pdo->prepare("
                INSERT INTO event_types (id, name, quick_view_fields, created_at, updated_at)
                VALUES (:id, :name, :qv, NOW(), NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), quick_view_fields = VALUES(quick_view_fields), updated_at = NOW()
            ");
            $stmt->execute([
                ':id' => $type['id'],
                ':name' => $type['name'],
                ':qv' => json_encode($type['quick_view_fields'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $stmt = $pdo->query("SELECT * FROM event_types ORDER BY name ASC");
        return $stmt->fetchAll() ?: defaultEventTypes();
    } catch (PDOException $e) {
        return defaultEventTypes();
    }
}

/**
 * Pricing schemes (admin-managed defaults for per-event pricing rows)
 *
 * Option A: classes live inside pricing schemes (not globally).
 * Events receive a copy into `event_pricing_rows` so editing an event does not change the scheme.
 */
function ensurePricingSchemeTables(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pricing_schemes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    } catch (PDOException $e) {
        // MySQL doesn't support CREATE INDEX IF NOT EXISTS in some versions; ignore.
    }
    if (!table_index_exists($pdo, 'pricing_schemes', 'idx_pricing_schemes_name')) {
        try {
            if (table_index_count($pdo, 'pricing_schemes') < 64) {
                $pdo->exec("CREATE INDEX idx_pricing_schemes_name ON pricing_schemes (name)");
            }
        } catch (PDOException $e) {
            // ignore (index may already exist)
        }
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pricing_scheme_event_types (
                scheme_id INT UNSIGNED NOT NULL,
                event_type_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (scheme_id, event_type_id),
                INDEX (event_type_id)
            )
        ");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pricing_scheme_rows (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scheme_id INT UNSIGNED NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                class_name VARCHAR(190) NOT NULL,
                class_code VARCHAR(32) NULL DEFAULT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                is_member_price TINYINT(1) NOT NULL DEFAULT 0,
                is_junior_ride TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (scheme_id),
                INDEX (sort_order)
            )
        ");
    } catch (PDOException $e) {
        // ignore
    }
    if (!table_column_exists($pdo, 'pricing_scheme_rows', 'is_junior_ride')) {
        try {
            $pdo->exec("ALTER TABLE pricing_scheme_rows ADD COLUMN is_junior_ride TINYINT(1) NOT NULL DEFAULT 0 AFTER is_member_price");
        } catch (PDOException $e) {
            // ignore
        }
    }
}

function ensureEventPricingTables(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS event_pricing_rows (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id INT UNSIGNED NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                class_name VARCHAR(190) NOT NULL,
                class_code VARCHAR(32) NULL DEFAULT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                is_member_price TINYINT(1) NOT NULL DEFAULT 0,
                is_junior_ride TINYINT(1) NOT NULL DEFAULT 0,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (event_id),
                INDEX (sort_order)
            )
        ");
    } catch (PDOException $e) {
        // ignore
    }
    if (!table_column_exists($pdo, 'event_pricing_rows', 'is_junior_ride')) {
        try {
            $pdo->exec("ALTER TABLE event_pricing_rows ADD COLUMN is_junior_ride TINYINT(1) NOT NULL DEFAULT 0 AFTER is_member_price");
        } catch (PDOException $e) {
            // ignore
        }
    }
}

/**
 * Seed a minimal set of pricing schemes if none exist yet and ensure each event type
 * has exactly one default scheme assigned (stored on event_types.default_pricing_scheme_id).
 *
 * This is intentionally conservative:
 * - Only seeds when there are zero schemes.
 * - Only sets defaults where none are set yet.
 */
function ensureDefaultPricingSchemes(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    ensurePricingSchemeTables($pdo);

    try {
        $count = (int)($pdo->query("SELECT COUNT(*) FROM pricing_schemes")->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return;
    }

    if ($count === 0) {
        try {
            $pdo->beginTransaction();

            $insertScheme = $pdo->prepare("INSERT INTO pricing_schemes (name, created_at, updated_at) VALUES (:name, NOW(), NOW())");
            $insertMap = $pdo->prepare("INSERT IGNORE INTO pricing_scheme_event_types (scheme_id, event_type_id) VALUES (:sid, :tid)");
            $insertRow = $pdo->prepare("
                INSERT INTO pricing_scheme_rows (scheme_id, sort_order, class_name, class_code, price, is_member_price, created_at, updated_at)
                VALUES (:sid, :sort_order, :class_name, :class_code, :price, :is_member_price, NOW(), NOW())
            ");

            // Ride defaults (mirrors the historical hard-coded PR/CTR/ER)
            $insertScheme->execute([':name' => 'Default Ride Pricing']);
            $rideSchemeId = (int)$pdo->lastInsertId();
            $insertMap->execute([':sid' => $rideSchemeId, ':tid' => 1]);
            $rideRows = [
                ['sort' => 10, 'name' => 'Pleasure Ride', 'code' => 'PR', 'price' => 12.00, 'member' => 0],
                ['sort' => 11, 'name' => 'Pleasure Ride', 'code' => 'PR', 'price' => 12.00, 'member' => 1],
                ['sort' => 20, 'name' => 'CTR', 'code' => 'CTR', 'price' => 15.00, 'member' => 0],
                ['sort' => 21, 'name' => 'CTR', 'code' => 'CTR', 'price' => 15.00, 'member' => 1],
                ['sort' => 30, 'name' => 'ER', 'code' => 'ER', 'price' => 25.00, 'member' => 0],
                ['sort' => 31, 'name' => 'ER', 'code' => 'ER', 'price' => 25.00, 'member' => 1],
            ];
            foreach ($rideRows as $r) {
                $insertRow->execute([
                    ':sid' => $rideSchemeId,
                    ':sort_order' => (int)$r['sort'],
                    ':class_name' => (string)$r['name'],
                    ':class_code' => (string)$r['code'],
                    ':price' => (float)$r['price'],
                    ':is_member_price' => (int)$r['member'],
                ]);
            }

            // Awards defaults
            $insertScheme->execute([':name' => 'Default Awards Pricing']);
            $awardsSchemeId = (int)$pdo->lastInsertId();
            $insertMap->execute([':sid' => $awardsSchemeId, ':tid' => 2]);
            $insertRow->execute([
                ':sid' => $awardsSchemeId,
                ':sort_order' => 10,
                ':class_name' => 'Awards',
                ':class_code' => 'Awards',
                ':price' => 0.00,
                ':is_member_price' => 0,
            ]);

            // Training defaults (single generic class)
            $insertScheme->execute([':name' => 'Default Training Pricing']);
            $trainingSchemeId = (int)$pdo->lastInsertId();
            $insertMap->execute([':sid' => $trainingSchemeId, ':tid' => 3]);
            $insertRow->execute([
                ':sid' => $trainingSchemeId,
                ':sort_order' => 10,
                ':class_name' => 'Training',
                ':class_code' => 'Training',
                ':price' => 0.00,
                ':is_member_price' => 0,
            ]);

            // Set defaults (only when missing).
            $setDefault = $pdo->prepare("UPDATE event_types SET default_pricing_scheme_id = :sid WHERE id = :tid AND (default_pricing_scheme_id IS NULL OR default_pricing_scheme_id = 0)");
            $setDefault->execute([':sid' => $rideSchemeId, ':tid' => 1]);
            $setDefault->execute([':sid' => $awardsSchemeId, ':tid' => 2]);
            $setDefault->execute([':sid' => $trainingSchemeId, ':tid' => 3]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    // Ensure each type has *some* default if still unset (fallback to first applicable scheme).
    try {
        $types = $pdo->query("SELECT id, default_pricing_scheme_id FROM event_types")->fetchAll() ?: [];
        foreach ($types as $t) {
            $tid = (int)($t['id'] ?? 0);
            $defaultId = (int)($t['default_pricing_scheme_id'] ?? 0);
            if ($tid <= 0 || $defaultId > 0) {
                continue;
            }
            $stmt = $pdo->prepare("
                SELECT scheme_id
                FROM pricing_scheme_event_types
                WHERE event_type_id = :tid
                ORDER BY scheme_id ASC
                LIMIT 1
            ");
            $stmt->execute([':tid' => $tid]);
            $sid = (int)($stmt->fetchColumn() ?: 0);
            if ($sid > 0) {
                $upd = $pdo->prepare("UPDATE event_types SET default_pricing_scheme_id = :sid WHERE id = :tid");
                $upd->execute([':sid' => $sid, ':tid' => $tid]);
            }
        }
    } catch (PDOException $e) {
        // ignore
    }
}

function fetchDefaultPricingSchemeIdForEventType(?PDO $pdo, int $eventTypeId): int
{
    if (!$pdo || $eventTypeId <= 0) {
        return 0;
    }
    ensurePricingSchemeTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT default_pricing_scheme_id FROM event_types WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $eventTypeId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function fetchPricingSchemes(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }
    ensurePricingSchemeTables($pdo);
    try {
        $schemes = $pdo->query("SELECT * FROM pricing_schemes ORDER BY name ASC")->fetchAll() ?: [];
        if (!$schemes) {
            return [];
        }

        $map = [];
        $stmt = $pdo->query("
            SELECT ps.scheme_id, et.id AS event_type_id, et.name AS event_type_name, et.default_pricing_scheme_id
            FROM pricing_scheme_event_types ps
            JOIN event_types et ON et.id = ps.event_type_id
            ORDER BY et.name ASC
        ");
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int)($row['scheme_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $map[$sid][] = [
                'id' => (int)($row['event_type_id'] ?? 0),
                'name' => (string)($row['event_type_name'] ?? ''),
                'is_default' => (int)($row['default_pricing_scheme_id'] ?? 0) === $sid,
            ];
        }

        foreach ($schemes as &$scheme) {
            $sid = (int)($scheme['id'] ?? 0);
            $scheme['event_types'] = $map[$sid] ?? [];
        }
        unset($scheme);

        return $schemes;
    } catch (PDOException $e) {
        return [];
    }
}

function fetchPricingSchemeById(?PDO $pdo, int $schemeId): ?array
{
    if (!$pdo || $schemeId <= 0) {
        return null;
    }
    ensurePricingSchemeTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM pricing_schemes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $schemeId]);
        $scheme = $stmt->fetch();
        return $scheme ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function fetchPricingSchemeEventTypeIds(?PDO $pdo, int $schemeId): array
{
    if (!$pdo || $schemeId <= 0) {
        return [];
    }
    ensurePricingSchemeTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT event_type_id FROM pricing_scheme_event_types WHERE scheme_id = :sid ORDER BY event_type_id ASC");
        $stmt->execute([':sid' => $schemeId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (PDOException $e) {
        return [];
    }
}

function fetchPricingSchemeRows(?PDO $pdo, int $schemeId): array
{
    if (!$pdo || $schemeId <= 0) {
        return [];
    }
    ensurePricingSchemeTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM pricing_scheme_rows WHERE scheme_id = :sid ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':sid' => $schemeId]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Save a pricing scheme and its rows/event-type assignments.
 *
 * Notes:
 * - One default scheme per event type is stored on event_types.default_pricing_scheme_id.
 * - Rows are replaced (delete missing ids) to keep the UI simple.
 */
function savePricingScheme(?PDO $pdo, array $data, array &$alerts, ?int $schemeId = null): ?int
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return null;
    }
    ensurePricingSchemeTables($pdo);
    $schemeId = $schemeId && $schemeId > 0 ? $schemeId : null;

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Scheme name is required.'];
        return null;
    }

    $eventTypeIds = $data['event_type_ids'] ?? [];
    if (!is_array($eventTypeIds)) {
        $eventTypeIds = [];
    }
    $eventTypeIds = array_values(array_unique(array_filter(array_map('intval', $eventTypeIds), fn($v) => $v > 0)));
    if (!$eventTypeIds) {
        $alerts[] = ['type' => 'danger', 'message' => 'Select one or more event types for this scheme.'];
        return null;
    }

    $defaultForTypeIds = $data['default_for_type_ids'] ?? [];
    if (!is_array($defaultForTypeIds)) {
        $defaultForTypeIds = [];
    }
    $defaultForTypeIds = array_values(array_unique(array_filter(array_map('intval', $defaultForTypeIds), fn($v) => $v > 0)));

    $rowIds = $data['row_id'] ?? [];
    $rowSort = $data['row_sort'] ?? [];
    $rowNames = $data['row_class_name'] ?? [];
    $rowCodes = $data['row_class_code'] ?? [];
    $rowPrices = $data['row_price'] ?? [];
    $rowMember = $data['row_is_member_price'] ?? [];
    $rowJunior = $data['row_is_junior_ride'] ?? [];

    $rows = [];
    if (!is_array($rowIds)) $rowIds = [];
    if (!is_array($rowSort)) $rowSort = [];
    if (!is_array($rowNames)) $rowNames = [];
    if (!is_array($rowCodes)) $rowCodes = [];
    if (!is_array($rowPrices)) $rowPrices = [];
    if (!is_array($rowMember)) $rowMember = [];
    if (!is_array($rowJunior)) $rowJunior = [];

    $keys = array_keys($rowNames);
    sort($keys);
    foreach ($keys as $i) {
        $className = trim((string)($rowNames[$i] ?? ''));
        if ($className === '') {
            continue;
        }
        $classCode = trim((string)($rowCodes[$i] ?? ''));
        if ($classCode === '') {
            $classCode = null;
        } elseif (mb_strlen($classCode) > 32) {
            $classCode = mb_substr($classCode, 0, 32);
        }
        $price = price_to_number((string)($rowPrices[$i] ?? '0'));
        $sortOrder = (int)($rowSort[$i] ?? (($i + 1) * 10));
        $isMemberPrice = !empty($rowMember[$i]) ? 1 : 0;
        $isJuniorRide = !empty($rowJunior[$i]) ? 1 : 0;
        $id = (int)($rowIds[$i] ?? 0);
        $rows[] = [
            'id' => $id > 0 ? $id : null,
            'sort_order' => $sortOrder,
            'class_name' => $className,
            'class_code' => $classCode,
            'price' => $price,
            'is_member_price' => $isMemberPrice,
            'is_junior_ride' => $isJuniorRide,
        ];
    }
    if (!$rows) {
        $alerts[] = ['type' => 'danger', 'message' => 'Add at least one pricing row.'];
        return null;
    }

    try {
        $pdo->beginTransaction();

        if ($schemeId) {
            $stmt = $pdo->prepare("UPDATE pricing_schemes SET name = :name, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':name' => $name, ':id' => $schemeId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO pricing_schemes (name, created_at, updated_at) VALUES (:name, NOW(), NOW())");
            $stmt->execute([':name' => $name]);
            $schemeId = (int)$pdo->lastInsertId();
        }

        // Event types mapping
        $pdo->prepare("DELETE FROM pricing_scheme_event_types WHERE scheme_id = :sid")->execute([':sid' => $schemeId]);
        $ins = $pdo->prepare("INSERT INTO pricing_scheme_event_types (scheme_id, event_type_id) VALUES (:sid, :tid)");
        foreach ($eventTypeIds as $tid) {
            $ins->execute([':sid' => $schemeId, ':tid' => $tid]);
        }

        // Defaults per event type (enforced by single column)
        if ($defaultForTypeIds) {
            $upd = $pdo->prepare("UPDATE event_types SET default_pricing_scheme_id = :sid WHERE id = :tid");
            foreach ($defaultForTypeIds as $tid) {
                $upd->execute([':sid' => $schemeId, ':tid' => $tid]);
            }
        }

        // Rows: upsert current, delete removed
        $existingIds = [];
        $stmt = $pdo->prepare("SELECT id FROM pricing_scheme_rows WHERE scheme_id = :sid");
        $stmt->execute([':sid' => $schemeId]);
        $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $keepIds = [];

        $updateRow = $pdo->prepare("
            UPDATE pricing_scheme_rows
            SET sort_order = :sort_order,
                class_name = :class_name,
                class_code = :class_code,
                price = :price,
                is_member_price = :is_member_price,
                is_junior_ride = :is_junior_ride,
                updated_at = NOW()
            WHERE id = :id AND scheme_id = :sid
        ");
        $insertRow = $pdo->prepare("
            INSERT INTO pricing_scheme_rows (scheme_id, sort_order, class_name, class_code, price, is_member_price, is_junior_ride, created_at, updated_at)
            VALUES (:sid, :sort_order, :class_name, :class_code, :price, :is_member_price, :is_junior_ride, NOW(), NOW())
        ");
        foreach ($rows as $row) {
            if ($row['id']) {
                $updateRow->execute([
                    ':sort_order' => $row['sort_order'],
                    ':class_name' => $row['class_name'],
                    ':class_code' => $row['class_code'],
                    ':price' => $row['price'],
                    ':is_member_price' => $row['is_member_price'],
                    ':is_junior_ride' => $row['is_junior_ride'],
                    ':id' => $row['id'],
                    ':sid' => $schemeId,
                ]);
                $keepIds[] = (int)$row['id'];
            } else {
                $insertRow->execute([
                    ':sid' => $schemeId,
                    ':sort_order' => $row['sort_order'],
                    ':class_name' => $row['class_name'],
                    ':class_code' => $row['class_code'],
                    ':price' => $row['price'],
                    ':is_member_price' => $row['is_member_price'],
                    ':is_junior_ride' => $row['is_junior_ride'],
                ]);
                $keepIds[] = (int)$pdo->lastInsertId();
            }
        }

        $toDelete = array_diff($existingIds, $keepIds);
        if ($toDelete) {
            $in = implode(',', array_fill(0, count($toDelete), '?'));
            $del = $pdo->prepare("DELETE FROM pricing_scheme_rows WHERE scheme_id = ? AND id IN ($in)");
            $del->execute(array_merge([$schemeId], array_values($toDelete)));
        }

        $pdo->commit();
        return $schemeId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save pricing scheme.'];
        return null;
    }
}

function deletePricingScheme(?PDO $pdo, int $schemeId, array &$alerts): bool
{
    if (!$pdo || $schemeId <= 0) {
        return false;
    }
    ensurePricingSchemeTables($pdo);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM pricing_scheme_rows WHERE scheme_id = :sid")->execute([':sid' => $schemeId]);
        $pdo->prepare("DELETE FROM pricing_scheme_event_types WHERE scheme_id = :sid")->execute([':sid' => $schemeId]);
        $pdo->prepare("UPDATE event_types SET default_pricing_scheme_id = NULL WHERE default_pricing_scheme_id = :sid")->execute([':sid' => $schemeId]);
        $pdo->prepare("DELETE FROM pricing_schemes WHERE id = :sid")->execute([':sid' => $schemeId]);
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete pricing scheme.'];
        return false;
    }
}

function fetchEventPricingRows(?PDO $pdo, int $eventId): array
{
    if (!$pdo || $eventId <= 0) {
        return [];
    }
    ensureEventPricingTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM event_pricing_rows WHERE event_id = :eid ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':eid' => $eventId]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function replaceEventPricingRows(?PDO $pdo, int $eventId, array $rows): bool
{
    if (!$pdo || $eventId <= 0) {
        return false;
    }
    ensureEventPricingTables($pdo);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM event_pricing_rows WHERE event_id = :eid")->execute([':eid' => $eventId]);
        $ins = $pdo->prepare("
            INSERT INTO event_pricing_rows (event_id, sort_order, class_name, class_code, price, is_member_price, is_junior_ride, enabled, created_at, updated_at)
            VALUES (:eid, :sort_order, :class_name, :class_code, :price, :is_member_price, :is_junior_ride, :enabled, NOW(), NOW())
        ");
        foreach ($rows as $row) {
            $ins->execute([
                ':eid' => $eventId,
                ':sort_order' => (int)($row['sort_order'] ?? 0),
                ':class_name' => (string)($row['class_name'] ?? ''),
                ':class_code' => ($row['class_code'] ?? null) !== '' ? $row['class_code'] : null,
                ':price' => (float)($row['price'] ?? 0),
                ':is_member_price' => !empty($row['is_member_price']) ? 1 : 0,
                ':is_junior_ride' => !empty($row['is_junior_ride']) ? 1 : 0,
                ':enabled' => !empty($row['enabled']) ? 1 : 0,
            ]);
        }
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function copyPricingSchemeToEvent(?PDO $pdo, int $schemeId, int $eventId): bool
{
    if (!$pdo || $schemeId <= 0 || $eventId <= 0) {
        return false;
    }
    ensurePricingSchemeTables($pdo);
    ensureEventPricingTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT sort_order, class_name, class_code, price, is_member_price, is_junior_ride FROM pricing_scheme_rows WHERE scheme_id = :sid ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':sid' => $schemeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'sort_order' => (int)($r['sort_order'] ?? 0),
                'class_name' => (string)($r['class_name'] ?? ''),
                'class_code' => $r['class_code'] ?? null,
                'price' => (float)($r['price'] ?? 0),
                'is_member_price' => (int)($r['is_member_price'] ?? 0),
                'is_junior_ride' => (int)($r['is_junior_ride'] ?? 0),
                'enabled' => 1,
            ];
        }
        return replaceEventPricingRows($pdo, $eventId, $rows);
    } catch (PDOException $e) {
        return false;
    }
}

function migrateEventClassesOfferedToPricingRows(?PDO $pdo, int $eventId, ?string $classesOfferedJson = null): bool
{
    if (!$pdo || $eventId <= 0) {
        return false;
    }
    ensureEventPricingTables($pdo);
    $classesOfferedJson = $classesOfferedJson !== null ? $classesOfferedJson : null;
    if ($classesOfferedJson === null) {
        try {
            $stmt = $pdo->prepare("SELECT classes_offered FROM events WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $eventId]);
            $classesOfferedJson = (string)($stmt->fetchColumn() ?: '');
        } catch (PDOException $e) {
            $classesOfferedJson = '';
        }
    }
    $decoded = json_decode((string)$classesOfferedJson, true);
    if (!is_array($decoded) || !$decoded) {
        return false;
    }
    $rows = [];
    $i = 0;
    foreach ($decoded as $cls) {
        if (!is_array($cls)) {
            continue;
        }
        $code = trim((string)($cls['code'] ?? ''));
        $label = trim((string)($cls['label'] ?? $code));
        $priceRaw = (string)($cls['price'] ?? '0');
        $price = price_to_number($priceRaw);
        $classCode = $code !== '' ? $code : null;
        if ($classCode !== null && mb_strlen($classCode) > 32) {
            $classCode = mb_substr($classCode, 0, 32);
        }
        $rows[] = [
            'sort_order' => (++$i) * 10,
            'class_name' => $label !== '' ? $label : ($code !== '' ? $code : 'Class'),
            'class_code' => $classCode,
            'price' => $price,
            'is_member_price' => 0,
            'enabled' => 1,
        ];
    }
    if (!$rows) {
        return false;
    }
    return replaceEventPricingRows($pdo, $eventId, $rows);
}

/**
 * Sync legacy events.classes_offered from event_pricing_rows (non-member, enabled rows only)
 * so existing front-end entry forms continue to work.
 */
function syncEventClassesOfferedFromPricingRows(?PDO $pdo, int $eventId): bool
{
    if (!$pdo || $eventId <= 0) {
        return false;
    }
    ensureEventPricingTables($pdo);
    $rows = fetchEventPricingRows($pdo, $eventId);
    $classes = [];
    foreach ($rows as $row) {
        if (!empty($row['is_member_price'])) {
            continue;
        }
        if (empty($row['enabled'])) {
            continue;
        }
        $code = trim((string)($row['class_code'] ?? ''));
        $label = trim((string)($row['class_name'] ?? ''));
        $price = format_price((float)($row['price'] ?? 0));
        $classes[] = [
            'code' => $code !== '' ? $code : ($label !== '' ? $label : 'Class'),
            'label' => $label !== '' ? $label : ($code !== '' ? $code : 'Class'),
            'price' => $price,
        ];
    }
    try {
        $stmt = $pdo->prepare("UPDATE events SET classes_offered = :c, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':c' => json_encode($classes, JSON_UNESCAPED_UNICODE),
            ':id' => $eventId,
        ]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Parse posted pricing rows from the event edit form.
 *
 * UX notes:
 * - Each row is a distinct price option (member/non-member).
 * - We still require at least one enabled non-member row for legacy entry forms until
 *   member-pricing selection is fully implemented on the public entry form.
 */
function parseEventPricingRowsFromPost(array $data, array &$alerts): array
{
    $rowSort = $data['event_row_sort'] ?? [];
    $rowNames = $data['event_row_class_name'] ?? [];
    $rowCodes = $data['event_row_class_code'] ?? [];
    $rowPrices = $data['event_row_price'] ?? [];
    $rowMember = $data['event_row_is_member_price'] ?? [];
    $rowJunior = $data['event_row_is_junior_ride'] ?? [];
    $rowEnabled = $data['event_row_enabled'] ?? [];

    if (!is_array($rowNames) || !is_array($rowPrices)) {
        return [];
    }
    $rows = [];
    $hasEnabledNonMember = false;

    // Use row keys rather than positional indexes so the UI can safely add/remove rows
    // and checkbox fields (which are omitted when unchecked) don't shift alignment.
    foreach (array_keys($rowNames) as $key) {
        $className = trim((string)($rowNames[$key] ?? ''));
        if ($className === '') {
            continue;
        }
        $classCode = trim((string)($rowCodes[$key] ?? ''));
        if ($classCode === '') {
            $classCode = null;
        } elseif (mb_strlen($classCode) > 32) {
            $classCode = mb_substr($classCode, 0, 32);
        }
        $sortOrder = (int)($rowSort[$key] ?? 0);
        if ($sortOrder <= 0) {
            $sortOrder = (count($rows) + 1) * 10;
        }

        $isMember = !empty($rowMember[$key]) ? 1 : 0;
        $isJuniorRide = !empty($rowJunior[$key]) ? 1 : 0;
        $enabled = !empty($rowEnabled[$key]) ? 1 : 0;
        $price = price_to_number((string)($rowPrices[$key] ?? '0'));
        if ($enabled && !$isMember) {
            $hasEnabledNonMember = true;
        }
        $rows[] = [
            'sort_order' => $sortOrder,
            'class_name' => $className,
            'class_code' => $classCode,
            'price' => $price,
            'is_member_price' => $isMember,
            'is_junior_ride' => $isJuniorRide,
            'enabled' => $enabled,
        ];
    }

    if (!$rows) {
        $alerts[] = ['type' => 'danger', 'message' => 'Add at least one pricing row.'];
        return [];
    }
    if (!$hasEnabledNonMember) {
        $alerts[] = ['type' => 'danger', 'message' => 'Enable at least one non-member price so the public entry form can offer a class choice.'];
        return [];
    }
    return $rows;
}

function findEventType(array $eventTypes, int $id = 0, string $name = ''): array
{
    $nameLower = strtolower(trim($name));
    foreach ($eventTypes as $type) {
        if ($id > 0 && (int)($type['id'] ?? 0) === $id) {
            return $type;
        }
        if ($nameLower !== '' && strtolower((string)($type['name'] ?? '')) === $nameLower) {
            return $type;
        }
    }
    return $eventTypes[0] ?? ['id' => 0, 'name' => $name ?: 'Ride', 'quick_view_fields' => []];
}

function hydrateEventTypeForRow(array $row, array $eventTypes): array
{
    $typeId = (int)($row['event_type_id'] ?? 0);
    $typeName = (string)($row['event_type_name'] ?? $row['event_type'] ?? '');
    $type = findEventType($eventTypes, $typeId, $typeName);
    $row['event_type_id'] = (int)($type['id'] ?? 0);
    $row['event_type_name'] = $type['name'] ?? ($typeName ?: 'Ride');
    return $row;
}

function defaultEvents(): array
{
    $types = defaultEventTypes();
    $typeMap = [];
    foreach ($types as $type) {
        $typeMap[strtolower((string)$type['name'])] = $type;
    }
    $rideType = $typeMap['ride'] ?? ['id' => 0, 'name' => 'Ride'];
    $awardsType = $typeMap['awards'] ?? ['id' => 0, 'name' => 'Awards'];
    $rideDate = '2026-01-18';
    $awardsDate = '2026-02-06';
    return [
        [
            'id' => 101,
            'title' => "Shane's Castle New Year Ride",
            'event_date' => $rideDate,
            'end_date' => $rideDate,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'venue' => "Shane's Castle",
            'venue_id' => 0,
            'organiser' => 'Ulster Branch Committee',
            'classes_offered' => json_encode([
                ['code' => 'PR', 'label' => 'Pleasure Ride', 'price' => '£12'],
                ['code' => 'CTR', 'label' => 'CTR', 'price' => '£15'],
            ], JSON_UNESCAPED_UNICODE),
            'entry_open_at' => date('Y-m-d H:i:s', strtotime($rideDate . ' -1 month')),
            'entry_close_at' => date('Y-m-d H:i:s', strtotime($rideDate . ' -1 week')),
            'status' => 'published',
            'description' => 'Pleasure ride only',
            'event_type_id' => $rideType['id'],
            'event_type_name' => $rideType['name'],
        ],
        [
            'id' => 102,
            'title' => 'ILDRA Dinner, Awards and AGM',
            'event_date' => $awardsDate,
            'end_date' => $awardsDate,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'venue' => 'TBC',
            'venue_id' => 0,
            'organiser' => 'Ulster Branch Committee',
            'classes_offered' => json_encode([
                ['code' => 'Awards', 'label' => 'Awards', 'price' => '—'],
            ], JSON_UNESCAPED_UNICODE),
            'entry_open_at' => date('Y-m-d H:i:s', strtotime($awardsDate . ' -1 month')),
            'entry_close_at' => date('Y-m-d H:i:s', strtotime($awardsDate . ' -1 week')),
            'status' => 'published',
            'description' => 'Applications close on 1st December.',
            'event_type_id' => $awardsType['id'],
            'event_type_name' => $awardsType['name'],
        ],
    ];
}

function ensureAdvertisingTable(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS advertising (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            image VARCHAR(255) DEFAULT NULL,
            url VARCHAR(1000) DEFAULT NULL,
            link_target VARCHAR(16) NOT NULL DEFAULT '_blank',
            start_date DATE DEFAULT NULL,
            finish_date DATE DEFAULT NULL,
            display_order INT NOT NULL DEFAULT 100,
            show_on_web TINYINT(1) NOT NULL DEFAULT 1,
            archived TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_advertising_web (show_on_web, archived, start_date, finish_date, display_order)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    if (!table_column_exists($pdo, 'advertising', 'link_target')) {
        $pdo->exec("ALTER TABLE advertising ADD COLUMN link_target VARCHAR(16) NOT NULL DEFAULT '_blank' AFTER url");
    }
}

function fetchAdvertising(?PDO $pdo, bool $webOnly = false): array
{
    if (!$pdo) return [];
    ensureAdvertisingTable($pdo);
    $sql = 'SELECT * FROM advertising';
    if ($webOnly) {
        $sql .= " WHERE show_on_web = 1 AND archived = 0
                  AND (start_date IS NULL OR start_date <= CURDATE())
                  AND (finish_date IS NULL OR finish_date >= CURDATE())";
    }
    $sql .= ' ORDER BY display_order ASC, name ASC, id ASC';
    return $pdo->query($sql)->fetchAll() ?: [];
}

function fetchAdvertisingById(?PDO $pdo, int $id): ?array
{
    if (!$pdo || $id <= 0) return null;
    ensureAdvertisingTable($pdo);
    $stmt = $pdo->prepare('SELECT * FROM advertising WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function ensureVenuesTable(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS venues (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL,
                address VARCHAR(64) DEFAULT NULL,
                postcode VARCHAR(8) DEFAULT NULL,
                google_url VARCHAR(128) DEFAULT NULL,
                directions LONGTEXT NULL,
                notes LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_venues_name (name)
            )
        ");
        if (!table_column_exists($pdo, 'venues', 'google_url')) {
            $pdo->exec("ALTER TABLE venues ADD COLUMN google_url VARCHAR(128) DEFAULT NULL");
        }
        if (!table_column_exists($pdo, 'venues', 'directions')) {
            $pdo->exec("ALTER TABLE venues ADD COLUMN directions LONGTEXT NULL");
        }
        if (!table_column_exists($pdo, 'venues', 'notes')) {
            $pdo->exec("ALTER TABLE venues ADD COLUMN notes LONGTEXT NULL");
        }
    } catch (PDOException $e) {
        // ignore; callers will handle errors
    }
}

function fetchVenues(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }
    ensureVenuesTable($pdo);
    try {
        $stmt = $pdo->query("
            SELECT id, name, address, postcode, google_url, directions, notes, created_at, updated_at
            FROM venues
            ORDER BY name ASC
        ");
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function fetchVenueById(?PDO $pdo, int $id): ?array
{
    if (!$pdo || $id <= 0) {
        return null;
    }
    ensureVenuesTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM venues WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * @return int|false
 */
function saveVenue(?PDO $pdo, array $data, array &$alerts)
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    ensureVenuesTable($pdo);

    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $name = trim((string)($data['name'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    $postcode = trim((string)($data['postcode'] ?? ''));
    $googleUrl = trim((string)($data['google_url'] ?? ''));
    $directions = trim((string)($data['directions'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));

    if ($name === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Venue name is required.'];
        return false;
    }

    // Soft length guards to avoid truncation errors if a DB already exists with shorter limits.
    $name = mb_substr($name, 0, 64);
    $address = $address !== '' ? mb_substr($address, 0, 64) : null;
    $postcode = $postcode !== '' ? mb_substr($postcode, 0, 8) : null;
    $googleUrl = $googleUrl !== '' ? mb_substr($googleUrl, 0, 128) : null;
    $directions = $directions !== '' ? $directions : null;
    $notes = $notes !== '' ? $notes : null;

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE venues
                SET name = :name, address = :address, postcode = :postcode, google_url = :google_url,
                    directions = :directions, notes = :notes, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':address' => $address,
                ':postcode' => $postcode,
                ':google_url' => $googleUrl,
                ':directions' => $directions,
                ':notes' => $notes,
                ':id' => $id,
            ]);
            return $id;
        }

        $stmt = $pdo->prepare("
            INSERT INTO venues (name, address, postcode, google_url, directions, notes, created_at, updated_at)
            VALUES (:name, :address, :postcode, :google_url, :directions, :notes, NOW(), NOW())
        ");
        $stmt->execute([
            ':name' => $name,
            ':address' => $address,
            ':postcode' => $postcode,
            ':google_url' => $googleUrl,
            ':directions' => $directions,
            ':notes' => $notes,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save venue.'];
        return false;
    }
}

function deleteVenue(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo || $id <= 0) {
        return false;
    }
    ensureVenuesTable($pdo);
    try {
        $stmt = $pdo->prepare("DELETE FROM venues WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete venue.'];
        return false;
    }
}

function hydrateVenueForRow(array $row): array
{
    $row['venue_id'] = (int)($row['venue_id'] ?? 0);
    if (empty($row['venue']) && !empty($row['venue_name'])) {
        $row['venue'] = $row['venue_name'];
    }
    return $row;
}

function fetchEvents(?PDO $pdo, bool $upcomingOnly = false): array
{
    $eventTypes = fetchEventTypes($pdo);

    if (!$pdo) {
        return array_map(fn($row) => hydrateVenueForRow(hydrateEventTypeForRow($row, $eventTypes)), defaultEvents());
    }

    $venueColumns = [];
    $venueJoin = '';
    try {
        // Preferred query includes entry_count (excluding withdrawn entries).
        // If booking_items schema is not yet migrated, fall back to a simpler query rather than default fixtures.
        ensure_bookings_tables($pdo);
        ensureVenuesTable($pdo);
        ensureEventVenueColumns($pdo);
        if (table_column_exists($pdo, 'events', 'venue_id')) {
            $venueColumns = [
                'v.name AS venue_name',
                'v.address AS venue_address',
                'v.postcode AS venue_postcode',
                'v.google_url AS venue_google_url',
                'v.directions AS venue_directions',
                'v.notes AS venue_notes',
            ];
            $venueJoin = "LEFT JOIN venues v ON v.id = e.venue_id";
        }
        $columns = array_merge(
            ['e.*', 'et.name AS event_type_name', 'et.quick_view_fields'],
            $venueColumns,
            [
                "(SELECT COUNT(*) FROM booking_items bi WHERE bi.event_id = e.id AND COALESCE(bi.is_withdrawn, 0) = 0) AS entry_count",
            ]
        );
        $sql = "
            SELECT " . implode(",\n                ", $columns) . "
            FROM events e
            LEFT JOIN event_types et ON e.event_type_id = et.id
            {$venueJoin}
        ";
        $params = [];
        if ($upcomingOnly) {
            $sql .= " WHERE e.event_date >= CURDATE()";
        }
        $sql .= " ORDER BY e.event_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map(fn($row) => hydrateVenueForRow(hydrateEventTypeForRow($row, $eventTypes)), $rows ?: []);
    } catch (PDOException $e) {
        // Fall back to events without entry_count instead of returning default fixtures (which masks real DB issues).
        try {
            $columns = array_merge(['e.*', 'et.name AS event_type_name', 'et.quick_view_fields'], $venueColumns);
            $sql = "
                SELECT " . implode(",\n                       ", $columns) . "
                FROM events e
                LEFT JOIN event_types et ON e.event_type_id = et.id
                {$venueJoin}
            ";
            $params = [];
            if ($upcomingOnly) {
                $sql .= " WHERE e.event_date >= CURDATE()";
            }
            $sql .= " ORDER BY e.event_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return array_map(fn($row) => hydrateVenueForRow(hydrateEventTypeForRow($row, $eventTypes)), $rows ?: []);
        } catch (PDOException $e2) {
            return array_map(fn($row) => hydrateVenueForRow(hydrateEventTypeForRow($row, $eventTypes)), defaultEvents());
        }
    }
}

function fetchEventById(?PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    // When no database connection exists, fall back to static fixtures
    if (!$pdo) {
        $eventTypes = defaultEventTypes();
        foreach (defaultEvents() as $event) {
            if ((int)($event['id'] ?? 0) === $id) {
                return hydrateVenueForRow(hydrateEventTypeForRow($event, $eventTypes));
            }
        }
        return null;
    }
    $venueColumns = [];
    $venueJoin = '';
    try {
        // Preferred query includes entry_count (excluding withdrawn entries).
        // If booking_items schema is not yet migrated, fall back to a simpler query rather than returning null.
        ensure_bookings_tables($pdo);
        ensureVenuesTable($pdo);
        ensureEventVenueColumns($pdo);
        ensureEventOrganiserUserColumn($pdo);
        $venueColumns = [];
        $venueJoin = '';
        if (table_column_exists($pdo, 'events', 'venue_id')) {
            $venueColumns = [
                'v.name AS venue_name',
                'v.address AS venue_address',
                'v.postcode AS venue_postcode',
                'v.google_url AS venue_google_url',
                'v.directions AS venue_directions',
                'v.notes AS venue_notes',
            ];
            $venueJoin = "LEFT JOIN venues v ON v.id = e.venue_id";
        }
        $columns = array_merge(
            ['e.*', 'et.name AS event_type_name', 'et.quick_view_fields', 'ou.email AS organiser_email', 'ou.first_name AS organiser_first_name', 'ou.last_name AS organiser_last_name'],
            $venueColumns,
            [
                "(SELECT COUNT(*) FROM booking_items bi WHERE bi.event_id = e.id AND COALESCE(bi.is_withdrawn, 0) = 0) AS entry_count",
            ]
        );
        $sql = "
            SELECT " . implode(",\n                ", $columns) . "
            FROM events e
            LEFT JOIN event_types et ON e.event_type_id = et.id
            LEFT JOIN users ou ON ou.id = e.organiser_user_id
            {$venueJoin}
            WHERE e.id = :id
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $eventTypes = fetchEventTypes($pdo);
        return hydrateVenueForRow(hydrateEventTypeForRow($row, $eventTypes));
    } catch (PDOException $e) {
        try {
            $columns = array_merge(['e.*', 'et.name AS event_type_name', 'et.quick_view_fields'], $venueColumns ?? []);
            $sql = "
                SELECT " . implode(",\n                    ", $columns) . "
                FROM events e
                LEFT JOIN event_types et ON e.event_type_id = et.id
                {$venueJoin}
                WHERE e.id = :id
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $eventTypes = fetchEventTypes($pdo);
            return hydrateVenueForRow(hydrateEventTypeForRow($row, $eventTypes));
        } catch (PDOException $e2) {
            return null;
        }
    }
}

function fetchMembershipTypes(?PDO $pdo, bool $publishedOnly = false): array
{
    if (!$pdo) {
        return defaultMembershipTypes();
    }

    try {
        ensureMembershipTypesTable($pdo);
        $sql = "SELECT * FROM membership_types";
        if ($publishedOnly) {
            $sql .= " WHERE status = 'published'";
        }
        $sql .= " ORDER BY membership_ends DESC, sale_starts ASC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return defaultMembershipTypes();
    }
}

function fetchMembershipTypeById(?PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    if (!$pdo) {
        foreach (defaultMembershipTypes() as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM membership_types WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function saveMembershipType(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    $id = isset($data['membership_type_id']) ? (int)$data['membership_type_id'] : 0;
    $name = trim((string)($data['name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $saleStarts = trim((string)($data['sale_starts'] ?? ''));
    $saleEnds = trim((string)($data['sale_ends'] ?? ''));
    $memberStarts = trim((string)($data['membership_starts'] ?? ''));
    $memberEnds = trim((string)($data['membership_ends'] ?? ''));
    $cost = trim((string)($data['cost'] ?? '0'));
    $type = trim((string)($data['type'] ?? 'senior'));
    $status = $data['status'] ?? 'draft';

    if ($name === '' || $cost === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Name and cost are required.'];
        return false;
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        $status = 'draft';
    }

    if (!in_array($type, ['junior', 'senior'], true)) {
        $type = 'senior';
    }

    try {
        ensureMembershipTypesTable($pdo);

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE membership_types SET
                    name = :name,
                    description = :description,
                    sale_starts = :sale_starts,
                    sale_ends = :sale_ends,
                    membership_starts = :membership_starts,
                    membership_ends = :membership_ends,
                    cost = :cost,
                    type = :type,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':sale_starts' => $saleStarts ?: null,
                ':sale_ends' => $saleEnds ?: null,
                ':membership_starts' => $memberStarts ?: null,
                ':membership_ends' => $memberEnds ?: null,
                ':cost' => $cost,
                ':type' => $type ?: 'standard',
                ':status' => $status,
                ':id' => $id,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO membership_types (name, description, sale_starts, sale_ends, membership_starts, membership_ends, cost, type, status, created_at, updated_at)
                VALUES (:name, :description, :sale_starts, :sale_ends, :membership_starts, :membership_ends, :cost, :type, :status, NOW(), NOW())
            ");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':sale_starts' => $saleStarts ?: null,
                ':sale_ends' => $saleEnds ?: null,
                ':membership_starts' => $memberStarts ?: null,
                ':membership_ends' => $memberEnds ?: null,
                ':cost' => $cost,
                ':type' => $type ?: 'standard',
                ':status' => $status,
            ]);
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save membership type.'];
        return false;
    }
}

function ensureMembershipTypesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS membership_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            description TEXT,
            sale_starts DATE DEFAULT NULL,
            sale_ends DATE DEFAULT NULL,
            membership_starts DATE DEFAULT NULL,
            membership_ends DATE DEFAULT NULL,
            cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                type VARCHAR(80) NOT NULL DEFAULT 'senior',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    try {
        $hasYear = $pdo->query("SHOW COLUMNS FROM membership_types LIKE 'membership_year'")->fetchColumn();
        if ($hasYear) {
            $pdo->exec("ALTER TABLE membership_types DROP COLUMN membership_year");
        }
    } catch (PDOException $e) {
        // ignore
    }
}

function deleteMembershipType(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM membership_types WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete membership type.'];
        return false;
    }
}

function fetchMemberships(?PDO $pdo): array
{
    if (!$pdo) {
        return defaultMemberships();
    }
    try {
        ensureMembershipTables($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS membership_purchases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchased_by_user_id INT UNSIGNED DEFAULT NULL,
                member_id INT UNSIGNED DEFAULT NULL,
                membership_type_id INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                starts_at DATE DEFAULT NULL,
                ends_at DATE DEFAULT NULL,
                purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (membership_type_id),
                INDEX (purchased_by_user_id),
                INDEX (member_id)
            )
        ");
        $sql = "
            SELECT
                mp.*,
                mt.name AS membership_name,
                u.email AS user_email,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
                m.member_number AS member_number,
                TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))) AS member_name,
                m.dob AS member_dob
            FROM membership_purchases mp
            LEFT JOIN membership_types mt ON mp.membership_type_id = mt.id
            LEFT JOIN users u ON mp.purchased_by_user_id = u.id
            LEFT JOIN people m ON mp.member_id = m.id
            ORDER BY mp.purchased_at DESC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['status'] = membership_status_for_row($row);
        }
        return $rows;
    } catch (PDOException $e) {
        return defaultMemberships();
    }
}

function membership_status_for_row(array $row): string
{
    $startsAt = trim((string)($row['starts_at'] ?? ''));
    $endsAt = trim((string)($row['ends_at'] ?? ''));
    $statusRaw = strtolower(trim((string)($row['status'] ?? '')));
    $statusRaw = in_array($statusRaw, ['active', 'pending', 'expired'], true) ? $statusRaw : 'active';

    if ($startsAt !== '' || $endsAt !== '') {
        try {
            $today = new DateTimeImmutable('today');
            if ($startsAt !== '') {
                $startDt = new DateTimeImmutable($startsAt);
                if ($today < $startDt) {
                    return 'pending';
                }
            }
            if ($endsAt !== '') {
                $endDt = new DateTimeImmutable($endsAt);
                if ($today > $endDt) {
                    return 'expired';
                }
            }
            return 'active';
        } catch (Exception $e) {
            // Fall through to stored status on invalid dates.
        }
    }
    return $statusRaw;
}

function saveMembershipPurchase(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    $typeId = (int)($data['membership_type_id'] ?? 0);
    $purchasedByUserId = isset($data['purchased_by_user_id']) ? (int)$data['purchased_by_user_id'] : null;
    $memberId = isset($data['member_id']) ? (int)$data['member_id'] : null;
    $amount = trim((string)($data['amount'] ?? '0'));
    $startsAt = trim((string)($data['starts_at'] ?? ''));
    $endsAt = trim((string)($data['ends_at'] ?? ''));
    $status = $data['status'] ?? 'active';

    if ($typeId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Membership type is required.'];
        return false;
    }
    if (!in_array($status, ['active', 'expired', 'pending'], true)) {
        $status = 'active';
    }

    try {
        ensureMembershipTables($pdo);
        if ($memberId && $memberId > 0) {
            // Assign membership number only once a membership purchase exists for this person.
            $assigned = assignMemberNumberIfNeeded($pdo, (int)$memberId, $alerts);
            if (!$assigned && $alerts) {
                return false;
            }
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS membership_purchases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchased_by_user_id INT UNSIGNED DEFAULT NULL,
                member_id INT UNSIGNED DEFAULT NULL,
                membership_type_id INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                starts_at DATE DEFAULT NULL,
                ends_at DATE DEFAULT NULL,
                purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (membership_type_id),
                INDEX (purchased_by_user_id),
                INDEX (member_id)
            )
        ");
        $stmt = $pdo->prepare("
            INSERT INTO membership_purchases (purchased_by_user_id, member_id, membership_type_id, amount, status, starts_at, ends_at, purchased_at, created_at, updated_at)
            VALUES (:purchased_by_user_id, :member_id, :membership_type_id, :amount, :status, :starts_at, :ends_at, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            ':purchased_by_user_id' => $purchasedByUserId ?: null,
            ':member_id' => $memberId ?: null,
            ':membership_type_id' => $typeId,
            ':amount' => $amount,
            ':status' => $status,
            ':starts_at' => $startsAt ?: null,
            ':ends_at' => $endsAt ?: null,
        ]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save membership purchase.'];
        return false;
    }
}

function ensureMembershipTables(PDO $pdo): void
{
    ensureSiteSettingsTable($pdo);
    // People (previously called "members").
    // A person can exist without a membership number; numbers are assigned only once a membership exists.
    // Option B: rename table from `members` → `people` (with best-effort automatic migration).
    try {
        $hasPeople = (bool)($pdo->query("SHOW TABLES LIKE 'people'")->fetchColumn());
        $hasMembers = (bool)($pdo->query("SHOW TABLES LIKE 'members'")->fetchColumn());
        if (!$hasPeople && $hasMembers) {
            // Preferred migration: rename the existing table so IDs remain stable (membership_purchases.member_id stays valid).
            $pdo->exec("RENAME TABLE members TO people");
            $hasPeople = true;
        }
    } catch (PDOException $e) {
        // ignore and fall back to creating `people`.
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS people (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            member_number INT UNSIGNED NULL,
            first_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(120) NOT NULL,
            dob DATE NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(60) NULL,
            address TEXT NULL,
            postcode VARCHAR(40) NULL,
            junior_or_senior VARCHAR(20) NULL,
            emergency_contact_name VARCHAR(255) NULL,
            emergency_contact_phone VARCHAR(60) NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_number (member_number),
            INDEX (owner_user_id),
            INDEX (is_archived)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");

    // Normalise schema for upgraded installs (best-effort; safe if already applied).
    try {
        $pdo->exec("ALTER TABLE people MODIFY member_number INT UNSIGNED NULL");
    } catch (PDOException $e) {
        // ignore
    }
    $maybeAddCols = [
        "ALTER TABLE people ADD COLUMN email VARCHAR(255) NULL",
        "ALTER TABLE people ADD COLUMN phone VARCHAR(60) NULL",
        "ALTER TABLE people ADD COLUMN address TEXT NULL",
        "ALTER TABLE people ADD COLUMN postcode VARCHAR(40) NULL",
        "ALTER TABLE people ADD COLUMN junior_or_senior VARCHAR(20) NULL",
        "ALTER TABLE people ADD COLUMN emergency_contact_name VARCHAR(255) NULL",
        "ALTER TABLE people ADD COLUMN emergency_contact_phone VARCHAR(60) NULL",
        "ALTER TABLE people ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($maybeAddCols as $sql) {
        try {
            if (preg_match('/ADD COLUMN ([a-z_]+)/i', $sql, $m)) {
                $col = strtolower($m[1]);
                if ($col !== '' && table_column_exists($pdo, 'people', $col)) {
                    continue;
                }
            }
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // ignore
        }
    }
    // Migrate legacy emergency_contact column into the new named column if present, then drop the old column.
    if (table_column_exists($pdo, 'people', 'emergency_contact')) {
        try {
            if (table_column_exists($pdo, 'people', 'emergency_contact_name')) {
                $pdo->exec("
                    UPDATE people
                    SET emergency_contact_name = COALESCE(emergency_contact_name, emergency_contact)
                    WHERE emergency_contact IS NOT NULL AND emergency_contact_name IS NULL
                ");
            }
            $pdo->exec("ALTER TABLE people DROP COLUMN emergency_contact");
        } catch (PDOException $e) {
            // ignore
        }
    }
    try {
        $pdo->exec("ALTER TABLE people MODIFY dob DATE NULL");
    } catch (PDOException $e) {
        // ignore
    }
    if (!table_index_on_column_exists($pdo, 'people', 'is_archived')) {
        try {
            if (table_index_count($pdo, 'people') < 64) {
                $pdo->exec("ALTER TABLE people ADD INDEX idx_people_is_archived (is_archived)");
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    // If both tables exist (or rename failed), keep them in sync once to preserve data.
    // This only runs when `people` is empty to avoid overwriting manual changes.
    try {
        $hasMembers = (bool)($pdo->query("SHOW TABLES LIKE 'members'")->fetchColumn());
        if ($hasMembers) {
            $peopleCount = (int)($pdo->query("SELECT COUNT(*) FROM people")->fetchColumn() ?: 0);
            if ($peopleCount === 0) {
                $pdo->exec("
                    INSERT INTO people (id, owner_user_id, member_number, first_name, last_name, dob, created_at, updated_at)
                    SELECT id, owner_user_id, member_number, first_name, last_name, dob, created_at, updated_at
                    FROM members
                ");
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    // Seed global membership number counter (burned forever). Range: 1000–3000.
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'next_member_number' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val === false) {
        $seed = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('next_member_number', '1000', NOW())");
        $seed->execute();
    }

    // Backwards-compat: migrate membership_purchases.user_id → purchased_by_user_id if needed.
    if (!table_column_exists($pdo, 'membership_purchases', 'purchased_by_user_id')) {
        try {
            $pdo->exec("ALTER TABLE membership_purchases ADD COLUMN purchased_by_user_id INT UNSIGNED DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'membership_purchases', 'member_id')) {
        try {
            $pdo->exec("ALTER TABLE membership_purchases ADD COLUMN member_id INT UNSIGNED DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_index_on_column_exists($pdo, 'membership_purchases', 'purchased_by_user_id')) {
        try {
            if (table_index_count($pdo, 'membership_purchases') < 64) {
                $pdo->exec("ALTER TABLE membership_purchases ADD INDEX idx_membership_purchases_purchaser (purchased_by_user_id)");
            }
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_index_on_column_exists($pdo, 'membership_purchases', 'member_id')) {
        try {
            if (table_index_count($pdo, 'membership_purchases') < 64) {
                $pdo->exec("ALTER TABLE membership_purchases ADD INDEX idx_membership_purchases_member (member_id)");
            }
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (table_column_exists($pdo, 'membership_purchases', 'user_id')) {
        try {
            $pdo->exec("UPDATE membership_purchases SET purchased_by_user_id = COALESCE(purchased_by_user_id, user_id) WHERE purchased_by_user_id IS NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
}

function ensureHorsesTables(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS horses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            dob DATE NULL,
            year_of_birth VARCHAR(10) NULL,
            breed VARCHAR(120) NULL,
            colour VARCHAR(120) NULL,
            qualification_id INT UNSIGNED NULL,
            passport_issuer VARCHAR(32) NULL,
            passport_number VARCHAR(32) NULL,
            sex VARCHAR(20) NULL,
            height_cm INT UNSIGNED NULL,
            flu_vac_date DATE NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (owner_user_id),
            INDEX (is_archived)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    // Qualification lookup
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS horse_qualifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            UNIQUE KEY uniq_name (name)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM horse_qualifications")->fetchColumn();
        if ($count === 0) {
            $seed = $pdo->prepare("INSERT INTO horse_qualifications (name) VALUES ('Bronze'), ('Silver'), ('Gold')");
            $seed->execute();
        }
    } catch (PDOException $e) {
        // ignore
    }
    // Backfill columns if missing on upgraded installs.
    $maybeAddCols = [
        "ALTER TABLE horses ADD COLUMN qualification_id INT UNSIGNED NULL",
        "ALTER TABLE horses ADD COLUMN passport_issuer VARCHAR(32) NULL",
        "ALTER TABLE horses ADD COLUMN passport_number VARCHAR(32) NULL",
        "ALTER TABLE horses ADD COLUMN sex VARCHAR(20) NULL",
        "ALTER TABLE horses ADD COLUMN height_cm INT UNSIGNED NULL",
        "ALTER TABLE horses ADD COLUMN flu_vac_date DATE NULL",
    ];
    foreach ($maybeAddCols as $sql) {
        try {
            if (preg_match('/ADD COLUMN ([a-z_]+)/i', $sql, $m)) {
                $col = strtolower($m[1]);
                if ($col !== '' && table_column_exists($pdo, 'horses', $col)) {
                    continue;
                }
            }
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // ignore
        }
    }
}

function ensureShareTables(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    ensureMembershipTables($pdo);
    ensureHorsesTables($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS share_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(20) NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NOT NULL,
            target_user_id INT UNSIGNED NULL,
            target_email VARCHAR(255) NULL,
            code VARCHAR(40) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NULL,
            accepted_at DATETIME NULL,
            accepted_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_share_code (code),
            INDEX idx_share_entity (entity_type, entity_id),
            INDEX idx_share_creator (created_by_user_id),
            INDEX idx_share_target (target_user_id),
            INDEX idx_share_status (status)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_person_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            person_id INT UNSIGNED NOT NULL,
            permission VARCHAR(40) NOT NULL DEFAULT 'select_only',
            status VARCHAR(20) NOT NULL DEFAULT 'approved',
            created_from_request_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            revoked_by_user_id INT UNSIGNED NULL,
            UNIQUE KEY uniq_user_person (user_id, person_id),
            INDEX idx_person_link (person_id),
            INDEX idx_person_link_status (status)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_horse_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            horse_id INT UNSIGNED NOT NULL,
            permission VARCHAR(40) NOT NULL DEFAULT 'select_only',
            status VARCHAR(20) NOT NULL DEFAULT 'approved',
            created_from_request_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            revoked_by_user_id INT UNSIGNED NULL,
            UNIQUE KEY uniq_user_horse (user_id, horse_id),
            INDEX idx_horse_link (horse_id),
            INDEX idx_horse_link_status (status)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
}

function fetchMembersForUser(?PDO $pdo, int $ownerUserId, bool $includeArchived = false): array
{
    if (!$pdo || $ownerUserId <= 0) {
        return [];
    }
    ensureShareTables($pdo);
    // Hide archived people from standard user pickers by default.
    $ownedWhere = $includeArchived ? '' : ' AND is_archived = 0';
    $stmt = $pdo->prepare("
        SELECT
            id,
            owner_user_id,
            member_number,
            first_name,
            last_name,
            dob,
            email,
            phone,
            address,
            postcode,
            junior_or_senior,
            emergency_contact_name,
            emergency_contact_phone,
            is_archived,
            0 AS is_linked,
            'owner' AS link_permission
        FROM people
        WHERE owner_user_id = :uid{$ownedWhere}
        ORDER BY last_name ASC, first_name ASC, id ASC
    ");
    $stmt->execute([':uid' => $ownerUserId]);
    $rows = $stmt->fetchAll() ?: [];

    $linkedWhere = $includeArchived ? '' : ' AND p.is_archived = 0';
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.owner_user_id,
            p.member_number,
            p.first_name,
            p.last_name,
            NULL AS dob,
            p.email,
            NULL AS phone,
            NULL AS address,
            NULL AS postcode,
            p.junior_or_senior,
            NULL AS emergency_contact_name,
            NULL AS emergency_contact_phone,
            p.is_archived,
            1 AS is_linked,
            upl.permission AS link_permission
        FROM user_person_links upl
        JOIN people p ON p.id = upl.person_id
        WHERE upl.user_id = :uid
          AND upl.status = 'approved'
          AND upl.revoked_at IS NULL
          AND p.owner_user_id <> :uid
          {$linkedWhere}
        ORDER BY p.last_name ASC, p.first_name ASC, p.id ASC
    ");
    $stmt->execute([':uid' => $ownerUserId]);
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $rows[] = $row;
    }
    return $rows;
}

function fetchHorsesForUser(?PDO $pdo, int $ownerUserId, bool $includeArchived = false): array
{
    if (!$pdo || $ownerUserId <= 0) {
        return [];
    }
    ensureShareTables($pdo);
    $where = $includeArchived ? '' : ' AND is_archived = 0';
    $stmt = $pdo->prepare("
        SELECT id, owner_user_id, name, dob, year_of_birth, breed, colour, qualification_id, passport_issuer, passport_number, sex, height_cm, flu_vac_date, is_archived, 0 AS is_linked, 'owner' AS link_permission
        FROM horses
        WHERE owner_user_id = :uid
          AND id <> 1{$where}
        ORDER BY name ASC, id ASC
    ");
    $stmt->execute([':uid' => $ownerUserId]);
    $rows = $stmt->fetchAll() ?: [];

    $linkedWhere = $includeArchived ? '' : ' AND h.is_archived = 0';
    $stmt = $pdo->prepare("
        SELECT
            h.id,
            h.owner_user_id,
            h.name,
            NULL AS dob,
            NULL AS year_of_birth,
            NULL AS breed,
            NULL AS colour,
            h.qualification_id,
            NULL AS passport_issuer,
            NULL AS passport_number,
            NULL AS sex,
            NULL AS height_cm,
            NULL AS flu_vac_date,
            h.is_archived,
            1 AS is_linked,
            uhl.permission AS link_permission
        FROM user_horse_links uhl
        JOIN horses h ON h.id = uhl.horse_id
        WHERE uhl.user_id = :uid
          AND uhl.status = 'approved'
          AND uhl.revoked_at IS NULL
          AND h.owner_user_id <> :uid
          AND h.id <> 1
          {$linkedWhere}
        ORDER BY h.name ASC, h.id ASC
    ");
    $stmt->execute([':uid' => $ownerUserId]);
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $rows[] = $row;
    }
    return $rows;
}

function fetchPersonForUserById(?PDO $pdo, int $ownerUserId, int $personId): ?array
{
    if (!$pdo || $ownerUserId <= 0 || $personId <= 0) {
        return null;
    }
    ensureShareTables($pdo);
    $stmt = $pdo->prepare("
        SELECT
            id,
            owner_user_id,
            member_number,
            first_name,
            last_name,
            dob,
            email,
            phone,
            address,
            postcode,
            junior_or_senior,
            emergency_contact_name,
            emergency_contact_phone,
            is_archived,
            0 AS is_linked,
            'owner' AS link_permission
        FROM people
        WHERE id = :id AND owner_user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':id' => $personId, ':uid' => $ownerUserId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.owner_user_id,
            p.member_number,
            p.first_name,
            p.last_name,
            NULL AS dob,
            p.email,
            NULL AS phone,
            NULL AS address,
            NULL AS postcode,
            p.junior_or_senior,
            NULL AS emergency_contact_name,
            NULL AS emergency_contact_phone,
            p.is_archived,
            1 AS is_linked,
            upl.permission AS link_permission
        FROM user_person_links upl
        JOIN people p ON p.id = upl.person_id
        WHERE upl.user_id = :uid
          AND upl.person_id = :id
          AND upl.status = 'approved'
          AND upl.revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $personId, ':uid' => $ownerUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchHorseForUserById(?PDO $pdo, int $ownerUserId, int $horseId): ?array
{
    if (!$pdo || $ownerUserId <= 0 || $horseId <= 0) {
        return null;
    }
    ensureShareTables($pdo);
    $stmt = $pdo->prepare("
        SELECT id, owner_user_id, name, dob, year_of_birth, breed, colour, qualification_id, passport_issuer, passport_number, sex, height_cm, flu_vac_date, is_archived, 0 AS is_linked, 'owner' AS link_permission
        FROM horses
        WHERE id = :id AND owner_user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':id' => $horseId, ':uid' => $ownerUserId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare("
        SELECT
            h.id,
            h.owner_user_id,
            h.name,
            NULL AS dob,
            NULL AS year_of_birth,
            NULL AS breed,
            NULL AS colour,
            h.qualification_id,
            NULL AS passport_issuer,
            NULL AS passport_number,
            NULL AS sex,
            NULL AS height_cm,
            NULL AS flu_vac_date,
            h.is_archived,
            1 AS is_linked,
            uhl.permission AS link_permission
        FROM user_horse_links uhl
        JOIN horses h ON h.id = uhl.horse_id
        WHERE uhl.user_id = :uid
          AND uhl.horse_id = :id
          AND uhl.status = 'approved'
          AND uhl.revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $horseId, ':uid' => $ownerUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function savePersonForUser(?PDO $pdo, int $ownerUserId, array $data, array &$alerts, ?int $personId = null): ?int
{
    if (!$pdo || $ownerUserId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Login required.'];
        return null;
    }
    ensureMembershipTables($pdo);

    $firstName = trim((string)($data['first_name'] ?? ''));
    $lastName = trim((string)($data['last_name'] ?? ''));
    $dob = trim((string)($data['dob'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    $postcode = trim((string)($data['postcode'] ?? ''));
    $juniorSenior = trim((string)($data['junior_or_senior'] ?? ''));
    $emergencyName = trim((string)($data['emergency_contact_name'] ?? ''));
    $emergencyPhone = trim((string)($data['emergency_contact_phone'] ?? ''));
    if (!in_array($juniorSenior, ['Junior', 'Senior'], true)) {
        $juniorSenior = '';
    }
    $requireContactDetails = !empty($data['require_contact_details']);

    if ($firstName === '' || $lastName === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'First name and last name are required.'];
        return null;
    }
    $dobValue = null;
    $dobDate = null;
    if ($dob !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
        if (!$dt) {
            $alerts[] = ['type' => 'danger', 'message' => 'Date of birth must be in YYYY-MM-DD format.'];
            return null;
        }
        $dobValue = $dt->format('Y-m-d');
        $dobDate = $dt;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Email must be a valid address.'];
        return null;
    }
    if ($requireContactDetails) {
        if ($address === '' || $postcode === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Address and postcode are required.'];
            return null;
        }
        if ($phone === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Phone number is required.'];
            return null;
        }
        if ($emergencyName === '' || $emergencyPhone === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Emergency contact name and phone are required.'];
            return null;
        }
    }
    if ($juniorSenior === 'Junior' && $dobValue === null) {
        $alerts[] = ['type' => 'danger', 'message' => 'Date of birth is required for juniors.'];
        return null;
    }
    if ($dobDate) {
        $cutoff = new DateTimeImmutable(date('Y') . '-01-01');
        $age = $dobDate->diff($cutoff)->y;
        if ($age < 18) {
            $juniorSenior = 'Junior';
        }
    }

    try {
        if ($personId && $personId > 0) {
            $stmt = $pdo->prepare("
                UPDATE people
                SET first_name = :first_name,
                    last_name = :last_name,
                    dob = :dob,
                    email = :email,
                    phone = :phone,
                    address = :address,
                    postcode = :postcode,
                    junior_or_senior = :junior_senior,
                    emergency_contact_name = :emergency_name,
                    emergency_contact_phone = :emergency_phone,
                    updated_at = NOW()
                WHERE id = :id AND owner_user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':dob' => $dobValue,
                ':email' => $email !== '' ? $email : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':address' => $address !== '' ? $address : null,
                ':postcode' => $postcode !== '' ? $postcode : null,
                ':junior_senior' => $juniorSenior !== '' ? $juniorSenior : null,
                ':emergency_name' => $emergencyName !== '' ? $emergencyName : null,
                ':emergency_phone' => $emergencyPhone !== '' ? $emergencyPhone : null,
                ':id' => $personId,
                ':uid' => $ownerUserId,
            ]);
            return $personId;
        }

        $stmt = $pdo->prepare("
            INSERT INTO people (owner_user_id, member_number, first_name, last_name, dob, email, phone, address, postcode, junior_or_senior, emergency_contact_name, emergency_contact_phone, is_archived, created_at, updated_at)
            VALUES (:uid, NULL, :first_name, :last_name, :dob, :email, :phone, :address, :postcode, :junior_senior, :emergency_name, :emergency_phone, 0, NOW(), NOW())
        ");
        $stmt->execute([
            ':uid' => $ownerUserId,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':dob' => $dobValue,
            ':email' => $email !== '' ? $email : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':address' => $address !== '' ? $address : null,
            ':postcode' => $postcode !== '' ? $postcode : null,
            ':junior_senior' => $juniorSenior !== '' ? $juniorSenior : null,
            ':emergency_name' => $emergencyName !== '' ? $emergencyName : null,
            ':emergency_phone' => $emergencyPhone !== '' ? $emergencyPhone : null,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save person.'];
        return null;
    }
}

function archivePersonForUser(?PDO $pdo, int $ownerUserId, int $personId, array &$alerts): bool
{
    if (!$pdo || $ownerUserId <= 0 || $personId <= 0) {
        return false;
    }
    ensureMembershipTables($pdo);
    try {
        $stmt = $pdo->prepare("UPDATE people SET is_archived = 1, updated_at = NOW() WHERE id = :id AND owner_user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $personId, ':uid' => $ownerUserId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not archive person.'];
        return false;
    }
}

function saveHorseForUser(?PDO $pdo, int $ownerUserId, array $data, array &$alerts, ?int $horseId = null): ?int
{
    if (!$pdo || $ownerUserId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Login required.'];
        return null;
    }
    ensureHorsesTables($pdo);

    $name = trim((string)($data['name'] ?? ''));
    $dob = trim((string)($data['dob'] ?? ''));
    $yearOfBirth = trim((string)($data['year_of_birth'] ?? ''));
    $breed = trim((string)($data['breed'] ?? ''));
    $colour = trim((string)($data['colour'] ?? ''));
    $qualificationId = isset($data['qualification_id']) ? (int)$data['qualification_id'] : null;
    $passportIssuer = trim((string)($data['passport_issuer'] ?? ''));
    $passportNumber = trim((string)($data['passport_number'] ?? ''));
    $sex = trim((string)($data['sex'] ?? ''));
    $height = trim((string)($data['height_cm'] ?? ''));
    $fluVacDate = trim((string)($data['flu_vac_date'] ?? ''));

    if ($name === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Horse name is required.'];
        return null;
    }
    $dobValue = null;
    if ($dob !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
        if (!$dt) {
            $alerts[] = ['type' => 'danger', 'message' => 'Horse date of birth must be in YYYY-MM-DD format.'];
            return null;
        }
        $dobValue = $dt->format('Y-m-d');
    }

    $allowedSexes = ['Mare', 'Gelding', 'Stallion'];
    if ($sex !== '' && !in_array($sex, $allowedSexes, true)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Sex must be Mare, Gelding, or Stallion.'];
        return null;
    }
    $heightValue = null;
    if ($height !== '') {
        $heightValue = (int)$height;
        if ($heightValue <= 0) {
            $alerts[] = ['type' => 'danger', 'message' => 'Height must be a positive number (cm).'];
            return null;
        }
    }
    $fluVacValue = null;
    if ($fluVacDate !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fluVacDate);
        if (!$dt) {
            $alerts[] = ['type' => 'danger', 'message' => 'Flu vac date must be in YYYY-MM-DD format.'];
            return null;
        }
        $fluVacValue = $dt->format('Y-m-d');
    }

    try {
        if ($horseId && $horseId > 0) {
            $stmt = $pdo->prepare("
                UPDATE horses
                SET name = :name,
                    dob = :dob,
                    year_of_birth = :yob,
                    breed = :breed,
                    colour = :colour,
                    qualification_id = :qualification_id,
                    passport_issuer = :passport_issuer,
                    passport_number = :passport_number,
                    sex = :sex,
                    height_cm = :height_cm,
                    flu_vac_date = :flu_vac_date,
                    updated_at = NOW()
                WHERE id = :id AND owner_user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $name,
                ':dob' => $dobValue,
                ':yob' => $yearOfBirth !== '' ? $yearOfBirth : null,
                ':breed' => $breed !== '' ? $breed : null,
                ':colour' => $colour !== '' ? $colour : null,
                ':qualification_id' => $qualificationId ?: null,
                ':passport_issuer' => $passportIssuer !== '' ? $passportIssuer : null,
                ':passport_number' => $passportNumber !== '' ? $passportNumber : null,
                ':sex' => $sex !== '' ? $sex : null,
                ':height_cm' => $heightValue,
                ':flu_vac_date' => $fluVacValue,
                ':id' => $horseId,
                ':uid' => $ownerUserId,
            ]);
            return $horseId;
        }

        $stmt = $pdo->prepare("
            INSERT INTO horses (owner_user_id, name, dob, year_of_birth, breed, colour, qualification_id, passport_issuer, passport_number, sex, height_cm, flu_vac_date, is_archived, created_at, updated_at)
            VALUES (:uid, :name, :dob, :yob, :breed, :colour, :qualification_id, :passport_issuer, :passport_number, :sex, :height_cm, :flu_vac_date, 0, NOW(), NOW())
        ");
        $stmt->execute([
            ':uid' => $ownerUserId,
            ':name' => $name,
            ':dob' => $dobValue,
            ':yob' => $yearOfBirth !== '' ? $yearOfBirth : null,
            ':breed' => $breed !== '' ? $breed : null,
            ':colour' => $colour !== '' ? $colour : null,
            ':qualification_id' => $qualificationId ?: null,
            ':passport_issuer' => $passportIssuer !== '' ? $passportIssuer : null,
            ':passport_number' => $passportNumber !== '' ? $passportNumber : null,
            ':sex' => $sex !== '' ? $sex : null,
            ':height_cm' => $heightValue,
            ':flu_vac_date' => $fluVacValue,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save horse.'];
        return null;
    }
}

function archiveHorseForUser(?PDO $pdo, int $ownerUserId, int $horseId, array &$alerts): bool
{
    if (!$pdo || $ownerUserId <= 0 || $horseId <= 0) {
        return false;
    }
    ensureHorsesTables($pdo);
    try {
        $stmt = $pdo->prepare("UPDATE horses SET is_archived = 1, updated_at = NOW() WHERE id = :id AND owner_user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $horseId, ':uid' => $ownerUserId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not archive horse.'];
        return false;
    }
}

function shareEntityLabel(?PDO $pdo, string $entityType, int $entityId): string
{
    if (!$pdo || $entityId <= 0) {
        return ucfirst($entityType);
    }
    if ($entityType === 'person') {
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM people WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch();
        $label = $row ? trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) : '';
        return $label !== '' ? $label : 'Person #' . $entityId;
    }
    if ($entityType === 'horse') {
        $stmt = $pdo->prepare("SELECT name FROM horses WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $entityId]);
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        return $name !== '' ? $name : 'Horse #' . $entityId;
    }
    return ucfirst($entityType) . ' #' . $entityId;
}

function userDisplayName(array $user): string
{
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return $name !== '' ? $name : (string)($user['email'] ?? 'A user');
}

function personRecordType(array $person, array $currentUser = []): string
{
    if (!empty($person['is_linked'])) {
        return 'linked';
    }
    $personEmail = strtolower(trim((string)($person['email'] ?? '')));
    $userEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
    if ($personEmail !== '' && $userEmail !== '' && $personEmail === $userEmail) {
        return 'user';
    }
    return 'person';
}

function personRecordTypeIcon(string $type): string
{
    if ($type === 'linked') {
        return 'fa-solid fa-link';
    }
    if ($type === 'user') {
        return 'fa-solid fa-user';
    }
    return 'fa-solid fa-user-plus';
}

function personRecordTypeMarker(string $type): string
{
    if ($type === 'linked') {
        return '[Linked]';
    }
    if ($type === 'user') {
        return '[You]';
    }
    return '[Person]';
}

function findShareTargetUser(?PDO $pdo, string $identifier): ?array
{
    if (!$pdo) {
        return null;
    }
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute([':email' => $identifier]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
    if (ctype_digit($identifier)) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name
            FROM people p
            JOIN users u ON u.id = p.owner_user_id
            WHERE p.member_number = :member_number
            LIMIT 1
        ");
        $stmt->execute([':member_number' => (int)$identifier]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
    return null;
}

function generateShareCode(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare("SELECT id FROM share_requests WHERE code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
    } while ($stmt->fetchColumn());
    return $code;
}

function userCanShareEntity(?PDO $pdo, int $userId, string $entityType, int $entityId): bool
{
    if (!$pdo || $userId <= 0 || $entityId <= 0) {
        return false;
    }
    if ($entityType === 'person') {
        $stmt = $pdo->prepare("SELECT id FROM people WHERE id = :id AND owner_user_id = :uid AND is_archived = 0 LIMIT 1");
    } elseif ($entityType === 'horse') {
        $stmt = $pdo->prepare("SELECT id FROM horses WHERE id = :id AND owner_user_id = :uid AND is_archived = 0 LIMIT 1");
    } else {
        return false;
    }
    $stmt->execute([':id' => $entityId, ':uid' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function createExternalShareCode(?PDO $pdo, int $userId, string $entityType, int $entityId, array &$alerts): ?array
{
    if (!$pdo || $userId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Login required.'];
        return null;
    }
    ensureShareTables($pdo);
    if (!in_array($entityType, ['person', 'horse'], true) || !userCanShareEntity($pdo, $userId, $entityType, $entityId)) {
        $alerts[] = ['type' => 'danger', 'message' => 'You can only share records you manage.'];
        return null;
    }
    $expiresAt = (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s');
    try {
        $stmt = $pdo->prepare("
            INSERT INTO share_requests (entity_type, entity_id, created_by_user_id, target_user_id, target_email, code, status, expires_at, created_at, updated_at)
            VALUES (:entity_type, :entity_id, :created_by, NULL, NULL, :code, 'pending', :expires_at, NOW(), NOW())
        ");
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':created_by' => $userId,
            ':code' => generateShareCode($pdo),
            ':expires_at' => $expiresAt,
        ]);
        return fetchShareRequestById($pdo, (int)$pdo->lastInsertId());
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not create share code.'];
        return null;
    }
}

function createShareRequest(?PDO $pdo, int $userId, string $entityType, int $entityId, string $recipient, array &$alerts): ?array
{
    if (!$pdo || $userId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Login required.'];
        return null;
    }
    ensureShareTables($pdo);
    if (!in_array($entityType, ['person', 'horse'], true) || !userCanShareEntity($pdo, $userId, $entityType, $entityId)) {
        $alerts[] = ['type' => 'danger', 'message' => 'You can only share records you manage.'];
        return null;
    }
    $recipient = trim($recipient);
    if ($recipient === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a recipient email address or member number.'];
        return null;
    }

    $targetUser = findShareTargetUser($pdo, $recipient);
    if ($targetUser && (int)$targetUser['id'] === $userId) {
        $alerts[] = ['type' => 'warning', 'message' => 'That record is already on your account.'];
        return null;
    }
    if ($targetUser) {
        $targetUserId = (int)$targetUser['id'];
        if ($entityType === 'person') {
            $stmt = $pdo->prepare("SELECT id FROM user_person_links WHERE user_id = :uid AND person_id = :entity_id AND status = 'approved' AND revoked_at IS NULL LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT id FROM user_horse_links WHERE user_id = :uid AND horse_id = :entity_id AND status = 'approved' AND revoked_at IS NULL LIMIT 1");
        }
        $stmt->execute([':uid' => $targetUserId, ':entity_id' => $entityId]);
        if ($stmt->fetchColumn()) {
            $alerts[] = ['type' => 'warning', 'message' => 'That user already has access.'];
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT id
            FROM share_requests
            WHERE entity_type = :entity_type
              AND entity_id = :entity_id
              AND target_user_id = :target_user_id
              AND status = 'pending'
              AND (expires_at IS NULL OR expires_at >= NOW())
            LIMIT 1
        ");
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':target_user_id' => $targetUserId,
        ]);
        if ($stmt->fetchColumn()) {
            $alerts[] = ['type' => 'warning', 'message' => 'That user already has a pending share request.'];
            return null;
        }
    }

    $expiresAt = (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s');
    $targetEmail = filter_var($recipient, FILTER_VALIDATE_EMAIL) ? strtolower($recipient) : null;
    $code = $targetUser ? null : generateShareCode($pdo);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO share_requests (entity_type, entity_id, created_by_user_id, target_user_id, target_email, code, status, expires_at, created_at, updated_at)
            VALUES (:entity_type, :entity_id, :created_by, :target_user, :target_email, :code, 'pending', :expires_at, NOW(), NOW())
        ");
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':created_by' => $userId,
            ':target_user' => $targetUser ? (int)$targetUser['id'] : null,
            ':target_email' => $targetEmail,
            ':code' => $code,
            ':expires_at' => $expiresAt,
        ]);
        $id = (int)$pdo->lastInsertId();
        $request = fetchShareRequestById($pdo, $id);
        if ($request && $targetEmail && $code) {
            sendShareCodeEmail($pdo, $request);
        }
        return $request;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not create share request.'];
        return null;
    }
}

function fetchShareRequestById(?PDO $pdo, int $requestId): ?array
{
    if (!$pdo || $requestId <= 0) {
        return null;
    }
    ensureShareTables($pdo);
    $stmt = $pdo->prepare("
        SELECT sr.*, u.email AS creator_email, u.first_name AS creator_first_name, u.last_name AS creator_last_name
        FROM share_requests sr
        LEFT JOIN users u ON u.id = sr.created_by_user_id
        WHERE sr.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['entity_label'] = shareEntityLabel($pdo, (string)$row['entity_type'], (int)$row['entity_id']);
    return $row;
}

function sendShareCodeEmail(?PDO $pdo, array $request): bool
{
    if (!$pdo || !function_exists('send_logged_email')) {
        return false;
    }
    $to = trim((string)($request['target_email'] ?? ''));
    $code = trim((string)($request['code'] ?? ''));
    if ($to === '' || $code === '') {
        return false;
    }
    $entityType = (string)($request['entity_type'] ?? 'record');
    $entityLabel = (string)($request['entity_label'] ?? shareEntityLabel($pdo, $entityType, (int)($request['entity_id'] ?? 0)));
    $creator = trim((string)($request['creator_first_name'] ?? '') . ' ' . (string)($request['creator_last_name'] ?? ''));
    if ($creator === '') {
        $creator = (string)($request['creator_email'] ?? 'Someone');
    }
    $subject = 'Shared ' . ($entityType === 'horse' ? 'horse' : 'rider') . ' for entries';
    $html = '<p>' . h($creator) . ' has shared ' . h($entityLabel) . ' with you for event entries.</p>'
        . '<p>Log in to your account and enter this share code:</p>'
        . '<p style="font-size:20px;font-weight:700;letter-spacing:2px;">' . h($code) . '</p>'
        . '<p>This code expires on ' . h(format_display_date($request['expires_at'] ?? null, 'the expiry date')) . '.</p>';
    $text = $creator . " has shared " . $entityLabel . " with you for event entries.\n\n"
        . "Log in and enter this share code: " . $code . "\n"
        . "Expires: " . (string)($request['expires_at'] ?? '') . "\n";
    return send_logged_email($pdo, $to, $subject, $html, $text, ['share_request_id' => (int)($request['id'] ?? 0)]);
}

function fetchIncomingShareRequests(?PDO $pdo, int $userId): array
{
    if (!$pdo || $userId <= 0) {
        return [];
    }
    ensureShareTables($pdo);
    $stmt = $pdo->prepare("
        SELECT sr.*, u.email AS creator_email, u.first_name AS creator_first_name, u.last_name AS creator_last_name
        FROM share_requests sr
        LEFT JOIN users u ON u.id = sr.created_by_user_id
        WHERE sr.target_user_id = :uid
          AND sr.status = 'pending'
          AND (sr.expires_at IS NULL OR sr.expires_at >= NOW())
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['entity_label'] = shareEntityLabel($pdo, (string)$row['entity_type'], (int)$row['entity_id']);
    }
    unset($row);
    return $rows;
}

function fetchOutgoingSharesForUser(?PDO $pdo, int $userId, ?string $entityType = null, ?int $entityId = null): array
{
    if (!$pdo || $userId <= 0) {
        return [];
    }
    ensureShareTables($pdo);
    $filters = "WHERE sr.created_by_user_id = :uid";
    $params = [':uid' => $userId];
    if ($entityType && $entityId) {
        $filters .= " AND sr.entity_type = :entity_type AND sr.entity_id = :entity_id";
        $params[':entity_type'] = $entityType;
        $params[':entity_id'] = $entityId;
    }
    $stmt = $pdo->prepare("
        SELECT sr.*, tu.email AS target_user_email, tu.first_name AS target_first_name, tu.last_name AS target_last_name
        FROM share_requests sr
        LEFT JOIN users tu ON tu.id = sr.target_user_id
        {$filters}
        ORDER BY sr.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['entity_label'] = shareEntityLabel($pdo, (string)$row['entity_type'], (int)$row['entity_id']);
    }
    unset($row);
    return $rows;
}

function acceptShareRequest(?PDO $pdo, int $userId, int $requestId, array &$alerts): bool
{
    if (!$pdo || $userId <= 0 || $requestId <= 0) {
        return false;
    }
    ensureShareTables($pdo);
    $request = fetchShareRequestById($pdo, $requestId);
    if (!$request || (int)($request['target_user_id'] ?? 0) !== $userId || (string)$request['status'] !== 'pending') {
        $alerts[] = ['type' => 'danger', 'message' => 'Share request not found.'];
        return false;
    }
    if (!empty($request['expires_at']) && strtotime((string)$request['expires_at']) < time()) {
        markShareRequestExpired($pdo, $requestId);
        $alerts[] = ['type' => 'warning', 'message' => 'That share request has expired.'];
        return false;
    }
    return createShareLinkFromRequest($pdo, $userId, $request, $alerts);
}

function acceptShareCode(?PDO $pdo, int $userId, string $code, array &$alerts): bool
{
    if (!$pdo || $userId <= 0) {
        return false;
    }
    ensureShareTables($pdo);
    $code = strtoupper(trim($code));
    if ($code === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a share code.'];
        return false;
    }
    $stmt = $pdo->prepare("SELECT id FROM share_requests WHERE code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $requestId = (int)($stmt->fetchColumn() ?: 0);
    $request = $requestId > 0 ? fetchShareRequestById($pdo, $requestId) : null;
    if (!$request || (string)$request['status'] !== 'pending') {
        $alerts[] = ['type' => 'danger', 'message' => 'Share code not found or already used.'];
        return false;
    }
    if (!empty($request['expires_at']) && strtotime((string)$request['expires_at']) < time()) {
        markShareRequestExpired($pdo, (int)$request['id']);
        $alerts[] = ['type' => 'warning', 'message' => 'That share code has expired.'];
        return false;
    }
    if ((int)$request['created_by_user_id'] === $userId) {
        $alerts[] = ['type' => 'warning', 'message' => 'You cannot accept your own share code.'];
        return false;
    }
    return createShareLinkFromRequest($pdo, $userId, $request, $alerts);
}

function createShareLinkFromRequest(PDO $pdo, int $userId, array $request, array &$alerts): bool
{
    $entityType = (string)($request['entity_type'] ?? '');
    $entityId = (int)($request['entity_id'] ?? 0);
    if (!in_array($entityType, ['person', 'horse'], true) || $entityId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Share request is invalid.'];
        return false;
    }
    try {
        $pdo->beginTransaction();
        if ($entityType === 'person') {
            $stmt = $pdo->prepare("
                INSERT INTO user_person_links (user_id, person_id, permission, status, created_from_request_id, created_at, revoked_at, revoked_by_user_id)
                VALUES (:uid, :entity_id, 'select_only', 'approved', :request_id, NOW(), NULL, NULL)
                ON DUPLICATE KEY UPDATE status = 'approved', permission = 'select_only', revoked_at = NULL, revoked_by_user_id = NULL
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO user_horse_links (user_id, horse_id, permission, status, created_from_request_id, created_at, revoked_at, revoked_by_user_id)
                VALUES (:uid, :entity_id, 'select_only', 'approved', :request_id, NOW(), NULL, NULL)
                ON DUPLICATE KEY UPDATE status = 'approved', permission = 'select_only', revoked_at = NULL, revoked_by_user_id = NULL
            ");
        }
        $stmt->execute([
            ':uid' => $userId,
            ':entity_id' => $entityId,
            ':request_id' => (int)$request['id'],
        ]);
        $upd = $pdo->prepare("
            UPDATE share_requests
            SET status = 'accepted', accepted_at = NOW(), accepted_by_user_id = :uid, updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':uid' => $userId, ':id' => (int)$request['id']]);
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['type' => 'danger', 'message' => 'Could not accept share.'];
        return false;
    }
}

function declineShareRequest(?PDO $pdo, int $userId, int $requestId, array &$alerts): bool
{
    if (!$pdo || $userId <= 0 || $requestId <= 0) {
        return false;
    }
    ensureShareTables($pdo);
    $stmt = $pdo->prepare("UPDATE share_requests SET status = 'declined', updated_at = NOW() WHERE id = :id AND target_user_id = :uid AND status = 'pending' LIMIT 1");
    $stmt->execute([':id' => $requestId, ':uid' => $userId]);
    return $stmt->rowCount() > 0;
}

function cancelShareRequest(?PDO $pdo, int $userId, int $requestId, array &$alerts): bool
{
    if (!$pdo || $userId <= 0 || $requestId <= 0) {
        return false;
    }
    ensureShareTables($pdo);
    $stmt = $pdo->prepare("UPDATE share_requests SET status = 'cancelled', updated_at = NOW() WHERE id = :id AND created_by_user_id = :uid AND status = 'pending' LIMIT 1");
    $stmt->execute([':id' => $requestId, ':uid' => $userId]);
    return $stmt->rowCount() > 0;
}

function markShareRequestExpired(PDO $pdo, int $requestId): void
{
    $stmt = $pdo->prepare("UPDATE share_requests SET status = 'expired', updated_at = NOW() WHERE id = :id AND status = 'pending' LIMIT 1");
    $stmt->execute([':id' => $requestId]);
}

function fetchLinkedAccessForOwner(?PDO $pdo, int $ownerUserId, string $entityType, int $entityId): array
{
    if (!$pdo || $ownerUserId <= 0 || $entityId <= 0 || !in_array($entityType, ['person', 'horse'], true)) {
        return [];
    }
    ensureShareTables($pdo);
    if (!userCanShareEntity($pdo, $ownerUserId, $entityType, $entityId)) {
        return [];
    }
    if ($entityType === 'person') {
        $stmt = $pdo->prepare("
            SELECT upl.*, u.email, u.first_name, u.last_name
            FROM user_person_links upl
            JOIN users u ON u.id = upl.user_id
            WHERE upl.person_id = :entity_id AND upl.status = 'approved' AND upl.revoked_at IS NULL
            ORDER BY upl.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT uhl.*, u.email, u.first_name, u.last_name
            FROM user_horse_links uhl
            JOIN users u ON u.id = uhl.user_id
            WHERE uhl.horse_id = :entity_id AND uhl.status = 'approved' AND uhl.revoked_at IS NULL
            ORDER BY uhl.created_at DESC
        ");
    }
    $stmt->execute([':entity_id' => $entityId]);
    return $stmt->fetchAll() ?: [];
}

function revokeSharedAccess(?PDO $pdo, int $ownerUserId, string $entityType, int $linkId, array &$alerts): bool
{
    if (!$pdo || $ownerUserId <= 0 || $linkId <= 0) {
        return false;
    }
    ensureShareTables($pdo);
    if ($entityType === 'person') {
        $stmt = $pdo->prepare("
            UPDATE user_person_links upl
            JOIN people p ON p.id = upl.person_id
            SET upl.status = 'revoked', upl.revoked_at = NOW(), upl.revoked_by_user_id = :uid
            WHERE upl.id = :link_id AND p.owner_user_id = :uid AND upl.status = 'approved'
            LIMIT 1
        ");
    } elseif ($entityType === 'horse') {
        $stmt = $pdo->prepare("
            UPDATE user_horse_links uhl
            JOIN horses h ON h.id = uhl.horse_id
            SET uhl.status = 'revoked', uhl.revoked_at = NOW(), uhl.revoked_by_user_id = :uid
            WHERE uhl.id = :link_id AND h.owner_user_id = :uid AND uhl.status = 'approved'
            LIMIT 1
        ");
    } else {
        return false;
    }
    $stmt->execute([':uid' => $ownerUserId, ':link_id' => $linkId]);
    return $stmt->rowCount() > 0;
}

function unlinkSharedRecord(?PDO $pdo, int $userId, string $entityType, int $entityId, array &$alerts): bool
{
    if (!$pdo || $userId <= 0 || $entityId <= 0) {
        return false;
    }
    ensureShareTables($pdo);
    if ($entityType === 'person') {
        $stmt = $pdo->prepare("UPDATE user_person_links SET status = 'revoked', revoked_at = NOW(), revoked_by_user_id = :uid WHERE user_id = :uid AND person_id = :entity_id AND status = 'approved' LIMIT 1");
    } elseif ($entityType === 'horse') {
        $stmt = $pdo->prepare("UPDATE user_horse_links SET status = 'revoked', revoked_at = NOW(), revoked_by_user_id = :uid WHERE user_id = :uid AND horse_id = :entity_id AND status = 'approved' LIMIT 1");
    } else {
        return false;
    }
    $stmt->execute([':uid' => $userId, ':entity_id' => $entityId]);
    return $stmt->rowCount() > 0;
}

function createMemberForUser(?PDO $pdo, int $ownerUserId, string $firstName, string $lastName, string $dob, array &$alerts): ?array
{
    if (!$pdo || $ownerUserId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Login required to add a member.'];
        return null;
    }
    ensureMembershipTables($pdo);
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $dob = trim($dob);
    if ($firstName === '' || $lastName === '' || $dob === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Member first name, last name, and date of birth are required.'];
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
    if (!$dt) {
        $alerts[] = ['type' => 'danger', 'message' => 'Date of birth must be a valid date.'];
        return null;
    }

    try {
        $insert = $pdo->prepare("
            INSERT INTO people (owner_user_id, member_number, first_name, last_name, dob, created_at, updated_at)
            VALUES (:uid, NULL, :first_name, :last_name, :dob, NOW(), NOW())
        ");
        $insert->execute([
            ':uid' => $ownerUserId,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':dob' => $dt->format('Y-m-d'),
        ]);
        $memberId = (int)$pdo->lastInsertId();
        return [
            'id' => $memberId,
            'member_number' => null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'dob' => $dt->format('Y-m-d'),
        ];
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not create member.'];
        return null;
    }
}

function assignMemberNumberIfNeeded(?PDO $pdo, int $personId, array &$alerts): ?int
{
    if (!$pdo || $personId <= 0) {
        return null;
    }
    ensureMembershipTables($pdo);

    try {
        $stmt = $pdo->prepare("SELECT member_number FROM people WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $personId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && $existing !== null && (int)$existing > 0) {
            return (int)$existing;
        }
    } catch (PDOException $e) {
        // ignore and proceed to attempt assignment
    }

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'next_member_number' FOR UPDATE");
        $lock->execute();
        $next = (int)($lock->fetchColumn() ?: 1000);
        $next = max(1000, $next);

        // Find the next available number (burned forever: we only ever move forward).
        // This self-heals if `next_member_number` was manually set backwards.
        $exists = $pdo->prepare("SELECT 1 FROM people WHERE member_number = :num LIMIT 1");
        while ($next <= 3000) {
            $exists->execute([':num' => $next]);
            if ($exists->fetchColumn() === false) {
                break;
            }
            $next++;
        }
        if ($next > 3000) {
            $pdo->rollBack();
            $alerts[] = ['type' => 'danger', 'message' => 'No membership numbers available (range exhausted).'];
            return null;
        }

        $updPerson = $pdo->prepare("UPDATE people SET member_number = :num, updated_at = NOW() WHERE id = :id AND member_number IS NULL");
        $updPerson->execute([':num' => $next, ':id' => $personId]);
        if ($updPerson->rowCount() === 0) {
            // Someone else may have assigned it; read current value.
            $stmt = $pdo->prepare("SELECT member_number FROM people WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $personId]);
            $cur = $stmt->fetchColumn();
            $pdo->commit();
            return $cur !== false && $cur !== null ? (int)$cur : null;
        }

        $upd = $pdo->prepare("UPDATE site_settings SET setting_value = :v, updated_at = NOW() WHERE setting_key = 'next_member_number'");
        $upd->execute([':v' => (string)($next + 1)]);

        $pdo->commit();
        return $next;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['type' => 'danger', 'message' => 'Could not assign membership number.'];
        return null;
    }
}

function ensureEntryComponentsTables(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS entry_components (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'product', -- product | question
            input_kind VARCHAR(50) NOT NULL DEFAULT 'checkbox',
            price DECIMAL(10,2) DEFAULT 0.00,
            allowed_event_type_ids TEXT DEFAULT NULL,
            description TEXT DEFAULT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_entry_components (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            component_id INT UNSIGNED NOT NULL,
            label_override VARCHAR(255) DEFAULT NULL,
            price_override DECIMAL(10,2) DEFAULT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_component (event_id, component_id),
            INDEX (event_id),
            INDEX (component_id)
        )
    ");
    // Backfill required column if missing
    if (!table_column_exists($pdo, 'entry_components', 'is_required')) {
        try {
            $pdo->exec("ALTER TABLE entry_components ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'entry_components', 'description')) {
        try {
            $pdo->exec("ALTER TABLE entry_components ADD COLUMN description TEXT DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'events', 'capacity_enabled')) {
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN capacity_enabled TINYINT(1) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'events', 'capacity_limit')) {
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN capacity_limit INT UNSIGNED NOT NULL DEFAULT 50");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'events', 'entry_form')) {
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN entry_form MEDIUMTEXT DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'event_entry_components', 'is_required')) {
        try {
            $pdo->exec("ALTER TABLE event_entry_components ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // ignore
        }
    }
    // Basket store
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS baskets (
            session_id VARCHAR(128) PRIMARY KEY,
            user_id INT UNSIGNED DEFAULT NULL,
            basket_json MEDIUMTEXT,
            last_added_at DATETIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (user_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
}

// Backwards compatibility helper for capacity migrations
function ensureEventCapacityColumns(PDO $pdo): void
{
    ensureEntryComponentsTables($pdo);
}

// Horse logbook types and purchases (per calendar year).
function ensureHorseLogbookTables(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS horse_logbook_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            valid_year INT NOT NULL,
            sale_starts DATE NULL,
            sale_ends DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (status),
            INDEX (valid_year)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS horse_logbook_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchased_by_user_id INT UNSIGNED DEFAULT NULL,
            horse_id INT UNSIGNED NOT NULL,
            logbook_type_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            starts_at DATE DEFAULT NULL,
            ends_at DATE DEFAULT NULL,
            purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (purchased_by_user_id),
            INDEX (horse_id),
            INDEX (logbook_type_id),
            INDEX (status),
            INDEX (starts_at),
            INDEX (ends_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    // Seed a default logbook type if none exists.
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM horse_logbook_types")->fetchColumn();
        if ($count === 0) {
            $year = (int)date('Y');
            $seed = $pdo->prepare("INSERT INTO horse_logbook_types (name, description, cost, status, valid_year) VALUES ('Horse Logbook', 'Annual logbook', 7.50, 'published', :yr)");
            $seed->execute([':yr' => $year]);
        }
    } catch (PDOException $e) {
        // ignore
    }
}

function fetchHorseLogbookTypes(?PDO $pdo, bool $publishedOnly = false): array
{
    if (!$pdo) {
        return [];
    }
    ensureHorseLogbookTables($pdo);
    try {
        $sql = "SELECT * FROM horse_logbook_types";
        if ($publishedOnly) {
            $sql .= " WHERE status = 'published'";
        }
        $sql .= " ORDER BY valid_year DESC, cost ASC, id ASC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function fetchHorseLogbookTypeById(?PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    if (!$pdo) {
        return null;
    }
    ensureHorseLogbookTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM horse_logbook_types WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function calc_logbook_status(array $row): string
{
    $status = strtolower((string)($row['status'] ?? 'active'));
    $status = in_array($status, ['active', 'pending', 'expired'], true) ? $status : 'active';
    $startsAt = $row['starts_at'] ?? null;
    $endsAt = $row['ends_at'] ?? null;
    if ($startsAt !== null || $endsAt !== null) {
        try {
            $today = new DateTimeImmutable('today');
            if ($startsAt) {
                $startDt = new DateTimeImmutable((string)$startsAt);
                if ($today < $startDt) {
                    return 'pending';
                }
            }
            if ($endsAt) {
                $endDt = new DateTimeImmutable((string)$endsAt);
                if ($today > $endDt) {
                    return 'expired';
                }
            }
            return 'active';
        } catch (Throwable $e) {
            return $status;
        }
    }
    return $status;
}

function saveHorseLogbookPurchase(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    $purchasedByUserId = isset($data['purchased_by_user_id']) ? (int)$data['purchased_by_user_id'] : null;
    $horseId = isset($data['horse_id']) ? (int)$data['horse_id'] : 0;
    $typeId = isset($data['logbook_type_id']) ? (int)$data['logbook_type_id'] : 0;
    $amount = trim((string)($data['amount'] ?? '0'));
    $status = $data['status'] ?? 'active';
    $startsAt = trim((string)($data['starts_at'] ?? ''));
    $endsAt = trim((string)($data['ends_at'] ?? ''));

    if ($horseId <= 0 || $typeId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Horse and logbook type are required.'];
        return false;
    }
    if (!in_array($status, ['active', 'expired', 'pending'], true)) {
        $status = 'active';
    }

    try {
        ensureHorseLogbookTables($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO horse_logbook_purchases (purchased_by_user_id, horse_id, logbook_type_id, amount, status, starts_at, ends_at, purchased_at, created_at, updated_at)
            VALUES (:puid, :hid, :tid, :amount, :status, :starts_at, :ends_at, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            ':puid' => $purchasedByUserId ?: null,
            ':hid' => $horseId,
            ':tid' => $typeId,
            ':amount' => $amount,
            ':status' => $status,
            ':starts_at' => $startsAt ?: null,
            ':ends_at' => $endsAt ?: null,
        ]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save logbook purchase.'];
        return false;
    }
}

function fetchHorseLogbooksForUser(?PDO $pdo, int $ownerUserId): array
{
    if (!$pdo || $ownerUserId <= 0) {
        return [];
    }
    ensureHorseLogbookTables($pdo);
    $stmt = $pdo->prepare("
        SELECT hlp.*, h.name AS horse_name, h.owner_user_id, h.is_archived,
               hlt.name AS logbook_name, hlt.valid_year
        FROM horse_logbook_purchases hlp
        JOIN horses h ON h.id = hlp.horse_id
        LEFT JOIN horse_logbook_types hlt ON hlt.id = hlp.logbook_type_id
        WHERE h.owner_user_id = :uid
        ORDER BY hlp.purchased_at DESC, hlp.id DESC
    ");
    $stmt->execute([':uid' => $ownerUserId]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['status'] = calc_logbook_status($row);
    }
    return $rows;
}

function horse_has_logbook_for_year(?PDO $pdo, int $horseId, int $year): bool
{
    if (!$pdo || $horseId <= 0 || $year <= 0) {
        return false;
    }
    ensureHorseLogbookTables($pdo);
    $stmt = $pdo->prepare("
        SELECT 1
        FROM horse_logbook_purchases
        WHERE horse_id = :hid
          AND status <> 'expired'
          AND (
                (starts_at IS NOT NULL AND YEAR(starts_at) = :yr)
             OR (starts_at IS NULL AND ends_at IS NOT NULL AND YEAR(ends_at) = :yr)
             OR (starts_at IS NULL AND ends_at IS NULL AND purchased_at IS NOT NULL AND YEAR(purchased_at) = :yr)
          )
        LIMIT 1
    ");
    $stmt->execute([':hid' => $horseId, ':yr' => $year]);
    return (bool)$stmt->fetchColumn();
}

function fetchHorseQualifications(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }
    ensureHorsesTables($pdo);
    try {
        $stmt = $pdo->query("SELECT id, name FROM horse_qualifications ORDER BY name ASC");
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function tidyExpiredBaskets(?PDO $pdo, int $timeoutSeconds): int
{
    if (!$pdo || $timeoutSeconds <= 0) {
        return 0;
    }
    // Expire old holds and also drop empty baskets
    $stmt = $pdo->prepare("
        DELETE FROM baskets
        WHERE
            basket_json IS NULL
            OR basket_json = '[]'
            OR (last_added_at IS NOT NULL AND last_added_at <= (NOW() - INTERVAL :secs SECOND))
    ");
    $stmt->execute([':secs' => $timeoutSeconds]);
    return $stmt->rowCount();
}

function loadBasketForSession(?PDO $pdo, string $sessionId): array
{
    if (!$pdo || $sessionId === '') {
        return [null, null, null];
    }
    $stmt = $pdo->prepare("SELECT basket_json, last_added_at, user_id FROM baskets WHERE session_id = :sid LIMIT 1");
    $stmt->execute([':sid' => $sessionId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [null, null, null];
    }
    $basket = json_decode((string)$row['basket_json'], true);
    if (!is_array($basket)) {
        $basket = [];
    }
    $lastAdded = $row['last_added_at'] ? strtotime((string)$row['last_added_at']) : null;
    $userId = $row['user_id'] ?? null;
    return [$basket, $lastAdded, $userId];
}

function saveBasketForSession(?PDO $pdo, string $sessionId, array $basket, ?int $userId, ?int $lastAddedTs): void
{
    if (!$pdo || $sessionId === '') {
        return;
    }
    ensureEntryComponentsTables($pdo);
    // If basket is empty, remove the row to avoid stale empties
    if (empty($basket)) {
        $del = $pdo->prepare("DELETE FROM baskets WHERE session_id = :sid");
        $del->execute([':sid' => $sessionId]);
        return;
    }
    $stmt = $pdo->prepare("
        REPLACE INTO baskets (session_id, user_id, basket_json, last_added_at, updated_at)
        VALUES (:sid, :uid, :basket_json, :last_added_at, NOW())
    ");
    $stmt->execute([
        ':sid' => $sessionId,
        ':uid' => $userId ?: null,
        ':basket_json' => json_encode($basket, JSON_UNESCAPED_UNICODE),
        ':last_added_at' => $lastAddedTs ? date('Y-m-d H:i:s', $lastAddedTs) : null,
    ]);
}

function parseAllowedEventTypeIds($raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('intval', $raw)));
    }
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('intval', $decoded)));
    }
    return [];
}

function componentAllowedForType(array $component, int $eventTypeId): bool
{
    $allowed = parseAllowedEventTypeIds($component['allowed_event_type_ids'] ?? []);
    if (!$allowed) {
        // No allowed types defined means this component should not be shown anywhere
        return false;
    }
    return in_array($eventTypeId, $allowed, true);
}

function defaultEntryComponents(): array
{
    return [
        [
            'id' => 1,
            'name' => 'Rosette',
            'type' => 'product',
            'input_kind' => 'checkbox',
            'price' => 3.00,
            'allowed_event_type_ids' => [],
            'is_required' => 0,
            'is_active' => 1,
        ],
        [
            'id' => 2,
            'name' => 'Allow photography',
            'type' => 'question',
            'input_kind' => 'checkbox',
            'price' => 0.00,
            'allowed_event_type_ids' => [],
            'is_required' => 0,
            'is_active' => 1,
        ],
    ];
}

function build_default_entry_form(array $event, array $eventComponents): array
{
    $blocks = [
        ['type' => 'classes', 'label' => 'Classes', 'enabled' => true],
        ['type' => 'rider_details', 'label' => 'Rider details', 'enabled' => true],
        ['type' => 'horse_details', 'label' => 'Horse details', 'enabled' => true],
        ['type' => 'contact', 'label' => 'Contact information', 'enabled' => true],
    ];
    foreach ($eventComponents as $comp) {
        $compId = (int)($comp['id'] ?? 0);
        if ($compId <= 0) {
            continue;
        }
        $blocks[] = [
            'type' => 'component',
            'component_id' => $compId,
            'label' => $comp['name'] ?? 'Component',
            'enabled' => true,
        ];
    }
    return array_values($blocks);
}

function normalize_entry_form(array $raw, array $eventComponents): array
{
    $allowedTypes = ['classes', 'rider_details', 'horse_details', 'contact', 'component'];
    $componentMap = [];
    foreach ($eventComponents as $c) {
        $componentMap[(int)($c['id'] ?? 0)] = $c;
    }
    $out = [];
    foreach ($raw as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = $block['type'] ?? '';
        if (!in_array($type, $allowedTypes, true)) {
            continue;
        }
        if ($type === 'component') {
            $cid = (int)($block['component_id'] ?? 0);
            if ($cid <= 0 || !isset($componentMap[$cid])) {
                continue;
            }
        }
        $out[] = [
            'type' => $type,
            'component_id' => isset($block['component_id']) ? (int)$block['component_id'] : null,
            'label' => $block['label'] ?? null,
            'enabled' => $type === 'classes' ? true : (isset($block['enabled']) ? (bool)$block['enabled'] : true),
        ];
    }
    // Ensure classes block exists
    $hasClasses = false;
    foreach ($out as $b) {
        if ($b['type'] === 'classes') {
            $hasClasses = true;
            break;
        }
    }
    if (!$hasClasses) {
        array_unshift($out, ['type' => 'classes', 'label' => 'Classes', 'enabled' => true]);
    }
    return array_values($out);
}

function event_entry_form(array $event, array $eventComponents): array
{
    $raw = [];
    if (!empty($event['entry_form'])) {
        $decoded = json_decode((string)$event['entry_form'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }
    if (!$raw) {
        return build_default_entry_form($event, $eventComponents);
    }
    return normalize_entry_form($raw, $eventComponents);
}

function fetchEntryComponents(?PDO $pdo, ?int $eventTypeId = null, bool $activeOnly = true): array
{
    if (!$pdo) {
        $components = defaultEntryComponents();
        if ($eventTypeId !== null) {
            $components = array_values(array_filter($components, fn($c) => componentAllowedForType($c, $eventTypeId)));
        }
        return $components;
    }
    ensureEntryComponentsTables($pdo);
    $sql = "SELECT * FROM entry_components";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    if (!$rows) {
        // Seed a couple of defaults for convenience
        $allTypeIds = array_map('intval', array_column(fetchEventTypes($pdo), 'id'));
        foreach (defaultEntryComponents() as $component) {
            $seedStmt = $pdo->prepare("
                INSERT INTO entry_components (name, type, input_kind, price, allowed_event_type_ids, is_active, created_at, updated_at)
                VALUES (:name, :type, :input_kind, :price, :allowed_event_type_ids, 1, NOW(), NOW())
            ");
            $seedStmt->execute([
                ':name' => $component['name'],
                ':type' => $component['type'],
                ':input_kind' => $component['input_kind'],
                ':price' => $component['price'],
                ':allowed_event_type_ids' => json_encode($component['allowed_event_type_ids'] ?: $allTypeIds),
            ]);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
    }
    if ($eventTypeId !== null) {
        $rows = array_values(array_filter($rows, fn($c) => componentAllowedForType($c, $eventTypeId)));
    }
    return $rows;
}

function fetchEventEntryComponents(?PDO $pdo, int $eventId, ?int $eventTypeId = null): array
{
    if ($eventId <= 0) {
        return [];
    }
    if (!$pdo) {
        return [];
    }
    ensureEntryComponentsTables($pdo);
    $sql = "
        SELECT c.*, eec.label_override, eec.price_override, eec.is_enabled
        FROM event_entry_components eec
        INNER JOIN entry_components c ON c.id = eec.component_id
        WHERE eec.event_id = :event_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':event_id' => $eventId]);
    $rows = $stmt->fetchAll() ?: [];
    if ($eventTypeId !== null) {
        $rows = array_values(array_filter($rows, fn($c) => componentAllowedForType($c, $eventTypeId)));
    }
    // Only return enabled rows
    return array_values(array_filter($rows, fn($row) => (int)($row['is_enabled'] ?? 0) === 1));
}

function saveEventEntryComponents(?PDO $pdo, int $eventId, array $data, array &$alerts): bool
{
    if (!$pdo || $eventId <= 0) {
        return false;
    }
    ensureEntryComponentsTables($pdo);

    $enabled = $data['component_enabled'] ?? [];
    $labels = $data['component_label'] ?? [];
    $prices = $data['component_price'] ?? [];

    try {
        $pdo->prepare("DELETE FROM event_entry_components WHERE event_id = :event_id")->execute([':event_id' => $eventId]);

        foreach ($enabled as $componentId => $flag) {
            if (!$flag) {
                continue;
            }
            $componentId = (int)$componentId;
            if ($componentId <= 0) {
                continue;
            }
            $label = trim((string)($labels[$componentId] ?? ''));
            $priceRaw = (string)($prices[$componentId] ?? '');
            $priceOverride = $priceRaw === '' ? null : price_to_number($priceRaw);

            $stmt = $pdo->prepare("
                INSERT INTO event_entry_components (event_id, component_id, label_override, price_override, is_enabled, created_at, updated_at)
                VALUES (:event_id, :component_id, :label_override, :price_override, 1, NOW(), NOW())
            ");
            $stmt->execute([
                ':event_id' => $eventId,
                ':component_id' => $componentId,
                ':label_override' => $label !== '' ? $label : null,
                ':price_override' => $priceOverride,
            ]);
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save entry form options.'];
        return false;
    }
}

function fetchEntryComponentById(?PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    if (!$pdo) {
        foreach (defaultEntryComponents() as $comp) {
            if ((int)($comp['id'] ?? 0) === $id) {
                return $comp;
            }
        }
        return null;
    }
    ensureEntryComponentsTables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM entry_components WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * @return int|false
 */
function saveEntryComponent(?PDO $pdo, array $data, array &$alerts)
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    ensureEntryComponentsTables($pdo);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $name = trim((string)($data['name'] ?? ''));
    $type = in_array(($data['type'] ?? 'product'), ['product', 'question'], true) ? $data['type'] : 'product';
    $inputKind = trim((string)($data['input_kind'] ?? 'checkbox')) ?: 'checkbox';
    if (!in_array($inputKind, ['checkbox', 'text', 'textarea', 'quantity', 'none'], true)) {
        $inputKind = 'checkbox';
    }
    $hasCost = isset($data['has_cost']) && (int)$data['has_cost'] === 1;
    $price = ($type === 'product' && $hasCost) ? price_to_number($data['price'] ?? 0) : 0.0;
    $allowed = parseAllowedEventTypeIds($data['allowed_event_type_ids'] ?? []);
    $canRequire = $inputKind === 'checkbox';
    $isRequired = $canRequire && !empty($data['is_required']);
    $isActive = 1; // deprecated flag; always treated as active
    $description = trim((string)($data['description'] ?? ''));

    if ($name === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Name is required.'];
        return false;
    }
    if (!$allowed) {
        $alerts[] = ['type' => 'danger', 'message' => 'Select at least one allowed event type.'];
        return false;
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE entry_components
                SET name = :name, type = :type, input_kind = :input_kind, price = :price,
                    allowed_event_type_ids = :allowed, is_required = :is_required, description = :description,
                    is_active = 1, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':type' => $type,
                ':input_kind' => $inputKind,
                ':price' => $price,
                ':allowed' => json_encode($allowed),
                ':is_required' => $isRequired ? 1 : 0,
                ':description' => $description !== '' ? $description : null,
                ':id' => $id,
            ]);
            return $id;
        }
        $stmt = $pdo->prepare("
            INSERT INTO entry_components (name, type, input_kind, price, allowed_event_type_ids, is_required, description, is_active, created_at, updated_at)
            VALUES (:name, :type, :input_kind, :price, :allowed, :is_required, :description, 1, NOW(), NOW())
        ");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':input_kind' => $inputKind,
            ':price' => $price,
            ':allowed' => json_encode($allowed),
            ':is_required' => $isRequired ? 1 : 0,
            ':description' => $description !== '' ? $description : null,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save entry component.'];
        return false;
    }
}

function deleteEntryComponent(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    if ($id <= 0) {
        return false;
    }
    ensureEntryComponentsTables($pdo);
    try {
        $pdo->prepare("DELETE FROM event_entry_components WHERE component_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM entry_components WHERE id = :id")->execute([':id' => $id]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete entry component.'];
        return false;
    }
}

function ensureEventTypeIdColumn(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        if (!table_column_exists($pdo, 'events', 'event_type_id')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN event_type_id INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if (!table_index_on_column_exists($pdo, 'events', 'event_type_id')) {
            if (table_index_count($pdo, 'events') < 64) {
                $pdo->exec("ALTER TABLE events ADD INDEX idx_events_event_type_id (event_type_id)");
            }
        }
    } catch (PDOException $e) {
        // Silently ignore; the caller will handle failures when saving.
    }
}

function ensureEventVenueColumns(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        if (!table_column_exists($pdo, 'events', 'venue_id')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN venue_id INT UNSIGNED NULL DEFAULT NULL AFTER venue");
        }
        if (!table_index_on_column_exists($pdo, 'events', 'venue_id')) {
            if (table_index_count($pdo, 'events') < 64) {
                $pdo->exec("ALTER TABLE events ADD INDEX idx_events_venue_id (venue_id)");
            }
        }
    } catch (PDOException $e) {
        // Ignore; saveEvent will surface errors if insert/update fails.
    }
}

function ensureEntryWindowColumns(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    if (!table_column_exists($pdo, 'events', 'entry_open_at')) {
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN entry_open_at DATETIME NULL DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'events', 'entry_close_at')) {
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN entry_close_at DATETIME NULL DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
    if (!table_column_exists($pdo, 'events', 'non_member_entry_open_at')) {
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN non_member_entry_open_at DATETIME NULL DEFAULT NULL AFTER entry_open_at");
        } catch (PDOException $e) {
            // ignore
        }
    }
}

function event_duplicate_date_defaults(string $eventDate): ?array
{
    $eventDate = trim($eventDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        return null;
    }

    try {
        $start = new DateTimeImmutable($eventDate . ' 12:00:00');
    } catch (Exception $e) {
        return null;
    }

    $open = $start->modify('-28 days');
    while ((int)$open->format('w') !== 5) {
        $open = $open->modify('+1 day');
    }

    $close = $start;
    $guard = 0;
    while ((int)$close->format('w') !== 4 && $guard < 7) {
        $close = $close->modify('-1 day');
        $guard++;
    }

    return [
        'event_date' => $start->format('Y-m-d'),
        'end_date' => $start->format('Y-m-d'),
        'start_time' => '09:00',
        'end_time' => '13:00',
        'entry_open_date' => $open->format('Y-m-d'),
        'entry_open_time' => '18:00',
        'non_member_entry_open_date' => $open->modify('+7 days')->format('Y-m-d'),
        'non_member_entry_open_time' => '18:00',
        'entry_close_date' => $close->format('Y-m-d'),
        'entry_close_time' => '23:59',
    ];
}

function buildDuplicatedEventPricingRows(?PDO $pdo, array $sourceEvent): array
{
    $sourceEventId = (int)($sourceEvent['id'] ?? 0);
    $eventTypeId = (int)($sourceEvent['event_type_id'] ?? 0);
    $sourceRows = fetchEventPricingRows($pdo, $sourceEventId);
    $schemeRows = [];
    $schemeId = $eventTypeId > 0 ? fetchDefaultPricingSchemeIdForEventType($pdo, $eventTypeId) : 0;
    if ($schemeId > 0) {
        $schemeRows = fetchPricingSchemeRows($pdo, $schemeId);
    }

    $buildKeys = static function (array $row): array {
        $code = mb_strtolower(trim((string)($row['class_code'] ?? '')));
        $name = mb_strtolower(trim((string)($row['class_name'] ?? '')));
        $member = !empty($row['is_member_price']) ? '1' : '0';
        $junior = !empty($row['is_junior_ride']) ? '1' : '0';
        $keys = [];
        if ($code !== '') {
            $keys[] = $code . '|' . $member . '|' . $junior;
        }
        if ($name !== '') {
            $keys[] = $name . '|' . $member . '|' . $junior;
        }
        return $keys;
    };

    $schemePrices = [];
    foreach ($schemeRows as $row) {
        foreach ($buildKeys($row) as $key) {
            if ($key === '' || array_key_exists($key, $schemePrices)) {
                continue;
            }
            $schemePrices[$key] = (float)($row['price'] ?? 0);
        }
    }

    if (!$sourceRows && $schemeRows) {
        foreach ($schemeRows as $i => $row) {
            $sourceRows[] = [
                'sort_order' => (int)($row['sort_order'] ?? (($i + 1) * 10)),
                'class_name' => (string)($row['class_name'] ?? ''),
                'class_code' => $row['class_code'] ?? null,
                'price' => (float)($row['price'] ?? 0),
                'is_member_price' => !empty($row['is_member_price']) ? 1 : 0,
                'is_junior_ride' => !empty($row['is_junior_ride']) ? 1 : 0,
                'enabled' => 1,
            ];
        }
    }

    $rows = [];
    foreach ($sourceRows as $i => $row) {
        $matchedPrice = null;
        foreach ($buildKeys($row) as $key) {
            if (array_key_exists($key, $schemePrices)) {
                $matchedPrice = $schemePrices[$key];
                break;
            }
        }
        $rows[] = [
            'sort_order' => (int)($row['sort_order'] ?? (($i + 1) * 10)),
            'class_name' => (string)($row['class_name'] ?? ''),
            'class_code' => ($row['class_code'] ?? null) !== '' ? $row['class_code'] : null,
            'price' => $matchedPrice !== null ? $matchedPrice : (float)($row['price'] ?? 0),
            'is_member_price' => !empty($row['is_member_price']) ? 1 : 0,
            'is_junior_ride' => !empty($row['is_junior_ride']) ? 1 : 0,
            'enabled' => array_key_exists('enabled', $row) ? (!empty($row['enabled']) ? 1 : 0) : 1,
        ];
    }

    return $rows;
}

/**
 * @return int|false
 */
function duplicateEventAsDraft(?PDO $pdo, int $sourceEventId, string $rideDate, array &$alerts)
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    if ($sourceEventId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Choose an event to copy.'];
        return false;
    }

    $sourceEvent = fetchEventById($pdo, $sourceEventId);
    if (!$sourceEvent) {
        $alerts[] = ['type' => 'danger', 'message' => 'The source event could not be found.'];
        return false;
    }

    $dateDefaults = event_duplicate_date_defaults($rideDate);
    if (!$dateDefaults) {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid ride date.'];
        return false;
    }

    ensureEventPricingTables($pdo);
    ensureEntryComponentsTables($pdo);

    $newEventData = [
        'title' => (string)($sourceEvent['title'] ?? ''),
        'event_date' => $dateDefaults['event_date'],
        'end_date' => $dateDefaults['end_date'],
        'start_time' => $dateDefaults['start_time'],
        'end_time' => $dateDefaults['end_time'],
        'venue' => (string)($sourceEvent['venue'] ?? ''),
        'venue_id' => (int)($sourceEvent['venue_id'] ?? 0),
        'organiser' => (string)($sourceEvent['organiser'] ?? ''),
        'organiser_user_id' => (int)($sourceEvent['organiser_user_id'] ?? 0),
        'entry_open_date' => $dateDefaults['entry_open_date'],
        'entry_open_time' => $dateDefaults['entry_open_time'],
        'non_member_entry_open_date' => $dateDefaults['non_member_entry_open_date'],
        'non_member_entry_open_time' => $dateDefaults['non_member_entry_open_time'],
        'entry_close_date' => $dateDefaults['entry_close_date'],
        'entry_close_time' => $dateDefaults['entry_close_time'],
        'entry_form_json' => (string)($sourceEvent['entry_form'] ?? ''),
        'status' => 'draft',
        'description' => (string)($sourceEvent['description'] ?? ''),
        'event_type_id' => (int)($sourceEvent['event_type_id'] ?? 0),
        'capacity_enabled' => !empty($sourceEvent['capacity_enabled']) ? 1 : 0,
        'capacity_limit' => (int)($sourceEvent['capacity_limit'] ?? 50),
        'classes_offered' => (string)($sourceEvent['classes_offered'] ?? ''),
    ];

    $eventTypeId = (int)($sourceEvent['event_type_id'] ?? 0);
    $componentRows = fetchEventEntryComponents($pdo, $sourceEventId, $eventTypeId);
    $pricingRows = buildDuplicatedEventPricingRows($pdo, $sourceEvent);

    try {
        $pdo->beginTransaction();

        $entryOpenAt = trim($newEventData['entry_open_date'] . ' ' . $newEventData['entry_open_time']);
        $nonMemberEntryOpenAt = trim($newEventData['non_member_entry_open_date'] . ' ' . $newEventData['non_member_entry_open_time']);
        $entryCloseAt = trim($newEventData['entry_close_date'] . ' ' . $newEventData['entry_close_time']);
        $insertEvent = $pdo->prepare("
            INSERT INTO events (
                title, event_date, end_date, start_time, end_time, venue, venue_id, organiser, organiser_user_id,
                classes_offered, entry_open_at, non_member_entry_open_at, entry_close_at, entry_form, status, description, event_type_id,
                capacity_enabled, capacity_limit, created_at, updated_at
            )
            VALUES (
                :title, :event_date, :end_date, :start_time, :end_time, :venue, :venue_id, :organiser, :organiser_user_id,
                :classes_offered, :entry_open_at, :non_member_entry_open_at, :entry_close_at, :entry_form, :status, :description, :event_type_id,
                :capacity_enabled, :capacity_limit, NOW(), NOW()
            )
        ");
        $insertEvent->execute([
            ':title' => $newEventData['title'],
            ':event_date' => $newEventData['event_date'],
            ':end_date' => $newEventData['end_date'] ?: null,
            ':start_time' => $newEventData['start_time'] ?: null,
            ':end_time' => $newEventData['end_time'] ?: null,
            ':venue' => $newEventData['venue'] !== '' ? $newEventData['venue'] : null,
            ':venue_id' => (int)$newEventData['venue_id'] > 0 ? (int)$newEventData['venue_id'] : null,
            ':organiser' => $newEventData['organiser'] !== '' ? $newEventData['organiser'] : null,
            ':organiser_user_id' => (int)$newEventData['organiser_user_id'] > 0 ? (int)$newEventData['organiser_user_id'] : null,
            ':classes_offered' => $newEventData['classes_offered'],
            ':entry_open_at' => $entryOpenAt !== '' ? $entryOpenAt : null,
            ':non_member_entry_open_at' => $nonMemberEntryOpenAt !== '' ? $nonMemberEntryOpenAt : null,
            ':entry_close_at' => $entryCloseAt !== '' ? $entryCloseAt : null,
            ':entry_form' => $newEventData['entry_form_json'] !== '' ? $newEventData['entry_form_json'] : null,
            ':status' => 'draft',
            ':description' => $newEventData['description'],
            ':event_type_id' => (int)$newEventData['event_type_id'],
            ':capacity_enabled' => !empty($newEventData['capacity_enabled']) ? 1 : 0,
            ':capacity_limit' => max(1, (int)($newEventData['capacity_limit'] ?? 50)),
        ]);
        $newEventId = (int)$pdo->lastInsertId();

        if ($pricingRows) {
            $insPricing = $pdo->prepare("
                INSERT INTO event_pricing_rows (event_id, sort_order, class_name, class_code, price, is_member_price, is_junior_ride, enabled, created_at, updated_at)
                VALUES (:event_id, :sort_order, :class_name, :class_code, :price, :is_member_price, :is_junior_ride, :enabled, NOW(), NOW())
            ");
            foreach ($pricingRows as $row) {
                $insPricing->execute([
                    ':event_id' => $newEventId,
                    ':sort_order' => (int)($row['sort_order'] ?? 0),
                    ':class_name' => (string)($row['class_name'] ?? ''),
                    ':class_code' => ($row['class_code'] ?? null) !== '' ? $row['class_code'] : null,
                    ':price' => (float)($row['price'] ?? 0),
                    ':is_member_price' => !empty($row['is_member_price']) ? 1 : 0,
                    ':is_junior_ride' => !empty($row['is_junior_ride']) ? 1 : 0,
                    ':enabled' => !empty($row['enabled']) ? 1 : 0,
                ]);
            }
            $classes = [];
            foreach ($pricingRows as $row) {
                if (!empty($row['is_member_price']) || empty($row['enabled'])) {
                    continue;
                }
                $code = trim((string)($row['class_code'] ?? ''));
                $label = trim((string)($row['class_name'] ?? ''));
                $classes[] = [
                    'code' => $code !== '' ? $code : ($label !== '' ? $label : 'Class'),
                    'label' => $label !== '' ? $label : ($code !== '' ? $code : 'Class'),
                    'price' => format_price((float)($row['price'] ?? 0)),
                ];
            }
            $updClasses = $pdo->prepare("UPDATE events SET classes_offered = :classes_offered, updated_at = NOW() WHERE id = :id");
            $updClasses->execute([
                ':classes_offered' => json_encode($classes, JSON_UNESCAPED_UNICODE),
                ':id' => $newEventId,
            ]);
        }

        if ($componentRows) {
            $insComponent = $pdo->prepare("
                INSERT INTO event_entry_components (event_id, component_id, label_override, price_override, is_enabled, created_at, updated_at)
                VALUES (:event_id, :component_id, :label_override, :price_override, 1, NOW(), NOW())
            ");
            foreach ($componentRows as $row) {
                $componentId = (int)($row['id'] ?? 0);
                if ($componentId <= 0) {
                    continue;
                }
                $priceOverride = $row['price_override'] ?? null;
                $insComponent->execute([
                    ':event_id' => $newEventId,
                    ':component_id' => $componentId,
                    ':label_override' => (($row['label_override'] ?? '') !== '') ? $row['label_override'] : null,
                    ':price_override' => $priceOverride !== null && $priceOverride !== '' ? (float)$priceOverride : null,
                ]);
            }
        }

        $pdo->commit();
        return $newEventId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['type' => 'danger', 'message' => 'Could not duplicate the event.'];
        return false;
    }
}

/**
 * @return int|false
 */
function saveEvent(?PDO $pdo, array $data, array &$alerts)
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    $eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
    $title = trim((string)($data['title'] ?? ''));
    $eventDate = trim((string)($data['event_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? ''));
    $startTime = trim((string)($data['start_time'] ?? ''));
    $endTime = trim((string)($data['end_time'] ?? ''));
    $venue = trim((string)($data['venue'] ?? ''));
    $venueId = isset($data['venue_id']) ? (int)$data['venue_id'] : 0;
    $organiser = trim((string)($data['organiser'] ?? ''));
    $organiserUserId = isset($data['organiser_user_id']) ? (int)$data['organiser_user_id'] : 0;
    $classPrices = $data['class_price'] ?? [];
    $selectedClasses = $data['class_selected'] ?? [];
    $classesOffered = null;
    $hasLegacyClassInputs = array_key_exists('class_selected', $data) || array_key_exists('class_price', $data);
    $eventTypeIdInput = (int)($data['event_type_id'] ?? 0);
    $eventTypes = fetchEventTypes($pdo);
    $selectedType = findEventType($eventTypes, $eventTypeIdInput);
    $eventTypeId = (int)($selectedType['id'] ?? 0);
    $eventTypeName = $selectedType['name'] ?? 'Ride';
    if ($venueId > 0) {
        $venueRow = fetchVenueById($pdo, $venueId);
        if ($venueRow) {
            $venue = (string)($venueRow['name'] ?? $venue);
        } else {
            $venueId = 0;
        }
    }
    if ($eventId > 0 && !array_key_exists('organiser', $data)) {
        try {
            $stmt = $pdo->prepare("SELECT organiser, organiser_user_id FROM events WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $eventId]);
            $existingRow = $stmt->fetch() ?: [];
            if ($organiserUserId <= 0) {
                $organiser = trim((string)($existingRow['organiser'] ?? $organiser));
                $organiserUserId = (int)($existingRow['organiser_user_id'] ?? $organiserUserId);
            }
        } catch (PDOException $e) {
            // ignore
        }
    }
    if ($organiserUserId > 0) {
        $organiserUser = fetchEligibleEventOrganiserById($pdo, $organiserUserId);
        if ($organiserUser) {
            $organiserName = trim((string)(($organiserUser['first_name'] ?? '') . ' ' . ($organiserUser['last_name'] ?? '')));
            $organiser = $organiserName !== '' ? $organiserName : (string)($organiserUser['email'] ?? '');
        } else {
            $organiserUserId = 0;
        }
    }
    $entryOpenDate = trim((string)($data['entry_open_date'] ?? ''));
    $entryOpenTime = trim((string)($data['entry_open_time'] ?? ''));
    $nonMemberEntryOpenDate = trim((string)($data['non_member_entry_open_date'] ?? ''));
    $nonMemberEntryOpenTime = trim((string)($data['non_member_entry_open_time'] ?? ''));
    $entryCloseDate = trim((string)($data['entry_close_date'] ?? ''));
    $entryCloseTime = trim((string)($data['entry_close_time'] ?? ''));
    $entryFormJson = trim((string)($data['entry_form_json'] ?? ''));
    $entryFormValue = $entryFormJson !== '' ? $entryFormJson : null;
    if ($hasLegacyClassInputs && is_array($selectedClasses)) {
        $classesOffered = [];
        foreach ($selectedClasses as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            $price = trim((string)($classPrices[$code] ?? ''));
            $classesOffered[] = [
                'code' => $code,
                'label' => $code,
                'price' => $price,
            ];
        }
    }
    if (isset($data['classes_offered']) && is_string($data['classes_offered'])) {
        $classesOffered = $data['classes_offered'];
        $hasLegacyClassInputs = true;
    }

    // If we're saving an existing event and the admin UI did not submit legacy class inputs,
    // preserve the existing classes_offered value. This prevents event_edit.php (which now
    // uses event_pricing_rows) from accidentally clearing classes_offered before the post-save
    // sync step runs.
    if ($eventId > 0 && !$hasLegacyClassInputs) {
        try {
            $stmt = $pdo->prepare("SELECT classes_offered FROM events WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $eventId]);
            $classesOffered = (string)($stmt->fetchColumn() ?: '');
        } catch (PDOException $e) {
            $classesOffered = '';
        }
    }
    if ($classesOffered === null) {
        $classesOffered = json_encode([], JSON_UNESCAPED_UNICODE);
    }
    $status = $data['status'] ?? 'draft';
    $description = trim((string)($data['description'] ?? ''));
    $eventTypes = fetchEventTypes($pdo);
    $selectedType = findEventType($eventTypes, (int)($data['event_type_id'] ?? 0), (string)($data['event_type'] ?? ''));
    $eventTypeId = (int)($selectedType['id'] ?? 0);
    $eventTypeSlug = $selectedType['slug'] ?? 'ride';

    if ($title === '' || $eventDate === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Event title and start date are required.'];
        return false;
    }

    if (!in_array($status, ['draft', 'published'], true)) {
        $status = 'draft';
    }
    if ($endDate === '' && $eventDate !== '') {
        $endDate = $eventDate;
    }
    if ($startTime === '') {
        $startTime = '09:00';
    }
    if ($endTime === '') {
        $endTime = '13:00';
    }
    if ($eventDate !== '') {
        if ($entryOpenDate === '') {
            $entryOpenDate = date('Y-m-d', strtotime($eventDate . ' -1 month'));
        }
        if ($nonMemberEntryOpenDate === '') {
            $baseOpenDate = $entryOpenDate !== '' ? $entryOpenDate : date('Y-m-d', strtotime($eventDate . ' -1 month'));
            $nonMemberEntryOpenDate = date('Y-m-d', strtotime($baseOpenDate . ' +1 week'));
        }
        if ($entryCloseDate === '') {
            $entryCloseDate = date('Y-m-d', strtotime($eventDate . ' -1 week'));
        }
    }
    $entryOpenAt = $entryOpenDate !== '' ? trim($entryOpenDate . ' ' . ($entryOpenTime !== '' ? $entryOpenTime : '00:00')) : null;
    $nonMemberEntryOpenAt = $nonMemberEntryOpenDate !== '' ? trim($nonMemberEntryOpenDate . ' ' . ($nonMemberEntryOpenTime !== '' ? $nonMemberEntryOpenTime : ($entryOpenTime !== '' ? $entryOpenTime : '00:00'))) : null;
    $entryCloseAt = $entryCloseDate !== '' ? trim($entryCloseDate . ' ' . ($entryCloseTime !== '' ? $entryCloseTime : '23:59')) : null;
    $capacityEnabled = !empty($data['capacity_enabled']) ? 1 : 0;
    $capacityLimit = (int)($data['capacity_limit'] ?? 50);
    if ($capacityLimit < 1) {
        $capacityLimit = 50;
    }

    try {
        if ($eventId > 0) {
            $stmt = $pdo->prepare("
                UPDATE events SET
                    title = :title,
                    event_date = :event_date,
                    end_date = :end_date,
                    start_time = :start_time,
                    end_time = :end_time,
                    venue = :venue,
                    venue_id = :venue_id,
                    organiser = :organiser,
                    organiser_user_id = :organiser_user_id,
                    classes_offered = :classes_offered,
                    entry_open_at = :entry_open_at,
                    non_member_entry_open_at = :non_member_entry_open_at,
                    entry_close_at = :entry_close_at,
                    entry_form = :entry_form,
                    status = :status,
                    description = :description,
                    event_type_id = :event_type_id,
                    capacity_enabled = :capacity_enabled,
                    capacity_limit = :capacity_limit,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => $title,
                ':event_date' => $eventDate,
                ':end_date' => $endDate ?: null,
                ':start_time' => $startTime ?: null,
                ':end_time' => $endTime ?: null,
                ':venue' => $venue,
                ':venue_id' => $venueId > 0 ? $venueId : null,
                ':organiser' => $organiser ?: null,
                ':organiser_user_id' => $organiserUserId > 0 ? $organiserUserId : null,
                ':classes_offered' => is_string($classesOffered) ? $classesOffered : json_encode($classesOffered, JSON_UNESCAPED_UNICODE),
                ':entry_open_at' => $entryOpenAt ?: null,
                ':non_member_entry_open_at' => $nonMemberEntryOpenAt ?: null,
                ':entry_close_at' => $entryCloseAt ?: null,
                ':entry_form' => $entryFormValue,
                ':status' => $status,
                ':description' => $description,
                ':event_type_id' => $eventTypeId ?: null,
                ':capacity_enabled' => $capacityEnabled,
                ':capacity_limit' => $capacityLimit,
                ':id' => $eventId,
            ]);
            return $eventId;
        }
        $stmt = $pdo->prepare("
            INSERT INTO events (title, event_date, end_date, start_time, end_time, venue, venue_id, organiser, organiser_user_id, classes_offered, entry_open_at, non_member_entry_open_at, entry_close_at, entry_form, status, description, event_type_id, capacity_enabled, capacity_limit, created_at, updated_at)
            VALUES (:title, :event_date, :end_date, :start_time, :end_time, :venue, :venue_id, :organiser, :organiser_user_id, :classes_offered, :entry_open_at, :non_member_entry_open_at, :entry_close_at, :entry_form, :status, :description, :event_type_id, :capacity_enabled, :capacity_limit, NOW(), NOW())
        ");
        $stmt->execute([
            ':title' => $title,
            ':event_date' => $eventDate,
            ':end_date' => $endDate ?: null,
            ':start_time' => $startTime ?: null,
            ':end_time' => $endTime ?: null,
            ':venue' => $venue,
            ':venue_id' => $venueId > 0 ? $venueId : null,
            ':organiser' => $organiser ?: null,
            ':organiser_user_id' => $organiserUserId > 0 ? $organiserUserId : null,
            ':classes_offered' => is_string($classesOffered) ? $classesOffered : json_encode($classesOffered, JSON_UNESCAPED_UNICODE),
            ':entry_open_at' => $entryOpenAt ?: null,
            ':non_member_entry_open_at' => $nonMemberEntryOpenAt ?: null,
            ':entry_close_at' => $entryCloseAt ?: null,
            ':entry_form' => $entryFormValue,
            ':status' => $status,
            ':description' => $description,
            ':event_type_id' => $eventTypeId ?: null,
            ':capacity_enabled' => $capacityEnabled,
            ':capacity_limit' => $capacityLimit,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save event.'];
        return false;
    }
}

function ensureEventOrganiserUserColumn(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        if (!table_column_exists($pdo, 'events', 'organiser_user_id')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN organiser_user_id INT UNSIGNED NULL DEFAULT NULL AFTER organiser");
        }
        if (
            !table_index_exists($pdo, 'events', 'idx_events_organiser_user_id')
            && !table_index_on_column_exists($pdo, 'events', 'organiser_user_id')
            && table_index_count($pdo, 'events') < 64
        ) {
            $pdo->exec("ALTER TABLE events ADD INDEX idx_events_organiser_user_id (organiser_user_id)");
        }
    } catch (PDOException $e) {
        // ignore; callers remain resilient
    }
}

function fetchEligibleEventOrganisers(?PDO $pdo): array
{
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->query("
            SELECT u.id, u.email, u.first_name, u.last_name, r.name AS role, r.level AS level
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE LOWER(r.name) IN ('organiser', 'admin', 'superadmin')
            ORDER BY COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email) ASC, u.id ASC
        ");
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function fetchEligibleEventOrganiserById(?PDO $pdo, int $userId): ?array
{
    if (!$pdo || $userId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, r.name AS role, r.level AS level
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
              AND LOWER(r.name) IN ('organiser', 'admin', 'superadmin')
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function deleteEvent(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete event.'];
        return false;
    }
}

function defaultFaqs(): array
{
    return [
        ['id' => 0, 'question' => 'Do I need my own horse?', 'answer' => 'Most riders bring their own horse, but pairing opportunities occasionally arise through the club.'],
        ['id' => 0, 'question' => 'What distances can I start with?', 'answer' => 'Pleasure rides often start around 8 miles with competitive trail rides from 20 miles upward.'],
    ];
}

function fetchFaqs(?PDO $pdo): array
{
    if (!$pdo) {
        return defaultFaqs();
    }

    try {
        $stmt = $pdo->query("SELECT * FROM faqs ORDER BY display_order ASC, id DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return defaultFaqs();
    }
}

function fetchFaqById(?PDO $pdo, int $id): ?array
{
    if (!$pdo || $id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM faqs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function saveFaq(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    $faqId = isset($data['faq_id']) ? (int)$data['faq_id'] : 0;
    $question = trim((string)($data['question'] ?? ''));
    $answer = trim((string)($data['answer'] ?? ''));
    $displayOrder = (int)($data['display_order'] ?? 0);

    if ($question === '' || $answer === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'FAQ question and answer are required.'];
        return false;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS faqs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                question TEXT NOT NULL,
                answer TEXT NOT NULL,
                display_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        if ($faqId > 0) {
            $stmt = $pdo->prepare("
                UPDATE faqs SET
                    question = :question,
                    answer = :answer,
                    display_order = :display_order,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':question' => $question,
                ':answer' => $answer,
                ':display_order' => $displayOrder,
                ':id' => $faqId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO faqs (question, answer, display_order, created_at, updated_at)
                VALUES (:question, :answer, :display_order, NOW(), NOW())
            ");
            $stmt->execute([
                ':question' => $question,
                ':answer' => $answer,
                ':display_order' => $displayOrder,
            ]);
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save FAQ.'];
        return false;
    }
}

function deleteFaq(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM faqs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not delete FAQ.'];
        return false;
    }
}

/**
 * Dynamic page sections mapping.
 *
 * Keep per-page dynamic integrations here so templates can stay generic.
 * Return value:
 * - before_content: renders before the main content row
 * - after_body: renders inside the main content card, after static body_html
 */
function page_dynamic_sections(?PDO $pdo, array $page, string $basePath = ''): array
{
    $sections = [
        'before_content' => '',
        'after_body' => '',
    ];

    $slug = strtolower(trim((string)($page['slug'] ?? '')));
    $group = strtolower(trim((string)($page['nav_group'] ?? '')));

    // Events pages should steer users to the dedicated events template.
    if ($group === 'events') {
        ob_start();
        ?>
        <div class="card-soft p-3 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <div class="fw-bold">Looking for the event listings?</div>
                    <div class="text-muted small">Events now live on a dedicated template with detail pages.</div>
                </div>
                <a class="btn btn-success" href="<?php echo h($basePath); ?>/events">View events</a>
            </div>
        </div>
        <?php
        $sections['before_content'] = (string)ob_get_clean();
    }

    // FAQ pages render DB-backed accordion entries.
    $isFaqPage = $group === 'faqs' || in_array($slug, ['faq', 'faqs'], true);
    if ($isFaqPage) {
        $faqs = fetchFaqs($pdo);
        ob_start();
        ?>
        <hr class="my-4">
        <?php if ($faqs): ?>
            <div class="accordion" id="faqAccordion">
                <?php foreach ($faqs as $index => $faq): ?>
                    <?php $collapseId = 'faqCollapse' . (int)$index; ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faqHeading<?php echo (int)$index; ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo h($collapseId); ?>" aria-expanded="false" aria-controls="<?php echo h($collapseId); ?>">
                                <?php echo h((string)($faq['question'] ?? '')); ?>
                            </button>
                        </h2>
                        <div id="<?php echo h($collapseId); ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body"><?php echo render_wysiwyg((string)($faq['answer'] ?? '')); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">No FAQs have been added yet.</div>
        <?php endif; ?>
        <?php
        $sections['after_body'] = (string)ob_get_clean();
    }

    return $sections;
}

function buildNavTree(array $pages): array
{
    $nav = [];
    foreach (NAV_GROUPS as $key => $label) {
        $nav[$key] = ['label' => $label, 'pages' => []];
    }

    foreach ($pages as $page) {
        $group = $page['nav_group'] ?? 'home';
        if (!isset($nav[$group])) {
            $nav[$group] = ['label' => ucfirst(str_replace('-', ' ', $group)), 'pages' => []];
        }
        $nav[$group]['pages'][] = $page;
    }

    return $nav;
}

function contentCounts(array $pages, array $events, array $faqs): array
{
    return [
        'pages' => count($pages),
        'events' => count($events),
        'faqs' => count($faqs),
    ];
}
