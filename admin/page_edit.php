<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = $pageId ? fetchPageById($pdo, $pageId) : null;

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
