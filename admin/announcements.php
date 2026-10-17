<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$siteSettings = getSiteSettings($pdo);
$announcementAssets = array_values(array_filter(fetchAssetLibrary($pdo, true), static function (array $asset): bool {
    return ($asset['asset_type'] ?? '') === 'image';
}));

$activeTab = (($_POST['settings_section'] ?? $_GET['tab'] ?? '') === 'super') ? 'super' : 'normal';
$prefix = $activeTab === 'super' ? 'super_announcement_' : 'announcement_';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = [
            $prefix . 'enabled' => isset($_POST[$prefix . 'enabled']) ? '1' : '0',
            $prefix . 'asset_id' => (string)max(0, (int)($_POST[$prefix . 'asset_id'] ?? 0)),
            $prefix . 'image_side' => ($_POST[$prefix . 'image_side'] ?? '') === 'right' ? 'right' : 'left',
            $prefix . 'body_html' => sanitize_rich_html((string)($_POST[$prefix . 'body_html'] ?? '')),
        ];
        foreach (['from', 'to', 'title', 'heading', 'subheading'] as $field) {
            $payload[$prefix . $field] = trim((string)($_POST[$prefix . $field] ?? ''));
        }
        foreach (['bg_colour' => '#0c4522', 'text_colour' => '#ffffff'] as $field => $fallback) {
            $payload[$prefix . $field] = announcement_colour((string)($_POST[$prefix . $field] ?? ''), $fallback);
        }
        if ($activeTab === 'normal') {
            $payload['event_announcement_enabled'] = isset($_POST['event_announcement_enabled']) ? '1' : '0';
        } elseif (isset($_POST['reset_super_dismissals'])) {
            $payload['super_announcement_revision'] = bin2hex(random_bytes(16));
        }
        $from = announcement_date($payload[$prefix . 'from']);
        $to = announcement_date($payload[$prefix . 'to']);
        if ($payload[$prefix . 'enabled'] === '1' && ($payload[$prefix . 'title'] === '' || !$from || !$to)) {
            $alerts[] = ['type' => 'danger', 'message' => 'An enabled announcement needs a title and valid Show from and Show to dates.'];
        }
        if (($payload[$prefix . 'from'] !== '' && !$from) || ($payload[$prefix . 'to'] !== '' && !$to) || ($from && $to && $from > $to)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Enter valid announcement dates, with Show to on or after Show from.'];
        }
        if ((int)$payload[$prefix . 'asset_id'] > 0) {
            $asset = fetchAssetLibraryById($pdo, (int)$payload[$prefix . 'asset_id']);
            if (!$asset || ($asset['asset_type'] ?? '') !== 'image' || !empty($asset['archived'])) {
                $alerts[] = ['type' => 'danger', 'message' => 'Choose an available image from the asset library.'];
            }
        }
        if (!$alerts && saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Announcement settings saved.';
            header('Location: announcements.php' . ($activeTab === 'super' ? '?tab=super' : ''));
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
}

admin_layout_start('Announcements', 'announcements');
?>
<ul class="nav nav-tabs mb-4" role="tablist">
    <?php foreach (['normal' => 'Normal Announcement', 'super' => 'Super Announcement'] as $tab => $label): ?>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeTab === $tab ? 'active' : ''; ?>" id="<?php echo $tab; ?>-tab" data-bs-toggle="tab" data-bs-target="#<?php echo $tab; ?>-panel" type="button" role="tab" aria-controls="<?php echo $tab; ?>-panel" aria-selected="<?php echo $activeTab === $tab ? 'true' : 'false'; ?>"><?php echo h($label); ?></button></li>
    <?php endforeach; ?>
