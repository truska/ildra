<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin'], true) || (int)($currentUser['level'] ?? 0) >= 4;
$editId = (int)($_GET['edit'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) $alerts[]=['type'=>'danger','message'=>'Only admins can manage Event Types.'];
    elseif (saveEventType($pdo, $_POST, $alerts)) { $_SESSION['flash_success']='Event Type saved.'; header('Location: event_types.php'); exit; }
}
$types=fetchEventTypes($pdo); $edit=['id'=>0,'name'=>'','form_profile'=>'ride'];
foreach($types as $type) if((int)$type['id'] === $editId) { $edit=$type; break; }
$filterForm = 'event-types-filter-form';
$columns = [
    'name'=>['label'=>'Name','field'=>'name','sortable'=>true,'filter'=>'text','form'=>$filterForm],
    'profile'=>['label'=>'Form profile','field'=>'form_profile','sortable'=>true,'filter'=>'select','options'=>['ride'=>'Ride','attendees'=>'Attendees'],'form'=>$filterForm,'value'=>static fn(array $row): string => (string)($row['form_profile'] ?? 'ride')],
];
$table = admin_table_prepare($types, $columns, 'name');
$types = $table['rows'];
admin_layout_start('Event Types', 'event_types');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Event setup</div><h5 class="mb-0">Event Types</h5></div><a class="btn btn-success" href="event_types.php?edit=new">Add Event Type</a></div>
<?php if (isset($_GET['edit'])): ?>
<div class="card-soft p-4 mb-4"><form method="post" class="row g-3"><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><div class="col-md-6"><label class="form-label">Name</label><input class="form-control" required name="name" value="<?php echo h((string)$edit['name']); ?>"></div><div class="col-md-6"><label class="form-label">Form profile</label><select class="form-select" name="form_profile"><option value="ride" <?php echo ($edit['form_profile']??'ride')==='ride'?'selected':''; ?>>Ride — rider, horse and class</option><option value="attendees" <?php echo ($edit['form_profile']??'ride')==='attendees'?'selected':''; ?>>Attendees — contact and repeatable people</option></select></div><div class="col-12"><button class="btn btn-success">Save Event Type</button> <a class="btn btn-outline-secondary" href="event_types.php">Cancel</a></div></form></div>
<?php endif; ?>
<form method="get" id="<?php echo h($filterForm); ?>"></form>
<div class="card-soft p-3"><?php echo admin_table_record_count($table, 'event type', 'event types'); ?><div class="table-responsive"><table class="table table-sm admin-data-table align-middle"><thead><tr><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?><th></th></tr><tr class="admin-table-filter-row"><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?><th class="text-end"><a class="btn btn-sm btn-outline-secondary" href="event_types.php">Clear</a></th></tr></thead><tbody><?php foreach($types as $type): ?><tr><td><?php echo h((string)$type['name']); ?></td><td><?php echo ($type['form_profile']??'ride')==='attendees'?'Attendees':'Ride'; ?></td><td class="text-end"><a class="btn btn-sm btn-outline-success" href="event_types.php?edit=<?php echo (int)$type['id']; ?>">Edit</a></td></tr><?php endforeach; ?><?php if(!$types): ?><tr><td colspan="3" class="text-muted">No event types match these filters.</td></tr><?php endif; ?></tbody></table></div><?php echo admin_table_pagination($table); ?></div>
<?php admin_layout_end();
