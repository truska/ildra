<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';
$activeTab = ($_POST['action'] ?? '') === 'register' ? 'register' : 'login';
$authView = (string)($_GET['auth'] ?? '');
$allowedAuthViews = ['forgot', 'magic', 'choose', 'password', 'app'];
if (!in_array($authView, $allowedAuthViews, true)) {
    $authView = 'default';
}
$resetToken = trim((string)($_GET['reset'] ?? ''));
$resetTokenInfo = null;
if ($resetToken !== '' && $pdo) {
    $resetTokenInfo = inspectPasswordResetToken($pdo, $resetToken);
    $activeTab = 'login';
    $authView = 'reset';
}

if (isset($_GET['logout'])) {
    handleLogout();
}

$siteSettings = getSiteSettings($pdo);
$loginEmail = currentLoginEmail();
$loginMethods = $loginEmail !== '' ? loginMethodState($pdo, $loginEmail, $siteSettings) : null;

// Magic-link sign-in (passwordless)
if (!$currentUser && $pdo && isset($_GET['magic'])) {
    $token = (string)($_GET['magic'] ?? '');
    $user = consumeMagicLoginToken($pdo, $token, $alerts);
    if ($user) {
        $_SESSION['flash_success'] = 'Signed in.';
        header('Location: ' . $basePath . '/account');
        exit;
    }
}

$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$userId = (int)($currentUser['id'] ?? 0);
$authAppStatus = $isLoggedIn ? currentUserAuthAppStatus($pdo, $userId) : ['enabled' => false, 'confirmed_at' => null];
$authAppSetup = $isLoggedIn ? pendingAuthAppSetup($currentUser ?: [], $siteSettings) : null;
$accountView = (string)($_GET['view'] ?? '');
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basket = $_SESSION['basket'] ?? [];
$basketCount = count($basket);
$recentBookings = [];
$userBookingCount = 0;
$userCreditBalance = 0.0;
$loyaltyCard = null;
$incomingShareRequests = [];
$allBookings = load_all_bookings($pdo);
if ($isLoggedIn) {
    $userId = (int)($currentUser['id'] ?? 0);
    $userEmail = strtolower((string)($currentUser['email'] ?? ''));
    $userCreditBalance = fetch_user_credit_balance($pdo, $userId);
    $loyaltyCard = fetch_or_create_loyalty_card($pdo, $userId, $alerts);
    $incomingShareRequests = fetchIncomingShareRequests($pdo, $userId);
    foreach ($allBookings as $booking) {
        $matchesUser = ($userId > 0 && (int)($booking['user_id'] ?? 0) === $userId)
            || ($userEmail !== '' && strtolower((string)($booking['contact_email'] ?? '')) === $userEmail);
        if ($matchesUser) {
            $userBookingCount++;
            $recentBookings[] = $booking;
        }
    }
}
$userMembershipPurchases = [];
if ($isLoggedIn) {
    $userMembershipPurchases = array_values(array_filter(fetchMemberships($pdo), static function (array $row) use ($userId): bool {
        return (int)($row['purchased_by_user_id'] ?? $row['user_id'] ?? 0) === (int)$userId;
    }));
}
$activeMembershipPurchases = [];
$previousMembershipPurchases = [];
if ($isLoggedIn) {
    $activeMembershipPurchases = array_values(array_filter($userMembershipPurchases, static function (array $row): bool {
        return strtolower((string)($row['status'] ?? 'active')) !== 'expired';
    }));
    $previousMembershipPurchases = array_values(array_filter($userMembershipPurchases, static function (array $row): bool {
        return strtolower((string)($row['status'] ?? '')) === 'expired';
    }));
}
$activeMembershipCount = count($activeMembershipPurchases);
$previousMembershipCount = count($previousMembershipPurchases);
$navItemEventsUrl = $basePath . '/events';
$accountName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?: ($currentUser['email'] ?? 'Your account');
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
$showPageHero = $scriptName === 'index.php';

if ($recentBookings) {
    usort($recentBookings, static function (array $a, array $b): int {
        $va = (string)($a['created_at'] ?? '');
        $vb = (string)($b['created_at'] ?? '');
        if ($va === $vb) {
            return 0;
        }
        // Descending: newest first.
        return $va < $vb ? 1 : -1;
    });
    $recentBookings = array_slice($recentBookings, 0, 5);
}

