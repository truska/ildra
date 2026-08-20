<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
ensureDevTaskTables($pdo);
$assignableUsers = devTaskAssignableUsers($pdo);

$taskId = max(0, (int)($_GET['id'] ?? $_POST['task_id'] ?? 0));
if (empty($_SESSION['dev_task_csrf'])) $_SESSION['dev_task_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['dev_task_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $alerts[] = ['type'=>'danger','message'=>'Your session token expired. Please try again.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $newId = devTaskCreate($pdo, $_POST, $_FILES['image'] ?? [], $currentUser, $alerts);
            if ($newId) { $_SESSION['flash_success']='Dev task created.'; header('Location: dev_task.php?id='.$newId); exit; }
        } elseif ($action === 'reply' && $taskId > 0) {
            if (devTaskAddMessage($pdo, $taskId, (string)($_POST['message'] ?? ''), $_FILES['image'] ?? [], $currentUser, $alerts)) {
                $_SESSION['flash_success']='Reply added.'; header('Location: dev_task.php?id='.$taskId.'#conversation'); exit;
            }
        } elseif ($action === 'status' && $taskId > 0) {
            $requestedStatus = (string)($_POST['status'] ?? 'open');
            $status = in_array($requestedStatus, ['open','completed','future','closed'], true) ? $requestedStatus : 'open';
            $stmt=$pdo->prepare("UPDATE dev_tasks SET status=:status,closed_by=:by,closed_at=:at WHERE id=:id");
            $stmt->execute([':status'=>$status, ':by'=>$status==='closed'?(int)$currentUser['id']:null, ':at'=>$status==='closed'?date('Y-m-d H:i:s'):null, ':id'=>$taskId]);
            $_SESSION['flash_success']=$status==='closed'?'Task closed.':($status==='completed'?'Task marked completed and ready for review.':($status==='future'?'Task moved to Future.':'Task reopened.')); header('Location: dev_task.php?id='.$taskId); exit;
        } elseif ($action === 'priority' && $taskId > 0) {
            $priority=(int)($_POST['priority']??3);
            if ($priority >= 1 && $priority <= 5) { $pdo->prepare('UPDATE dev_tasks SET priority=:p WHERE id=:id')->execute([':p'=>$priority,':id'=>$taskId]); $_SESSION['flash_success']='Priority updated.'; header('Location: dev_task.php?id='.$taskId); exit; }
        } elseif ($action === 'assignee' && $taskId > 0) {
            $nextActionBy = devTaskAssigneeId($pdo, $_POST['next_action_by'] ?? 0, $alerts);
            if (!$alerts) { $pdo->prepare('UPDATE dev_tasks SET next_action_by=:user WHERE id=:id')->execute([':user'=>$nextActionBy,':id'=>$taskId]); $_SESSION['flash_success']='Next action updated.'; header('Location: dev_task.php?id='.$taskId); exit; }
        }
    }
}

$task = null; $messages=[];
if ($taskId > 0) {
    $stmt=$pdo->prepare('SELECT * FROM dev_tasks WHERE id=:id'); $stmt->execute([':id'=>$taskId]); $task=$stmt->fetch() ?: null;
    if ($task) { $stmt=$pdo->prepare('SELECT * FROM dev_task_messages WHERE task_id=:id ORDER BY created_at,id'); $stmt->execute([':id'=>$taskId]); $messages=$stmt->fetchAll() ?: []; }
    if (!$task) { http_response_code(404); $alerts[]=['type'=>'danger','message'=>'Dev task not found.']; }
}
admin_layout_start($task ? 'Dev Task #'.$taskId : 'New Dev Task', 'dev_tasks');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
<div><div class="small text-muted"><?php echo $task?'Dev Task #'.$taskId:'Create a question, fault or suggestion'; ?></div><h5 class="mb-0"><?php echo $task?h($task['title']):'New Dev Task'; ?></h5></div>
<a class="btn btn-outline-secondary" href="dev_tasks.php">Back to tasks</a></div>

