<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/archives.php';
require_once __DIR__ . '/table_sort.php';
if (!canManageArchives((string)($currentUser['role'] ?? ''))) { http_response_code(403); exit('Access denied.'); }
if (!$pdo) { http_response_code(503); exit('Database unavailable.'); }
$requestedTab = (string)($_POST['tab'] ?? $_GET['tab'] ?? 'pages');
$activeTab = in_array($requestedTab, ['pages','announcements','events','venues'], true) ? $requestedTab : 'pages';
// Perform additive setup before any transactional archive action.
if ($activeTab === 'pages') {
fetchPages($pdo);
ensurePageArchiveColumn($pdo);
ensurePageContentElementsTable($pdo);
ensureMediaBatchTables($pdo);
} elseif ($activeTab === 'announcements') ensure_announcements_table($pdo, getSiteSettings($pdo));
elseif ($activeTab === 'events') ensureEventArchiveColumn($pdo);
else ensureVenueArchiveColumns($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($activeTab === 'venues') {
        $canManageVenues = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
        if (!$canManageVenues) $alerts[] = ['type'=>'danger', 'message'=>'You do not have permission to restore venues.'];
        elseif ($action === 'restore_venue') {
            if (changeVenueArchiveState($pdo, (int)($_POST['id'] ?? 0), $action, $alerts) !== null) {
                $_SESSION['flash_success'] = 'Venue restored as a draft. Review and save it in Venues before using it for new events.';
            }
        } else $alerts[] = ['type'=>'danger', 'message'=>'Invalid venue archive action.'];
        if ($alerts) $_SESSION['flash_alerts'] = $alerts;
        header('Location: archives.php?tab=venues'); exit;
    }
    if ($activeTab === 'events') {
        if (!adminActionAllowed($pdo, 'events.delete', strtolower((string)($currentUser['role'] ?? '')))) {
            $alerts[] = ['type'=>'danger', 'message'=>'You do not have permission to restore events.'];
        } elseif ($action === 'restore_event') {
            if (changeEventArchiveState($pdo, (int)($_POST['event_id'] ?? 0), $action, $alerts) !== null) {
                $_SESSION['flash_success'] = 'Event restored as a draft. Review it in Events before publishing.';
            }
        } else $alerts[] = ['type'=>'danger', 'message'=>'Invalid event archive action.'];
        if ($alerts) $_SESSION['flash_alerts'] = $alerts;
        header('Location: archives.php?tab=events'); exit;
    }
    if ($action === 'purge' && ($_POST['delete_confirmation'] ?? null) !== 'DELETE') {
        $_SESSION['flash_alerts'] = [['type'=>'danger', 'message'=>'Type DELETE in capital letters to confirm permanent deletion. Nothing was deleted.']];
        header('Location: archives.php?tab=' . $activeTab); exit;
    }
    if ($activeTab === 'announcements') {
        if (manageArchivedAnnouncement($pdo, (int)($_POST['announcement_id'] ?? 0), $action, $alerts)) {
            $_SESSION['flash_success'] = $action === 'restore' ? 'Announcement restored as a disabled draft. Review it in Announcements before enabling.' : 'Announcement permanently deleted.';
        } else $_SESSION['flash_alerts'] = $alerts;
        header('Location: archives.php?tab=announcements'); exit;
    }
    if (manageArchivedPage($pdo, (int)($_POST['page_id'] ?? 0), $action, $alerts)) {
        $_SESSION['flash_success'] = $action === 'restore' ? 'Page restored as a draft. Review it in Pages before publishing.' : 'Page permanently deleted.';
    } else $_SESSION['flash_alerts'] = $alerts;
    header('Location: archives.php?tab=pages'); exit;
}
$columns = [
    'title'=>['label'=>'Title','filter'=>'text','sortable'=>true],
    'slug'=>['label'=>'Slug','filter'=>'text','sortable'=>true],
    'nav_group'=>['label'=>'Menu section','filter'=>'select','options'=>NAV_GROUPS,'sortable'=>true],
    'updated_at'=>['label'=>'Last updated','filter'=>'date','sortable'=>true],
];
foreach ($columns as &$column) $column['form']='archive-filters';
unset($column);
$table = $activeTab === 'pages' ? admin_table_prepare(fetchArchivedPages($pdo), $columns, 'updated_at') : null;
admin_layout_start('Archives', 'archives');
?>
<ul class="nav nav-tabs mb-4"><?php foreach (['pages'=>'Pages','announcements'=>'Announcements','events'=>'Events','venues'=>'Venues'] as $tab=>$label): ?><li class="nav-item"><a class="nav-link <?php echo $activeTab === $tab ? 'active' : ''; ?>" href="archives.php?tab=<?php echo $tab; ?>" <?php echo $activeTab === $tab ? 'aria-current="page"' : ''; ?>><?php echo $label; ?></a></li><?php endforeach; ?></ul>
<?php if ($activeTab === 'venues') { require __DIR__ . '/archive_venues.php'; admin_layout_end(); return; } ?>
<?php if ($activeTab === 'events') { require __DIR__ . '/archive_events.php'; admin_layout_end(); return; } ?>
<?php if ($activeTab === 'announcements') { require __DIR__ . '/archive_announcements.php'; admin_layout_end(); return; } ?>
<h3 class="h5">Archived pages</h3>
<p class="text-muted">Restore returns a page to Pages as an unpublished draft, keeping its content and images.</p>
<p class="text-muted">Permanently Delete removes the page and its content sections and cannot be undone. Shared library assets are retained.</p>
<p class="text-muted">This section is being developed and is included for Stage 2 enhancements.</p>
<p class="text-muted small">Purge is blocked while another page uses this page as its destination, or while the page or its sections contain uploaded images or banners. Restore as draft to review those dependencies first.</p>
<div class="card-soft p-3">
<form method="get" id="archive-filters" class="text-end mb-2"><input type="hidden" name="tab" value="pages"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a href="archives.php?tab=pages" class="btn btn-sm btn-link">Clear</a></form>
<?php echo admin_table_record_count($table, 'archived page', 'archived pages'); ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr>
<?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?><th>Actions</th></tr>
<tr class="admin-table-filter-row"><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
<?php foreach ($table['rows'] as $page): $blockers=archivedPagePurgeBlockers($pdo,(int)$page['id']); ?>
<tr><td><?php echo h($page['title']); ?></td><td><?php echo h($page['slug']); ?></td><td><?php echo h(NAV_GROUPS[$page['nav_group']]??$page['nav_group']); ?></td><td><?php echo h(admin_display_datetime($page['updated_at'])); ?></td><td>
<form method="post" class="d-inline" data-confirm-title="Restore page?" data-confirm-message="<?php echo h('Restore “'.$page['title'].'” as an unpublished draft?'); ?>" data-confirm-button="Restore as Draft"><?php echo csrf_input(); ?><input type="hidden" name="page_id" value="<?php echo (int)$page['id']; ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success">Restore as Draft</button></form>
<form method="post" class="d-inline" data-confirm-title="Are you sure?" data-confirm-message="<?php echo h('Permanently delete “'.$page['title'].'” and all its content sections? This cannot be undone. Shared library assets will be kept.'); ?>" data-confirm-style="danger" data-confirm-button="Permanently Delete" data-confirm-require-text="DELETE"><?php echo csrf_input(); ?><input type="hidden" name="page_id" value="<?php echo (int)$page['id']; ?>"><input type="hidden" name="action" value="purge"><button class="btn btn-sm btn-outline-danger" <?php echo $blockers?'disabled':''; ?>>Permanently Delete</button></form>
<?php if ($blockers): ?><div class="small text-muted mt-2"><?php echo h(implode(' ', $blockers)); ?></div><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$table['rows']): ?><tr><td colspan="5" class="text-muted">No archived pages match the current filters.</td></tr><?php endif; ?>
</tbody></table></div><?php echo admin_table_pagination($table); ?></div>
<?php admin_layout_end(); ?>