$promptPersonId = 0;
$promptPerson = null;
if ($isLoggedIn) {
    $promptPersonId = (int)($_SESSION['prompt_person_completion'] ?? 0);
    if ($promptPersonId > 0 && $pdo) {
        $promptPerson = fetchPersonForUserById($pdo, $userId, $promptPersonId);
    }
    if ($promptPersonId > 0) {
        unset($_SESSION['prompt_person_completion']);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $user = handleRegister($pdo, $alerts, $successMessage);
        if ($user) {
            $currentUser = $user;
            $_SESSION['flash_success'] = $successMessage;
            header('Location: ' . $basePath . '/account');
            exit;
        }
    } elseif ($action === 'login_lookup') {
        $loginMethods = handleLoginLookup($pdo, $siteSettings, $alerts);
        $loginEmail = currentLoginEmail();
        if ($loginMethods && !$alerts) {
            $authView = 'choose';
        }
    } elseif ($action === 'login') {
        $authView = 'password';
        $loginEmail = currentLoginEmail();
        rememberLoginEmail($loginEmail);
        $loginMethods = $loginEmail !== '' ? loginMethodState($pdo, $loginEmail, $siteSettings) : null;
        $user = handleLogin($pdo, $siteSettings, $alerts, $successMessage);
        if ($user) {
            $currentUser = $user;
            $_SESSION['flash_success'] = $successMessage;
            header('Location: ' . $basePath . '/account');
            exit;
        }
    } elseif ($action === 'auth_app_login') {
        $authView = 'app';
        $loginEmail = currentLoginEmail();
        rememberLoginEmail($loginEmail);
        $loginMethods = $loginEmail !== '' ? loginMethodState($pdo, $loginEmail, $siteSettings) : null;
        $user = handleAuthAppLogin($pdo, $siteSettings, $alerts, $successMessage);
        if ($user) {
            $currentUser = $user;
            $_SESSION['flash_success'] = $successMessage;
            header('Location: ' . $basePath . '/account');
            exit;
        }
    } elseif ($action === 'magic_link') {
        $authView = 'magic';
        $loginEmail = currentLoginEmail();
        if ($loginEmail !== '') {
            $_POST['email'] = $loginEmail;
            rememberLoginEmail($loginEmail);
        }
        handleMagicLinkRequest($pdo, $siteSettings, $alerts, $successMessage);
        if (!$alerts && $successMessage) {
            $_SESSION['flash_success'] = $successMessage;
            header('Location: ' . $basePath . '/account?auth=magic');
            exit;
        }
    } elseif ($action === 'password_reset_request') {
        $authView = 'forgot';
        handlePasswordResetRequest($pdo, $alerts, $successMessage);
        if (!$alerts && $successMessage) {
            $_SESSION['flash_success'] = $successMessage;
            header('Location: ' . $basePath . '/account?auth=forgot');
            exit;
        }
    } elseif ($action === 'password_reset') {
        $authView = 'reset';
        $user = handlePasswordReset($pdo, $alerts, $successMessage);
        if ($user && !$alerts) {
            $currentUser = $user;
            $_SESSION['flash_success'] = $successMessage;
            header('Location: ' . $basePath . '/account');
            exit;
        }
    } elseif ($isLoggedIn) {
        $userId = (int)($currentUser['id'] ?? 0);
        if ($action === 'auth_app_begin_setup') {
            $authAppSetup = beginAuthAppSetup($currentUser ?: [], $siteSettings);
            $accountView = 'security';
        } elseif ($action === 'auth_app_confirm_setup') {
            $accountView = 'security';
            if (confirmAuthAppSetup($pdo, $userId, $alerts)) {
                $_SESSION['flash_success'] = 'Authenticator app login enabled.';
                header('Location: ' . $basePath . '/account?view=security');
                exit;
            }
            $authAppSetup = pendingAuthAppSetup($currentUser ?: [], $siteSettings);
        } elseif ($action === 'auth_app_disable') {
            $accountView = 'security';
            if (disableAuthApp($pdo, $userId, $alerts)) {
                $_SESSION['flash_success'] = 'Authenticator app login disabled.';
                header('Location: ' . $basePath . '/account?view=security');
                exit;
            }
        } elseif ($action === 'save_person') {
            $personId = (int)($_POST['person_id'] ?? 0);
            $savedId = savePersonForUser($pdo, $userId, $_POST, $alerts, $personId > 0 ? $personId : null);
            if ($savedId && !$alerts) {
                $_SESSION['flash_success'] = $personId > 0 ? 'Person updated.' : 'Person added.';
                header('Location: ' . $basePath . '/account?view=people&person_id=' . (int)$savedId . '#person-form');
                exit;
            }
        } elseif ($action === 'archive_person') {
            $personId = (int)($_POST['person_id'] ?? 0);
            if ($personId > 0 && archivePersonForUser($pdo, $userId, $personId, $alerts)) {
                $_SESSION['flash_success'] = 'Person archived.';
                header('Location: ' . $basePath . '/account?view=people');
                exit;
            }
        } elseif ($action === 'create_share') {
            $entityType = (string)($_POST['entity_type'] ?? '');
            $entityId = (int)($_POST['entity_id'] ?? 0);
            $entityRef = (string)($_POST['entity_ref'] ?? '');
            if ($entityRef !== '' && strpos($entityRef, ':') !== false) {
                [$entityType, $entityIdRaw] = explode(':', $entityRef, 2);
                $entityId = (int)$entityIdRaw;
            }
            $recipient = trim((string)($_POST['recipient'] ?? ''));
            $request = createShareRequest($pdo, $userId, $entityType, $entityId, $recipient, $alerts);
            if ($request && !$alerts) {
                if (!empty($request['target_user_id'])) {
                    $_SESSION['flash_success'] = 'Share request sent to their dashboard.';
                } else {
                    $_SESSION['flash_success'] = 'Share code created: ' . (string)$request['code'];
                }
                header('Location: ' . $basePath . '/account?view=shares');
                exit;
            }
        } elseif ($action === 'create_external_share_code') {
            $entityType = (string)($_POST['entity_type'] ?? '');
            $entityId = (int)($_POST['entity_id'] ?? 0);
            $entityRef = (string)($_POST['entity_ref'] ?? '');
            if ($entityRef !== '' && strpos($entityRef, ':') !== false) {
                [$entityType, $entityIdRaw] = explode(':', $entityRef, 2);
                $entityId = (int)$entityIdRaw;
            }
            $request = createExternalShareCode($pdo, $userId, $entityType, $entityId, $alerts);
            if ($request && !$alerts) {
                $_SESSION['flash_success'] = 'External share code created: ' . (string)$request['code'];
                header('Location: ' . $basePath . '/account?view=shares');
                exit;
            }
        } elseif ($action === 'accept_share_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            if (acceptShareRequest($pdo, $userId, $requestId, $alerts)) {
                $_SESSION['flash_success'] = 'Share accepted.';
                header('Location: ' . $basePath . '/account');
                exit;
            }
        } elseif ($action === 'decline_share_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            if (declineShareRequest($pdo, $userId, $requestId, $alerts)) {
                $_SESSION['flash_success'] = 'Share declined.';
                header('Location: ' . $basePath . '/account');
                exit;
            }
        } elseif ($action === 'accept_share_code') {
            $code = (string)($_POST['share_code'] ?? '');
            if (acceptShareCode($pdo, $userId, $code, $alerts)) {
                $_SESSION['flash_success'] = 'Share code accepted.';
                header('Location: ' . $basePath . '/account');
                exit;
            }
        } elseif ($action === 'cancel_share_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            if (cancelShareRequest($pdo, $userId, $requestId, $alerts)) {
                $_SESSION['flash_success'] = 'Pending share cancelled.';
                header('Location: ' . $basePath . '/account?view=shares');
                exit;
            }
        } elseif ($action === 'revoke_shared_access') {
            $entityType = (string)($_POST['entity_type'] ?? '');
            $linkId = (int)($_POST['link_id'] ?? 0);
            if (revokeSharedAccess($pdo, $userId, $entityType, $linkId, $alerts)) {
                $_SESSION['flash_success'] = 'Shared access removed.';
                header('Location: ' . $basePath . '/account?view=shares');
                exit;
            }
        } elseif ($action === 'unlink_shared_record') {
            $entityType = (string)($_POST['entity_type'] ?? '');
            $entityId = (int)($_POST['entity_id'] ?? 0);
            if (unlinkSharedRecord($pdo, $userId, $entityType, $entityId, $alerts)) {
                $_SESSION['flash_success'] = 'Linked record removed from your account.';
                header('Location: ' . $basePath . '/account?view=' . ($entityType === 'horse' ? 'horses' : 'people'));
                exit;
            }
        } elseif ($action === 'save_horse') {
            $horseId = (int)($_POST['horse_id'] ?? 0);
            $savedId = saveHorseForUser($pdo, $userId, $_POST, $alerts, $horseId > 0 ? $horseId : null);
            if ($savedId && !$alerts) {
                $_SESSION['flash_success'] = $horseId > 0 ? 'Horse updated.' : 'Horse added.';
                header('Location: ' . $basePath . '/account?view=horses&horse_id=' . (int)$savedId . '#horse-form');
                exit;
            }
        } elseif ($action === 'archive_horse') {
            $horseId = (int)($_POST['horse_id'] ?? 0);
            if ($horseId > 0 && archiveHorseForUser($pdo, $userId, $horseId, $alerts)) {
                $_SESSION['flash_success'] = 'Horse archived.';
                header('Location: ' . $basePath . '/account?view=horses');
                exit;
            }
        } elseif ($action === 'add_logbook') {
            $typeId = (int)($_POST['logbook_type_id'] ?? 0);
            $logbookType = fetchHorseLogbookTypeById($pdo, $typeId);
            if (!$logbookType || strtolower((string)($logbookType['status'] ?? '')) !== 'published') {
                $alerts[] = ['type' => 'danger', 'message' => 'Logbook type not available.'];
            } else {
                $horseId = (int)($_POST['horse_id'] ?? 0);
                $horse = $horseId > 0 ? fetchHorseForUserById($pdo, $userId, $horseId) : null;
                if (!$horse || !empty($horse['is_linked'])) {
                    $alerts[] = ['type' => 'danger', 'message' => 'Select a horse for this logbook.'];
                }
                $logbookYear = (int)($logbookType['valid_year'] ?? (int)date('Y'));
                if (!$alerts) {
                    foreach ($_SESSION['basket'] ?? [] as $item) {
                        if (($item['booking_type'] ?? '') === 'horse_logbook'
                            && (int)($item['horse_id'] ?? 0) === $horseId
                            && (int)($item['logbook_year'] ?? 0) === $logbookYear) {
                            $alerts[] = ['type' => 'warning', 'message' => 'That horse already has this year\'s logbook in the basket.'];
                            break;
                        }
                    }
                }
                if (!$alerts && horse_has_logbook_for_year($pdo, $horseId, $logbookYear)) {
                    $alerts[] = ['type' => 'warning', 'message' => 'That horse already has a logbook for this year.'];
                }
                if (!$alerts && $horse) {
                    $basket = $_SESSION['basket'] ?? [];
                    $entry = [
                        'id' => uniqid('log', true),
                        'booking_type' => 'horse_logbook',
                        'logbook_type_id' => $logbookType['id'],
                        'logbook_year' => $logbookYear,
                        'horse_id' => $horseId,
                        'horse_name' => $horse['name'] ?? '',
                        'membership_name' => $logbookType['name'],
                        'event_title' => 'Horse logbook: ' . ($horse['name'] ?? 'Horse'),
                        'class_label' => 'Horse logbook ' . $logbookYear,
                        'rider_name' => $horse['name'] ?? '',
                        'horse_name' => $horse['name'] ?? '',
                        'price' => $logbookType['cost'] ?? '0',
                    ];
                    $basket[] = $entry;
                    $_SESSION['basket'] = $basket;
                    $_SESSION['basket_last_added'] = time();
                    saveBasketForSession($pdo, session_id(), $basket, $userId, $_SESSION['basket_last_added']);
                    $_SESSION['flash_success'] = 'Logbook added to basket.';
                    header('Location: ' . $basePath . '/basket');
                    exit;
                }
            }
        }
    }
    $activeTab = $action === 'register' ? 'register' : 'login';
}