<?php if (!$task && $taskId===0): ?>
<div class="card-soft p-4"><form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="action" value="create">
<div class="mb-3"><label class="form-label" for="title">Short title</label><input class="form-control" id="title" name="title" maxlength="255" required value="<?php echo h($_POST['title']??''); ?>"></div>
<div class="mb-3"><label class="form-label" for="message">Question, fault or suggestion</label><textarea class="form-control" id="message" name="message" rows="7" required><?php echo h($_POST['message']??''); ?></textarea></div>
<div class="row g-3"><div class="col-md-3"><label class="form-label" for="priority">Priority</label><select class="form-select" id="priority" name="priority"><?php for($p=1;$p<=5;$p++): ?><option value="<?php echo $p; ?>" <?php echo (int)($_POST['priority']??3)===$p?'selected':''; ?>><?php echo $p; ?> — <?php echo ['','Urgent','High','Normal','Low','When possible'][$p]; ?></option><?php endfor; ?></select></div>
<div class="col-md-4"><label class="form-label" for="next-action-by">Next action by</label><select class="form-select" id="next-action-by" name="next_action_by"><option value="">Unassigned</option><?php foreach($assignableUsers as $assignableUser): $assignableName=devTaskAuthorName($assignableUser); ?><option value="<?php echo (int)$assignableUser['id']; ?>" <?php echo (int)($_POST['next_action_by']??0)===(int)$assignableUser['id']?'selected':''; ?>><?php echo h($assignableName); ?></option><?php endforeach; ?></select></div>
<div class="col-md-5"><label class="form-label" for="image">Screenshot (optional)</label><input class="form-control" id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><div class="form-text">JPG, PNG, GIF or WebP, up to 10 MB.</div></div></div>
<button class="btn btn-success mt-4">Create task</button></form></div>
<?php elseif ($task): ?>
<div class="card-soft p-3 mb-4"><div class="d-flex flex-wrap gap-3 align-items-end justify-content-between">
<div><span class="badge <?php echo $task['status']==='open'?'text-bg-success':($task['status']==='completed'?'text-bg-primary':($task['status']==='future'?'text-bg-warning':'text-bg-secondary')); ?> me-2"><?php echo ucfirst(h($task['status'])); ?></span><?php if((int)($task['next_action_by']??0)===(int)$currentUser['id']): ?><span class="badge text-bg-primary me-2">Your next action</span><?php endif; ?><span class="text-muted small">Created <?php echo h(date('j M Y, H:i',strtotime($task['created_at']))); ?></span></div>
<div class="d-flex flex-wrap gap-2"><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="action" value="priority"><input type="hidden" name="task_id" value="<?php echo $taskId; ?>"><select class="form-select form-select-sm" name="priority" aria-label="Priority"><?php for($p=1;$p<=5;$p++): ?><option value="<?php echo $p; ?>" <?php echo (int)$task['priority']===$p?'selected':''; ?>>Priority <?php echo $p; ?></option><?php endfor; ?></select><button class="btn btn-sm btn-outline-success">Update</button></form>
<form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="action" value="assignee"><input type="hidden" name="task_id" value="<?php echo $taskId; ?>"><select class="form-select form-select-sm" name="next_action_by" aria-label="Next action by"><option value="">Unassigned</option><?php foreach($assignableUsers as $assignableUser): ?><option value="<?php echo (int)$assignableUser['id']; ?>" <?php echo (int)($task['next_action_by']??0)===(int)$assignableUser['id']?'selected':''; ?>><?php echo h(devTaskAuthorName($assignableUser)); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary">Assign</button></form>
<form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="task_id" value="<?php echo $taskId; ?>"><select class="form-select form-select-sm" name="status" aria-label="Task status"><option value="open" <?php echo $task['status']==='open'?'selected':''; ?>>Open</option><option value="completed" <?php echo $task['status']==='completed'?'selected':''; ?>>Completed — ready for review</option><option value="future" <?php echo $task['status']==='future'?'selected':''; ?>>Future</option><option value="closed" <?php echo $task['status']==='closed'?'selected':''; ?>>Closed</option></select><button class="btn btn-sm btn-outline-success">Update</button></form></div></div></div>
<div id="conversation">
<?php foreach($messages as $message): ?><article class="card-soft p-3 mb-3"><div class="d-flex justify-content-between gap-3 mb-2"><strong><?php echo h($message['author_name']); ?></strong><time class="small text-muted text-nowrap"><?php echo h(date('j M Y, H:i',strtotime($message['created_at']))); ?></time></div><div style="white-space:pre-wrap"><?php echo h($message['message']); ?></div><?php if(!empty($message['image_filename'])): ?><a href="<?php echo h(image_upload_public_path('dev-tasks','original',$message['image_filename'])); ?>" target="_blank" rel="noopener" class="d-inline-block mt-3"><img class="img-fluid rounded border" style="max-height:420px" src="<?php echo h(image_upload_public_path('dev-tasks','lg',$message['image_filename'])); ?>" alt="Screenshot attached by <?php echo h($message['author_name']); ?>"></a><?php endif; ?></article><?php endforeach; ?>
</div>
<div class="card-soft p-4 mt-4"><h6>Add to the conversation</h6><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="task_id" value="<?php echo $taskId; ?>"><textarea class="form-control mb-3" name="message" rows="5" required placeholder="Write a reply…"><?php echo h($_POST['message']??''); ?></textarea><label class="form-label" for="reply-image">Attach screenshot (optional)</label><input class="form-control" id="reply-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><button class="btn btn-success mt-3">Add reply</button></form></div>
<?php endif; ?>
<?php admin_layout_end(); ?>
