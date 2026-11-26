<?php
declare(strict_types=1);
if (!isset($activeTab,$pdo,$currentUser) || !in_array($activeTab,['people','horses'],true) || !function_exists('canManageArchives') || !canManageArchives((string)($currentUser['role']??''))) { http_response_code(404);exit; }
$subjectLabel=$activeTab==='people'?'person':'horse';
$subjectColumns=[
    'archive_name'=>['label'=>'Name','filter'=>'text','sortable'=>true],
    'owner_email'=>['label'=>'Managing account','filter'=>'text','sortable'=>true],
    'updated_at'=>['label'=>'Last updated','filter'=>'date','sortable'=>true],
];
foreach ($subjectColumns as &$column) $column['form']='subject-archive-filters';
unset($column);
$subjectTable=admin_table_prepare(fetchArchivedSubjects($pdo,$activeTab),$subjectColumns,'updated_at');
?>
<h3 class="h5">Archived <?php echo h($activeTab); ?></h3>
<p class="text-muted">Restore returns the record to the active list. Permanently Delete removes only this archived record and cannot be undone. The managing login account is retained.</p>
<p class="text-muted small">Deletion is blocked while memberships, logbooks, bookings, baskets, results, sharing, onboarding or other linked records remain. The reason is shown beside the record. Historical notes and audit records are retained.</p>
<div class="card-soft p-3">
<form method="get" id="subject-archive-filters" class="text-end mb-2"><input type="hidden" name="tab" value="<?php echo h($activeTab); ?>"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="archives.php?tab=<?php echo h($activeTab); ?>">Clear</a></form>
<?php echo admin_table_record_count($subjectTable,'archived '.$subjectLabel,'archived '.$activeTab); ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr>
<?php foreach ($subjectColumns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$subjectTable['sort_key'],$subjectTable['sort_dir']); ?></th><?php endforeach; ?><th>Actions</th></tr>
<tr class="admin-table-filter-row"><?php foreach ($subjectColumns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$subjectTable['filters']); ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
<?php foreach ($subjectTable['rows'] as $subject): $blockers=archivedSubjectPurgeBlockers($pdo,$activeTab,(int)$subject['id']); ?>
<tr><td><?php echo h($subject['archive_name']); ?><div class="small text-muted">ID: <?php echo (int)$subject['id']; ?></div></td><td><?php echo h($subject['owner_email']??''); ?></td><td><?php echo h(admin_display_datetime($subject['updated_at']??'')); ?></td><td>
<form method="post" class="d-inline" data-confirm-title="Restore <?php echo h($subjectLabel); ?>?" data-confirm-message="<?php echo h('Restore '.$subject['archive_name'].' to the active list?'); ?>" data-confirm-button="Restore"><?php echo csrf_input(); ?><input type="hidden" name="tab" value="<?php echo h($activeTab); ?>"><input type="hidden" name="id" value="<?php echo (int)$subject['id']; ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success">Restore</button></form>
<form method="post" class="d-inline" data-confirm-title="Permanently delete <?php echo h($subjectLabel); ?>?" data-confirm-message="<?php echo h('Permanently delete '.$subject['archive_name'].'? This cannot be undone. The managing login account is retained.'); ?>" data-confirm-style="danger" data-confirm-button="Permanently Delete" data-confirm-require-text="DELETE"><?php echo csrf_input(); ?><input type="hidden" name="tab" value="<?php echo h($activeTab); ?>"><input type="hidden" name="id" value="<?php echo (int)$subject['id']; ?>"><input type="hidden" name="action" value="purge"><button class="btn btn-sm btn-outline-danger" <?php echo $blockers?'disabled':''; ?>>Permanently Delete</button></form>
<?php if ($blockers): ?><div class="small text-muted mt-2"><?php echo h(implode(' ',$blockers)); ?></div><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$subjectTable['rows']): ?><tr><td colspan="4" class="text-muted">No archived <?php echo h($activeTab); ?> match the current filters.</td></tr><?php endif; ?>
</tbody></table></div><?php echo admin_table_pagination($subjectTable); ?></div>
