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
// Keep old Security links/bookmarks working now that security lives in My Account.
if ($accountView === 'security') {
    $accountView = 'my-account';
}
$accountSectionLabels = [
    'people' => 'People Management',
    'horses' => 'Horse Management',
    'shares' => 'Share Management',
    'my-account' => 'My Account',
];
$isAccountManagementView = isset($accountSectionLabels[$accountView]);
$accountSectionTitle = $accountSectionLabels[$accountView] ?? 'Account Overview';
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basket = $_SESSION['basket'] ?? [];
$basketCount = count($basket);
$recentBookings = [];
$userBookingCount = 0;
$userCreditBalance = 0.0;
$incomingShareRequests = [];
$allBookings = load_all_bookings($pdo);
if ($isLoggedIn) {
    $userId = (int)($currentUser['id'] ?? 0);
    $userEmail = strtolower((string)($currentUser['email'] ?? ''));
    $userCreditBalance = fetch_user_credit_balance($pdo, $userId);
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
$renewalMembershipTypes = [];
if ($isLoggedIn) {
    $userMembershipPurchases = array_values(array_filter(fetchMemberships($pdo), static function (array $row) use ($userId): bool {
        return (int)($row['purchased_by_user_id'] ?? $row['user_id'] ?? 0) === (int)$userId;
    }));
    $renewalMembershipTypes = fetchMembershipTypes($pdo, true);
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
$loggedInMembershipNumber = '';
if ($isLoggedIn && $activeMembershipPurchases) {
    $accountEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
    $accountFirst = strtolower(trim((string)($currentUser['first_name'] ?? '')));
    $accountLast = strtolower(trim((string)($currentUser['last_name'] ?? '')));
    $ownedPeople = fetchMembersForUser($pdo, $userId);
    $activeMemberIds = array_map('intval', array_column($activeMembershipPurchases, 'member_id'));
    foreach ($ownedPeople as $person) {
        if (!in_array((int)$person['id'], $activeMemberIds, true)) continue;
        $emailMatches = $accountEmail !== '' && strtolower(trim((string)($person['email'] ?? ''))) === $accountEmail;
        $nameMatches = $accountFirst !== '' && $accountLast !== ''
            && strtolower(trim((string)($person['first_name'] ?? ''))) === $accountFirst
            && strtolower(trim((string)($person['last_name'] ?? ''))) === $accountLast;
        if ($emailMatches || $nameMatches) {
            $loggedInMembershipNumber = trim((string)($person['member_number'] ?? ''));
            break;
        }
    }
}
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
        if ($action === 'save_user_details') {
            $accountView = 'my-account';
            $updatedDetails = updateOwnUserDetails($pdo, $userId, $_POST, $alerts);
            if ($updatedDetails && !$alerts) {
                $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], $updatedDetails);
                $_SESSION['flash_success'] = 'Your account details have been updated.';
                header('Location: ' . $basePath . '/account?view=my-account');
                exit;
            }
        } elseif ($action === 'change_own_password') {
            $accountView = 'my-account';
            if (changeOwnPassword($pdo, $userId, $_POST, $alerts)) {
                $_SESSION['flash_success'] = 'Your password has been changed.';
                header('Location: ' . $basePath . '/account?view=my-account');
                exit;
            }
        } elseif ($action === 'auth_app_begin_setup') {
            $authAppSetup = beginAuthAppSetup($currentUser ?: [], $siteSettings);
            $accountView = 'my-account';
        } elseif ($action === 'auth_app_confirm_setup') {
            $accountView = 'my-account';
            if (confirmAuthAppSetup($pdo, $userId, $alerts)) {
                $_SESSION['flash_success'] = 'Authenticator app login enabled.';
                header('Location: ' . $basePath . '/account?view=my-account');
                exit;
            }
            $authAppSetup = pendingAuthAppSetup($currentUser ?: [], $siteSettings);
        } elseif ($action === 'auth_app_disable') {
            $accountView = 'my-account';
            if (disableAuthApp($pdo, $userId, $alerts)) {
                $_SESSION['flash_success'] = 'Authenticator app login disabled.';
                header('Location: ' . $basePath . '/account?view=my-account');
                exit;
            }
        } elseif ($action === 'save_person') {
            $personId = (int)($_POST['person_id'] ?? 0);
            $savedId = savePersonForUser($pdo, $userId, $_POST, $alerts, $personId > 0 ? $personId : null);
            if ($savedId && !$alerts) {
                $_SESSION['flash_success'] = $personId > 0 ? 'Person updated.' : 'Person added.';
                header('Location: ' . $basePath . '/account?view=people');
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
                if ($horseId <= 0) {
                    header('Location: ' . $basePath . '/logbooks?horse_id=' . (int)$savedId . '#available-logbooks');
                } else {
                    $_SESSION['flash_success'] = 'Horse updated.';
                    header('Location: ' . $basePath . '/account?view=horses');
                }
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
$accountIntroModal = $isLoggedIn && $isAccountManagementView ? fetchAccountIntroModal($pdo, $accountView) : null;
$accountIntroAutoOpen = false;
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
        .account-stat-label-phone { display: none; }
        @media (max-width: 575.98px) {
            .account-summary-layout {
                display: flex !important;
                flex-direction: column !important;
                align-items: start !important;
                gap: 0.6rem !important;
            }
            .account-summary-identity {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.55rem !important;
                min-width: 0;
                width: 100%;
            }
            .account-summary-layout .account-member-metrics {
                display: grid;
                grid-template-columns: minmax(135px, 1fr) minmax(0, 1fr);
                align-items: stretch;
                gap: 0.5rem;
                width: 100%;
            }
            .account-summary-layout .account-member-metrics > .border { min-width: 0 !important; }
            .account-mobile-stats {
                display: grid;
                grid-template-rows: repeat(2, minmax(0, 1fr));
                gap: 0.35rem;
            }
            .account-mobile-stats .stat-pill {
                display: flex;
                align-items: baseline;
                justify-content: center;
                gap: 0.3rem;
                padding: 0.35rem 0.45rem;
            }
            .account-summary-actions {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.4rem !important;
                width: 100%;
            }
            .account-summary-actions .btn {
                width: 100%;
                padding: 0.4rem 0.3rem;
                font-size: 0.78rem;
            }
            .account-stats,
            .account-stats-divider { display: none !important; }
            .account-stat-label-full { display: none; }
            .account-stat-label-phone { display: inline; }
        }
        .account-mobile-stats { display: none; }
        @media (max-width: 575.98px) {
            .account-mobile-stats { display: grid; }
            .account-bookings-table th,
            .account-bookings-table td {
                white-space: nowrap;
                padding: 0.3rem;
            }
        }
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
        .btn-logbook-action:disabled,
        .btn-logbook-action.disabled {
            background: #e5e7e9;
            border-color: #e5e7e9;
            color: #555b61;
            opacity: 1;
            box-shadow: none;
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
            font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 400;
        }
        .account-people-table .btn:disabled,
        .account-people-table .btn.disabled {
            background: #e5e7e9;
            border-color: #e5e7e9;
            color: #555b61;
            opacity: 1;
            box-shadow: none;
        }
        .membership-status {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            white-space: nowrap;
        }
        .membership-status-active { color: #198754; }
        .membership-status-renewed { color: #b26a00; }
        .membership-status-inactive { color: #dc3545; }
        .account-detail-list { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr); margin: 0; }
        .account-detail-list dt,
        .account-detail-list dd { margin: 0; padding: 0.7rem 0; border-bottom: 1px solid rgba(0, 0, 0, 0.08); }
        .account-detail-list dt { padding-right: 1rem; color: var(--muted); font-size: 0.85rem; }
        .account-detail-list dd { overflow-wrap: anywhere; white-space: pre-line; font-weight: 700; }
        @media (max-width: 767.98px) {
            .membership-purchased-column { display: none; }
            .membership-actions-column { display: none; }
            .booking-reference-column,
            .booking-actions-column { display: none; }
            .booking-item-column { min-width: 8rem; white-space: normal !important; }
            .account-people-table .person-type-icon,
            .people-postcode-column,
            .people-email-column,
            .people-phone-column,
            .people-actions-column { display: none; }
            .account-people-table th,
            .account-people-table td {
                padding-left: 0.25rem;
                padding-right: 0.25rem;
            }
            .people-membership-heading-full { display: none; }
            .people-membership-heading-mobile { display: inline !important; }
            .people-data-row > td { border-bottom: 0; }
            .people-mobile-actions-cell {
                padding: 0.25rem 0.3rem 0.75rem !important;
                border-bottom-color: #c5c9c5 !important;
            }
            .people-mobile-actions {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 0.35rem;
            }
            .people-mobile-actions > *,
            .people-mobile-actions .btn {
                width: 100%;
                min-width: 0;
            }
            .people-mobile-actions > form {
                display: flex;
                height: 100%;
            }
            .people-mobile-actions .btn {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 2.5rem;
                height: 100%;
                padding: 0.4rem 0.2rem;
                font-size: 0.75rem;
                line-height: 1.15;
                white-space: normal;
            }
            .horse-breed-column,
            .horse-colour-column,
            .horse-sex-column,
            .horse-actions-column { display: none; }
            .account-horses-table th,
            .account-horses-table td {
                padding-left: 0.25rem;
                padding-right: 0.25rem;
            }
            .horse-data-row > td { border-bottom: 0; }
            .horse-mobile-actions-cell {
                padding: 0.25rem 0.3rem 0.75rem !important;
                border-bottom-color: #c5c9c5 !important;
            }
            .horse-mobile-actions {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 0.35rem;
            }
            .horse-mobile-actions > *,
            .horse-mobile-actions .btn {
                width: 100%;
                min-width: 0;
            }
            .horse-mobile-actions > form {
                display: flex;
                height: 100%;
            }
            .horse-mobile-actions .btn {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 2.5rem;
                height: 100%;
                padding: 0.4rem 0.2rem;
                font-size: 0.75rem;
                line-height: 1.15;
                white-space: normal;
            }
            .js-account-detail-row { cursor: pointer; }
            .membership-status-cell,
            .membership-status-heading { width: 1%; padding-left: 0.3rem !important; padding-right: 0.3rem !important; text-align: center; }
            .membership-status-label,
            .membership-status-heading-label {
                position: absolute !important;
                width: 1px !important;
                height: 1px !important;
                padding: 0 !important;
                margin: -1px !important;
                overflow: hidden !important;
                clip: rect(0, 0, 0, 0) !important;
                white-space: nowrap !important;
                border: 0 !important;
            }
        }
        .people-membership-heading-mobile { display: none; }
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
                    } elseif ($accountView === 'my-account') {
                        echo 'My Account';
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
	                        <div class="card-soft <?php echo $isAccountManagementView ? 'p-3' : 'p-4'; ?>">
	                            <?php if ($isAccountManagementView): ?>
	                                <h2 class="h4 fw-bold mb-3"><?php echo h($accountSectionTitle); ?></h2>
	                            <?php endif; ?>
	                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 account-summary-layout">
	                                <div class="d-flex flex-wrap align-items-center gap-3 account-summary-identity">
	                                    <div>
	                                    <div class="text-uppercase small text-muted">Signed in as</div>
	                                    <div class="fw-bold h3 mb-1"><?php echo h($accountName); ?></div>
	                                    <div class="text-muted small"><?php echo h($currentUser['email'] ?? ''); ?></div>
	                                    </div>
	                                    <div class="account-member-metrics">
	                                    <?php if ($loggedInMembershipNumber !== ''): ?>
	                                        <div class="border rounded-3 px-3 py-2 bg-light text-center" style="min-width:135px;">
	                                            <div class="text-uppercase text-muted" style="font-size:.68rem;line-height:1.1;">Membership Number</div>
	                                            <div class="fw-bold fs-4 lh-sm mt-1"><?php echo h($loggedInMembershipNumber); ?></div>
	                                        </div>
	                                    <?php else: ?>
	                                        <div class="border border-warning rounded-3 px-3 py-2 bg-warning-subtle text-center" style="min-width:135px;">
	                                            <div class="text-uppercase text-muted" style="font-size:.68rem;line-height:1.1;">Membership</div>
	                                            <div class="fw-bold lh-sm mt-1">NOT A MEMBER</div>
	                                        </div>
	                                    <?php endif; ?>
	                                        <div class="account-mobile-stats">
	                                            <div class="stat-pill"><div class="fw-bold"><?php echo $basketCount; ?></div><div class="small text-uppercase">Basket</div></div>
	                                            <div class="stat-pill"><div class="fw-bold"><?php echo '£' . number_format((float)$userCreditBalance, 2); ?></div><div class="small text-uppercase">Credit</div></div>
	                                        </div>
	                                    </div>
	                                </div>
	                                <div class="d-flex flex-wrap gap-2 align-items-center account-summary-actions">
	                                    <a class="btn <?php echo $accountView === 'people' ? 'btn-success' : 'btn-outline-success'; ?>" href="<?php echo h($basePath); ?>/account?view=people">People</a>
	                                    <a class="btn <?php echo $accountView === 'horses' ? 'btn-success' : 'btn-outline-success'; ?>" href="<?php echo h($basePath); ?>/account?view=horses">Horses</a>
	                                    <a class="btn <?php echo $accountView === 'shares' ? 'btn-success' : 'btn-outline-success'; ?>" href="<?php echo h($basePath); ?>/account?view=shares">Shares</a>
	                                    <?php if ($canViewAdmin): ?>
	                                        <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/admin/index.php">Admin</a>
	                                    <?php endif; ?>
	                                </div>
	                            </div>

	                            <div class="divider account-stats-divider"></div>

	                            <div class="d-flex flex-wrap gap-2 account-stats">
	                                <div class="stat-pill account-stat-memberships">
	                                    <div class="fw-bold"><?php echo $activeMembershipCount; ?></div>
	                                    <div class="small text-uppercase">Active memberships</div>
	                                </div>
	                                <div class="stat-pill account-stat-basket">
	                                    <div class="fw-bold"><?php echo $basketCount; ?></div>
	                                    <div class="small text-uppercase"><span class="account-stat-label-full">Basket items</span><span class="account-stat-label-phone">Basket</span></div>
	                                </div>
	                                <div class="stat-pill account-stat-bookings">
	                                    <div class="fw-bold"><?php echo $userBookingCount; ?></div>
	                                    <div class="small text-uppercase">Bookings</div>
	                                </div>
		                                <div class="stat-pill account-stat-credit">
		                                    <div class="fw-bold"><?php echo '£' . number_format((float)$userCreditBalance, 2); ?></div>
		                                    <div class="small text-uppercase"><span class="account-stat-label-full">Credit balance</span><span class="account-stat-label-phone">Credit</span></div>
		                                </div>
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

                            <?php if ($accountView === 'my-account'): ?>
                                <div class="card-soft p-4 mt-4">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-3">
                                        <div>
                                            <div class="text-uppercase small text-muted">User account</div>
                                            <h4 class="fw-bold mb-1">Login details</h4>
                                            <p class="text-muted small mb-0">These details belong to your website user account, not a person or membership record.</p>
                                        </div>
                                        <?php if ($accountIntroModal): ?>
                                            <button class="btn btn-sm btn-outline-secondary text-nowrap" type="button" data-bs-toggle="modal" data-bs-target="#accountIntroModal">
                                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i> More Information
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" class="row g-3">
                                        <input type="hidden" name="action" value="save_user_details">
                                        <div class="col-md-6">
                                            <label class="form-label" for="user_first_name">First name</label>
                                            <input class="form-control" id="user_first_name" name="first_name" value="<?php echo h((string)($_POST['first_name'] ?? $currentUser['first_name'] ?? '')); ?>" autocomplete="given-name" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="user_last_name">Last name</label>
                                            <input class="form-control" id="user_last_name" name="last_name" value="<?php echo h((string)($_POST['last_name'] ?? $currentUser['last_name'] ?? '')); ?>" autocomplete="family-name" required>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label" for="user_email">Login email</label>
                                            <input class="form-control" type="email" id="user_email" name="email" value="<?php echo h((string)($_POST['email'] ?? $currentUser['email'] ?? '')); ?>" autocomplete="email" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label" for="details_current_password">Current password</label>
                                            <input class="form-control" type="password" id="details_current_password" name="current_password" autocomplete="current-password">
                                            <div class="form-text">Required only when changing your email.</div>
                                        </div>
                                        <div class="col-12"><button class="btn btn-success">Save login details</button></div>
                                    </form>
                                </div>

                                <div class="card-soft p-4 mt-4">
                                    <div class="text-uppercase small text-muted">Security</div>
                                    <h4 class="fw-bold mb-1">Change password</h4>
                                    <p class="text-muted small">Enter your new password twice so it can be checked before saving.</p>
                                    <form method="POST" class="row g-3">
                                        <input type="hidden" name="action" value="change_own_password">
                                        <div class="col-md-4"><label class="form-label" for="password_current">Current password</label><input class="form-control" type="password" id="password_current" name="current_password" autocomplete="current-password" required></div>
                                        <div class="col-md-4"><label class="form-label" for="password_new">New password</label><input class="form-control" type="password" id="password_new" name="new_password" minlength="8" autocomplete="new-password" required></div>
                                        <div class="col-md-4"><label class="form-label" for="password_confirm">Repeat new password</label><input class="form-control" type="password" id="password_confirm" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
                                        <div class="col-12"><button class="btn btn-success">Change password</button></div>
                                    </form>
                                </div>

                                <div class="card-soft p-4 mt-4">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-3">
                                        <div>
                                            <div class="text-uppercase small text-muted">Security</div>
                                            <h4 class="fw-bold mb-1">Authenticator app</h4>
                                            <div class="text-muted small">Use a 6-digit code from an authentication app as a sign-in option.</div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($accountIntroModal): ?><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#accountIntroModal"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Information</button><?php endif; ?>
                                            <span class="badge-role"><?php echo !empty($authAppStatus['enabled']) ? 'Enabled' : 'Not set up'; ?></span>
                                        </div>
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
                                $personMembershipYears = [];
                                foreach (fetchMemberships($pdo) as $membershipPurchase) {
                                    $membershipPersonId = (int)($membershipPurchase['member_id'] ?? 0);
                                    $membershipYear = (int)($membershipPurchase['membership_year'] ?? 0);
                                    if ($membershipPersonId > 0 && $membershipYear > ($personMembershipYears[$membershipPersonId] ?? 0)) {
                                        $personMembershipYears[$membershipPersonId] = $membershipYear;
                                    }
                                }
                                $editPersonId = (int)($_GET['person_id'] ?? 0);
                                $editPerson = $editPersonId > 0 ? fetchPersonForUserById($pdo, $userId, $editPersonId) : null;
                                if ($editPerson && !empty($editPerson['is_linked'])) {
                                    $editPerson = null;
                                }
                                $accountIntroAutoOpen = !$activePeople && !$editPerson && !(($action ?? '') === 'save_person' && !empty($alerts));
                                ?>

	                                <div class="card-soft p-4 mt-4">
	                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
	                                        <div>
	                                            <div class="fw-bold">Your people</div>
	                                            <div class="text-muted small"><?php echo count($activePeople); ?> active<?php echo $archivedPeople ? ' · ' . count($archivedPeople) . ' archived' : ''; ?></div>
	                                        </div>
	                                        <div class="d-flex gap-2">
                                                <?php if ($accountIntroModal): ?><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#accountIntroModal"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Information</button><?php endif; ?>
	                                            <button class="btn btn-success btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#personEditorModal">Add person</button>
                                            </div>
	                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle account-people-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Member #</th>
                                                    <th><span class="people-membership-heading-full">Membership</span><span class="people-membership-heading-mobile">Mem</span></th>
                                                    <th class="people-email-column">Email</th>
                                                    <th class="people-phone-column">Phone</th>
                                                    <th class="people-postcode-column">Postcode</th>
                                                    <th class="text-end people-actions-column">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!$activePeople): ?>
                                                    <tr><td colspan="7" class="text-muted small">No people yet.</td></tr>
                                                <?php endif; ?>
	                                                <?php foreach ($activePeople as $p): ?>
                                                        <?php
                                                        $personType = personRecordType($p, $currentUser ?? []);
                                                        $membershipState = annual_renewal_state((int)($personMembershipYears[(int)$p['id']] ?? 0), 'Membership', 'Buy Membership', 'Renew', null, 'Current Member');
                                                        ?>
	                                                    <tr class="people-data-row">
	                                                        <td class="fw-semibold">
                                                                <span class="person-type-icon me-1" title="<?php echo h(ucfirst($personType)); ?>" aria-label="<?php echo h(ucfirst($personType)); ?>">
                                                                    <i class="<?php echo h(personRecordTypeIcon($personType)); ?>" aria-hidden="true"></i>
                                                                </span>
                                                                <?php echo h(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))); ?>
                                                            </td>
	                                                        <td class="text-muted small"><?php echo $p['member_number'] ? (int)$p['member_number'] : '—'; ?></td>
	                                                        <td class="small"><span class="<?php echo h($membershipState['class']); ?> d-inline-flex align-items-center" title="<?php echo h($membershipState['title']); ?>"><i class="<?php echo h($membershipState['icon']); ?>" aria-hidden="true"></i><span class="visually-hidden"><?php echo h($membershipState['label']); ?></span></span></td>
	                                                        <td class="text-muted small people-email-column"><?php $personEmail=trim((string)($p['email']??'')); echo $personEmail!==''?'<a href="mailto:'.h($personEmail).'">'.h($personEmail).'</a>':'—'; ?></td>
	                                                        <td class="text-muted small people-phone-column"><?php $personPhone=trim((string)($p['phone']??''));$personTel=preg_replace('/[^0-9+]/','',$personPhone)?:'';echo $personPhone!==''&&$personTel!==''?'<a href="tel:'.h($personTel).'">'.h($personPhone).'</a>':'—'; ?></td>
	                                                        <td class="text-muted small text-uppercase people-postcode-column"><?php echo h($p['postcode'] ?? '—'); ?></td>
	                                                        <td class="text-end people-actions-column">
                                                                <?php if (!empty($p['is_linked'])): ?>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="action" value="unlink_shared_record">
                                                                        <input type="hidden" name="entity_type" value="person">
                                                                        <input type="hidden" name="entity_id" value="<?php echo (int)$p['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this linked person from your account?');">Remove link</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <?php if (!empty($membershipState['action_enabled'])): ?>
                                                                        <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/memberships?member_id=<?php echo (int)$p['id']; ?>"><?php echo h($membershipState['action_label']); ?></a>
                                                                    <?php else: ?>
                                                                        <button class="btn btn-sm btn-outline-success" type="button" disabled title="<?php echo h($membershipState['action_title']); ?>"><?php echo h($membershipState['action_label']); ?></button>
                                                                    <?php endif; ?>
                                                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=people&person_id=<?php echo (int)$p['id']; ?>">Edit</a>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="action" value="archive_person">
                                                                        <input type="hidden" name="person_id" value="<?php echo (int)$p['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this person?');">Archive</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <tr class="d-md-none">
                                                        <td colspan="7" class="people-mobile-actions-cell">
                                                            <div class="people-mobile-actions">
                                                                <?php if (!empty($p['is_linked'])): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="unlink_shared_record">
                                                                        <input type="hidden" name="entity_type" value="person">
                                                                        <input type="hidden" name="entity_id" value="<?php echo (int)$p['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this linked person from your account?');">Remove link</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <?php if (!empty($membershipState['action_enabled'])): ?>
                                                                        <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/memberships?member_id=<?php echo (int)$p['id']; ?>"><?php echo h($membershipState['action_label']); ?></a>
                                                                    <?php else: ?>
                                                                        <button class="btn btn-sm btn-outline-success" type="button" disabled title="<?php echo h($membershipState['action_title']); ?>"><?php echo h($membershipState['action_label']); ?></button>
                                                                    <?php endif; ?>
                                                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=people&amp;person_id=<?php echo (int)$p['id']; ?>">Edit</a>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="archive_person">
                                                                        <input type="hidden" name="person_id" value="<?php echo (int)$p['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this person?');">Archive</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
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
                                            <table class="table table-sm align-middle account-people-table">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Member #</th>
                                                        <th><span class="people-membership-heading-full">Membership</span><span class="people-membership-heading-mobile">Mem</span></th>
                                                        <th class="people-email-column">Email</th>
                                                        <th class="people-phone-column">Phone</th>
                                                        <th class="people-postcode-column">Postcode</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($archivedPeople as $p): ?>
                                                        <?php $personType = personRecordType($p, $currentUser ?? []); $archivedMembershipState = annual_renewal_state((int)($personMembershipYears[(int)$p['id']] ?? 0), 'Membership', 'Buy Membership', 'Renew', null, 'Current Member'); ?>
                                                        <tr class="text-muted">
                                                            <td>
                                                                <span class="person-type-icon me-1" title="<?php echo h(ucfirst($personType)); ?>" aria-label="<?php echo h(ucfirst($personType)); ?>">
                                                                    <i class="<?php echo h(personRecordTypeIcon($personType)); ?>" aria-hidden="true"></i>
                                                                </span>
                                                                <?php echo h(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))); ?>
                                                            </td>
                                                            <td class="small"><?php echo $p['member_number'] ? (int)$p['member_number'] : '—'; ?></td>
                                                            <td class="small"><span class="<?php echo h($archivedMembershipState['class']); ?>" title="<?php echo h($archivedMembershipState['title']); ?>"><i class="<?php echo h($archivedMembershipState['icon']); ?>" aria-hidden="true"></i><span class="visually-hidden"><?php echo h($archivedMembershipState['label']); ?></span></span></td>
                                                            <td class="small people-email-column"><?php $personEmail=trim((string)($p['email']??'')); echo $personEmail!==''?'<a href="mailto:'.h($personEmail).'">'.h($personEmail).'</a>':'—'; ?></td>
                                                            <td class="small people-phone-column"><?php $personPhone=trim((string)($p['phone']??''));$personTel=preg_replace('/[^0-9+]/','',$personPhone)?:'';echo $personPhone!==''&&$personTel!==''?'<a href="tel:'.h($personTel).'">'.h($personPhone).'</a>':'—'; ?></td>
                                                            <td class="small text-uppercase people-postcode-column"><?php echo h($p['postcode'] ?? '—'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
	                                    <?php endif; ?>
	                                </div>

	                                <div class="modal fade" id="personEditorModal" tabindex="-1" aria-labelledby="personEditorModalLabel" aria-hidden="true">
	                                    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
	                                        <div class="modal-content">
	                                            <div class="modal-header">
	                                                <div>
	                                                    <h4 class="modal-title fw-bold mb-1" id="personEditorModalLabel"><?php echo $editPerson ? 'Edit person' : 'Add person'; ?></h4>
	                                                    <div class="text-muted small">People can be selected on event entry forms to prefill details.</div>
	                                                </div>
	                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
	                                            </div>
	                                            <div class="modal-body">
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
	                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
	                                        </div>
	                                    </form>
	                                            </div>
	                                        </div>
	                                    </div>
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
                                        <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-start">
                                        <?php if ($accountIntroModal): ?><button class="btn btn-sm btn-outline-secondary text-nowrap" type="button" data-bs-toggle="modal" data-bs-target="#accountIntroModal"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Information</button><?php endif; ?>
                                        <form method="post" class="d-flex flex-column flex-sm-row gap-2">
                                            <input type="hidden" name="action" value="accept_share_code">
                                            <input class="form-control" name="share_code" placeholder="Share code" style="min-width: 180px;">
                                            <button class="btn btn-outline-success fw-bold" type="submit">Accept code</button>
                                        </form>
                                        </div>
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
                                $accountIntroAutoOpen = !$activeHorses && !$editHorse && !(($action ?? '') === 'save_horse' && !empty($alerts));
                                $horseQualifications = fetchHorseQualifications($pdo);
                                $horseQualificationLookup = [];
                                foreach ($horseQualifications as $hq) {
                                    $horseQualificationLookup[(int)($hq['id'] ?? 0)] = (string)($hq['name'] ?? '');
                                }
                                $logbookTypes = fetchHorseLogbookTypes($pdo, true);
                                $logbookType = $logbookTypes ? $logbookTypes[0] : null;
                                $logbooks = fetchHorseLogbooksForUser($pdo, $userId);
                                $horseRegisteredYear = [];
                                foreach ($logbooks as $horseLogbook) {
                                    $registeredHorseId = (int)($horseLogbook['horse_id'] ?? 0);
                                    $registeredYear = (int)($horseLogbook['valid_year'] ?? 0);
                                    if ($registeredHorseId > 0 && $registeredYear > ($horseRegisteredYear[$registeredHorseId] ?? 0)) {
                                        $horseRegisteredYear[$registeredHorseId] = $registeredYear;
                                    }
                                }
                                ?>

	                                <div class="card-soft p-4 mt-4">
	                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
	                                        <div>
	                                            <div class="fw-bold">Your horses</div>
	                                            <div class="text-muted small"><?php echo count($activeHorses); ?> active<?php echo $archivedHorses ? ' · ' . count($archivedHorses) . ' archived' : ''; ?></div>
	                                        </div>
	                                        <div class="d-flex gap-2">
                                                <?php if ($accountIntroModal): ?><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#accountIntroModal"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Information</button><?php endif; ?>
	                                            <button class="btn btn-success btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#horseEditorModal">Add Horse Registration / Logbook</button>
                                            </div>
	                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle account-horses-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Year</th>
                                                    <th class="horse-breed-column">Breed</th>
                                                    <th class="horse-colour-column">Colour</th>
                                                    <th>Qualification</th>
                                                    <th class="horse-sex-column">Sex</th>
                                                    <th>Logbook</th>
                                                    <th class="text-end horse-actions-column">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!$activeHorses): ?>
                                                    <tr><td colspan="8" class="text-muted small">No horses yet.</td></tr>
                                                <?php endif; ?>
	                                                <?php foreach ($activeHorses as $h): ?>
                                                    <?php
                                                    $horseRegistrationYear = (int)($horseRegisteredYear[(int)$h['id']] ?? 0);
                                                    $logbookStatus = annual_renewal_state($horseRegistrationYear, 'Logbook', 'Register / Renew', 'Register / Renew');
                                                    $horseLogbookActionEnabled = !empty($logbookStatus['action_enabled']);
                                                    ?>
                                                    <tr class="horse-data-row">
                                                        <td class="fw-semibold">
                                                            <?php echo h($h['name'] ?? ''); ?>
                                                            <?php if (!empty($h['is_linked'])): ?><span class="linked-badge ms-1">link</span><?php endif; ?>
                                                        </td>
                                                        <td class="text-muted small"><?php echo h($h['year_of_birth'] ?? '—'); ?></td>
                                                        <td class="text-muted small horse-breed-column"><?php echo h($h['breed'] ?? '—'); ?></td>
                                                        <td class="text-muted small horse-colour-column"><?php echo h($h['colour'] ?? '—'); ?></td>
                                                        <td class="text-muted small"><?php echo h($horseQualificationLookup[(int)($h['qualification_id'] ?? 0)] ?? '—'); ?></td>
                                                        <td class="text-muted small horse-sex-column"><?php echo h($h['sex'] ?? '—'); ?></td>
                                                        <td class="small">
                                                            <span class="<?php echo h($logbookStatus['class']); ?> d-inline-flex align-items-center gap-1" title="<?php echo h($logbookStatus['title']); ?>">
                                                                <i class="<?php echo h($logbookStatus['icon']); ?>" aria-hidden="true"></i>
                                                                <span class="visually-hidden"><?php echo h($logbookStatus['label']); ?></span>
                                                            </span>
                                                        </td>
                                                        <td class="text-end horse-actions-column">
                                                            <?php if (!empty($h['is_linked'])): ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="action" value="unlink_shared_record">
                                                                    <input type="hidden" name="entity_type" value="horse">
                                                                    <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                    <input type="hidden" name="entity_id" value="<?php echo (int)$h['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this linked horse from your account?');">Remove link</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=horses&horse_id=<?php echo (int)$h['id']; ?>">Edit</a>
                                                                <?php if ($logbookType): ?>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="action" value="add_logbook">
                                                                        <input type="hidden" name="logbook_type_id" value="<?php echo (int)($logbookType['id'] ?? 0); ?>">
                                                                        <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-success btn-logbook-action" <?php echo $horseLogbookActionEnabled ? '' : 'disabled'; ?> title="<?php echo h($logbookStatus['action_title']); ?>">Register / Renew</button>
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
                                                    <tr class="d-md-none">
                                                        <td colspan="8" class="horse-mobile-actions-cell">
                                                            <div class="horse-mobile-actions">
                                                                <?php if (!empty($h['is_linked'])): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="unlink_shared_record">
                                                                        <input type="hidden" name="entity_type" value="horse">
                                                                        <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                        <input type="hidden" name="entity_id" value="<?php echo (int)$h['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this linked horse from your account?');">Remove link</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($basePath); ?>/account?view=horses&amp;horse_id=<?php echo (int)$h['id']; ?>">Edit</a>
                                                                    <?php if ($logbookType): ?>
                                                                        <form method="post">
                                                                            <input type="hidden" name="action" value="add_logbook">
                                                                            <input type="hidden" name="logbook_type_id" value="<?php echo (int)($logbookType['id'] ?? 0); ?>">
                                                                            <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                            <button type="submit" class="btn btn-sm btn-outline-success btn-logbook-action" <?php echo $horseLogbookActionEnabled ? '' : 'disabled'; ?> title="<?php echo h($logbookStatus['action_title']); ?>">Register / Renew</button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="archive_horse">
                                                                        <input type="hidden" name="horse_id" value="<?php echo (int)$h['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this horse?');">Archive</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
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
                                            <table class="table table-sm align-middle account-horses-table">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Year</th>
                                                        <th class="horse-breed-column">Breed</th>
                                                        <th class="horse-colour-column">Colour</th>
                                                        <th>Qualification</th>
                                                        <th class="horse-sex-column">Sex</th>
                                                        <th>Logbook</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($archivedHorses as $h): ?>
                                                        <?php $archivedLogbookStatus = annual_renewal_state((int)($horseRegisteredYear[(int)$h['id']] ?? 0), 'Logbook', 'Register / Renew', 'Register / Renew'); ?>
                                                        <tr class="text-muted">
                                                            <td><?php echo h($h['name'] ?? ''); ?></td>
                                                            <td class="small"><?php echo h($h['year_of_birth'] ?? '—'); ?></td>
                                                            <td class="small horse-breed-column"><?php echo h($h['breed'] ?? '—'); ?></td>
                                                            <td class="small horse-colour-column"><?php echo h($h['colour'] ?? '—'); ?></td>
                                                            <td class="small"><?php echo h($horseQualificationLookup[(int)($h['qualification_id'] ?? 0)] ?? '—'); ?></td>
                                                            <td class="small horse-sex-column"><?php echo h($h['sex'] ?? '—'); ?></td>
                                                            <td class="small"><span class="<?php echo h($archivedLogbookStatus['class']); ?> d-inline-flex align-items-center" title="<?php echo h($archivedLogbookStatus['title']); ?>"><i class="<?php echo h($archivedLogbookStatus['icon']); ?>" aria-hidden="true"></i><span class="visually-hidden"><?php echo h($archivedLogbookStatus['label']); ?></span></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
	                                    <?php endif; ?>
	                                </div>

	                                <div class="modal fade" id="horseEditorModal" tabindex="-1" aria-labelledby="horseEditorModalLabel" aria-hidden="true">
	                                    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
	                                        <div class="modal-content">
	                                            <div class="modal-header">
	                                                <div>
	                                                    <h4 class="modal-title fw-bold mb-1" id="horseEditorModalLabel"><?php echo $editHorse ? 'Edit horse' : 'Add Horse Registration / Logbook'; ?></h4>
	                                                    <div class="text-muted small">Horses can be selected on event entry forms to prefill details.</div>
	                                                </div>
	                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
	                                            </div>
	                                            <div class="modal-body">
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
                                            <button class="btn btn-success fw-bold" type="submit"><?php echo $editHorse ? 'Save changes' : 'Continue'; ?></button>
	                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        </div>
                                    </form>
	                                            </div>
	                                        </div>
	                                    </div>
                                </div>
                                <?php
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
	                            $renderMembershipRows = static function (array $purchases, bool $isPrevious = false) use ($userMembershipPurchases, $renewalMembershipTypes, $basePath): void {
	                                foreach ($purchases as $purchase) {
	                                    $memberLabel = trim((string)($purchase['member_name'] ?? ''));
	                                    $memberNumber = trim((string)($purchase['member_number'] ?? ''));
	                                    $membershipStatus = trim((string)($purchase['status'] ?? 'active')) ?: 'active';
	                                    $purchaseYear = (int)($purchase['membership_year'] ?? 0);
	                                    $memberId = (int)($purchase['member_id'] ?? 0);
	                                    $hasRenewed = $isPrevious && array_filter($userMembershipPurchases, static fn(array $candidate): bool =>
	                                        (int)($candidate['member_id'] ?? 0) === $memberId
	                                        && (int)($candidate['membership_year'] ?? 0) > $purchaseYear
	                                    );
	                                    $displayStatus = $hasRenewed ? 'Renewed' : ucfirst($membershipStatus);
	                                    $membershipIsActive = !$isPrevious && strtolower($membershipStatus) === 'active';
	                                    $statusClass = $hasRenewed ? 'membership-status-renewed' : ($membershipIsActive ? 'membership-status-active' : 'membership-status-inactive');
	                                    $statusIcon = $hasRenewed ? 'fa-arrows-rotate' : ($membershipIsActive ? 'fa-circle-check' : 'fa-circle-xmark');
	                                    $renewalState = annual_renewal_state($purchaseYear, 'Membership', 'Buy Membership', 'Renew', null, 'Current Member');
	                                    $renewalActionEnabled = $isPrevious ? !$hasRenewed : !empty($renewalState['action_enabled']);
	                                    $renewalType = null;
	                                    if ($renewalActionEnabled) {
	                                        $sourceNameKey = strtolower(trim((string)preg_replace('/\\b(?:19|20)\\d{2}\\b/', '', (string)($purchase['membership_name'] ?? ''))));
	                                        $sourceTypeKey = strtolower(trim((string)($purchase['membership_type_key'] ?? '')));
	                                        $fallbackTypes = [];
	                                        foreach ($renewalMembershipTypes as $candidateType) {
	                                            if ((int)($candidateType['membership_year'] ?? 0) <= $purchaseYear) continue;
	                                            $candidateNameKey = strtolower(trim((string)preg_replace('/\\b(?:19|20)\\d{2}\\b/', '', (string)($candidateType['name'] ?? ''))));
	                                            if ($sourceNameKey !== '' && $candidateNameKey === $sourceNameKey) {
	                                                $renewalType = $candidateType;
	                                                break;
	                                            }
	                                            if ($sourceTypeKey !== '' && strtolower((string)($candidateType['type'] ?? '')) === $sourceTypeKey) $fallbackTypes[] = $candidateType;
	                                        }
	                                        if (!$renewalType && count($fallbackTypes) === 1) $renewalType = $fallbackTypes[0];
	                                    }
	                                    $renewUrl = $renewalType
	                                        ? $basePath . '/memberships?member_id=' . $memberId . '&membership_type_id=' . (int)$renewalType['id'] . '#membership-type-' . (int)$renewalType['id']
	                                        : ($isPrevious && !$hasRenewed ? $basePath . '/memberships?member_id=' . $memberId : '');
	                                    $renewDisabled = !$renewalActionEnabled || (!$isPrevious && $renewUrl === '');
	                                    $renewTitle = $hasRenewed
	                                        ? 'This membership has been renewed'
	                                        : ($renewDisabled ? (string)($renewalState['action_title'] ?? 'Renewal is not available yet') : 'Renew membership');
	                                    $membershipDetails = [
	                                        ['label' => 'Member', 'value' => $memberLabel !== '' ? $memberLabel : 'Not assigned'],
	                                        ['label' => 'Membership number', 'value' => $memberNumber !== '' ? $memberNumber : '—'],
	                                        ['label' => 'Membership', 'value' => (string)($purchase['membership_name'] ?? 'Membership')],
	                                        ['label' => 'Membership year', 'value' => (string)(int)($purchase['membership_year'] ?? 0)],
                                        ['label' => 'Status', 'value' => $displayStatus],
                                        ['label' => 'Purchase date', 'value' => format_display_date($purchase['purchased_at'] ?? null, '—')],
	                                        ['label' => 'Amount', 'value' => '£' . number_format((float)($purchase['amount'] ?? 0), 2)],
	                                        ['label' => 'Reference', 'value' => '#' . (int)($purchase['id'] ?? 0)],
	                                    ];
	                                    $membershipDetailsJson = json_encode($membershipDetails, JSON_HEX_APOS | JSON_HEX_QUOT);
	                                    ?>
	                                    <tr class="js-account-detail-row" data-account-detail-title="Membership details" data-account-detail-items="<?php echo h((string)$membershipDetailsJson); ?>" data-account-detail-action-label="Renew" data-account-detail-action-url="<?php echo h($renewUrl); ?>" data-account-detail-action-disabled="<?php echo $renewDisabled ? '1' : '0'; ?>">
	                                        <td class="small"><?php echo h($memberLabel !== '' ? $memberLabel : 'Not assigned'); ?></td>
	                                        <td class="small"><?php echo h($memberNumber !== '' ? $memberNumber : '—'); ?></td>
	                                        <td class="small fw-semibold"><?php echo h($purchase['membership_name'] ?? 'Membership'); ?></td>
	                                        <td class="small"><?php echo (int)($purchase['membership_year'] ?? 0); ?></td>
	                                        <td class="membership-status-cell small text-capitalize">
	                                            <span class="membership-status <?php echo h($statusClass); ?>">
	                                                <i class="fa-solid <?php echo h($statusIcon); ?>" aria-hidden="true"></i>
	                                                <span class="membership-status-label"><?php echo h($displayStatus); ?></span>
	                                            </span>
	                                        </td>
	                                        <td class="membership-purchased-column text-muted small"><?php echo h(format_display_date($purchase['purchased_at'] ?? null, '—')); ?></td>
	                                        <td class="text-end small fw-semibold"><?php echo '£' . number_format((float)($purchase['amount'] ?? 0), 2); ?></td>
	                                        <td class="membership-actions-column text-end text-nowrap">
	                                            <?php if ($renewDisabled): ?><button class="btn btn-sm btn-secondary" type="button" disabled title="<?php echo h($renewTitle); ?>">Renew</button>
	                                            <?php else: ?><a class="btn btn-sm btn-success" href="<?php echo h($renewUrl); ?>" title="<?php echo h($renewTitle); ?>">Renew</a><?php endif; ?>
	                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-account-detail-open>View</button>
	                                        </td>
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
	                                            <table class="table table-sm align-middle mb-0">
	                                                <thead class="table-light">
	                                                    <tr>
                                                        <th>Member</th>
                                                        <th>#</th>
                                                        <th>Membership</th>
                                                        <th>Year</th>
	                                                        <th class="membership-status-heading"><span class="membership-status-heading-label">Status</span></th>
	                                                        <th class="membership-purchased-column">Purchased</th>
	                                                        <th class="text-end">Amount</th>
	                                                        <th class="membership-actions-column text-end">Actions</th>
	                                                    </tr>
	                                                </thead>
	                                                <tbody>
	                                                    <?php $renderMembershipRows($activeMembershipPurchases); ?>
	                                                </tbody>
	                                            </table>
	                                        </div>
	                                        <div class="d-md-none small text-muted text-start mt-1">Click row for more details</div>
	                                    <?php endif; ?>
	                                </div>

	                                <div class="divider"></div>

	                                <div>
	                                    <div class="fw-bold mb-2">Previous</div>
	                                    <?php if (!$previousMembershipPurchases): ?>
	                                        <div class="text-muted small">No previous memberships.</div>
	                                    <?php else: ?>
	                                        <div class="table-responsive">
	                                            <table class="table table-sm align-middle mb-0">
	                                                <thead class="table-light">
	                                                    <tr>
                                                        <th>Member</th>
                                                        <th>#</th>
                                                        <th>Membership</th>
                                                        <th>Year</th>
	                                                        <th class="membership-status-heading"><span class="membership-status-heading-label">Status</span></th>
	                                                        <th class="membership-purchased-column">Purchased</th>
	                                                        <th class="text-end">Amount</th>
	                                                        <th class="membership-actions-column text-end">Actions</th>
	                                                    </tr>
	                                                </thead>
	                                                <tbody>
	                                                    <?php $renderMembershipRows($previousMembershipPurchases, true); ?>
	                                                </tbody>
	                                            </table>
	                                        </div>
	                                        <div class="d-md-none small text-muted text-start mt-1">Click row for more details</div>
	                                    <?php endif; ?>
	                                </div>
	                            <?php endif; ?>
	                        </div>

	                        <div class="card-soft p-4 mt-4">
	                            <div class="d-flex justify-content-between align-items-center mb-3">
	                                <div class="fw-bold">Recent Purchases</div>
	                                <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/bookings">View all purchases</a>
	                            </div>

	                            <?php if (!$recentBookings): ?>
	                                <div class="text-muted small">No purchases yet. Start by exploring upcoming events.</div>
	                            <?php else: ?>
	                                <div class="table-responsive">
	                                    <table class="table table-sm align-middle account-bookings-table mb-0">
	                                        <thead class="table-light">
	                                            <tr>
	                                                <th class="booking-reference-column">Booking Ref</th>
	                                                <th><span class="d-none d-sm-inline">Items</span><span class="d-sm-none">#</span></th>
	                                                <th class="booking-item-column">Item</th>
	                                                <th>Total</th>
	                                                <th>Placed</th>
	                                                <th class="booking-actions-column text-end">Actions</th>
	                                            </tr>
	                                        </thead>
	                                        <tbody>
	                                            <?php foreach ($recentBookings as $booking): ?>
	                                                <?php
	                                                $bookingRef = $booking['booking_ref'] ?? $booking['id'] ?? '';
	                                                $placedAt = trim((string)($booking['created_at'] ?? ''));
	                                                $placedTimestamp = $placedAt !== '' ? strtotime($placedAt) : false;
	                                                $placedPhone = $placedTimestamp !== false ? date('d M y', $placedTimestamp) : '—';
	                                                $purchaseLabels = [];
	                                                $purchaseDetailLabels = [];
	                                                foreach ($booking['items'] ?? [] as $bookingItem) {
	                                                    $bookingType = strtolower((string)($bookingItem['booking_type'] ?? 'ride'));
	                                                    if ($bookingType === 'horse_logbook') {
	                                                        $purchaseLabel = 'Logbook';
	                                                    } elseif ($bookingType === 'membership') {
	                                                        $purchaseLabel = 'Membership';
	                                                    } else {
	                                                        $purchaseLabel = trim((string)($bookingItem['event_title'] ?? $bookingItem['event_name'] ?? ''));
	                                                        if ($purchaseLabel === '') $purchaseLabel = 'Event entry';
	                                                    }
	                                                    if (!in_array($purchaseLabel, $purchaseLabels, true)) $purchaseLabels[] = $purchaseLabel;
	                                                    $purchaseDetailLabel = $purchaseLabel;
	                                                    if (isset($bookingItem['price'])) $purchaseDetailLabel .= ' — £' . number_format((float)$bookingItem['price'], 2);
	                                                    $purchaseDetailLabels[] = $purchaseDetailLabel;
	                                                }
	                                                $bookingDetails = [
	                                                    ['label' => 'Booking reference', 'value' => $bookingRef !== '' ? '#' . $bookingRef : '—'],
	                                                    ['label' => 'Items', 'value' => $purchaseDetailLabels ? implode("\n", $purchaseDetailLabels) : '—'],
	                                                    ['label' => 'Item count', 'value' => (string)count($booking['items'] ?? [])],
	                                                    ['label' => 'Total', 'value' => isset($booking['total']) ? '£' . number_format((float)$booking['total'], 2) : '—'],
	                                                    ['label' => 'Placed', 'value' => format_display_date($booking['created_at'] ?? null, '—')],
	                                                    ['label' => 'Contact', 'value' => trim((string)($booking['contact_name'] ?? '')) ?: '—'],
	                                                    ['label' => 'Email', 'value' => trim((string)($booking['contact_email'] ?? '')) ?: '—'],
	                                                    ['label' => 'Phone', 'value' => trim((string)($booking['contact_phone'] ?? '')) ?: '—'],
	                                                ];
	                                                $bookingDetailsJson = json_encode($bookingDetails, JSON_HEX_APOS | JSON_HEX_QUOT);
	                                                ?>
	                                                <tr class="js-account-detail-row" data-account-detail-title="Purchase details" data-account-detail-items="<?php echo h((string)$bookingDetailsJson); ?>">
	                                                    <td class="booking-reference-column small fw-semibold"><?php echo $bookingRef !== '' ? '#' . h($bookingRef) : '<span class="text-muted">—</span>'; ?></td>
	                                                    <td><?php echo count($booking['items'] ?? []); ?></td>
	                                                    <td class="booking-item-column small text-muted">
	                                                        <?php if ($purchaseLabels): ?>
	                                                            <?php foreach ($purchaseLabels as $purchaseLabel): ?><div><?php echo h($purchaseLabel); ?></div><?php endforeach; ?>
	                                                        <?php else: ?>—<?php endif; ?>
	                                                    </td>
	                                                    <td><?php echo isset($booking['total']) ? '£' . number_format((float)$booking['total'], 2) : '—'; ?></td>
	                                                    <td class="text-muted small"><span class="d-none d-sm-inline"><?php echo h(format_display_date($booking['created_at'] ?? null, '—')); ?></span><span class="d-sm-none"><?php echo h($placedPhone); ?></span></td>
	                                                    <td class="booking-actions-column text-end"><button class="btn btn-sm btn-outline-secondary" type="button" data-account-detail-open>View</button></td>
	                                                </tr>
	                                            <?php endforeach; ?>
	                                        </tbody>
	                                    </table>
	                                </div>
	                                <div class="d-md-none small text-muted text-start mt-1">Click row for more details</div>
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

    <div class="modal fade" id="accountDetailModal" tabindex="-1" aria-labelledby="accountDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="accountDetailModalLabel">Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <dl class="account-detail-list" data-account-detail-list></dl>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-success" href="#" data-account-detail-action hidden>Continue</a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

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

    <?php if ($accountIntroModal): ?>
        <div class="modal fade" id="accountIntroModal" tabindex="-1" aria-labelledby="accountIntroModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="accountIntroModalLabel"><?php echo h((string)$accountIntroModal['heading']); ?></h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body account-intro-content"><?php echo (string)$accountIntroModal['body_html']; ?></div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
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

        const accountDetailModalElement = document.getElementById('accountDetailModal');
        if (accountDetailModalElement) {
            const accountDetailModal = new bootstrap.Modal(accountDetailModalElement);
            const accountDetailTitle = accountDetailModalElement.querySelector('.modal-title');
            const accountDetailList = accountDetailModalElement.querySelector('[data-account-detail-list]');
            const accountDetailAction = accountDetailModalElement.querySelector('[data-account-detail-action]');
            const openAccountDetail = row => {
                if (!row || !accountDetailList) return;
                let items = [];
                try { items = JSON.parse(row.dataset.accountDetailItems || '[]'); } catch (error) { return; }
                accountDetailTitle.textContent = row.dataset.accountDetailTitle || 'Details';
                if (accountDetailAction) {
                    const actionLabel = row.dataset.accountDetailActionLabel || '';
                    const actionUrl = row.dataset.accountDetailActionUrl || '';
                    const actionDisabled = row.dataset.accountDetailActionDisabled === '1';
                    accountDetailAction.hidden = actionLabel === '';
                    accountDetailAction.textContent = actionLabel || 'Continue';
                    accountDetailAction.classList.toggle('disabled', actionDisabled);
                    accountDetailAction.classList.toggle('btn-success', !actionDisabled);
                    accountDetailAction.classList.toggle('btn-secondary', actionDisabled);
                    accountDetailAction.setAttribute('aria-disabled', actionDisabled ? 'true' : 'false');
                    if (actionUrl !== '' && !actionDisabled) accountDetailAction.href = actionUrl;
                    else accountDetailAction.removeAttribute('href');
                }
                accountDetailList.replaceChildren();
                items.forEach(item => {
                    const term = document.createElement('dt');
                    const description = document.createElement('dd');
                    term.textContent = item.label || '';
                    description.textContent = item.value || '—';
                    accountDetailList.append(term, description);
                });
                accountDetailModal.show();
            };
            document.querySelectorAll('[data-account-detail-open]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    openAccountDetail(button.closest('.js-account-detail-row'));
                });
            });
            document.querySelectorAll('.js-account-detail-row').forEach(row => {
                row.addEventListener('click', event => {
                    if (!window.matchMedia('(max-width: 767.98px)').matches) return;
                    if (event.target.closest('a, button, input, select, textarea, label')) return;
                    openAccountDetail(row);
                });
            });
        }
        <?php if ($accountView === 'people' && (!empty($editPerson) || (($action ?? '') === 'save_person' && !empty($alerts)))): ?>
        const personEditorModalEl = document.getElementById('personEditorModal');
        if (personEditorModalEl) {
            new bootstrap.Modal(personEditorModalEl).show();
            const clearPersonEditorUrl = () => {
                const peoplePageUrl = new URL(window.location.href);
                if (!peoplePageUrl.searchParams.has('person_id')) return;
                peoplePageUrl.searchParams.delete('person_id');
                window.history.replaceState({}, '', peoplePageUrl.pathname + peoplePageUrl.search + peoplePageUrl.hash);
            };
            clearPersonEditorUrl();
            personEditorModalEl.addEventListener('hidden.bs.modal', clearPersonEditorUrl);
        }
        <?php endif; ?>
        <?php if ($accountView === 'horses' && (!empty($editHorse) || (($action ?? '') === 'save_horse' && !empty($alerts)))): ?>
        const horseEditorModalEl = document.getElementById('horseEditorModal');
        if (horseEditorModalEl) {
            new bootstrap.Modal(horseEditorModalEl).show();
        }
        <?php endif; ?>
        <?php if ($accountIntroModal && $accountIntroAutoOpen): ?>
        const accountIntroModalEl = document.getElementById('accountIntroModal');
        if (accountIntroModalEl) {
            new bootstrap.Modal(accountIntroModalEl).show();
        }
        <?php endif; ?>
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
