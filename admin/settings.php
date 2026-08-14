<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$siteSettings = getSiteSettings($pdo);
$companySocials = fetchCompanySocials($pdo);
$companyAffiliates = fetchCompanyAffiliates($pdo);
$companyLogoAssets = array_values(array_filter(fetchAssetLibrary($pdo, true), static function (array $asset): bool {
    return ($asset['asset_type'] ?? '') === 'image';
}));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsSection = (string)($_POST['settings_section'] ?? 'global');
    if ($settingsSection === 'company') {
        $payload = [
            'company_name' => trim((string)($_POST['company_name'] ?? '')),
            'company_short_name' => trim((string)($_POST['company_short_name'] ?? '')),
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

        $postedSocials = isset($_POST['socials']) && is_array($_POST['socials']) ? array_values($_POST['socials']) : [];
        $postedAffiliates = isset($_POST['affiliates']) && is_array($_POST['affiliates']) ? array_values($_POST['affiliates']) : [];
        if (!$alerts && saveSiteSettings($pdo, $payload, $alerts) && saveCompanySocials($pdo, $postedSocials, $alerts) && saveCompanyAffiliates($pdo, $postedAffiliates, $alerts)) {
            $_SESSION['flash_success'] = 'Company settings saved.';
            header('Location: settings.php?tab=company');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
        $companySocials = $postedSocials;
        $companyAffiliates = $postedAffiliates;
    } else {
        $minutes = max(0, (int)($_POST['basket_timeout_minutes'] ?? 0));
        $seconds = max(0, (int)($_POST['basket_timeout_seconds'] ?? 0));
        $totalSeconds = max(1, ($minutes * 60) + $seconds);
        $rememberDays = max(0, (int)($_POST['remember_me_days'] ?? 30));
        $rememberTtlSeconds = $rememberDays === 0 ? 0 : ($rememberDays * 86400);
        $manualAssetId = max(0, (int)($_POST['admin_manual_asset_id'] ?? 0));
        $authAppLoginEnabled = !empty($_POST['auth_app_login_enabled']) ? '1' : '0';

        $payload = [
            'basket_timeout_seconds' => $totalSeconds,
            'remember_me_ttl_seconds' => $rememberTtlSeconds,
            'admin_manual_asset_id' => (string)$manualAssetId,
            'auth_app_login_enabled' => $authAppLoginEnabled,
        ];
        if (saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Global settings saved.';
            header('Location: settings.php');
            exit;
        }
        $siteSettings['basket_timeout_seconds'] = $totalSeconds;
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
$activeSettingsTab = (string)($_GET['tab'] ?? ($_POST['settings_section'] ?? 'global')) === 'company' ? 'company' : 'global';

admin_layout_start('Settings', 'settings');
?>
<div class="card-soft p-4">
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'global' ? 'active' : ''; ?>" id="global-tab" data-bs-toggle="tab" data-bs-target="#global-settings" type="button" role="tab" aria-controls="global-settings" aria-selected="<?php echo $activeSettingsTab === 'global' ? 'true' : 'false'; ?>">Global</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeSettingsTab === 'company' ? 'active' : ''; ?>" id="company-tab" data-bs-toggle="tab" data-bs-target="#company-settings" type="button" role="tab" aria-controls="company-settings" aria-selected="<?php echo $activeSettingsTab === 'company' ? 'true' : 'false'; ?>">Company</button>
        </li>
    </ul>
    <div class="tab-content">
    <div class="tab-pane fade <?php echo $activeSettingsTab === 'global' ? 'show active' : ''; ?>" id="global-settings" role="tabpanel" aria-labelledby="global-tab" tabindex="0">
    <div class="row g-4">
        <div class="col-12">
            <h3 class="h5 fw-bold mb-1">Global settings</h3>
            <p class="text-muted small mb-3">Settings that apply across the site.</p>
            <div class="card-soft p-3">
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="settings_section" value="global">
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-2">Basket timeout</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold">Time before basket is cleared:</span>
                            <input type="number" min="0" class="form-control" style="max-width: 110px;" name="basket_timeout_minutes" value="<?php echo h($timeoutMinutes); ?>">
                            <span class="text-muted">minutes</span>
                            <input type="number" min="0" max="59" class="form-control" style="max-width: 110px;" name="basket_timeout_seconds" value="<?php echo h($timeoutRemainder); ?>">
                            <span class="text-muted">seconds</span>
                        </div>
                    </div>
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
                        <label class="form-label fw-semibold mb-2">CMS manual</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <select class="form-select" style="max-width: 420px;" name="admin_manual_asset_id">
                                <option value="0">No manual selected</option>
                                <?php foreach ($manualDocuments as $manualDocument): ?>
                                    <option value="<?php echo (int)$manualDocument['id']; ?>" <?php echo $manualAssetId === (int)$manualDocument['id'] ? 'selected' : ''; ?>><?php echo h((string)($manualDocument['title'] ?: $manualDocument['name'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-muted small">PDFs are managed in Document &amp; Image Library.</span>
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
                <div class="col-12 col-lg-8">
                    <label class="form-label fw-semibold" for="company_name">Company name</label>
                    <input class="form-control" id="company_name" name="company_name" value="<?php echo h((string)($siteSettings['company_name'] ?? '')); ?>" placeholder="Irish Long Distance Riding Association Ltd.">
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold" for="company_short_name">Company short name</label>
                    <input class="form-control" id="company_short_name" name="company_short_name" value="<?php echo h((string)($siteSettings['company_short_name'] ?? '')); ?>" placeholder="ILDRA">
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
                <div class="col-12">
                    <hr class="my-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <h4 class="h6 fw-bold mb-1">Social media links</h4>
                            <p class="text-muted small mb-0">Add any number of pages. Enabled links with a URL appear in the footer.</p>
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
                    <button class="btn btn-success">Save company settings</button>
                    <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
                </div>
            </form>
        </div>
    </div>
    </div>
</div>
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
<?php
admin_layout_end();
