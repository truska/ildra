<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/campaign_navigation.php';
require_once __DIR__.'/../record_notes.php';
header('Cache-Control: no-store, private');
if (!$pdo || !recordNotesCanRead($currentRole)) { http_response_code(403);exit('Administrator access required.'); }
ensureRecordNotesTables($pdo);
$filters=[];
foreach(['email','record_type','record_id','action','from','to'] as $field) $filters[$field]=trim((string)($_GET[$field]??''));
$offset=max(0,(int)($_GET['offset']??0));$rows=[];$filterErrors=[];
foreach(['from','to'] as $field) if($filters[$field]!=='') {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$field]);
    if(!$date || $date->format('Y-m-d')!==$filters[$field]) $filterErrors[$field]='Enter a valid date.';
}
if($filters['record_id']!=='' && (!ctype_digit($filters['record_id']) || (int)$filters['record_id']<=0)) $filterErrors['record_id']='Enter a positive record ID.';
try { if(!$filterErrors)$rows=recordNotesFind($pdo,$filters,$offset); }
catch (RuntimeException $e) { $alerts[]=['type'=>'danger','message'=>$e instanceof PDOException?'Notes could not be loaded.':$e->getMessage()]; }
$more=count($rows)>100;$rows=array_slice($rows,0,100);
admin_layout_start('Internal record notes','users');
$notesReturn=campaign_navigation_request_return();
if($notesReturn==='migration_campaign.php') render_campaign_navigation('migration',$currentRole,[$notesReturn,'Back to Account migration']);
else echo render_back_navigation('users.php','Back to Users','Notes navigation');
?>
<div class="card-soft p-4"><h1 class="h4">Internal record notes</h1><p>Private history for archived and current records. Notes retain the email and record identifiers from the time of the action.</p>
<form method="get" class="row g-3 mb-4"><?php if($notesReturn==='migration_campaign.php'): ?><input type="hidden" name="return" value="migration_campaign.php"><?php endif; ?>
    <div class="col-md-4"><label class="form-label" for="notes-email">Email contains</label><input id="notes-email" class="form-control" name="email" value="<?= h($filters['email']) ?>"></div>
    <div class="col-md-2"><label class="form-label" for="notes-type">Record type</label><select id="notes-type" class="form-select" name="record_type"><option value="">All</option><?php foreach(['user','person','horse'] as $type): ?><option value="<?= h($type) ?>" <?= $filters['record_type']===$type?'selected':'' ?>><?= h(ucfirst($type)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label" for="notes-id">Record ID</label><?php if(isset($filterErrors['record_id'])): ?><div class="text-danger small mb-1" id="notes-id-error"><?= h($filterErrors['record_id']) ?></div><?php endif; ?><input id="notes-id" class="form-control <?= isset($filterErrors['record_id'])?'is-invalid':'' ?>" <?= isset($filterErrors['record_id'])?'aria-invalid="true" aria-describedby="notes-id-error"':'' ?> type="number" min="1" name="record_id" value="<?= h($filters['record_id']) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="notes-action">Action</label><input id="notes-action" class="form-control" name="action" value="<?= h($filters['action']) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="notes-from">From</label><?php if(isset($filterErrors['from'])): ?><div class="text-danger small mb-1" id="notes-from-error"><?= h($filterErrors['from']) ?></div><?php endif; ?><input id="notes-from" class="form-control <?= isset($filterErrors['from'])?'is-invalid':'' ?>" <?= isset($filterErrors['from'])?'aria-invalid="true" aria-describedby="notes-from-error"':'' ?> type="date" name="from" value="<?= h($filters['from']) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="notes-to">To</label><?php if(isset($filterErrors['to'])): ?><div class="text-danger small mb-1" id="notes-to-error"><?= h($filterErrors['to']) ?></div><?php endif; ?><input id="notes-to" class="form-control <?= isset($filterErrors['to'])?'is-invalid':'' ?>" <?= isset($filterErrors['to'])?'aria-invalid="true" aria-describedby="notes-to-error"':'' ?> type="date" name="to" value="<?= h($filters['to']) ?>"></div>
    <div class="col-md-6 align-self-end"><button class="btn btn-success">Filter</button> <a class="btn btn-outline-secondary" href="record_notes.php<?= $notesReturn==='migration_campaign.php'?'?return=migration_campaign.php':'' ?>">Clear</a></div>
</form>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date / time</th><th>Record</th><th>Email at time</th><th>Action / note</th><th>Author</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= h(admin_display_datetime($row['created_at'])) ?></td><td><?= h(ucfirst($row['record_type']).' #'.$row['record_id']) ?></td><td><?= h($row['email']) ?></td><td><strong><?= h($row['action']) ?></strong><div><?= nl2br(h($row['note'])) ?></div><details class="small mt-2"><summary>Confirmation evidence</summary><pre class="text-wrap"><?= h(json_encode(json_decode((string)$row['metadata_json'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre><div>Event: <?= h($row['event_key']) ?></div></details></td><td><?= h($row['author_kind'].' #'.$row['actor_user_id']) ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" class="text-muted">No matching notes.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="d-flex gap-2"><?php if($offset>0): ?><a class="btn btn-outline-secondary" href="?<?= h(http_build_query($filters+($notesReturn==='migration_campaign.php'?['return'=>$notesReturn]:[])+['offset'=>max(0,$offset-100)])) ?>">Previous</a><?php endif; ?><?php if($more): ?><a class="btn btn-outline-secondary" href="?<?= h(http_build_query($filters+($notesReturn==='migration_campaign.php'?['return'=>$notesReturn]:[])+['offset'=>$offset+100])) ?>">Next</a><?php endif; ?></div>
</div><?php admin_layout_end(); ?>
