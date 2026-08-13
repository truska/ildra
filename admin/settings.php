<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$siteSettings = getSiteSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

admin_layout_start('Settings', 'settings');
?>
<div class="card-soft p-4">
    <div class="row g-4">
        <div class="col-12">
            <h3 class="h5 fw-bold mb-1">Global settings</h3>
            <p class="text-muted small mb-3">Settings that apply across the site.</p>
            <div class="card-soft p-3">
                <form method="POST" class="row g-3 align-items-end">
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
<?php
admin_layout_end();
