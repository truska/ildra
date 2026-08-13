<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$siteBase = $siteBase ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage pages.'];
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
    header('Location: pages.php');
    exit;
}

$pages = fetchPages($pdo);
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
                    <tr>
                        <td><?php echo h($page['title']); ?></td>
                        <td><?php echo h(NAV_GROUPS[$page['nav_group']] ?? $page['nav_group']); ?></td>
                        <td class="text-muted small"><?php echo h($page['slug']); ?></td>
                        <td><?php echo h((string)($page['display_order'] ?? 0)); ?></td>
                        <td><?php echo ($page['is_published'] ?? 0) ? 'Yes' : 'No'; ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-success" href="page_edit.php?id=<?php echo (int)$page['id']; ?>">Edit</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo h($siteBase); ?>/pages/<?php echo h($page['slug']); ?>" target="_blank">View</a>
                            <?php if ($isAdmin): ?>
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
