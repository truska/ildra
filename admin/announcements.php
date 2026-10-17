<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$siteSettings = getSiteSettings($pdo);
$announcementAssets = array_values(array_filter(fetchAssetLibrary($pdo, true), static function (array $asset): bool {
    return ($asset['asset_type'] ?? '') === 'image';
}));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = [
            'announcement_enabled' => isset($_POST['announcement_enabled']) ? '1' : '0',
            'event_announcement_enabled' => isset($_POST['event_announcement_enabled']) ? '1' : '0',
            'announcement_asset_id' => (string)max(0, (int)($_POST['announcement_asset_id'] ?? 0)),
            'announcement_image_side' => ($_POST['announcement_image_side'] ?? '') === 'right' ? 'right' : 'left',
            'announcement_body_html' => sanitize_rich_html((string)($_POST['announcement_body_html'] ?? '')),
        ];
        foreach (['from', 'to', 'title', 'heading', 'subheading'] as $field) {
            $payload['announcement_' . $field] = trim((string)($_POST['announcement_' . $field] ?? ''));
        }
        foreach (['bg_colour' => '#0c4522', 'text_colour' => '#ffffff'] as $field => $fallback) {
            $payload['announcement_' . $field] = announcement_colour((string)($_POST['announcement_' . $field] ?? ''), $fallback);
        }
        $from = announcement_date($payload['announcement_from']);
        $to = announcement_date($payload['announcement_to']);
        if ($payload['announcement_enabled'] === '1' && ($payload['announcement_title'] === '' || !$from || !$to)) {
            $alerts[] = ['type' => 'danger', 'message' => 'An enabled announcement needs a bar title and valid Show from and Show to dates.'];
        }
        if (($payload['announcement_from'] !== '' && !$from) || ($payload['announcement_to'] !== '' && !$to) || ($from && $to && $from > $to)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Enter valid announcement dates, with Show to on or after Show from.'];
        }
        if ((int)$payload['announcement_asset_id'] > 0) {
            $asset = fetchAssetLibraryById($pdo, (int)$payload['announcement_asset_id']);
            if (!$asset || ($asset['asset_type'] ?? '') !== 'image' || !empty($asset['archived'])) {
                $alerts[] = ['type' => 'danger', 'message' => 'Choose an available image from the asset library.'];
            }
        }
        if (!$alerts && saveSiteSettings($pdo, $payload, $alerts)) {
            $_SESSION['flash_success'] = 'Announcement settings saved.';
            header('Location: announcements.php');
            exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
}

admin_layout_start('Announcements', 'announcements');
?>
        <div class="card-soft p-4">
            <h3 class="h5 fw-bold">Announcements</h3>
            <form method="POST" class="row g-3">
                <input type="hidden" name="settings_section" value="announcements">
                <div class="col-12"><h4 class="h6 fw-bold">Automatic event bar</h4><div class="form-check"><input class="form-check-input" type="checkbox" name="event_announcement_enabled" id="event_announcement_enabled" value="1" <?php echo !empty($siteSettings['event_announcement_enabled']) ? 'checked' : ''; ?>><label class="form-check-label" for="event_announcement_enabled">Show automatic event notices</label></div><p class="form-text mb-0">Displayed above the manual announcement. Member and non-member opening notices run for 48 hours. Closing notices run for the final 48 hours and take priority, with the nearest deadline first; otherwise the most recent opening is shown. Full and unpublished events are excluded. Each notice links to its event.</p></div>
                <div class="col-12"><hr><h4 class="h6 fw-bold">Manual announcement</h4><div class="form-check"><input class="form-check-input" type="checkbox" name="announcement_enabled" id="announcement_enabled" value="1" <?php echo !empty($siteSettings['announcement_enabled']) ? 'checked' : ''; ?>><label class="form-check-label" for="announcement_enabled">Enable manual announcement</label></div></div>
                <?php foreach (['from' => 'Show from', 'to' => 'Show to'] as $field => $label): ?>
                    <div class="col-md-6"><label class="form-label" for="announcement_<?php echo h($field); ?>"><?php echo h($label); ?></label><input type="date" class="form-control" name="announcement_<?php echo h($field); ?>" id="announcement_<?php echo h($field); ?>" value="<?php echo h((string)$siteSettings['announcement_' . $field]); ?>"></div>
                <?php endforeach; ?>
                <div class="col-12 form-text">Both dates are inclusive, using Europe/London time. The announcement hides automatically after the final day.</div>
                <?php foreach (['title' => 'Bar title', 'heading' => 'Expanded heading (full width)', 'subheading' => 'Subheading'] as $field => $label): ?>
                    <div class="col-12"><label class="form-label" for="announcement_<?php echo h($field); ?>"><?php echo h($label); ?></label><input class="form-control" name="announcement_<?php echo h($field); ?>" id="announcement_<?php echo h($field); ?>" value="<?php echo h((string)$siteSettings['announcement_' . $field]); ?>"></div>
                <?php endforeach; ?>
                <div class="col-12"><label class="form-label" for="announcement_body_html">Text</label><textarea class="form-control wysiwyg-field" rows="6" id="announcement_body_html" name="announcement_body_html"><?php echo h((string)$siteSettings['announcement_body_html']); ?></textarea></div>
                <div class="col-md-8"><label class="form-label" for="announcement_asset_id">Image from library (optional)</label><select class="form-select" name="announcement_asset_id" id="announcement_asset_id"><option value="0">No image — full-width text</option><?php foreach ($announcementAssets as $asset): if (!empty($asset['archived'])) continue; ?><option value="<?php echo (int)$asset['id']; ?>" <?php echo (int)$siteSettings['announcement_asset_id'] === (int)$asset['id'] ? 'selected' : ''; ?>><?php echo h((string)($asset['title'] ?: $asset['name'])); ?></option><?php endforeach; ?></select><a class="small" href="asset_library.php">Upload/manage library images</a></div>
                <div class="col-md-4"><label class="form-label" for="announcement_image_side">Image position</label><select class="form-select" id="announcement_image_side" name="announcement_image_side"><option value="left">Left</option><option value="right" <?php echo $siteSettings['announcement_image_side'] === 'right' ? 'selected' : ''; ?>>Right</option></select></div>
                <?php foreach (['bg_colour' => 'Background colour', 'text_colour' => 'Text colour'] as $field => $label): ?>
                    <div class="col-md-6"><label class="form-label" for="announcement_<?php echo h($field); ?>"><?php echo h($label); ?></label><input type="color" class="form-control form-control-color" name="announcement_<?php echo h($field); ?>" id="announcement_<?php echo h($field); ?>" value="<?php echo h((string)$siteSettings['announcement_' . $field]); ?>"></div>
                <?php endforeach; ?>
                <div class="col-12"><button class="btn btn-success">Save announcement settings</button></div>
            </form>
        </div>
<?php admin_layout_end(); ?>