$isLoggedIn = !empty($currentUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account | <?php echo h($siteSettings['hero_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
	    <style>
        :root {
            --green: #146118;
            --green-alt: #1f7c24;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
            --accent: var(--green);
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .page-hero {
            background: linear-gradient(120deg, rgba(20, 97, 24, 0.9), rgba(20, 97, 24, 0.75)), url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            padding: 2.5rem 0;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.12), transparent 32%);
            z-index: 0;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .card-strong { box-shadow: 0 20px 60px rgba(0,0,0,0.12); }
	        .badge-role {
            background: rgba(20, 97, 24, 0.12);
            color: var(--green);
            border: 1px solid rgba(20, 97, 24, 0.2);
            border-radius: 999px;
            padding: 4px 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
	        }
	        .sort-link { display: inline-flex; align-items: center; gap: 0.35rem; }
	        .sort-arrow { display: inline-block; width: 1ch; text-align: center; color: #888; }
        .meta-chip {
            background: rgba(20, 97, 24, 0.12);
            color: var(--green);
            padding: 6px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
        }
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0, 0, 0, 0.08), transparent);
            margin: 1.25rem 0;
        }
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(20, 97, 24, 0.08);
            color: var(--green);
            font-weight: 700;
        }
        .stat-pill .small { color: #23492a; }
        .quick-actions .btn { min-width: 130px; }
        .table thead th { font-size: 0.85rem; letter-spacing: 0.01em; }
        .table td, .table th { vertical-align: middle; }
        .small-link {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--green-alt);
        }
        .auth-helper {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 14px;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .btn-toggle {
            background: var(--site-btn-quiet-bg, #f2f5ee);
            color: var(--site-btn-quiet-text, var(--green));
            border: 1px solid var(--site-btn-quiet-border, rgba(20, 97, 24, 0.2));
            border-radius: 12px;
            padding: 0.65rem 1.4rem;
            transition: background-color 0.22s ease, border-color 0.22s ease, color 0.22s ease, box-shadow 0.22s ease, transform 0.22s ease;
        }
        .btn-toggle:hover,
        .btn-toggle:focus-visible {
            background: rgba(20, 97, 24, 0.12);
            border-color: rgba(20, 97, 24, 0.38);
            color: var(--green-alt);
            transform: translateY(-1px);
            box-shadow: var(--site-btn-hover-shadow, 0 10px 24px rgba(20, 97, 24, 0.16));
        }
        .btn-toggle.active {
            color: #f8fbf7;
            background: var(--accent);
            border-color: var(--accent);
            box-shadow: 0 10px 30px rgba(20, 97, 24, 0.28);
        }
        .btn-toggle.active:hover,
        .btn-toggle.active:focus-visible {
            color: #f8fbf7;
            background: var(--green-alt);
            border-color: var(--green-alt);
        }
        .auth-link-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .auth-link-row .btn {
            min-width: 0;
        }
        .auth-link-btn {
            padding: 0.5rem 0.9rem;
            font-size: 0.9rem;
            font-weight: 700;
            border-radius: 10px;
        }
        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(0, 0, 0, 0.06);
            color: var(--text-main);
            border-radius: 12px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.25rem rgba(20, 97, 24, 0.18);
            background: #fff;
            color: var(--text-main);
        }
        .placeholder-muted::placeholder {
            color: var(--muted);
            transition: color 0.2s ease;
        }
        .linked-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 999px;
            border: 1px solid rgba(20, 97, 24, 0.2);
            color: var(--green);
            background: rgba(20, 97, 24, 0.08);
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 800;
        }
        .person-type-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.45rem;
            height: 1.45rem;
            border-radius: 999px;
            border: 1px solid rgba(20, 97, 24, 0.18);
            color: var(--green);
            background: rgba(20, 97, 24, 0.08);
            font-size: 0.78rem;
            vertical-align: middle;
        }
        .person-type-select {
            font-family: "Font Awesome 6 Free", "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 900;
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body class="<?php echo $showPageHero ? 'show-page-hero' : ''; ?>">
    <?php include __DIR__ . '/views/header.php'; ?>

	    <header class="page-hero">
	        <div class="container">
	            <p class="mb-1 text-uppercase small fw-bold text-white-50">Account</p>
	            <h1 class="fw-bold mb-1">
                    <?php
                    if (!$isLoggedIn) {
                        echo 'Sign in to manage your account';
                    } elseif ($accountView === 'people') {
                        echo 'Your people';
                    } elseif ($accountView === 'horses') {
                        echo 'Your horses';
                    } elseif ($accountView === 'shares') {
                        echo 'Shares';
                    } elseif ($accountView === 'security') {
                        echo 'Security';
                    } else {
                        echo 'Your account';
                    }
                    ?>
                </h1>
	            <div class="text-white-50">
	                <?php echo $isLoggedIn ? 'Manage memberships, people, horses, and your basket.' : 'Create an account to manage bookings and memberships.'; ?>
	            </div>
	            <div class="d-flex flex-wrap gap-2 mt-3">
	                <span class="meta-chip">Memberships: <?php echo $activeMembershipCount; ?> active<?php echo $previousMembershipCount ? ' · ' . $previousMembershipCount . ' previous' : ''; ?></span>
	                <span class="meta-chip">Basket: <?php echo $basketCount; ?></span>
	                <?php if ($isLoggedIn): ?>
	                    <span class="meta-chip text-white bg-success border-0">Role: <?php echo h($currentUser['role'] ?? 'user'); ?></span>
	                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>

	            <?php if ($isLoggedIn): ?>
	                <div class="row g-4">
	                    <div class="col-12">
	                        <div class="card-soft p-4">
	                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
	                                <div>
	                                    <div class="text-uppercase small text-muted">Signed in as</div>
	                                    <h3 class="fw-bold mb-1"><?php echo h($accountName); ?></h3>
	                                    <div class="text-muted small"><?php echo h($currentUser['email'] ?? ''); ?></div>
	                                </div>
	                                <div class="d-flex flex-wrap gap-2 align-items-center">
	                                    <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/account?view=people">People</a>
	                                    <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/account?view=horses">Horses</a>
	                                    <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/account?view=shares">Shares</a>
	                                    <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/account?view=security">Security</a>
	                                    <?php if ($canViewAdmin): ?>
	                                        <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/admin/index.php">Admin</a>
	                                    <?php endif; ?>
	                                    <a class="btn btn-outline-danger" href="<?php echo h($basePath); ?>/account?logout=1">Logout</a>
	                                </div>
	                            </div>

	                            <div class="divider"></div>

	                            <div class="d-flex flex-wrap gap-2">
	                                <div class="stat-pill">
	                                    <div class="fw-bold"><?php echo $activeMembershipCount; ?></div>
	                                    <div class="small text-uppercase">Active memberships</div>
	                                </div>
	                                <div class="stat-pill">
	                                    <div class="fw-bold"><?php echo $basketCount; ?></div>
	                                    <div class="small text-uppercase">Basket items</div>
	                                </div>
	                                <div class="stat-pill">
	                                    <div class="fw-bold"><?php echo $userBookingCount; ?></div>
	                                    <div class="small text-uppercase">Bookings</div>
	                                </div>
		                                <div class="stat-pill">
		                                    <div class="fw-bold"><?php echo '£' . number_format((float)$userCreditBalance, 2); ?></div>
		                                    <div class="small text-uppercase">Credit balance</div>
		                                </div>
		                                <?php if ($loyaltyCard): ?>
		                                    <div class="stat-pill">
		                                        <div class="fw-bold"><?php echo (int)($loyaltyCard['points_balance'] ?? 0); ?></div>
		                                        <div class="small text-uppercase">Loyalty points</div>
		                                    </div>
		                                <?php endif; ?>
		                            </div>
		                        </div>

                                <?php if ($incomingShareRequests): ?>
                                <div class="card-soft p-4 mt-4" id="share-requests">
                                    <div>
                                        <div class="fw-bold">Shared with you</div>
                                        <div class="text-muted small">Accept or decline pending share requests.</div>
                                    </div>
                                        <div class="divider"></div>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Record</th>
                                                        <th>Shared by</th>
                                                        <th>Expires</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($incomingShareRequests as $request): ?>
                                                        <?php
                                                        $creator = trim((string)($request['creator_first_name'] ?? '') . ' ' . (string)($request['creator_last_name'] ?? ''));
                                                        if ($creator === '') {
                                                            $creator = (string)($request['creator_email'] ?? 'User');
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td class="fw-semibold"><?php echo h(ucfirst((string)$request['entity_type'])); ?>: <?php echo h((string)$request['entity_label']); ?></td>
                                                            <td class="text-muted small"><?php echo h($creator); ?></td>
                                                            <td class="text-muted small"><?php echo h(format_display_date($request['expires_at'] ?? null, '—')); ?></td>
                                                            <td class="text-end">
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="action" value="accept_share_request">
                                                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                                    <button class="btn btn-sm btn-success" type="submit">Accept</button>
                                                                </form>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="action" value="decline_share_request">
                                                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Decline</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                </div>
                                <?php endif; ?>

                            <?php if ($accountView === 'security'): ?>
                                <div class="card-soft p-4 mt-4">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-3">
                                        <div>
                                            <div class="text-uppercase small text-muted">Security</div>
                                            <h4 class="fw-bold mb-1">Authenticator app</h4>
                                            <div class="text-muted small">Use a 6-digit code from an authentication app as a sign-in option.</div>
                                        </div>
                                        <span class="badge-role"><?php echo !empty($authAppStatus['enabled']) ? 'Enabled' : 'Not set up'; ?></span>
                                    </div>
                                    <?php if (empty($siteSettings['auth_app_login_enabled']) || (string)$siteSettings['auth_app_login_enabled'] === '0'): ?>
                                        <div class="alert alert-warning mb-3">Authenticator app login is currently disabled at site level. Users can set it up, but it will not appear as a login option until the site setting is enabled.</div>
                                    <?php endif; ?>
                                    <?php if (!empty($authAppStatus['enabled'])): ?>
                                        <p class="text-muted small mb-3">Confirmed <?php echo h(format_display_date($authAppStatus['confirmed_at'] ?? null, 'recently')); ?>.</p>
                                        <form method="POST" onsubmit="return confirm('Disable authenticator app login for this account?');">
                                            <input type="hidden" name="action" value="auth_app_disable">
                                            <button class="btn btn-outline-danger">Disable authenticator app</button>
                                        </form>
                                    <?php else: ?>
                                        <?php if (!$authAppSetup): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="auth_app_begin_setup">
                                                <button class="btn btn-success">Set up authenticator app</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="row g-4 align-items-start">
                                                <div class="col-md-auto">
                                                    <?php if (!empty($authAppSetup['qr_data_uri'])): ?>
                                                        <img src="<?php echo h($authAppSetup['qr_data_uri']); ?>" alt="Authenticator app QR code" width="220" height="220" class="border rounded bg-white p-2">
                                                    <?php else: ?>
                                                        <div class="border rounded bg-white p-3 text-muted small" style="max-width: 220px;">QR generation is unavailable on this server. Enter the setup key manually.</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md">
                                                    <label class="form-label fw-semibold">Setup key</label>
                                                    <div class="form-control bg-white fw-bold" style="height: auto; word-break: break-word;"><?php echo h($authAppSetup['formatted_secret'] ?? ''); ?></div>
                                                    <div class="text-muted small mt-2">Scan the QR code or enter this key in your authenticator app, then type the current 6-digit code below.</div>
                                                    <form method="POST" class="row g-3 mt-1">
                                                        <input type="hidden" name="action" value="auth_app_confirm_setup">
                                                        <div class="col-sm-7 col-lg-5">
                                                            <label class="form-label">Code</label>
                                                            <input type="text" name="code" class="form-control" placeholder="123456" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required>
                                                        </div>
                                                        <div class="col-12">
                                                            <button class="btn btn-success">Verify and enable</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($accountView === 'people'): ?>
                                <?php
                                $userId = (int)($currentUser['id'] ?? 0);
                                $peopleAll = fetchMembersForUser($pdo, $userId, true);
                                $activePeople = array_values(array_filter($peopleAll, static fn(array $p): bool => (int)($p['is_archived'] ?? 0) === 0));
                                $archivedPeople = array_values(array_filter($peopleAll, static fn(array $p): bool => (int)($p['is_archived'] ?? 0) === 1));
                                $editPersonId = (int)($_GET['person_id'] ?? 0);
                                $editPerson = $editPersonId > 0 ? fetchPersonForUserById($pdo, $userId, $editPersonId) : null;
                                if ($editPerson && !empty($editPerson['is_linked'])) {
                                    $editPerson = null;
                                }
                                ?>

	                                <div class="card-soft p-4 mt-4">
	                                    <div class="d-flex justify-content-between align-items-center mb-3">
	                                        <div class="fw-bold">Your people</div>
	                                        <div class="text-muted small"><?php echo count($activePeople); ?> active<?php echo $archivedPeople ? ' · ' . count($archivedPeople) . ' archived' : ''; ?></div>
	                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Member #</th>
                                                    <th>DOB</th>
                                                    <th>Email</th>
                                                    <th>Phone</th>
                                                    <th>Postcode</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!$activePeople): ?>
                                                    <tr><td colspan="7" class="text-muted small">No people yet.</td></tr>
                                                <?php endif; ?>
	                                                <?php foreach ($activePeople as $p): ?>
                                                        <?php $personType = personRecordType($p, $currentUser ?? []); ?>
	                                                    <tr>
	                                                        <td class="fw-semibold">
                                                                <span class="person-type-icon me-1" title="<?php echo h(ucfirst($personType)); ?>" aria-label="<?php echo h(ucfirst($personType)); ?>">
                                                                    <i class="<?php echo h(personRecordTypeIcon($personType)); ?>" aria-hidden="true"></i>
                                                                </span>
                                                                <?php echo h(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))); ?>
                                                            </td>
	                                                        <td class="text-muted small"><?php echo $p['member_number'] ? (int)$p['member_number'] : '—'; ?></td>
	                                                        <td class="text-muted small"><?php echo h(format_display_date($p['dob'] ?? null, '—')); ?></td>
	                                                        <td class="text-muted small"><?php echo h($p['email'] ?? '—'); ?></td>
	                                                        <td class="text-muted small"><?php echo h($p['phone'] ?? '—'); ?></td>
	                                                        <td class="text-muted small text-uppercase"><?php echo h($p['postcode'] ?? '—'); ?></td>
	                                                        <td class="text-end">
                                                                <?php if (!empty($p['is_linked'])): ?>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="action" value="unlink_shared_record">
                                                                        <input type="hidden" name="entity_type" value="person">
                                                                        <input type="hidden" name="entity_id" value="<?php echo (int)$p['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this linked person from your account?');">Remove link</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=people&person_id=<?php echo (int)$p['id']; ?>#person-form">Edit</a>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="action" value="archive_person">
                                                                        <input type="hidden" name="person_id" value="<?php echo (int)$p['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this person?');">Archive</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($archivedPeople): ?>
                                        <div class="divider"></div>
                                        <div class="fw-bold mb-2">Archived</div>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Member #</th>
                                                        <th>DOB</th>
                                                        <th>Email</th>
                                                        <th>Phone</th>
                                                        <th>Postcode</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($archivedPeople as $p): ?>
                                                        <?php $personType = personRecordType($p, $currentUser ?? []); ?>
                                                        <tr class="text-muted">
                                                            <td>
                                                                <span class="person-type-icon me-1" title="<?php echo h(ucfirst($personType)); ?>" aria-label="<?php echo h(ucfirst($personType)); ?>">
                                                                    <i class="<?php echo h(personRecordTypeIcon($personType)); ?>" aria-hidden="true"></i>
                                                                </span>
                                                                <?php echo h(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))); ?>
                                                            </td>
                                                            <td class="small"><?php echo $p['member_number'] ? (int)$p['member_number'] : '—'; ?></td>
                                                            <td class="small"><?php echo h(format_display_date($p['dob'] ?? null, '—')); ?></td>
                                                            <td class="small"><?php echo h($p['email'] ?? '—'); ?></td>
                                                            <td class="small"><?php echo h($p['phone'] ?? '—'); ?></td>
                                                            <td class="small text-uppercase"><?php echo h($p['postcode'] ?? '—'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
	                                    <?php endif; ?>
	                                </div>

	                                <div class="card-soft p-4 mt-4" id="person-form">
	                                    <div class="d-flex justify-content-between align-items-start gap-3">
	                                        <div>
	                                            <div class="text-uppercase small text-muted">People</div>
	                                            <h4 class="fw-bold mb-1"><?php echo $editPerson ? 'Edit person' : 'Add person'; ?></h4>
	                                            <div class="text-muted small">People can be selected on event entry forms to prefill details.</div>
	                                        </div>
	                                    </div>
	                                    <div class="divider"></div>
                                    <form method="post" class="row g-3 person-form">
                                        <input type="hidden" name="action" value="save_person">
                                        <input type="hidden" name="person_id" value="<?php echo $editPerson ? (int)$editPerson['id'] : 0; ?>">
                                        <input type="hidden" name="require_contact_details" value="1">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">First name <span class="text-danger">*</span></label>
                                            <input class="form-control" name="first_name" required value="<?php echo h($editPerson['first_name'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Last name <span class="text-danger">*</span></label>
                                            <input class="form-control" name="last_name" required value="<?php echo h($editPerson['last_name'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Phone <span class="text-danger">*</span></label>
                                            <input class="form-control" name="phone" placeholder="+44..." value="<?php echo h($editPerson['phone'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Address <span class="text-danger">*</span></label>
                                            <textarea class="form-control" name="address" rows="2" placeholder="House number, street, town" required><?php echo h($editPerson['address'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Postcode <span class="text-danger">*</span></label>
                                            <input class="form-control text-uppercase" name="postcode" placeholder="BT12 3AB" value="<?php echo h($editPerson['postcode'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Email (optional)</label>
                                            <input type="email" class="form-control" name="email" value="<?php echo h($editPerson['email'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Emergency contact name <span class="text-danger">*</span></label>
                                            <input class="form-control" name="emergency_contact_name" placeholder="Who we call in an emergency" value="<?php echo h($editPerson['emergency_contact_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Emergency contact phone <span class="text-danger">*</span></label>
                                            <input class="form-control" name="emergency_contact_phone" placeholder="Emergency phone" value="<?php echo h($editPerson['emergency_contact_phone'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Date of birth (required for juniors)</label>
                                            <input type="date" class="form-control" name="dob" value="<?php echo h($editPerson['dob'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Junior or Senior</label>
                                            <select class="form-select" name="junior_or_senior">
                                                <option value="">Select...</option>
                                                <option value="Junior" <?php echo (isset($editPerson['junior_or_senior']) && $editPerson['junior_or_senior'] === 'Junior') ? 'selected' : ''; ?>>Junior</option>
                                                <option value="Senior" <?php echo (isset($editPerson['junior_or_senior']) && $editPerson['junior_or_senior'] === 'Senior') ? 'selected' : ''; ?>>Senior</option>
                                            </select>
                                        </div>
                                        <div class="col-12 d-flex gap-2">
                                            <button class="btn btn-success fw-bold" type="submit"><?php echo $editPerson ? 'Save changes' : 'Add person'; ?></button>
                                            <?php if ($editPerson): ?>
                                                <a class="btn btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=people">Cancel</a>
                                            <?php endif; ?>
	                                        </div>
	                                    </form>
	                                </div>
                            <?php elseif ($accountView === 'shares'): ?>
                                <?php
                                $userId = (int)($currentUser['id'] ?? 0);
                                $sharePeople = array_values(array_filter(
                                    fetchMembersForUser($pdo, $userId),
                                    static fn(array $p): bool => empty($p['is_linked']) && (int)($p['is_archived'] ?? 0) === 0
                                ));
                                $shareHorses = array_values(array_filter(
                                    fetchHorsesForUser($pdo, $userId),
                                    static fn(array $h): bool => empty($h['is_linked']) && (int)($h['is_archived'] ?? 0) === 0
                                ));
                                $outgoingShares = fetchOutgoingSharesForUser($pdo, $userId);
                                ?>
                                <div class="card-soft p-4 mt-4" id="shares">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                                        <div>
                                            <div class="text-uppercase small text-muted">Shares</div>
                                            <h4 class="fw-bold mb-1">Share for entries</h4>
                                            <div class="text-muted small">Share a rider or horse as select-only for event entries.</div>
                                        </div>
                                        <form method="post" class="d-flex flex-column flex-sm-row gap-2">
                                            <input type="hidden" name="action" value="accept_share_code">
                                            <input class="form-control" name="share_code" placeholder="Share code" style="min-width: 180px;">
                                            <button class="btn btn-outline-success fw-bold" type="submit">Accept code</button>
                                        </form>
                                    </div>
                                    <div class="divider"></div>
                                    <div class="row g-4">
                                        <div class="col-12 col-lg-6">
                                            <div class="fw-bold mb-2">Create a share</div>
                                            <form method="post" class="row g-3">
                                                <input type="hidden" name="action" value="create_share">
                                                <div class="col-12">
                                                    <label class="form-label fw-bold">Record</label>
                                                    <select class="form-select" name="entity_ref" required>
                                                        <option value="">Choose...</option>
                                                        <?php if ($sharePeople): ?>
                                                            <optgroup label="People">
                                                                <?php foreach ($sharePeople as $person): ?>
                                                                    <?php $label = trim((string)($person['first_name'] ?? '') . ' ' . (string)($person['last_name'] ?? '')) ?: ('Person #' . (int)$person['id']); ?>
                                                                    <option value="person:<?php echo (int)$person['id']; ?>"><?php echo h($label); ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endif; ?>
                                                        <?php if ($shareHorses): ?>
                                                            <optgroup label="Horses">
                                                                <?php foreach ($shareHorses as $horse): ?>
                                                                    <option value="horse:<?php echo (int)$horse['id']; ?>"><?php echo h((string)($horse['name'] ?? ('Horse #' . (int)$horse['id']))); ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label fw-bold">Recipient email or member number</label>
                                                    <input class="form-control" name="recipient" placeholder="name@example.com or member number" required>
                                                </div>
                                                <div class="col-12">
                                                    <button class="btn btn-success fw-bold" type="submit">Send dashboard share</button>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="col-12 col-lg-6">
                                            <div class="fw-bold mb-2">Create external code</div>
                                            <form method="post" class="row g-3">
                                                <input type="hidden" name="action" value="create_external_share_code">
                                                <div class="col-12">
                                                    <label class="form-label fw-bold">Record</label>
                                                    <select class="form-select" name="entity_ref" required>
                                                        <option value="">Choose...</option>
                                                        <?php if ($sharePeople): ?>
                                                            <optgroup label="People">
                                                                <?php foreach ($sharePeople as $person): ?>
                                                                    <?php $label = trim((string)($person['first_name'] ?? '') . ' ' . (string)($person['last_name'] ?? '')) ?: ('Person #' . (int)$person['id']); ?>
                                                                    <option value="person:<?php echo (int)$person['id']; ?>"><?php echo h($label); ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endif; ?>
                                                        <?php if ($shareHorses): ?>
                                                            <optgroup label="Horses">
                                                                <?php foreach ($shareHorses as $horse): ?>
                                                                    <option value="horse:<?php echo (int)$horse['id']; ?>"><?php echo h((string)($horse['name'] ?? ('Horse #' . (int)$horse['id']))); ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <button class="btn btn-outline-success fw-bold" type="submit">Create code</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-soft p-4 mt-4">
                                    <div class="fw-bold mb-3">Current links</div>
                                    <?php
                                    $hasCurrentLinks = false;
                                    foreach ([['person', $sharePeople], ['horse', $shareHorses]] as $group):
                                        [$entityType, $entities] = $group;
                                        foreach ($entities as $entity):
                                            $links = fetchLinkedAccessForOwner($pdo, $userId, $entityType, (int)$entity['id']);
                                            if (!$links) {
                                                continue;
                                            }
                                            $hasCurrentLinks = true;
                                            $entityLabel = $entityType === 'person'
                                                ? (trim((string)($entity['first_name'] ?? '') . ' ' . (string)($entity['last_name'] ?? '')) ?: ('Person #' . (int)$entity['id']))
                                                : (string)($entity['name'] ?? ('Horse #' . (int)$entity['id']));
                                    ?>
                                            <div class="table-responsive mb-3">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th><?php echo h(ucfirst($entityType)); ?></th>
                                                            <th>Linked account</th>
                                                            <th class="text-end">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($links as $link): ?>
                                                            <tr>
                                                                <td class="fw-semibold small"><?php echo h($entityLabel); ?></td>
                                                                <td class="small"><?php echo h(userDisplayName($link)); ?><br><span class="text-muted"><?php echo h($link['email'] ?? ''); ?></span></td>
                                                                <td class="text-end">
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="revoke_shared_access">
                                                                        <input type="hidden" name="entity_type" value="<?php echo h($entityType); ?>">
                                                                        <input type="hidden" name="link_id" value="<?php echo (int)$link['id']; ?>">
                                                                        <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Remove this shared access?');">Remove</button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                    <?php
                                        endforeach;
                                    endforeach;
                                    ?>
                                    <?php if (!$hasCurrentLinks): ?>
                                        <div class="text-muted small">No accepted links yet.</div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-soft p-4 mt-4">
                                    <div class="fw-bold mb-3">Recent requests and codes</div>
                                    <?php if (!$outgoingShares): ?>
                                        <div class="text-muted small">No share requests yet.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Record</th>
                                                        <th>Recipient / code</th>
                                                        <th>Status</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($outgoingShares as $share): ?>
                                                        <tr>
                                                            <td class="small fw-semibold"><?php echo h(ucfirst((string)$share['entity_type'])); ?>: <?php echo h((string)$share['entity_label']); ?></td>
                                                            <td class="small">
                                                                <?php echo h($share['target_user_email'] ?? $share['target_email'] ?? 'External code'); ?>
                                                                <?php if (!empty($share['code']) && $share['status'] === 'pending'): ?><br><span class="fw-bold"><?php echo h((string)$share['code']); ?></span><?php endif; ?>
                                                            </td>
                                                            <td class="text-muted small"><?php echo h((string)$share['status']); ?><?php if (!empty($share['expires_at'])): ?> · expires <?php echo h(format_display_date($share['expires_at'] ?? null, '—')); ?><?php endif; ?></td>
                                                            <td class="text-end">
                                                                <?php if (($share['status'] ?? '') === 'pending'): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="cancel_share_request">
                                                                        <input type="hidden" name="request_id" value="<?php echo (int)$share['id']; ?>">
                                                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Cancel</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($accountView === 'horses'): ?>
                                <?php
                                $userId = (int)($currentUser['id'] ?? 0);
                                $horsesAll = fetchHorsesForUser($pdo, $userId, true);
                                $activeHorses = array_values(array_filter($horsesAll, static fn(array $h): bool => (int)($h['is_archived'] ?? 0) === 0));
                                $archivedHorses = array_values(array_filter($horsesAll, static fn(array $h): bool => (int)($h['is_archived'] ?? 0) === 1));
                                $editHorseId = (int)($_GET['horse_id'] ?? 0);
                                $editHorse = $editHorseId > 0 ? fetchHorseForUserById($pdo, $userId, $editHorseId) : null;
                                if ($editHorse && !empty($editHorse['is_linked'])) {
                                    $editHorse = null;
                                }
                                $horseQualifications = fetchHorseQualifications($pdo);
                                $horseQualificationLookup = [];
                                foreach ($horseQualifications as $hq) {
                                    $horseQualificationLookup[(int)($hq['id'] ?? 0)] = (string)($hq['name'] ?? '');
                                }
                                $logbookTypes = fetchHorseLogbookTypes($pdo, true);
                                $logbookType = $logbookTypes ? $logbookTypes[0] : null;
                                ?>

	                                <div class="card-soft p-4 mt-4">
	                                    <div class="d-flex justify-content-between align-items-center mb-3">
	                                        <div class="fw-bold">Your horses</div>
	                                        <div class="text-muted small"><?php echo count($activeHorses); ?> active<?php echo $archivedHorses ? ' · ' . count($archivedHorses) . ' archived' : ''; ?></div>
	                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Year</th>
                                                    <th>Breed</th>
                                                    <th>Colour</th>
                                                    <th>Qualification</th>
                                                    <th>Sex</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!$activeHorses): ?>
                                                    <tr><td colspan="7" class="text-muted small">No horses yet.</td></tr>
                                                <?php endif; ?>
	                                                <?php foreach ($activeHorses as $h): ?>
                                                    <tr>
                                                        <td class="fw-semibold">
                                                            <?php echo h($h['name'] ?? ''); ?>
                                                            <?php if (!empty($h['is_linked'])): ?><span class="linked-badge ms-1">link</span><?php endif; ?>
                                                        </td>
                                                        <td class="text-muted small"><?php echo h($h['year_of_birth'] ?? '—'); ?></td>
                                                        <td class="text-muted small"><?php echo h($h['breed'] ?? '—'); ?></td>
                                                        <td class="text-muted small"><?php echo h($h['colour'] ?? '—'); ?></td>
                                                        <td class="text-muted small"><?php echo h($horseQualificationLookup[(int)($h['qualification_id'] ?? 0)] ?? '—'); ?></td>
                                                        <td class="text-muted small"><?php echo h($h['sex'] ?? '—'); ?></td>
                                                        <td class="text-end">
                                                            <?php if (!empty($h['is_linked'])): ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="action" value="unlink_shared_record">
                                                                    <input type="hidden" name="entity_type" value="horse">
                                                                    <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                    <input type="hidden" name="entity_id" value="<?php echo (int)$h['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this linked horse from your account?');">Remove link</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=horses&horse_id=<?php echo (int)$h['id']; ?>#horse-form">Edit</a>
                                                                <?php if ($logbookType): ?>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="action" value="add_logbook">
                                                                        <input type="hidden" name="logbook_type_id" value="<?php echo (int)($logbookType['id'] ?? 0); ?>">
                                                                        <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-success">Buy/Renew logbook</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="action" value="archive_horse">
                                                                    <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this horse?');">Archive</button>
                                                               </form>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($archivedHorses): ?>
                                        <div class="divider"></div>
                                        <div class="fw-bold mb-2">Archived</div>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Year</th>
                                                        <th>Breed</th>
                                                        <th>Colour</th>
                                                        <th>Qualification</th>
                                                        <th>Sex</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($archivedHorses as $h): ?>
                                                        <tr class="text-muted">
                                                            <td><?php echo h($h['name'] ?? ''); ?></td>
                                                            <td class="small"><?php echo h($h['year_of_birth'] ?? '—'); ?></td>
                                                            <td class="small"><?php echo h($h['breed'] ?? '—'); ?></td>
                                                            <td class="small"><?php echo h($h['colour'] ?? '—'); ?></td>
                                                            <td class="small"><?php echo h($horseQualificationLookup[(int)($h['qualification_id'] ?? 0)] ?? '—'); ?></td>
                                                            <td class="small"><?php echo h($h['sex'] ?? '—'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
	                                    <?php endif; ?>
	                                </div>

	                                <div class="card-soft p-4 mt-4" id="horse-form">
	                                    <div class="d-flex justify-content-between align-items-start gap-3">
	                                        <div>
	                                            <div class="text-uppercase small text-muted">Horses</div>
	                                            <h4 class="fw-bold mb-1"><?php echo $editHorse ? 'Edit horse' : 'Add horse'; ?></h4>
	                                            <div class="text-muted small">Horses can be selected on event entry forms to prefill details.</div>
	                                        </div>
	                                    </div>
	                                    <div class="divider"></div>
	                                    <form method="post" class="row g-3">
	                                        <input type="hidden" name="action" value="save_horse">
	                                        <input type="hidden" name="horse_id" value="<?php echo $editHorse ? (int)$editHorse['id'] : 0; ?>">
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Horse name</label>
	                                            <input class="form-control" name="name" required value="<?php echo h($editHorse['name'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Breed</label>
	                                            <input class="form-control" name="breed" value="<?php echo h($editHorse['breed'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Colour</label>
	                                            <input class="form-control" name="colour" value="<?php echo h($editHorse['colour'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Sex</label>
	                                            <select class="form-select" name="sex">
	                                                <option value="">Select...</option>
	                                                <?php
	                                                $sexes = ['Mare', 'Gelding', 'Stallion'];
	                                                foreach ($sexes as $sexOpt):
	                                                ?>
	                                                    <option value="<?php echo h($sexOpt); ?>" <?php echo (($editHorse['sex'] ?? '') === $sexOpt) ? 'selected' : ''; ?>><?php echo h($sexOpt); ?></option>
	                                                <?php endforeach; ?>
	                                            </select>
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Height (cm)</label>
	                                            <input class="form-control" name="height_cm" inputmode="numeric" pattern="[0-9]*" value="<?php echo h($editHorse['height_cm'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Qualification</label>
	                                            <select class="form-select" name="qualification_id">
	                                                <option value="">None</option>
	                                                <?php foreach ($horseQualifications as $hq): ?>
	                                                    <option value="<?php echo (int)($hq['id'] ?? 0); ?>" <?php echo ((int)($editHorse['qualification_id'] ?? 0) === (int)($hq['id'] ?? 0)) ? 'selected' : ''; ?>>
	                                                        <?php echo h($hq['name'] ?? ''); ?>
	                                                    </option>
	                                                <?php endforeach; ?>
	                                            </select>
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Year of birth</label>
	                                            <input class="form-control" name="year_of_birth" value="<?php echo h($editHorse['year_of_birth'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Date of birth</label>
	                                            <input type="date" class="form-control" name="dob" value="<?php echo h($editHorse['dob'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
                                            <label class="form-label fw-bold">Passport issuer</label>
                                            <input class="form-control" name="passport_issuer" value="<?php echo h($editHorse['passport_issuer'] ?? ''); ?>" maxlength="32">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Passport number</label>
	                                            <input class="form-control" name="passport_number" value="<?php echo h($editHorse['passport_number'] ?? ''); ?>" maxlength="32">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Flu vaccination date</label>
	                                            <input type="date" class="form-control" name="flu_vac_date" value="<?php echo h($editHorse['flu_vac_date'] ?? ''); ?>">
	                                        </div>
                                        <div class="col-12 d-flex gap-2">
                                            <button class="btn btn-success fw-bold" type="submit"><?php echo $editHorse ? 'Save changes' : 'Add horse'; ?></button>
                                            <?php if ($editHorse): ?>
                                                <a class="btn btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=horses">Cancel</a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                                <?php
                                $logbooks = fetchHorseLogbooksForUser($pdo, $userId);
                                $activeLogbooks = array_filter($logbooks, static fn(array $lb): bool => ($lb['status'] ?? '') === 'active' || ($lb['status'] ?? '') === 'pending');
                                $previousLogbooks = array_filter($logbooks, static fn(array $lb): bool => ($lb['status'] ?? '') === 'expired');
                                ?>
                                <div class="card-soft p-4 mt-4" id="horse-logbooks">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="fw-bold">Horse logbooks</div>
                                        <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/logbooks">Buy / renew</a>
                                    </div>
                                    <?php if (!$logbooks): ?>
                                        <div class="text-muted small">No logbooks yet.</div>
                                    <?php else: ?>
                                        <div class="mb-3">
                                            <div class="fw-bold mb-2">Active</div>
                                            <?php if (!$activeLogbooks): ?>
                                                <div class="text-muted small">No active logbooks.</div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Horse</th>
                                                                <th>Logbook</th>
                                                                <th>Status</th>
                                                                <th>Year</th>
                                                                <th>Period</th>
                                                                <th>Purchased</th>
                                                                <th class="text-end">Amount</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($activeLogbooks as $lb): ?>
                                                                <tr>
                                                                    <td class="small fw-semibold"><?php echo h($lb['horse_name'] ?? ''); ?></td>
                                                                    <td class="small"><?php echo h($lb['logbook_name'] ?? 'Logbook'); ?></td>
                                                                    <td class="small text-capitalize"><?php echo h($lb['status'] ?? 'active'); ?></td>
                                                                    <td class="small"><?php echo h($lb['valid_year'] ?? '—'); ?></td>
                                                                    <td class="text-muted small">
                                                                        <div><?php echo h(format_display_date($lb['starts_at'] ?? null, '—')); ?></div>
                                                                        <div><?php echo h(format_display_date($lb['ends_at'] ?? null, '—')); ?></div>
                                                                    </td>
                                                                    <td class="text-muted small"><?php echo h(format_display_date($lb['purchased_at'] ?? null, '—')); ?></td>
                                                                    <td class="text-end small fw-semibold"><?php echo '£' . number_format((float)($lb['amount'] ?? 0), 2); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="divider"></div>
                                        <div>
                                            <div class="fw-bold mb-2">Previous</div>
                                            <?php if (!$previousLogbooks): ?>
                                                <div class="text-muted small">No previous logbooks.</div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Horse</th>
                                                                <th>Logbook</th>
                                                                <th>Status</th>
                                                                <th>Year</th>
                                                                <th>Period</th>
                                                                <th>Purchased</th>
                                                                <th class="text-end">Amount</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($previousLogbooks as $lb): ?>
                                                                <tr class="text-muted">
                                                                    <td class="small fw-semibold"><?php echo h($lb['horse_name'] ?? ''); ?></td>
                                                                    <td class="small"><?php echo h($lb['logbook_name'] ?? 'Logbook'); ?></td>
                                                                    <td class="small text-capitalize"><?php echo h($lb['status'] ?? 'expired'); ?></td>
                                                                    <td class="small"><?php echo h($lb['valid_year'] ?? '—'); ?></td>
                                                                    <td class="text-muted small">
                                                                        <div><?php echo h(format_display_date($lb['starts_at'] ?? null, '—')); ?></div>
                                                                        <div><?php echo h(format_display_date($lb['ends_at'] ?? null, '—')); ?></div>
                                                                    </td>
                                                                    <td class="text-muted small"><?php echo h(format_display_date($lb['purchased_at'] ?? null, '—')); ?></td>
                                                                    <td class="text-end small fw-semibold"><?php echo '£' . number_format((float)($lb['amount'] ?? 0), 2); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
	                            <?php else: ?>
                                <div class="card-soft p-4 mt-4" id="my-memberships">
	                            <div class="d-flex justify-content-between align-items-center mb-3">
	                                <div class="fw-bold">Memberships</div>
	                                <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/memberships">Buy / renew</a>
	                            </div>

	                            <?php
	                            $renderMembershipRows = static function (array $purchases): void {
	                                foreach ($purchases as $purchase) {
	                                    $memberLabel = trim((string)($purchase['member_name'] ?? ''));
	                                    $memberNumber = (string)($purchase['member_number'] ?? '');
	                                    if ($memberNumber !== '' && $memberLabel !== '') {
	                                        $memberLabel = $memberNumber . ' · ' . $memberLabel;
	                                    } elseif ($memberNumber !== '') {
	                                        $memberLabel = $memberNumber;
	                                    }
	                                    ?>
	                                    <tr>
	                                        <td class="small"><?php echo h($memberLabel !== '' ? $memberLabel : 'Not assigned'); ?></td>
	                                        <td class="small fw-semibold"><?php echo h($purchase['membership_name'] ?? 'Membership'); ?></td>
	                                        <td class="small text-capitalize"><?php echo h($purchase['status'] ?? 'active'); ?></td>
	                                        <td class="text-muted small">
	                                            <div><?php echo h(format_display_date($purchase['starts_at'] ?? null, '—')); ?></div>
	                                            <div><?php echo h(format_display_date($purchase['ends_at'] ?? null, '—')); ?></div>
	                                        </td>
	                                        <td class="text-muted small"><?php echo h(format_display_date($purchase['purchased_at'] ?? null, '—')); ?></td>
	                                        <td class="text-end small fw-semibold"><?php echo '£' . number_format((float)($purchase['amount'] ?? 0), 2); ?></td>
	                                    </tr>
	                                    <?php
	                                }
	                            };
	                            ?>

	                            <?php if (!$userMembershipPurchases): ?>
	                                <div class="text-muted small">No memberships yet.</div>
	                            <?php else: ?>
	                                <div class="mb-3">
	                                    <div class="fw-bold mb-2">Active</div>
	                                    <?php if (!$activeMembershipPurchases): ?>
	                                        <div class="text-muted small">No active memberships.</div>
	                                    <?php else: ?>
	                                        <div class="table-responsive">
	                                            <table class="table table-sm align-middle">
	                                                <thead class="table-light">
	                                                    <tr>
	                                                        <th>Member</th>
	                                                        <th>Membership</th>
	                                                        <th>Status</th>
	                                                        <th>Period</th>
	                                                        <th>Purchased</th>
	                                                        <th class="text-end">Amount</th>
	                                                    </tr>
	                                                </thead>
	                                                <tbody>
	                                                    <?php $renderMembershipRows($activeMembershipPurchases); ?>
	                                                </tbody>
	                                            </table>
	                                        </div>
	                                    <?php endif; ?>
	                                </div>

	                                <div class="divider"></div>

	                                <div>
	                                    <div class="fw-bold mb-2">Previous</div>
	                                    <?php if (!$previousMembershipPurchases): ?>
	                                        <div class="text-muted small">No previous memberships.</div>
	                                    <?php else: ?>
	                                        <div class="table-responsive">
	                                            <table class="table table-sm align-middle">
	                                                <thead class="table-light">
	                                                    <tr>
	                                                        <th>Member</th>
	                                                        <th>Membership</th>
	                                                        <th>Status</th>
	                                                        <th>Period</th>
	                                                        <th>Purchased</th>
	                                                        <th class="text-end">Amount</th>
	                                                    </tr>
	                                                </thead>
	                                                <tbody>
	                                                    <?php $renderMembershipRows($previousMembershipPurchases); ?>
	                                                </tbody>
	                                            </table>
	                                        </div>
	                                    <?php endif; ?>
	                                </div>
	                            <?php endif; ?>
	                        </div>

	                        <div class="card-soft p-4 mt-4">
	                            <div class="d-flex justify-content-between align-items-center mb-3">
	                                <div class="fw-bold">Bookings</div>
	                                <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/bookings">View all bookings</a>
	                            </div>

	                            <?php if (!$recentBookings): ?>
	                                <div class="text-muted small">No bookings yet. Start by exploring upcoming events.</div>
	                            <?php else: ?>
	                                <div class="table-responsive">
	                                    <table class="table table-sm align-middle">
	                                        <thead class="table-light">
	                                            <tr>
	                                                <th>Booking</th>
	                                                <th>Entries</th>
	                                                <th>Total</th>
	                                                <th>Placed</th>
	                                            </tr>
	                                        </thead>
	                                        <tbody>
	                                            <?php foreach ($recentBookings as $booking): ?>
	                                                <?php $bookingRef = $booking['booking_ref'] ?? $booking['id'] ?? ''; ?>
	                                                <tr>
	                                                    <td class="small fw-semibold">
	                                                        <?php if ($bookingRef !== ''): ?>
	                                                            <a class="text-decoration-none small-link" href="<?php echo h($basePath); ?>/checkout/complete?id=<?php echo h($bookingRef); ?>">
	                                                                #<?php echo h($bookingRef); ?>
	                                                            </a>
	                                                        <?php else: ?>
	                                                            <span class="text-muted">—</span>
	                                                        <?php endif; ?>
	                                                    </td>
	                                                    <td><?php echo count($booking['items'] ?? []); ?></td>
	                                                    <td><?php echo isset($booking['total']) ? '£' . number_format((float)$booking['total'], 2) : '—'; ?></td>
	                                                    <td class="text-muted small"><?php echo h(format_display_datetime($booking['created_at'] ?? null, '—')); ?></td>
	                                                </tr>
	                                            <?php endforeach; ?>
	                                        </tbody>
	                                    </table>
	                                </div>
	                            <?php endif; ?>
	                        </div>
                            <?php endif; ?>
	                    </div>
	                </div>
	            <?php else: ?>
	                <div class="row justify-content-center">
	                    <div class="col-lg-6">
	                        <div class="p-3 p-lg-4 mb-3 auth-helper">
                            <div class="fw-bold mb-1">Create or access your account</div>
                            <div class="text-muted small">Save your rider and horse details, keep track of bookings and revisit your basket any time.</div>
                        </div>
                        <?php include __DIR__ . '/views/auth_forms.php'; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($promptPerson): ?>
        <div class="modal fade" id="personCompletionModal" tabindex="-1" aria-labelledby="personCompletionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="personCompletionModalLabel">Finish setting up your details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">We use this information for bookings and in case of emergency. Please complete the details for your primary rider.</p>
                        <form method="post" class="row g-3 person-form">
                            <input type="hidden" name="action" value="save_person">
                            <input type="hidden" name="person_id" value="<?php echo (int)($promptPerson['id'] ?? 0); ?>">
                            <input type="hidden" name="require_contact_details" value="1">
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">First name <span class="text-danger">*</span></label>
                                <input class="form-control" name="first_name" required value="<?php echo h($promptPerson['first_name'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Last name <span class="text-danger">*</span></label>
                                <input class="form-control" name="last_name" required value="<?php echo h($promptPerson['last_name'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Phone <span class="text-danger">*</span></label>
                                <input class="form-control" name="phone" placeholder="+44..." value="<?php echo h($promptPerson['phone'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="address" rows="2" placeholder="House number, street, town" required><?php echo h($promptPerson['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Postcode <span class="text-danger">*</span></label>
                                <input class="form-control text-uppercase" name="postcode" placeholder="BT12 3AB" value="<?php echo h($promptPerson['postcode'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Email (optional)</label>
                                <input type="email" class="form-control" name="email" value="<?php echo h($promptPerson['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Emergency contact name <span class="text-danger">*</span></label>
                                <input class="form-control" name="emergency_contact_name" placeholder="Who we call in an emergency" value="<?php echo h($promptPerson['emergency_contact_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Emergency contact phone <span class="text-danger">*</span></label>
                                <input class="form-control" name="emergency_contact_phone" placeholder="Emergency phone" value="<?php echo h($promptPerson['emergency_contact_phone'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Date of birth (required for juniors)</label>
                                <input type="date" class="form-control" name="dob" value="<?php echo h($promptPerson['dob'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Junior or Senior</label>
                                <select class="form-select" name="junior_or_senior">
                                    <option value="">Select...</option>
                                    <option value="Junior" <?php echo (isset($promptPerson['junior_or_senior']) && $promptPerson['junior_or_senior'] === 'Junior') ? 'selected' : ''; ?>>Junior</option>
                                    <option value="Senior" <?php echo (isset($promptPerson['junior_or_senior']) && $promptPerson['junior_or_senior'] === 'Senior') ? 'selected' : ''; ?>>Senior</option>
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-success fw-bold" type="submit">Save details</button>
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not now</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.querySelectorAll('.btn-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.btn-toggle').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.add('d-none'));
                btn.classList.add('active');
                const target = btn.getAttribute('data-target');
                document.getElementById(target)?.classList.remove('d-none');
            });
        });

        document.querySelectorAll('input, select, textarea').forEach(el => {
            const original = el.getAttribute('placeholder');
            if (original) {
                el.dataset.placeholder = original;
            }
            el.addEventListener('focus', () => {
                if (el.value === '' && el.dataset.placeholder) {
                    el.setAttribute('placeholder', '');
                }
            });
            el.addEventListener('blur', () => {
                if (el.value === '' && el.dataset.placeholder) {
                    el.setAttribute('placeholder', el.dataset.placeholder);
                }
            });
        });
        const cutoff = new Date(new Date().getFullYear(), 0, 1);
        const calculateAgeAtCutoff = (dobStr) => {
            if (!dobStr) return null;
            const dob = new Date(dobStr + 'T00:00:00');
            if (Number.isNaN(dob.getTime())) return null;
            let age = cutoff.getFullYear() - dob.getFullYear();
            const hasHadBirthday = (cutoff.getMonth() > dob.getMonth()) || (cutoff.getMonth() === dob.getMonth() && cutoff.getDate() >= dob.getDate());
            if (!hasHadBirthday) age -= 1;
            return age;
        };
        document.querySelectorAll('.person-form').forEach(form => {
            const dobInput = form.querySelector('input[name="dob"]');
            const levelSelect = form.querySelector('select[name="junior_or_senior"]');
            if (!dobInput || !levelSelect) return;
            const syncDobJunior = () => {
                const age = calculateAgeAtCutoff(dobInput.value);
                if (age !== null && age < 18) {
                    levelSelect.value = 'Junior';
                }
                dobInput.required = levelSelect.value === 'Junior';
            };
            levelSelect.addEventListener('change', syncDobJunior);
            dobInput.addEventListener('change', syncDobJunior);
            syncDobJunior();
        });
        <?php if ($promptPerson): ?>
        const personModalEl = document.getElementById('personCompletionModal');
        if (personModalEl) {
            const personModal = new bootstrap.Modal(personModalEl);
            personModal.show();
        }
        <?php endif; ?>
    </script>
    <?php render_password_reveal_assets(); ?>
</body>
</html>
