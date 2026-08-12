<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = $pageId ? fetchPageById($pdo, $pageId) : null;
$libraryAssets = fetchAssetLibrary($pdo, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage pages.'];
    } else {
        if (savePage($pdo, $_POST, $alerts)) {
            $successMessage = 'Page saved.';
            $_SESSION['flash_success'] = $successMessage;
            header('Location: pages.php');
            exit;
        }
    }
}

$page = $page ?? [
    'id' => 0,
    'title' => '',
    'slug' => '',
    'nav_group' => 'home',
    'display_order' => 0,
    'excerpt' => '',
    'body_html' => '',
    'button_name' => '',
    'button_title' => '',
    'button_url' => '',
    'button_asset_id' => null,
    'button_target' => '_self',
    'is_published' => 1,
];

admin_layout_start($pageId ? 'Edit Page' : 'Add Page', 'pages');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted"><?php echo $pageId ? 'Edit page' : 'Add new page'; ?></div>
        <h5 class="mb-0"><?php echo $pageId ? h($page['title']) : 'New Page'; ?></h5>
    </div>
    <div>
        <a class="btn btn-outline-secondary" href="pages.php">Back to list</a>
    </div>
</div>

<div class="card-soft p-4">
    <form method="POST">
        <input type="hidden" name="action" value="save_page">
        <input type="hidden" name="page_id" value="<?php echo h((string)$page['id']); ?>">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required value="<?php echo h($page['title']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Slug</label>
                <input type="text" name="slug" class="form-control" required value="<?php echo h($page['slug']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Menu group</label>
                <select name="nav_group" class="form-select">
                    <?php foreach (NAV_GROUPS as $key => $label): ?>
                        <option value="<?php echo h($key); ?>" <?php echo ($page['nav_group'] === $key) ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Order</label>
                <input type="number" name="display_order" class="form-control" value="<?php echo h((string)$page['display_order']); ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_published" id="published" <?php echo ($page['is_published'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="published">Published</label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Excerpt</label>
                <textarea name="excerpt" class="form-control" rows="2"><?php echo h($page['excerpt']); ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Body</span>
                    <span class="small text-muted">Use the editor toolbar for rich formatting</span>
                </label>
                <textarea id="pageBody" name="body_html" class="form-control wysiwyg-field" rows="12"><?php echo h($page['body_html']); ?></textarea>
            </div>
            <div class="col-12"><hr><div class="fw-semibold">Optional content button</div><div class="small text-muted">Displayed left-aligned beneath this page’s content using the secondary button style.</div></div>
            <div class="col-md-4">
                <label class="form-label">Name / button label</label>
                <input type="text" name="button_name" class="form-control" value="<?php echo h($page['button_name'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Title / mouse-over text</label>
                <input type="text" name="button_title" class="form-control" value="<?php echo h($page['button_title'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Target</label>
                <select name="button_target" class="form-select">
                    <option value="_self" <?php echo ($page['button_target'] ?? '_self') === '_self' ? 'selected' : ''; ?>>Same window</option>
                    <option value="_blank" <?php echo ($page['button_target'] ?? '_self') === '_blank' ? 'selected' : ''; ?>>New window / tab</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Choose from document &amp; image library</label>
                <select id="library-button-asset" name="button_asset_id" class="form-select">
                    <option value="">Select an asset (optional)</option>
                    <?php foreach (['pdf' => 'PDF documents', 'image' => 'Images'] as $assetType => $groupLabel):
                        $groupItems = array_values(array_filter($libraryAssets, static fn(array $asset): bool => ($asset['asset_type'] ?? '') === $assetType));
                        if (!$groupItems) continue; ?>
                        <optgroup label="<?php echo h($groupLabel); ?>">
                            <?php foreach ($groupItems as $asset): ?><option value="<?php echo (int)$asset['id']; ?>" data-title="<?php echo h($asset['title'] ?: $asset['name']); ?>" <?php echo (int)($page['button_asset_id'] ?? 0) === (int)$asset['id'] ? 'selected' : ''; ?>><?php echo h($asset['name']); ?><?php echo !empty($asset['category']) ? ' — ' . h($asset['category']) : ''; ?></option><?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Use this or enter a destination URL below.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Destination URL</label>
                <input id="button-url" type="text" name="button_url" class="form-control" value="<?php echo h($page['button_url'] ?? ''); ?>" placeholder="/events or https://example.com/">
                <div class="form-text">Use this or select a library item above. Leave the label and both destinations blank to show no button.</div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-success">Save</button>
            <a class="btn btn-outline-secondary" href="pages.php">Cancel</a>
        </div>
    </form>
</div>
<?php render_tinymce_bootstrap(); ?>
<script>
    (function() {
        const picker = document.getElementById('library-button-asset');
        const url = document.getElementById('button-url');
        const title = document.querySelector('[name="button_title"]');
        picker.addEventListener('change', function() {
            if (!this.value) return;
            url.value = '';
            const option = this.options[this.selectedIndex];
            if (title && !title.value) title.value = option.dataset.title || '';
        });
        url.addEventListener('input', function() {
            if (this.value.trim()) picker.value = '';
        });
        if (!window.tinymce) {
            return;
        }
        tinymce.init(window.ildraTinyMceConfig({
            selector: 'textarea.wysiwyg-field'
        }));
    })();
</script>
<?php
admin_layout_end();
