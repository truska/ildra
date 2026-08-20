<?php
declare(strict_types=1);

session_start();

if (isset($_GET['clear_sql_errors'])) {
    unset($_SESSION['sql_errors']);
    if (!headers_sent()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['clear_sql_errors']);
        $clean = $path;
        if ($query) {
            $clean .= '?' . http_build_query($query);
        }
        header('Location: ' . $clean);
        exit;
    }
}

// Exit "act as" / impersonation mode (front-end only).
// This is intentionally handled before flash extraction so redirects are clean.
if (isset($_GET['exit_act_as'])) {
    if (!headers_sent()) {
        if (!empty($_SESSION['act_as_original_user']) && is_array($_SESSION['act_as_original_user'])) {
            $_SESSION['user'] = $_SESSION['act_as_original_user'];
        }
        unset($_SESSION['act_as_original_user'], $_SESSION['act_as_started_at']);

        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $basePath = $basePath === '/' ? '' : $basePath;
        if (substr($basePath, -6) === '/admin') {
            $basePath = rtrim(substr($basePath, 0, -6), '/');
        }

        // Optional safe redirect after exit (internal paths only).
        $returnTo = (string)($_GET['return'] ?? '');
        $returnPath = '';
        if ($returnTo !== '') {
            $parsed = @parse_url($returnTo);
            $hasExternalParts = is_array($parsed) && (!empty($parsed['scheme']) || !empty($parsed['host']));
            if (!$hasExternalParts) {
                // Only allow absolute paths within this app base (prevents open redirects).
                if (strpos($returnTo, ($basePath ?: '') . '/') === 0) {
                    $returnPath = $returnTo;
                }
            }
        }

        header('Location: ' . ($returnPath !== '' ? $returnPath : (($basePath ?: '') . '/')));
        exit;
    }
}

// Flash messages from previous request (redirects)
$alerts = $_SESSION['flash_alerts'] ?? [];
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_alerts'], $_SESSION['flash_success']);

$config = require __DIR__ . '/config.php';
// Optional: load private stripe overrides if present (not committed to git).
foreach ([__DIR__ . '/../private/stripe.php', __DIR__ . '/private/stripe.php'] as $stripePath) {
    if (file_exists($stripePath)) {
        $stripeConfig = require $stripePath;
        if (is_array($stripeConfig)) {
            $config['stripe'] = array_merge($config['stripe'] ?? [], $stripeConfig);
        }
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/image_upload.php';
require_once __DIR__ . '/media_batches.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/cms.php';
require_once __DIR__ . '/admin_menu.php';
require_once __DIR__ . '/help_support.php';
require_once __DIR__ . '/dev_tasks.php';
require_once __DIR__ . '/event_ride_notes.php';
require_once __DIR__ . '/bookings_store.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/stripe.php';

$pdo = createPdo($config, $alerts);
$currentUser = $_SESSION['user'] ?? null;
$allUsers = [];

// Site settings are needed for shared behaviours (basket timeout, remember-me TTL, etc.)
$siteSettingsBootstrap = $pdo ? getSiteSettings($pdo) : defaultSiteSettings();

// If there's no active session, try to restore a user session from a "remember me" cookie.
if (!$currentUser && $pdo) {
    tidyAuthTokens($pdo);
    $remembered = attemptRememberMeLogin($pdo, $siteSettingsBootstrap, $alerts);
    if ($remembered) {
        $currentUser = $_SESSION['user'] ?? $remembered;
    }
}

// While acting-as, keep the original admin user in a separate session key and treat
// the current session user as the impersonated user across the front-end.
$isActingAs = !empty($_SESSION['act_as_original_user']);
$actingAsOriginalUser = $isActingAs && is_array($_SESSION['act_as_original_user'])
    ? $_SESSION['act_as_original_user']
    : null;

// Refresh user details from DB each request so role/level changes take effect
if ($currentUser && $pdo) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name, u.last_login_at
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => (int)$currentUser['id']]);
    $fresh = $stmt->fetch();
    if ($fresh) {
        $fresh['level'] = (int)$fresh['level'];
        $_SESSION['user'] = $currentUser = $fresh;
    } else {
        $_SESSION['user'] = $currentUser = null;
    }
}
$allUsers = [];

// Basket tidy/load (shared across front/back)
if ($pdo) {
    $basketTimeoutBootstrap = max(300, (int)($siteSettingsBootstrap['basket_timeout_seconds'] ?? 900));
    ensureEntryComponentsTables($pdo); // also ensures basket table
    ensureMembershipTables($pdo);
    ensureHorsesTables($pdo);
    ensureShareTables($pdo);
    ensureEmailTables($pdo);
    ensurePricingSchemeTables($pdo);
    ensureEventPricingTables($pdo);
    ensureDefaultPricingSchemes($pdo);
    tidyExpiredBaskets($pdo, $basketTimeoutBootstrap);
    [$storedBasket, $storedLast, $storedUser] = loadBasketForSession($pdo, session_id());
    if ($storedBasket !== null) {
        // Sync from DB if present, but don't wipe in-memory basket if nothing is stored.
        $_SESSION['basket'] = $storedBasket;
        $_SESSION['basket_last_added'] = $storedLast;
    }
}