</ul>
<div class="tab-content">
<?php foreach (['normal', 'super'] as $tab): $isSuper = $tab === 'super'; $formPrefix = $isSuper ? 'super_announcement_' : 'announcement_'; ?>
<div class="tab-pane fade <?php echo $activeTab === $tab ? 'show active' : ''; ?>" id="<?php echo $tab; ?>-panel" role="tabpanel" aria-labelledby="<?php echo $tab; ?>-tab" tabindex="0">
        <div class="card-soft p-4">
            <h3 class="h5 fw-bold"><?php echo $isSuper ? 'Super Announcement' : 'Announcements'; ?></h3>
            <?php if ($isSuper): ?><p class="text-muted">A large welcome notice on every public page. Close dismisses it for the current tab session; Hide for 30 days remembers the choice on that browser and device. New dates or resetting hidden notices will show it again.</p><?php endif; ?>
            <form method="POST" class="row g-3">
                <input type="hidden" name="settings_section" value="<?php echo $isSuper ? 'super' : 'normal'; ?>">
                <?php if (!$isSuper): ?>
                <div class="col-12"><h4 class="h6 fw-bold">Automatic event bar</h4><div class="form-check"><input class="form-check-input" type="checkbox" name="event_announcement_enabled" id="event_announcement_enabled" value="1" <?php echo !empty($siteSettings['event_announcement_enabled']) ? 'checked' : ''; ?>><label class="form-check-label" for="event_announcement_enabled">Show automatic event notices</label></div><p class="form-text mb-0">Displayed above the manual announcement. Member and non-member opening notices run for 48 hours. Closing notices run for the final 48 hours and take priority, with the nearest deadline first; otherwise the most recent opening is shown. Full and unpublished events are excluded. Each notice links to its event.</p></div>
                <?php endif; ?>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="<?php echo h($formPrefix); ?>enabled" id="<?php echo h($formPrefix); ?>enabled" value="1" <?php echo !empty($siteSettings[$formPrefix . 'enabled']) ? 'checked' : ''; ?>><label class="form-check-label" for="<?php echo h($formPrefix); ?>enabled">Enable <?php echo $isSuper ? 'Super Announcement' : 'manual announcement'; ?></label></div></div>
                <?php foreach (['from' => 'Show from', 'to' => 'Show to'] as $field => $label): ?>
                    <div class="col-md-6"><label class="form-label" for="<?php echo h($formPrefix); ?><?php echo h($field); ?>"><?php echo h($label); ?></label><input type="date" class="form-control" name="<?php echo h($formPrefix); ?><?php echo h($field); ?>" id="<?php echo h($formPrefix); ?><?php echo h($field); ?>" value="<?php echo h((string)$siteSettings[$formPrefix . $field]); ?>"></div>
                <?php endforeach; ?>
                <div class="col-12 form-text">Both dates are inclusive, using Europe/London time. The announcement hides automatically after the final day.</div>
                <?php foreach (['title' => ($isSuper ? 'Notice title' : 'Bar title'), 'heading' => 'Expanded heading (full width)', 'subheading' => 'Subheading'] as $field => $label): ?>
                    <div class="col-12"><label class="form-label" for="<?php echo h($formPrefix); ?><?php echo h($field); ?>"><?php echo h($label); ?></label><input class="form-control" name="<?php echo h($formPrefix); ?><?php echo h($field); ?>" id="<?php echo h($formPrefix); ?><?php echo h($field); ?>" value="<?php echo h((string)$siteSettings[$formPrefix . $field]); ?>"></div>
                <?php endforeach; ?>
                <div class="col-12"><label class="form-label" for="<?php echo h($formPrefix); ?>body_html">Text</label><textarea class="form-control wysiwyg-field" rows="6" id="<?php echo h($formPrefix); ?>body_html" name="<?php echo h($formPrefix); ?>body_html"><?php echo h((string)$siteSettings[$formPrefix . 'body_html']); ?></textarea></div>
                <div class="col-md-8"><label class="form-label" for="<?php echo h($formPrefix); ?>asset_id">Image from library (optional)</label><select class="form-select" name="<?php echo h($formPrefix); ?>asset_id" id="<?php echo h($formPrefix); ?>asset_id"><option value="0">No image — full-width text</option><?php foreach ($announcementAssets as $asset): if (!empty($asset['archived'])) continue; ?><option value="<?php echo (int)$asset['id']; ?>" <?php echo (int)$siteSettings[$formPrefix . 'asset_id'] === (int)$asset['id'] ? 'selected' : ''; ?>><?php echo h((string)($asset['title'] ?: $asset['name'])); ?></option><?php endforeach; ?></select><a class="small" href="asset_library.php">Upload/manage library images</a></div>
                <div class="col-md-4"><label class="form-label" for="<?php echo h($formPrefix); ?>image_side">Image position</label><select class="form-select" id="<?php echo h($formPrefix); ?>image_side" name="<?php echo h($formPrefix); ?>image_side"><option value="left">Left</option><option value="right" <?php echo $siteSettings[$formPrefix . 'image_side'] === 'right' ? 'selected' : ''; ?>>Right</option></select></div>
                <?php foreach (['bg_colour' => 'Background colour', 'text_colour' => 'Text colour'] as $field => $label): ?>
                    <div class="col-md-6"><label class="form-label" for="<?php echo h($formPrefix); ?><?php echo h($field); ?>"><?php echo h($label); ?></label><input type="color" class="form-control form-control-color" name="<?php echo h($formPrefix); ?><?php echo h($field); ?>" id="<?php echo h($formPrefix); ?><?php echo h($field); ?>" value="<?php echo h((string)$siteSettings[$formPrefix . $field]); ?>"></div>
                <?php endforeach; ?>
                <?php if ($isSuper): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="reset_super_dismissals" id="reset_super_dismissals"><label class="form-check-label" for="reset_super_dismissals">Show again to devices that have hidden this notice</label></div></div><?php endif; ?>
                <div class="col-12"><button class="btn btn-success">Save announcement settings</button></div>
            </form>
        </div>
</div>
<?php endforeach; ?>
</div>
<?php admin_layout_end(); ?>
