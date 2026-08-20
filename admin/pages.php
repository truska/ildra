<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$siteBase = $siteBase ?? '';
$pagesReturnUrl = (string)($_SESSION['admin_list_returns']['pages'] ?? 'pages.php');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pagesQuery = http_build_query($_GET);
    $pagesReturnUrl = 'pages.php' . ($pagesQuery !== '' ? '?' . $pagesQuery : '');
    $_SESSION['admin_list_returns']['pages'] = $pagesReturnUrl;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage pages.'];
    } elseif ($action === 'save_menu_overviews') {
        $saveOverview = $pdo->prepare('REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW())');
        foreach (NAV_GROUPS as $groupKey => $groupLabel) {
            if (in_array($groupKey, ['home', 'not-on-menu'], true)) continue;
            $saveOverview->execute([
                ':key' => 'menu_overview_' . $groupKey,
                ':value' => !empty($_POST['menu_overview'][$groupKey]) ? '1' : '0',
            ]);
        }
        $successMessage = 'Menu overview settings saved.';
    } elseif ($action === 'delete_page') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        if ($pageId > 0 && deletePage($pdo, $pageId, $alerts)) {
            $successMessage = 'Page deleted.';
        }
    }
    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: ' . $pagesReturnUrl);
    exit;
}

$pages = fetchPages($pdo);
$siteSettings = getSiteSettings($pdo);
$filterForm = 'page-filter-form';
$tableColumns = [
    'title' => ['label' => 'Title', 'field' => 'title', 'sortable' => true, 'filter' => 'text', 'form' => $filterForm],
    'group' => ['label' => 'Menu Section', 'field' => 'nav_group', 'sortable' => true, 'filter' => 'select', 'options' => NAV_GROUPS, 'form' => $filterForm],
    'slug' => ['label' => 'Slug', 'field' => 'slug', 'sortable' => true, 'filter' => 'text', 'form' => $filterForm],
    'order' => ['label' => 'Order', 'field' => 'display_order', 'sortable' => true, 'filter' => 'text', 'compare' => 'number', 'form' => $filterForm],
    'published' => ['label' => 'Published', 'field' => 'is_published', 'sortable' => true, 'filter' => 'select', 'options' => ['1' => 'Published', '0' => 'Unpublished'], 'compare' => 'number', 'form' => $filterForm],
];
$table = admin_table_prepare($pages, $tableColumns, 'title');
$pages = $table['rows'];

admin_layout_start('Pages', 'pages');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Manage all pages</div>
        <h5 class="mb-0">Pages</h5>
    </div>
    <div>
        <a class="btn btn-success" href="page_edit.php">Add New</a>
    </div>
</div>

<div class="card-soft p-3 mb-4">
    <div class="fw-semibold">Menu overview pages</div>
    <div class="small text-muted mb-3">Show an Overview link at the top of each dropdown. Its first page displays a visual card menu for that section.</div>
    <?php if ($isAdmin): ?>
        <form method="post" class="d-flex flex-wrap gap-4 align-items-end">
            <input type="hidden" name="action" value="save_menu_overviews">
            <?php foreach (NAV_GROUPS as $groupKey => $groupLabel): ?>
                <?php if (in_array($groupKey, ['home', 'not-on-menu'], true)) continue; ?>
                <?php $settingKey = 'menu_overview_' . $groupKey; $enabled = !array_key_exists($settingKey, $siteSettings) || !empty($siteSettings[$settingKey]); ?>
                <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="menu_overview[<?php echo h($groupKey); ?>]" value="1" <?php echo $enabled ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo h($groupLabel); ?></span></label>
            <?php endforeach; ?>
            <button class="btn btn-sm btn-success">Save menu settings</button>
        </form>
    <?php endif; ?>
</div>

<form method="get" id="<?php echo h($filterForm); ?>"></form>
<div class="card-soft p-3">
    <?php echo admin_table_record_count($table, 'page', 'pages'); ?>
    <div class="table-responsive">
		<table class="table table-sm align-middle">
			<thead class="table-light">
				<tr>
					<?php foreach ($tableColumns as $key => $column): ?>
                        <th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th>
                    <?php endforeach; ?>
					<th class="text-end">Actions</th>
				</tr>
				<tr class="admin-table-filter-row">
                    <?php foreach ($tableColumns as $key => $column): ?>
                        <th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th>
                    <?php endforeach; ?>
                    <th class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="pages.php">Clear</a>
                    </th>
                </tr>
			</thead>
            <tbody>
                <?php foreach ($pages as $page): ?>
                    <tr id="page-<?php echo (int)$page['id']; ?>">
                        <td><?php echo h($page['title']); ?></td>
                        <td><?php echo h(NAV_GROUPS[$page['nav_group']] ?? $page['nav_group']); ?></td>
                        <td class="text-muted small"><?php echo h($page['slug']); ?></td>
                        <td><?php echo h((string)($page['display_order'] ?? 0)); ?></td>
                        <td><?php echo ($page['is_published'] ?? 0) ? 'Yes' : 'No'; ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-success" href="page_edit.php?id=<?php echo (int)$page['id']; ?>">Edit</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo h($siteBase); ?><?php echo (string)$page['slug'] === 'home' ? '/' : '/pages/' . h(page_destination_slug($page)); ?>" target="_blank">View</a>
                            <?php if ($isAdmin && (string)$page['slug'] !== 'home'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this page?');">
                                    <input type="hidden" name="action" value="delete_page">
                                    <input type="hidden" name="page_id" value="<?php echo (int)$page['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pages): ?>
                    <tr><td colspan="6" class="text-muted">No pages yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_table_pagination($table); ?>
</div>
<?php
admin_layout_end();
