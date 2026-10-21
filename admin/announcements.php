<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';
$siteSettings = getSiteSettings($pdo);
ensure_announcements_table($pdo, $siteSettings);
$requestedTab = (string)($_POST['settings_section'] ?? $_GET['tab'] ?? 'manual');
$activeTab = in_array($requestedTab, ['manual', 'super', 'automatic'], true) ? $requestedTab : 'manual';
$isSuper = $activeTab === 'super';
$prefix = $formPrefix = $isSuper ? 'super_announcement_' : 'announcement_';
$id = $activeTab === 'automatic' ? 0 : max(0, (int)($_POST['id'] ?? $_GET['id'] ?? 0));
$editing = $activeTab !== 'automatic' && (isset($_GET['new']) || $id > 0);
$record = ['id' => 0, 'display_order' => 0];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=? AND announcement_type=?');
    $stmt->execute([$id, $activeTab]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) { http_response_code(404); exit('Announcement not found.'); }
    foreach (['enabled','title','heading','subheading','body_html','asset_id','image_side','bg_colour','text_colour','revision'] as $field) $siteSettings[$prefix . $field] = $record[$field];
    $siteSettings[$prefix . 'from'] = $record['show_from'] ?? '';
    $siteSettings[$prefix . 'to'] = $record['show_to'] ?? '';
} elseif ($editing) {
    foreach (['title','heading','subheading','body_html','from','to'] as $field) $siteSettings[$prefix . $field] = '';
    $siteSettings[$prefix . 'enabled'] = '0';
    $siteSettings[$prefix . 'asset_id'] = '0';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'save_events') {
        if (saveSiteSettings($pdo, ['event_announcement_enabled' => isset($_POST['event_announcement_enabled']) ? '1' : '0'], $alerts)) {
            $_SESSION['flash_success'] = 'Automatic event notices updated.';
            header('Location: announcements.php?tab=automatic'); exit;
        }
    } elseif (($_POST['action'] ?? '') === 'save' && $activeTab !== 'automatic') {
        $editing = true;
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
        if ($activeTab === 'super' && isset($_POST['reset_super_dismissals'])) $payload['super_announcement_revision'] = bin2hex(random_bytes(16));
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

        if ($payload[$prefix . 'title'] === '' || mb_strlen($payload[$prefix . 'title']) > 255) $alerts[] = ['type'=>'danger', 'message'=>'Enter a title of up to 255 characters.'];
        foreach (['heading','subheading'] as $field) if (mb_strlen($payload[$prefix . $field]) > 255) $alerts[] = ['type'=>'danger', 'message'=>'Headings must be no longer than 255 characters.'];
        $record['display_order'] = (int)($_POST['display_order'] ?? 0);
        if (!$alerts) {
            $data = ['display_order' => $record['display_order']];
            foreach ($payload as $key => $value) {
                $field = substr($key, strlen($prefix));
                if (in_array($field, ['from','to'], true)) { $field = 'show_' . $field; $value = $value !== '' ? $value : null; }
                $data[$field] = $value;
            }
            if ($id) {
                $assignments = implode(',', array_map(static fn($field) => "$field=?", array_keys($data)));
                $stmt = $pdo->prepare("UPDATE announcements SET $assignments WHERE id=? AND announcement_type=?");
                $stmt->execute([...array_values($data), $id, $activeTab]);
            } else {
                $data['announcement_type'] = $activeTab;
                $data['revision'] = bin2hex(random_bytes(16));
                $columns = implode(',', array_keys($data)); $params = implode(',', array_fill(0, count($data), '?'));
                $pdo->prepare("INSERT INTO announcements ($columns) VALUES ($params)")->execute(array_values($data));
            }
            $_SESSION['flash_success'] = 'Announcement saved.';
            header('Location: announcements.php?tab=' . $activeTab); exit;
        }
        $siteSettings = array_merge($siteSettings, $payload);
    }
}
$announcementAssets = array_values(array_filter(fetchAssetLibrary($pdo, true), static fn(array $asset): bool => ($asset['asset_type'] ?? '') === 'image'));
admin_layout_start('Announcements', 'announcements');
?>
<ul class="nav nav-tabs mb-4">
<?php foreach (['manual' => 'Manual', 'super' => 'Super', 'automatic' => 'Automatic'] as $tab => $label): ?>
<li class="nav-item"><a class="nav-link <?php echo $activeTab === $tab ? 'active' : ''; ?>" href="announcements.php?tab=<?php echo $tab; ?>"><?php echo $label; ?></a></li>
<?php endforeach; ?>
</ul>
<?php if ($activeTab === 'automatic'): ?>
<h3 class="h5 mb-3">Automatic announcements</h3>
<form method="post" class="card-soft p-3 mb-3"><input type="hidden" name="action" value="save_events"><input type="hidden" name="settings_section" value="automatic"><label class="form-check"><input class="form-check-input" type="checkbox" name="event_announcement_enabled" value="1" <?php echo !empty($siteSettings['event_announcement_enabled']) ? 'checked' : ''; ?>>Show automatic event notices</label><div class="form-text mb-2">Displayed above the manual announcement. Closing notices take priority over opening notices. Existing event notice rules are unchanged.</div><button class="btn btn-sm btn-success">Save event notices</button></form>

