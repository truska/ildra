<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$settingsRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageCampaignEmail = in_array($settingsRole, ['developer', 'superadmin', 'admin'], true);
$siteSettings = getSiteSettings($pdo);
$footerServiceLinks = json_decode((string)$siteSettings['footer_service_links'], true) ?: [];
$companySocials = fetchCompanySocials($pdo);
$companyAffiliates = fetchCompanyAffiliates($pdo);
$companyLogoAssets = array_values(array_filter(fetchAssetLibrary($pdo, true), static function (array $asset): bool {
    return ($asset['asset_type'] ?? '') === 'image';
}));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsSection = (string)($_POST['settings_section'] ?? 'global');
    if ($settingsSection === 'home') {
        $payload = [
            'hero_title' => trim((string)($_POST['hero_title'] ?? '')),
            'company_short_name' => trim((string)($_POST['company_short_name'] ?? '')),
            'hero_tagline' => trim((string)($_POST['hero_tagline'] ?? '')),
            'hero_cta_label' => trim((string)($_POST['hero_cta_label'] ?? '')),
            'hero_cta_url' => trim((string)($_POST['hero_cta_url'] ?? '')),
            'hero_cta_2_label' => trim((string)($_POST['hero_cta_2_label'] ?? '')),
            'hero_cta_2_url' => trim((string)($_POST['hero_cta_2_url'] ?? '')),
            'home_established_text' => trim((string)($_POST['home_established_text'] ?? '')),
            'home_heading_text' => trim((string)($_POST['home_heading_text'] ?? '')),
            'sponsor_image_url' => trim((string)($_POST['sponsor_image_url'] ?? '')),
            'home_carousel_interval_seconds' => max(2, min(60, (int)($_POST['home_carousel_interval_seconds'] ?? 6))),
        ];
        if (saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Home page settings saved.';
            header('Location: settings.php?tab=home');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
    } elseif ($settingsSection === 'company') {
        $payload = [
            'company_name' => trim((string)($_POST['company_name'] ?? '')),
            'company_contact_email' => trim((string)($_POST['company_contact_email'] ?? '')),
            'company_webmaster_email' => trim((string)($_POST['company_webmaster_email'] ?? '')),
            'company_website_url' => trim((string)($_POST['company_website_url'] ?? '')),
            'company_address' => trim((string)($_POST['company_address'] ?? '')),
            'company_postcode' => trim((string)($_POST['company_postcode'] ?? '')),
        ];

        foreach (['company_contact_email', 'company_webmaster_email'] as $emailKey) {
            if ($payload[$emailKey] !== '' && !filter_var($payload[$emailKey], FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid email address.'];
                break;
            }
        }
        foreach (['company_website_url'] as $urlKey) {
            if (!$alerts && $payload[$urlKey] !== '' && !filter_var($payload[$urlKey], FILTER_VALIDATE_URL)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Enter a complete, valid URL including https://.'];
                break;
            }
        }

        if (!$alerts && saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Company settings saved.';
            header('Location: settings.php?tab=company');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
    } elseif ($settingsSection === 'footer') {
        $payload = [];
        foreach (['main', 'contact', 'events', 'services', 'socials', 'partners'] as $column) {
            $payload['footer_' . $column . '_title'] = trim((string)($_POST['footer_' . $column . '_title'] ?? ''));
        }
        $payload['footer_partners_show_rule'] = isset($_POST['footer_partners_show_rule']) ? '1' : '0';
        $footerServiceLinks = [];
        foreach ((array)($_POST['footer_links'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $title = trim((string)($row['title'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            $icon = trim((string)($row['icon'] ?? ''));
            if ($title === '' && $url === '' && $icon === '') continue;
            // Allow site-relative paths and ordinary web, email and phone links only.
            if ($title === '' || !preg_match('~^(?:/(?!/)|https?://|mailto:|tel:)\S*$~i', $url)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Each footer link needs a title and a URL starting with /, https://, http://, mailto: or tel:.'];
            }
            if ($icon !== '' && !preg_match('/^fa[a-z0-9-]*(?: +fa[a-z0-9-]+)*$/i', $icon)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Enter Font Awesome class names such as fa-solid fa-user, or leave the icon blank.'];
            }
            $footerServiceLinks[] = ['title' => $title, 'url' => $url, 'target' => ($row['target'] ?? '') === '_blank' ? '_blank' : '_self', 'icon' => $icon];
        }
        $payload['footer_service_links'] = json_encode($footerServiceLinks, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $postedSocials = isset($_POST['socials']) && is_array($_POST['socials']) ? array_values($_POST['socials']) : [];
        $postedAffiliates = isset($_POST['affiliates']) && is_array($_POST['affiliates']) ? array_values($_POST['affiliates']) : [];
        if (!$alerts && saveSiteSettings($pdo, $payload, $alerts) && saveCompanySocials($pdo, $postedSocials, $alerts) && saveCompanyAffiliates($pdo, $postedAffiliates, $alerts)) {
            $_SESSION['flash_success'] = 'Footer settings saved.';
            header('Location: settings.php?tab=footer');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
        $companySocials = $postedSocials;
        $companyAffiliates = $postedAffiliates;
    } elseif ($settingsSection === 'campaign_email') {
        if (!$canManageCampaignEmail) {
            $alerts[] = ['type' => 'danger', 'message' => 'Campaign email settings are restricted to senior administrators.'];
        } else {
            $baseUrl = rtrim(trim((string)($_POST['campaign_public_base_url'] ?? '')), '/');
            if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Campaign public base URL must be a valid absolute URL.'];
            }
            $payload = [
                'campaign_live_sending_enabled' => !empty($_POST['campaign_live_sending_enabled']) ? '1' : '0',
                'campaign_default_batch_size' => max(1, min(500, (int)($_POST['campaign_default_batch_size'] ?? 25))),
                'campaign_public_base_url' => $baseUrl,
            ];
            if (!$alerts && saveSiteSettings($pdo, $payload, $alerts)) {
                $_SESSION['flash_success'] = 'Campaign email settings saved.';
                header('Location: settings.php?tab=campaign_email');
                exit;
            }
            $siteSettings = array_merge($siteSettings, $payload);
        }
    } elseif ($settingsSection === 'marketing') {
        $payload = [
            'ride_helper_coupon_value' => number_format(max(0, price_to_number($_POST['ride_helper_coupon_value'] ?? 0)), 2, '.', ''),
            'coupon_default_terms_html' => trim((string)($_POST['coupon_default_terms_html'] ?? '')),
        ];
        if (saveSiteSettings($pdo, $payload, $alerts)) { $_SESSION['flash_success']='Marketing settings saved.'; header('Location: settings.php?tab=marketing'); exit; }
        $siteSettings=array_merge($siteSettings,$payload);
    } elseif ($settingsSection === 'ride_notes_email') {
        $payload = [
            'ride_notes_email_intro_html' => trim((string)($_POST['ride_notes_email_intro_html'] ?? '')),
            'ride_notes_email_signature_html' => trim((string)($_POST['ride_notes_email_signature_html'] ?? '')),
            'ride_notes_email_header_image_url' => trim((string)($_POST['ride_notes_email_header_image_url'] ?? '')),
        ];
        if (saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Ride Notes email settings saved.';
            header('Location: settings.php?tab=ride_notes_email');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
    } elseif ($settingsSection === 'events') {
        $minutes = max(0, (int)($_POST['basket_timeout_minutes'] ?? 0));
        $seconds = max(0, (int)($_POST['basket_timeout_seconds'] ?? 0));
        $totalSeconds = max(1, ($minutes * 60) + $seconds);
        $payload = [
            'basket_timeout_seconds' => $totalSeconds,
            'event_default_start_time' => trim((string)($_POST['event_default_start_time'] ?? '')),
            'event_default_end_days' => max(0, (int)($_POST['event_default_end_days'] ?? 0)),
            'event_default_end_time' => trim((string)($_POST['event_default_end_time'] ?? '')),
            'event_member_open_weeks' => max(0, (int)($_POST['event_member_open_weeks'] ?? 0)),
            'event_member_open_weekday' => max(0, min(6, (int)($_POST['event_member_open_weekday'] ?? 5))),
            'event_member_open_time' => trim((string)($_POST['event_member_open_time'] ?? '')),
            'event_non_member_open_weeks' => max(0, (int)($_POST['event_non_member_open_weeks'] ?? 0)),
            'event_non_member_open_weekday' => max(0, min(6, (int)($_POST['event_non_member_open_weekday'] ?? 5))),
            'event_non_member_open_time' => trim((string)($_POST['event_non_member_open_time'] ?? '')),
            'event_entry_close_weeks' => max(0, (int)($_POST['event_entry_close_weeks'] ?? 0)),
            'event_entry_close_weekday' => max(0, min(6, (int)($_POST['event_entry_close_weekday'] ?? 4))),
            'event_entry_close_time' => trim((string)($_POST['event_entry_close_time'] ?? '')),
            'event_late_entries_enabled' => !empty($_POST['event_late_entries_enabled']) ? '1' : '0',
            'event_late_entry_close_days' => max(0, min(365, (int)($_POST['event_late_entry_close_days'] ?? 1))),
            'event_late_entry_close_time' => trim((string)($_POST['event_late_entry_close_time'] ?? '18:00')),
            'event_late_entry_fee' => number_format(max(0, price_to_number($_POST['event_late_entry_fee'] ?? '0')), 2, '.', ''),
            'event_stripe_refund_fee' => number_format(max(0, price_to_number($_POST['event_stripe_refund_fee'] ?? '5.00')), 2, '.', ''),
        ];
        if (saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Event settings saved.';
            header('Location: settings.php?tab=events');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
    } else {
        $rememberDays = max(0, (int)($_POST['remember_me_days'] ?? 30));
        $rememberTtlSeconds = $rememberDays === 0 ? 0 : ($rememberDays * 86400);
        $manualAssetId = max(0, (int)($_POST['admin_manual_asset_id'] ?? 0));
        $authAppLoginEnabled = !empty($_POST['auth_app_login_enabled']) ? '1' : '0';

        $payload = [
            'remember_me_ttl_seconds' => $rememberTtlSeconds,
            'admin_manual_asset_id' => (string)$manualAssetId,
            'auth_app_login_enabled' => $authAppLoginEnabled,
            'membership_next_year_from' => trim((string)($_POST['membership_next_year_from'] ?? '11-01')),
        ];
        if (saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Global settings saved.';
            header('Location: settings.php?tab=global');
            exit;
        }
        $siteSettings['remember_me_ttl_seconds'] = $rememberTtlSeconds;
        $siteSettings['auth_app_login_enabled'] = $authAppLoginEnabled;
    }
}

$timeoutSeconds = (int)($siteSettings['basket_timeout_seconds'] ?? 900);
$timeoutMinutes = intdiv($timeoutSeconds, 60);
$timeoutRemainder = $timeoutSeconds % 60;
$rememberMeSeconds = (int)($siteSettings['remember_me_ttl_seconds'] ?? (30 * 86400));
$rememberMeDays = $rememberMeSeconds > 0 ? (int)floor($rememberMeSeconds / 86400) : 0;
$manualAssetId = (int)($siteSettings['admin_manual_asset_id'] ?? 0);
$manualDocuments = array_values(array_filter(fetchAssetLibrary($pdo, true), static function (array $asset): bool {
    return ($asset['asset_type'] ?? '') === 'pdf' && empty($asset['archived']);
}));
$authAppLoginEnabled = !empty($siteSettings['auth_app_login_enabled']) && (string)$siteSettings['auth_app_login_enabled'] !== '0';
$requestedSettingsTab = (string)($_GET['tab'] ?? ($_POST['settings_section'] ?? 'company'));
$allowedSettingsTabs = ['footer', 'company', 'home', 'events', 'ride_notes_email', 'marketing', 'global'];
if ($canManageCampaignEmail) $allowedSettingsTabs[] = 'campaign_email';
$activeSettingsTab = in_array($requestedSettingsTab, $allowedSettingsTabs, true) ? $requestedSettingsTab : 'company';

admin_layout_start('Settings', 'settings');
?>
<div class="card-soft p-4">
    <ul class="nav nav-tabs admin-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'company' ? 'active' : ''; ?>" id="company-tab" data-bs-toggle="tab" data-bs-target="#company-settings" type="button" role="tab" aria-controls="company-settings" aria-selected="<?php echo $activeSettingsTab === 'company' ? 'true' : 'false'; ?>">Company</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'home' ? 'active' : ''; ?>" id="home-tab" data-bs-toggle="tab" data-bs-target="#home-settings" type="button" role="tab" aria-controls="home-settings" aria-selected="<?php echo $activeSettingsTab === 'home' ? 'true' : 'false'; ?>">Home Page</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'events' ? 'active' : ''; ?>" id="events-tab" data-bs-toggle="tab" data-bs-target="#event-settings" type="button" role="tab" aria-controls="event-settings" aria-selected="<?php echo $activeSettingsTab === 'events' ? 'true' : 'false'; ?>">Events</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'ride_notes_email' ? 'active' : ''; ?>" id="ride-notes-email-tab" data-bs-toggle="tab" data-bs-target="#ride-notes-email-settings" type="button" role="tab" aria-controls="ride-notes-email-settings" aria-selected="<?php echo $activeSettingsTab === 'ride_notes_email' ? 'true' : 'false'; ?>">Ride Notes Email</button>
        </li>
        <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeSettingsTab === 'marketing' ? 'active' : ''; ?>" id="marketing-tab" data-bs-toggle="tab" data-bs-target="#marketing-settings" type="button" role="tab">Marketing</button></li>
        <?php if ($canManageCampaignEmail): ?><li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'campaign_email' ? 'active' : ''; ?>" id="campaign-email-tab" data-bs-toggle="tab" data-bs-target="#campaign-email-settings" type="button" role="tab" aria-controls="campaign-email-settings" aria-selected="<?php echo $activeSettingsTab === 'campaign_email' ? 'true' : 'false'; ?>">Campaign Email</button>
        </li><?php endif; ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'global' ? 'active' : ''; ?>" id="global-tab" data-bs-toggle="tab" data-bs-target="#global-settings" type="button" role="tab" aria-controls="global-settings" aria-selected="<?php echo $activeSettingsTab === 'global' ? 'true' : 'false'; ?>">Global</button>
        </li>
        <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeSettingsTab === 'footer' ? 'active' : ''; ?>" id="footer-tab" data-bs-toggle="tab" data-bs-target="#footer-settings" type="button" role="tab" aria-controls="footer-settings" aria-selected="<?php echo $activeSettingsTab === 'footer' ? 'true' : 'false'; ?>">Footer</button></li>
    </ul>
    <div class="tab-content">
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'home' ? 'show active' : ''; ?>" id="home-settings" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
        <h3 class="h5 fw-bold mb-1">Home page heading</h3>
        <p class="text-muted small mb-3">Controls the site identity and the green heading strip. The welcome copy and its call to action are edited on the Home Page in Pages.</p>
        <div class="card-soft p-3">
            <form method="POST" class="row g-3">
                <input type="hidden" name="settings_section" value="home">
                <div class="col-6"><label class="form-label fw-semibold" for="hero_title">Site name</label><input class="form-control" id="hero_title" name="hero_title" value="<?php echo h((string)$siteSettings['hero_title']); ?>"></div>
                <div class="col-6">
                    <label class="form-label fw-semibold" for="company_short_name">Company short name</label>
                    <input class="form-control" id="company_short_name" name="company_short_name" value="<?php echo h((string)($siteSettings['company_short_name'] ?? '')); ?>" placeholder="ILDRA">
                </div>
                <div class="col-6"><label class="form-label fw-semibold" for="home_heading_text">Heading</label><input class="form-control" id="home_heading_text" name="home_heading_text" value="<?php echo h((string)$siteSettings['home_heading_text']); ?>"></div>
                <div class="col-6"><label class="form-label fw-semibold" for="home_established_text">Established text</label><input class="form-control" id="home_established_text" name="home_established_text" value="<?php echo h((string)$siteSettings['home_established_text']); ?>"></div>
                <div class="col-12"><label class="form-label fw-semibold" for="hero_tagline">Tagline</label><input class="form-control" id="hero_tagline" name="hero_tagline" value="<?php echo h((string)$siteSettings['hero_tagline']); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold" for="hero_cta_label">Heading button label</label><input class="form-control" id="hero_cta_label" name="hero_cta_label" value="<?php echo h((string)$siteSettings['hero_cta_label']); ?>"></div>
                <div class="col-md-9"><label class="form-label fw-semibold" for="hero_cta_url">Heading button destination</label><input class="form-control" id="hero_cta_url" name="hero_cta_url" value="<?php echo h((string)$siteSettings['hero_cta_url']); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold" for="hero_cta_2_label">Second button label</label><input class="form-control" id="hero_cta_2_label" name="hero_cta_2_label" value="<?php echo h((string)$siteSettings['hero_cta_2_label']); ?>"></div>
                <div class="col-md-9"><label class="form-label fw-semibold" for="hero_cta_2_url">Second button destination</label><input class="form-control" id="hero_cta_2_url" name="hero_cta_2_url" value="<?php echo h((string)$siteSettings['hero_cta_2_url']); ?>"></div>
                <div class="col-12 text-muted small">Each button appears only when both its label and destination are filled in.</div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="sponsor_image_url">Site logo URL</label><input class="form-control" id="sponsor_image_url" name="sponsor_image_url" value="<?php echo h((string)$siteSettings['sponsor_image_url']); ?>"></div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="home_carousel_interval_seconds">Banner slide interval</label><div class="input-group"><input type="number" min="2" max="60" class="form-control" id="home_carousel_interval_seconds" name="home_carousel_interval_seconds" value="<?php echo (int)$siteSettings['home_carousel_interval_seconds']; ?>"><span class="input-group-text">seconds</span></div></div>
                <div class="col-12"><button class="btn btn-success">Save home page settings</button></div>
            </form>
        </div>
    </div>
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'events' ? 'show active' : ''; ?>" id="event-settings" role="tabpanel" aria-labelledby="events-tab" tabindex="0">
        <h3 class="h5 fw-bold mb-1">Event defaults</h3>
        <p class="text-muted small mb-3">Applied when a new event is given a start date. Existing events keep their saved dates and times.</p>
        <div class="card-soft p-3">
            <form method="POST" class="row g-3">
                <input type="hidden" name="settings_section" value="events">
                <div class="col-12">
                    <label class="form-label fw-semibold mb-2">Basket timeout</label>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span>Time before basket is cleared:</span>
                        <input type="number" min="0" class="form-control" style="max-width:110px" name="basket_timeout_minutes" value="<?php echo h($timeoutMinutes); ?>">
                        <span class="text-muted">minutes</span>
                        <input type="number" min="0" max="59" class="form-control" style="max-width:110px" name="basket_timeout_seconds" value="<?php echo h($timeoutRemainder); ?>">
                        <span class="text-muted">seconds</span>
                    </div>
                </div>
                <div class="col-12"><hr class="my-1"><h4 class="h6 fw-bold mb-1">Date and time defaults</h4><p class="form-text mb-0">Weeks select the numbered previous occurrence of the weekday: 2 / Friday is the second previous Friday. Use 0 for the immediately previous weekday.</p></div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_default_start_time">Event start time</label>
                    <input type="time" class="form-control" id="event_default_start_time" name="event_default_start_time" required value="<?php echo h((string)$siteSettings['event_default_start_time']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_default_end_days">Event end date</label>
                    <div class="input-group"><input type="number" min="0" max="365" class="form-control" id="event_default_end_days" name="event_default_end_days" required value="<?php echo (int)$siteSettings['event_default_end_days']; ?>"><span class="input-group-text">days after start</span></div>
                    <div class="form-text">Use 0 for a one-day event.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_default_end_time">Event end time</label>
                    <input type="time" class="form-control" id="event_default_end_time" name="event_default_end_time" required value="<?php echo h((string)$siteSettings['event_default_end_time']); ?>">
                </div>
                <?php $weekdayOptions = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; ?>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_member_open_weekday">Member entries open on a</label>
                    <select class="form-select" id="event_member_open_weekday" name="event_member_open_weekday"><?php foreach ($weekdayOptions as $weekdayValue => $weekdayLabel): ?><option value="<?php echo $weekdayValue; ?>" <?php echo (int)$siteSettings['event_member_open_weekday'] === $weekdayValue ? 'selected' : ''; ?>><?php echo h($weekdayLabel); ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_member_open_weeks">Weeks before start date</label>
                    <input type="number" min="0" max="52" class="form-control" id="event_member_open_weeks" name="event_member_open_weeks" required value="<?php echo (int)$siteSettings['event_member_open_weeks']; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_member_open_time">Time</label>
                    <input type="time" class="form-control" id="event_member_open_time" name="event_member_open_time" required value="<?php echo h((string)$siteSettings['event_member_open_time']); ?>">
                </div>
                <div class="w-100"></div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_non_member_open_weekday">Non-member entries open on a</label>
                    <select class="form-select" id="event_non_member_open_weekday" name="event_non_member_open_weekday"><?php foreach ($weekdayOptions as $weekdayValue => $weekdayLabel): ?><option value="<?php echo $weekdayValue; ?>" <?php echo (int)$siteSettings['event_non_member_open_weekday'] === $weekdayValue ? 'selected' : ''; ?>><?php echo h($weekdayLabel); ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_non_member_open_weeks">Weeks before start date</label>
                    <input type="number" min="0" max="52" class="form-control" id="event_non_member_open_weeks" name="event_non_member_open_weeks" required value="<?php echo (int)$siteSettings['event_non_member_open_weeks']; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_non_member_open_time">Time</label>
                    <input type="time" class="form-control" id="event_non_member_open_time" name="event_non_member_open_time" required value="<?php echo h((string)$siteSettings['event_non_member_open_time']); ?>">
                </div>
                <div class="w-100"></div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_entry_close_weekday">Entries close on a</label>
                    <select class="form-select" id="event_entry_close_weekday" name="event_entry_close_weekday"><?php foreach ($weekdayOptions as $weekdayValue => $weekdayLabel): ?><option value="<?php echo $weekdayValue; ?>" <?php echo (int)$siteSettings['event_entry_close_weekday'] === $weekdayValue ? 'selected' : ''; ?>><?php echo h($weekdayLabel); ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_entry_close_weeks">Weeks before start date</label>
                    <input type="number" min="0" max="52" class="form-control" id="event_entry_close_weeks" name="event_entry_close_weeks" required value="<?php echo (int)$siteSettings['event_entry_close_weeks']; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_entry_close_time">Time</label>
                    <input type="time" class="form-control" id="event_entry_close_time" name="event_entry_close_time" required value="<?php echo h((string)$siteSettings['event_entry_close_time']); ?>">
                </div>
                <div class="w-100"></div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_late_entry_close_days">Late entries close</label>
                    <div class="input-group"><input type="number" min="0" max="365" class="form-control" id="event_late_entry_close_days" name="event_late_entry_close_days" required value="<?php echo (int)$siteSettings['event_late_entry_close_days']; ?>"><span class="input-group-text">days before ride</span></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_late_entry_close_time">Late close time</label>
                    <input type="time" class="form-control" id="event_late_entry_close_time" name="event_late_entry_close_time" required value="<?php echo h((string)$siteSettings['event_late_entry_close_time']); ?>">
                </div>
                <div class="col-md-4 pt-4">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="event_late_entries_enabled" name="event_late_entries_enabled" value="1" <?php echo !empty($siteSettings['event_late_entries_enabled']) ? 'checked' : ''; ?>><label class="form-check-label fw-semibold" for="event_late_entries_enabled">Allow late entries by default</label></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_late_entry_fee">Late entry fee (£)</label>
                    <input type="number" min="0" step="0.01" class="form-control" id="event_late_entry_fee" name="event_late_entry_fee" required value="<?php echo h((string)$siteSettings['event_late_entry_fee']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="event_stripe_refund_fee">Stripe refund fee (£)</label>
                    <input type="number" min="0" step="0.01" class="form-control" id="event_stripe_refund_fee" name="event_stripe_refund_fee" required value="<?php echo h((string)$siteSettings['event_stripe_refund_fee']); ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-success">Save event settings</button>
                    <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
                </div>
            </form>
        </div>
    </div>
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'ride_notes_email' ? 'show active' : ''; ?>" id="ride-notes-email-settings" role="tabpanel" aria-labelledby="ride-notes-email-tab" tabindex="0">
        <h3 class="h5 fw-bold mb-1">Ride Notes email</h3>
        <p class="text-muted small mb-3">These defaults are used for new Ride Notes emails. Each event can override the intro text.</p>
        <div class="card-soft p-3"><form method="post" class="row g-3"><input type="hidden" name="settings_section" value="ride_notes_email">
            <div class="col-12"><label class="form-label fw-semibold">Default intro text</label><textarea class="form-control wysiwyg-field" rows="6" name="ride_notes_email_intro_html"><?php echo h((string)($siteSettings['ride_notes_email_intro_html'] ?? '<p>Important information for your upcoming ride is now available.</p>')); ?></textarea></div>
            <div class="col-12"><label class="form-label fw-semibold">Header image URL</label><input class="form-control" type="url" name="ride_notes_email_header_image_url" value="<?php echo h((string)($siteSettings['ride_notes_email_header_image_url'] ?? '')); ?>" placeholder="https://…"><div class="form-text">Leave blank to use the site email logo.</div></div>
            <div class="col-12"><label class="form-label fw-semibold">Signature block</label><textarea class="form-control wysiwyg-field" rows="6" name="ride_notes_email_signature_html"><?php echo h((string)($siteSettings['ride_notes_email_signature_html'] ?? '')); ?></textarea><div class="form-text">The normal site email signature and logo are also added automatically.</div></div>
            <div class="col-12"><button class="btn btn-success">Save Ride Notes email settings</button></div>
        </form></div>
    </div>
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'marketing' ? 'show active' : ''; ?>" id="marketing-settings" role="tabpanel" aria-labelledby="marketing-tab" tabindex="0"><h3 class="h5 fw-bold mb-1">Marketing and coupons</h3><p class="text-muted small mb-3">Ride Helper coupons use this fixed value when issued.</p><div class="card-soft p-3"><form method="post" class="row g-3"><input type="hidden" name="settings_section" value="marketing"><div class="col-md-4"><label class="form-label fw-semibold">Ride Helper coupon value (£)</label><input class="form-control" type="number" min="0" step="0.01" name="ride_helper_coupon_value" value="<?php echo h((string)($siteSettings['ride_helper_coupon_value']??'0.00')); ?>"></div><div class="col-12"><label class="form-label fw-semibold">Default coupon terms and conditions</label><textarea class="form-control wysiwyg-field" rows="6" name="coupon_default_terms_html"><?php echo h((string)($siteSettings['coupon_default_terms_html']??'')); ?></textarea><div class="form-text">Used as the starting terms for each new coupon. The terms remain editable on the individual coupon.</div></div><div class="col-12"><button class="btn btn-success">Save marketing settings</button></div></form></div></div>
    <?php if ($canManageCampaignEmail): ?><div class="tab-pane fade <?php echo $activeSettingsTab === 'campaign_email' ? 'show active' : ''; ?>" id="campaign-email-settings" role="tabpanel" aria-labelledby="campaign-email-tab" tabindex="0">
        <h3 class="h5 fw-bold mb-1">Campaign email</h3>
        <p class="text-muted small mb-3">Senior administrator controls for scheduled and bulk campaign delivery.</p>
        <div class="card-soft p-3"><form method="post" class="row g-3 align-items-end"><input type="hidden" name="settings_section" value="campaign_email">
            <div class="col-12">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="campaign_live_sending_enabled" name="campaign_live_sending_enabled" value="1" <?php echo (string)($siteSettings['campaign_live_sending_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>><label class="form-check-label fw-semibold" for="campaign_live_sending_enabled">Enable live campaign delivery</label></div>
                <div class="form-text">Keep disabled while campaign templates and audiences are being tested. Approved campaigns cannot send while this is off.</div>
            </div>
            <div class="col-12 col-md-3"><label class="form-label fw-semibold" for="campaign_default_batch_size">Default batch size</label><input class="form-control" id="campaign_default_batch_size" name="campaign_default_batch_size" type="number" min="1" max="500" value="<?php echo (int)($siteSettings['campaign_default_batch_size'] ?? 25); ?>"><div class="form-text">Maximum recipients processed per scheduled run.</div></div>
            <div class="col-12 col-md-6"><label class="form-label fw-semibold" for="campaign_public_base_url">Public base URL</label><input class="form-control" id="campaign_public_base_url" name="campaign_public_base_url" type="url" value="<?php echo h((string)($siteSettings['campaign_public_base_url'] ?? '')); ?>" placeholder="https://enduranceridingireland.com"><div class="form-text">Used for tracking, unsubscribe and campaign links generated by cron.</div></div>
            <div class="col-12"><button class="btn btn-success">Save campaign email settings</button></div>
        </form></div>
    </div><?php endif; ?>
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'global' ? 'show active' : ''; ?>" id="global-settings" role="tabpanel" aria-labelledby="global-tab" tabindex="0">
    <div class="row g-4">
        <div class="col-12">
            <h3 class="h5 fw-bold mb-1">Global settings</h3>
            <p class="text-muted small mb-3">Settings that apply across the site.</p>
            <div class="card-soft p-3">
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="settings_section" value="global">
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-2">Remember me</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold">Keep users signed in for:</span>
                            <input type="number" min="0" class="form-control" style="max-width: 110px;" name="remember_me_days" value="<?php echo h($rememberMeDays); ?>">
                            <span class="text-muted">days</span>
                            <span class="text-muted small">Set to 0 to disable “Keep me signed in”.</span>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-2">Authenticator app login</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="authAppLoginEnabled" name="auth_app_login_enabled" value="1" <?php echo $authAppLoginEnabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="authAppLoginEnabled">Offer authenticator app codes as a login option when users have set them up.</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="membership_next_year_from">Membership rollover date</label>
                        <input class="form-control" style="max-width:180px" id="membership_next_year_from" name="membership_next_year_from" type="text" pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])" placeholder="11-01" value="<?php echo h((string)($siteSettings['membership_next_year_from'] ?? '11-01')); ?>">
                        <div class="form-text">Memberships purchased on or after this month-day apply to the following membership year and receive full rights immediately. Default: 11-01.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-2">CMS manual</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <select class="form-select" style="max-width: 420px;" name="admin_manual_asset_id">
                                <option value="0">No manual selected</option>
                                <?php foreach ($manualDocuments as $manualDocument): ?>
                                    <option value="<?php echo (int)$manualDocument['id']; ?>" <?php echo $manualAssetId === (int)$manualDocument['id'] ? 'selected' : ''; ?>><?php echo h((string)($manualDocument['title'] ?: $manualDocument['name'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-muted small">PDFs are managed in Document &amp; Image Library.<br>
                            This is now not used as the Help System creates a dynamic manual.</span>
                        </div>
                    </div>
                    <div class="col-12">
                        <hr>
                        <h4 class="h6 fw-bold">Default site banners</h4>
                        <p class="text-muted small">Used when a page has no banner of its own. The inside banner is displayed at a shallower, letterbox ratio.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-success" href="banner_images.php?scope=home">Manage Default Home Banner</a>
                            <a class="btn btn-outline-success" href="banner_images.php?scope=inside">Manage Default Inside Banner</a>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-success">Save settings</button>
                        <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </div>

    <div class="tab-pane fade <?php echo $activeSettingsTab === 'company' ? 'show active' : ''; ?>" id="company-settings" role="tabpanel" aria-labelledby="company-tab" tabindex="0">
        <h3 class="h5 fw-bold mb-1">Company settings</h3>
        <p class="text-muted small mb-3">Public organisation and contact details. Outbound and transactional delivery settings remain on the Email page.</p>
        <div class="card-soft p-3">
            <form method="POST" class="row g-3">
                <input type="hidden" name="settings_section" value="company">
                <div class="col-12">
                    <label class="form-label fw-semibold" for="company_name">Company name</label>
                    <input class="form-control" id="company_name" name="company_name" value="<?php echo h((string)($siteSettings['company_name'] ?? '')); ?>" placeholder="Irish Long Distance Riding Association Ltd.">
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label fw-semibold" for="company_contact_email">General contact email</label>
                    <input type="email" class="form-control" id="company_contact_email" name="company_contact_email" value="<?php echo h((string)($siteSettings['company_contact_email'] ?? '')); ?>" placeholder="info@example.com">
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label fw-semibold" for="company_webmaster_email">Webmaster email</label>
                    <input type="email" class="form-control" id="company_webmaster_email" name="company_webmaster_email" value="<?php echo h((string)($siteSettings['company_webmaster_email'] ?? '')); ?>" placeholder="webmaster@example.com">
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label fw-semibold" for="company_website_url">Website URL</label>
                    <input type="url" class="form-control" id="company_website_url" name="company_website_url" value="<?php echo h((string)($siteSettings['company_website_url'] ?? '')); ?>" placeholder="https://example.com">
                    <div class="form-text">Useful for emails, exports and links generated outside the website.</div>
                </div>
                <div class="col-12 col-lg-9">
                    <label class="form-label fw-semibold" for="company_address">Address</label>
                    <textarea class="form-control" id="company_address" name="company_address" rows="3" placeholder="Address (optional)"><?php echo h((string)($siteSettings['company_address'] ?? '')); ?></textarea>
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label fw-semibold" for="company_postcode">Postcode</label>
                    <input class="form-control" id="company_postcode" name="company_postcode" value="<?php echo h((string)($siteSettings['company_postcode'] ?? '')); ?>" placeholder="Optional">
                </div>
                <div class="col-12"><button class="btn btn-success">Save company settings</button></div>
            </form>
        </div>
    </div>
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'footer' ? 'show active' : ''; ?>" id="footer-settings" role="tabpanel" aria-labelledby="footer-tab" tabindex="0">
        <h3 class="h5 fw-bold mb-1">Footer settings</h3>
        <p class="text-muted small mb-3">Manage the five column headings, column 4 links, socials and partners. Main menu links come from Pages, contact details from Company, and events from the calendar.</p>
        <div class="card-soft p-3">
            <form method="POST" class="row g-3">
                <input type="hidden" name="settings_section" value="footer">
                <?php foreach (['main' => 'Column 1 — Main menu', 'contact' => 'Column 2 — Contact', 'events' => 'Column 3 — Events', 'services' => 'Column 4 — Custom links'] as $column => $label): $titleKey = 'footer_' . $column . '_title'; ?>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="<?php echo h($titleKey); ?>"><?php echo h($label); ?></label>
                        <input class="form-control" id="<?php echo h($titleKey); ?>" name="<?php echo h($titleKey); ?>" value="<?php echo h((string)$siteSettings[$titleKey]); ?>">
                        <div class="form-text">Leave blank to hide this heading.</div>
                        <?php $columnSource = ['main' => 'Content sourced from Pages.', 'contact' => 'Content sourced from Settings → Company.', 'events' => 'Content sourced from the events calendar.'][$column] ?? ''; ?>
                        <?php if ($columnSource !== ''): ?><div class="form-text"><?php echo h($columnSource); ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-2"><h4 class="h6 fw-bold mb-0">Column 4 links</h4><button class="btn btn-outline-success btn-sm" type="button" id="add-footer-link">Add link</button></div>
                    <p class="form-text">Use a site path such as /memberships or a full URL. Links appear in row order; use the arrows to reorder. Icons are optional, e.g. fa-solid fa-user.</p>
                    <div id="footer-links" class="d-grid gap-2">
                        <?php foreach ($footerServiceLinks as $linkIndex => $link): ?>
                            <?php renderFooterSettingsLinkRow((string)$linkIndex, $link); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <hr class="my-2">
                    <h4 class="h6 fw-bold mb-3">Column 5 — Socials and partners</h4>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="footer_socials_title">Socials title</label>
                        <input class="form-control" id="footer_socials_title" name="footer_socials_title" value="<?php echo h((string)$siteSettings['footer_socials_title']); ?>">
                        <div class="form-text">Leave blank to hide this heading.</div>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <h4 class="h6 fw-bold mb-1">Social media links</h4>
                            <p class="text-muted small mb-0">Add any number of pages. Enabled links with a URL appear under Socials in the final footer column. Use a different display label for each page so visitors can tell them apart.</p>
                        </div>
                        <button class="btn btn-outline-success btn-sm" type="button" id="add-social-link"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add social link</button>
                    </div>
                    <div id="social-links" class="d-grid gap-2">
                        <?php foreach ($companySocials as $socialIndex => $social): ?>
                            <div class="social-link-row border rounded p-3">
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-semibold">Platform</label>
                                        <select class="form-select" name="socials[<?php echo (int)$socialIndex; ?>][platform]">
                                            <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'x-twitter' => 'X / Twitter', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'website' => 'Other website'] as $platformValue => $platformLabel): ?>
                                                <option value="<?php echo h($platformValue); ?>" <?php echo (string)($social['platform'] ?? '') === $platformValue ? 'selected' : ''; ?>><?php echo h($platformLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-semibold">Display label</label>
                                        <input class="form-control" name="socials[<?php echo (int)$socialIndex; ?>][label]" value="<?php echo h((string)($social['label'] ?? '')); ?>" placeholder="Endurance Riding Ireland">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small fw-semibold">Page URL</label>
                                        <input type="url" class="form-control" name="socials[<?php echo (int)$socialIndex; ?>][url]" value="<?php echo h((string)($social['url'] ?? '')); ?>" placeholder="https://...">
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label small fw-semibold">Order</label>
                                        <input type="number" class="form-control" name="socials[<?php echo (int)$socialIndex; ?>][display_order]" value="<?php echo (int)($social['display_order'] ?? 0); ?>">
                                    </div>
                                    <div class="col-6 col-md-1 d-flex justify-content-between align-items-center pb-2">
                                        <div class="form-check m-0">
                                            <input type="checkbox" class="form-check-input" name="socials[<?php echo (int)$socialIndex; ?>][is_active]" value="1" title="Show in footer" <?php echo !empty($social['is_active']) ? 'checked' : ''; ?>>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger remove-social-link" type="button" aria-label="Remove social link"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <hr class="my-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div><h4 class="h6 fw-bold mb-1">Partners</h4><p class="text-muted small mb-0">Partner logo links shown in the final footer column.</p></div>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-secondary btn-sm" href="asset_library.php"><i class="fa-regular fa-images" aria-hidden="true"></i> Upload/manage library images</a>
                            <button class="btn btn-outline-success btn-sm" type="button" id="add-affiliate"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add affiliate</button>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold" for="footer_partners_title">Footer Title</label>
                            <input class="form-control" id="footer_partners_title" name="footer_partners_title" value="<?php echo h((string)($siteSettings['footer_partners_title'] ?? 'Partners')); ?>" placeholder="Footer Title below Social">
                            <div class="form-text">Optional heading above enabled partner logos in the footer. Leave blank for no heading.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="form-check mt-md-4 pt-md-2">
                                <input class="form-check-input" type="checkbox" id="footer_partners_show_rule" name="footer_partners_show_rule" value="1" <?php echo !empty($siteSettings['footer_partners_show_rule']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="footer_partners_show_rule">Show rule above partners</label>
                                <div class="form-text">Shows a centred rule at 75% of the column width, above the title if one is entered. Hidden when no partners are enabled.</div>
                            </div>
                        </div>
                    </div>
                    <div id="affiliate-links" class="d-grid gap-2">
                        <?php foreach ($companyAffiliates as $affiliateIndex => $affiliate): ?>
                            <div class="affiliate-row border rounded p-3"><div class="row g-2 align-items-end">
                                <div class="col-12 col-md-3"><label class="form-label small fw-semibold">Affiliate name</label><input class="form-control" name="affiliates[<?php echo (int)$affiliateIndex; ?>][name]" value="<?php echo h((string)($affiliate['name'] ?? '')); ?>"></div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold">Logo from library</label>
                                    <select class="form-select affiliate-asset-select" name="affiliates[<?php echo (int)$affiliateIndex; ?>][asset_id]">
                                        <option value="0" data-preview="/filestore/images/affiliate-placeholder.svg">Holding image</option>
                                        <?php foreach ($companyLogoAssets as $logoAsset): ?>
                                            <?php $logoAssetUrl = assetLibraryPublicUrl($logoAsset, 'sm'); ?>
                                            <option value="<?php echo (int)$logoAsset['id']; ?>" data-preview="<?php echo h($logoAssetUrl); ?>" <?php echo (int)($affiliate['asset_id'] ?? 0) === (int)$logoAsset['id'] ? 'selected' : ''; ?>><?php echo h((string)($logoAsset['title'] ?: $logoAsset['name'])); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <img class="affiliate-logo-preview mt-2 border rounded bg-white p-1" src="<?php
                                        $selectedAffiliateAsset = fetchAssetLibraryById($pdo, (int)($affiliate['asset_id'] ?? 0));
                                        echo h($selectedAffiliateAsset ? assetLibraryPublicUrl($selectedAffiliateAsset, 'sm') : '/filestore/images/affiliate-placeholder.svg');
                                    ?>" alt="Selected logo preview" style="width:100%;height:60px;object-fit:contain;">
                                </div>
                                <div class="col-12 col-md-4"><label class="form-label small fw-semibold">Website URL</label><input type="url" class="form-control" name="affiliates[<?php echo (int)$affiliateIndex; ?>][website_url]" value="<?php echo h((string)($affiliate['website_url'] ?? '')); ?>" placeholder="https://..."></div>
                                <div class="col-6 col-md-1"><label class="form-label small fw-semibold">Order</label><input type="number" class="form-control" name="affiliates[<?php echo (int)$affiliateIndex; ?>][display_order]" value="<?php echo (int)($affiliate['display_order'] ?? 0); ?>"></div>
                                <div class="col-6 col-md-1 d-flex justify-content-between align-items-center pb-2"><div class="form-check m-0"><input type="checkbox" class="form-check-input" name="affiliates[<?php echo (int)$affiliateIndex; ?>][is_active]" value="1" title="Show in footer" <?php echo !empty($affiliate['is_active']) ? 'checked' : ''; ?>></div><button class="btn btn-sm btn-outline-danger remove-affiliate" type="button" aria-label="Remove affiliate"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
                            </div></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-success">Save footer settings</button>
                    <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
                </div>
            </form>
        </div>
    </div>
    </div>
</div>
<?php
function renderFooterSettingsLinkRow(string $index, array $link): void { ?>
    <div class="footer-link-row border rounded p-3"><div class="row g-2 align-items-end">
        <?php foreach (['title' => 'Title', 'url' => 'URL', 'icon' => 'Font Awesome icon'] as $field => $label): ?>
            <div class="col-12 col-md-3"><label class="form-label small fw-semibold"><?php echo h($label); ?></label><input class="form-control" data-footer-field="<?php echo h($field); ?>" name="footer_links[<?php echo h($index); ?>][<?php echo h($field); ?>]" value="<?php echo h((string)($link[$field] ?? '')); ?>"></div>
        <?php endforeach; ?>
        <div class="col-12 col-md-3"><label class="form-label small fw-semibold">Target</label><select class="form-select" data-footer-field="target" name="footer_links[<?php echo h($index); ?>][target]"><option value="_self">Same tab</option><option value="_blank" <?php echo ($link['target'] ?? '') === '_blank' ? 'selected' : ''; ?>>New tab</option></select></div>
        <div class="col-12 d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-footer-action="up" aria-label="Move link up">↑</button><button type="button" class="btn btn-sm btn-outline-secondary" data-footer-action="down" aria-label="Move link down">↓</button><button type="button" class="btn btn-sm btn-outline-danger" data-footer-action="remove">Remove</button></div>
    </div></div>
<?php } ?>
<template id="footer-link-template"><?php renderFooterSettingsLinkRow('__INDEX__', []); ?></template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('footer-links');
    function renumber() {
        list.querySelectorAll('.footer-link-row').forEach(function (row, index) {
            row.querySelectorAll('[data-footer-field]').forEach(function (field) {
                field.name = 'footer_links[' + index + '][' + field.dataset.footerField + ']';
            });
        });
    }
    document.getElementById('add-footer-link').addEventListener('click', function () {
        list.appendChild(document.getElementById('footer-link-template').content.cloneNode(true));
        renumber();
    });
    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-footer-action]');
        if (!button) return;
        const row = button.closest('.footer-link-row');
        if (button.dataset.footerAction === 'remove') row.remove();
        if (button.dataset.footerAction === 'up' && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
        if (button.dataset.footerAction === 'down' && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
        renumber();
    });
});
</script>
<template id="social-link-template">
    <div class="social-link-row border rounded p-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3"><label class="form-label small fw-semibold">Platform</label><select class="form-select" data-name="platform"><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="youtube">YouTube</option><option value="x-twitter">X / Twitter</option><option value="linkedin">LinkedIn</option><option value="tiktok">TikTok</option><option value="website">Other website</option></select></div>
            <div class="col-12 col-md-3"><label class="form-label small fw-semibold">Display label</label><input class="form-control" data-name="label" placeholder="Page name"></div>
            <div class="col-12 col-md-4"><label class="form-label small fw-semibold">Page URL</label><input type="url" class="form-control" data-name="url" placeholder="https://..."></div>
            <div class="col-6 col-md-1"><label class="form-label small fw-semibold">Order</label><input type="number" class="form-control" data-name="display_order" value="0"></div>
            <div class="col-6 col-md-1 d-flex justify-content-between align-items-center pb-2"><div class="form-check m-0"><input type="checkbox" class="form-check-input" data-name="is_active" value="1" title="Show in footer" checked></div><button class="btn btn-sm btn-outline-danger remove-social-link" type="button" aria-label="Remove social link"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
        </div>
    </div>
</template>
<template id="affiliate-template">
    <div class="affiliate-row border rounded p-3"><div class="row g-2 align-items-end">
        <div class="col-12 col-md-3"><label class="form-label small fw-semibold">Affiliate name</label><input class="form-control" data-affiliate-name="name"></div>
        <div class="col-12 col-md-3"><label class="form-label small fw-semibold">Logo from library</label><select class="form-select affiliate-asset-select" data-affiliate-name="asset_id"><option value="0" data-preview="/filestore/images/affiliate-placeholder.svg">Holding image</option><?php foreach ($companyLogoAssets as $logoAsset): ?><option value="<?php echo (int)$logoAsset['id']; ?>" data-preview="<?php echo h(assetLibraryPublicUrl($logoAsset, 'sm')); ?>"><?php echo h((string)($logoAsset['title'] ?: $logoAsset['name'])); ?></option><?php endforeach; ?></select><img class="affiliate-logo-preview mt-2 border rounded bg-white p-1" src="/filestore/images/affiliate-placeholder.svg" alt="Selected logo preview" style="width:100%;height:60px;object-fit:contain;"></div>
        <div class="col-12 col-md-4"><label class="form-label small fw-semibold">Website URL</label><input type="url" class="form-control" data-affiliate-name="website_url" placeholder="https://..."></div>
        <div class="col-6 col-md-1"><label class="form-label small fw-semibold">Order</label><input type="number" class="form-control" data-affiliate-name="display_order" value="0"></div>
        <div class="col-6 col-md-1 d-flex justify-content-between align-items-center pb-2"><div class="form-check m-0"><input type="checkbox" class="form-check-input" data-affiliate-name="is_active" value="1" title="Show in footer" checked></div><button class="btn btn-sm btn-outline-danger remove-affiliate" type="button" aria-label="Remove affiliate"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
    </div></div>
</template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('social-links');
    const template = document.getElementById('social-link-template');
    const addButton = document.getElementById('add-social-link');
    if (!list || !template || !addButton) return;

    let nextIndex = list.querySelectorAll('.social-link-row').length;
    addButton.addEventListener('click', function () {
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[data-name]').forEach(function (field) {
            field.name = 'socials[' + nextIndex + '][' + field.dataset.name + ']';
            field.removeAttribute('data-name');
        });
        nextIndex += 1;
        list.appendChild(fragment);
    });
    list.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-social-link');
        if (button) button.closest('.social-link-row').remove();
    });

    const affiliateList = document.getElementById('affiliate-links');
    const affiliateTemplate = document.getElementById('affiliate-template');
    const affiliateAdd = document.getElementById('add-affiliate');
    if (affiliateList && affiliateTemplate && affiliateAdd) {
        let affiliateIndex = affiliateList.querySelectorAll('.affiliate-row').length;
        affiliateAdd.addEventListener('click', function () {
            const fragment = affiliateTemplate.content.cloneNode(true);
            fragment.querySelectorAll('[data-affiliate-name]').forEach(function (field) {
                field.name = 'affiliates[' + affiliateIndex + '][' + field.dataset.affiliateName + ']';
                field.removeAttribute('data-affiliate-name');
            });
            affiliateIndex += 1;
            affiliateList.appendChild(fragment);
        });
        affiliateList.addEventListener('click', function (event) {
            const button = event.target.closest('.remove-affiliate');
            if (button) button.closest('.affiliate-row').remove();
        });
        affiliateList.addEventListener('change', function (event) {
            if (!event.target.matches('.affiliate-asset-select')) return;
            const option = event.target.options[event.target.selectedIndex];
            const preview = event.target.closest('.affiliate-row').querySelector('.affiliate-logo-preview');
            if (preview) preview.src = option.dataset.preview || '/filestore/images/affiliate-placeholder.svg';
        });
    }
});
</script>
<?php render_tinymce_bootstrap(); ?>
<script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php
admin_layout_end();
