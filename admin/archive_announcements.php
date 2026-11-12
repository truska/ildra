<?php
declare(strict_types=1);
if (!isset($activeTab, $pdo) || $activeTab !== 'announcements' || !function_exists('canManageArchives') || !canManageArchives((string)($currentUser['role'] ?? ''))) { http_response_code(404); exit; }
$columns = [
    'title'=>['label'=>'Title','filter'=>'text','sortable'=>true],
    'announcement_type'=>['label'=>'Type','filter'=>'select','options'=>['manual'=>'Announcement','super'=>'Super Announcement'],'sortable'=>true],
    'show_from'=>['label'=>'Show from','filter'=>'date','sortable'=>true],
    'show_to'=>['label'=>'Show to','filter'=>'date','sortable'=>true],
    'updated_at'=>['label'=>'Last updated','filter'=>'date','sortable'=>true],
];
foreach ($columns as &$column) $column['form']='announcement-archive-filters';
unset($column);
$table=admin_table_prepare(fetchArchivedAnnouncements($pdo),$columns,'updated_at');
?>
<h3 class="h5">Archived announcements</h3>
<p class="text-muted">Restore returns an announcement to Announcements as a disabled draft, keeping its content, image, dates and display settings. Review it before enabling.</p>
<p class="text-muted">Permanently Delete removes the announcement and cannot be undone. Shared library images are retained.</p>
<p class="text-muted">This section is being developed and is included for Stage 2 enhancements.</p>
<div class="card-soft p-3">
<form method="get" id="announcement-archive-filters" class="text-end mb-2"><input type="hidden" name="tab" value="announcements"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a href="archives.php?tab=announcements" class="btn btn-sm btn-link">Clear</a></form>
<?php echo admin_table_record_count($table,'archived announcement','archived announcements'); ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr>
<?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?><th>Actions</th></tr>
<tr class="admin-table-filter-row"><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
<?php foreach ($table['rows'] as $notice): ?><tr>
<td><?php echo h($notice['title']); ?></td><td><?php echo $notice['announcement_type']==='super'?'Super Announcement':'Announcement'; ?></td><td><?php echo h($notice['show_from']??'—'); ?></td><td><?php echo h($notice['show_to']??'—'); ?></td><td><?php echo h($notice['updated_at']); ?></td><td>
<form method="post" class="d-inline" data-confirm-title="Restore announcement?" data-confirm-message="<?php echo h('Restore “'.$notice['title'].'” as a disabled draft?'); ?>" data-confirm-button="Restore as Draft"><?php echo csrf_input(); ?><input type="hidden" name="tab" value="announcements"><input type="hidden" name="announcement_id" value="<?php echo (int)$notice['id']; ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success">Restore as Draft</button></form>
<form method="post" class="d-inline" data-confirm-title="Are you sure?" data-confirm-message="<?php echo h('Permanently delete “'.$notice['title'].'”? This cannot be undone. Shared library images will be kept.'); ?>" data-confirm-style="danger" data-confirm-button="Permanently Delete"><?php echo csrf_input(); ?><input type="hidden" name="tab" value="announcements"><input type="hidden" name="announcement_id" value="<?php echo (int)$notice['id']; ?>"><input type="hidden" name="action" value="purge"><button class="btn btn-sm btn-outline-danger">Permanently Delete</button></form>
</td></tr><?php endforeach; ?>
<?php if (!$table['rows']): ?><tr><td colspan="6" class="text-muted">No archived announcements match the current filters.</td></tr><?php endif; ?>
</tbody></table></div><?php echo admin_table_pagination($table); ?></div>