<?php elseif ($editing): ?>
<div class="d-flex justify-content-between mb-3"><h3 class="h5"><?php echo $id ? 'Edit' : 'Add'; ?> <?php echo $isSuper ? 'Super' : 'Manual'; ?> announcement</h3><a href="announcements.php?tab=<?php echo $activeTab; ?>" class="btn btn-outline-secondary">Back to list</a></div>
<?php if ($isSuper): ?><p class="text-muted">Close dismisses this notice for the current tab session. Hide for 30 days remembers this notice on the device. Changing dates or resetting hidden notices shows it again.</p><?php endif; ?>
<div class="card-soft p-4">
            <form method="POST" class="row g-3">
                <input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="settings_section" value="<?php echo $activeTab; ?>">

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
                <div class="col-md-6"><label class="form-label" for="display_order">Display order (lowest first)</label><input class="form-control" type="number" id="display_order" name="display_order" value="<?php echo (int)$record['display_order']; ?>"></div>
                <div class="col-12"><button class="btn btn-success">Save announcement</button></div>
            </form>
</div>
<?php else:
$stmt = $pdo->prepare('SELECT * FROM announcements WHERE announcement_type=?');
$stmt->execute([$activeTab]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$selected = select_announcement($rows, $activeTab);
foreach ($rows as &$row) {
    $row['showing'] = $selected && (int)$selected['id'] === (int)$row['id'] ? '1' : '0';
}
unset($row);
$columns = [
    'title' => ['label'=>'Title','filter'=>'text','sortable'=>true],
    'enabled' => ['label'=>'Show / hide','filter'=>'select','options'=>['1'=>'Show','0'=>'Hide'],'sortable'=>true],
    'show_from' => ['label'=>'Show from','filter'=>'date','sortable'=>true],
    'show_to' => ['label'=>'Show to','filter'=>'date','sortable'=>true],
    'display_order' => ['label'=>'Order','filter'=>'text','sortable'=>true,'compare'=>'number'],
    'showing' => ['label'=>'Selected now','filter'=>'select','options'=>['1'=>'Yes','0'=>'No'],'sortable'=>true],
];
foreach ($columns as &$column) $column['form'] = 'announcement-filters';
unset($column);
$table = admin_table_prepare($rows, $columns, 'display_order');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0"><?php echo $isSuper ? 'Super' : 'Manual'; ?> announcements</h3><a class="btn btn-success" href="announcements.php?tab=<?php echo $activeTab; ?>&amp;new=1">Add New</a></div>
<p class="text-muted small">One announcement per type is selected within its inclusive date range (Europe/London). Lowest order wins; ties use the latest start date, then newest record. Hidden and expired notices remain available to edit.</p>

<div class="card-soft p-3">
<form method="get" id="announcement-filters" class="text-end mb-2"><input type="hidden" name="tab" value="<?php echo $activeTab; ?>"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="announcements.php?tab=<?php echo $activeTab; ?>">Clear</a></form>
<?php echo admin_table_record_count($table, 'announcement', 'announcements'); ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr>
<?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?><th>Actions</th></tr>
<tr class="admin-table-filter-row"><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
<?php foreach ($table['rows'] as $row): ?><tr>
<td><?php echo h($row['title']); ?></td><td><?php echo $row['enabled'] ? 'Show' : 'Hide'; ?></td><td><?php echo h($row['show_from'] ?? '—'); ?></td><td><?php echo h($row['show_to'] ?? '—'); ?></td><td><?php echo (int)$row['display_order']; ?></td><td><?php echo $row['showing'] ? 'Yes' : 'No'; ?></td><td><a class="btn btn-sm btn-outline-success" href="announcements.php?tab=<?php echo $activeTab; ?>&amp;id=<?php echo (int)$row['id']; ?>">Edit</a></td></tr><?php endforeach; ?>
<?php if (!$table['rows']): ?><tr><td colspan="7" class="text-muted">No announcements match the current filters.</td></tr><?php endif; ?>
</tbody></table></div><?php echo admin_table_pagination($table); ?></div>
<?php endif; ?>
<?php if ($editing): render_tinymce_bootstrap(); ?>
<script>if (window.tinymce) tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php endif; admin_layout_end(); ?>
