<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

ensureDevTaskTables($pdo);
$requestedStatus = (string)($_GET['status'] ?? 'open');
$filter = in_array($requestedStatus, ['open','closed','all'], true) ? $requestedStatus : 'open';
$params = [];
$where = '';
if ($filter !== 'all') { $where = 'WHERE t.status=:status'; $params[':status']=$filter; }
$stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.email,
    (SELECT COUNT(*) FROM dev_task_messages m WHERE m.task_id=t.id) AS message_count
    FROM dev_tasks t LEFT JOIN users u ON u.id=t.created_by $where
    ORDER BY CASE WHEN t.status='open' THEN 0 ELSE 1 END, t.priority ASC, t.updated_at DESC");
$stmt->execute($params);
$tasks = $stmt->fetchAll() ?: [];
$counts = ['open'=>0,'closed'=>0];
foreach ($pdo->query('SELECT status,COUNT(*) qty FROM dev_tasks GROUP BY status')->fetchAll() ?: [] as $row) $counts[$row['status']] = (int)$row['qty'];

admin_layout_start('Dev Tasks', 'dev_tasks');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">Tester questions, faults and suggestions</div><h5 class="mb-0">Dev Tasks</h5></div>
    <a class="btn btn-success" href="dev_task.php"><i class="fa-solid fa-plus me-1"></i> New task</a>
</div>
<div class="d-flex gap-2 mb-3">
    <a class="btn btn-sm <?php echo $filter==='open'?'btn-success':'btn-outline-secondary'; ?>" href="?status=open">Open <span class="badge text-bg-light ms-1"><?php echo $counts['open']; ?></span></a>
    <a class="btn btn-sm <?php echo $filter==='closed'?'btn-success':'btn-outline-secondary'; ?>" href="?status=closed">Closed <span class="badge text-bg-light ms-1"><?php echo $counts['closed']; ?></span></a>
    <a class="btn btn-sm <?php echo $filter==='all'?'btn-success':'btn-outline-secondary'; ?>" href="?status=all">All</a>
</div>
<div class="card-soft p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead class="table-light"><tr><th>Priority</th><th>Task</th><th>Raised by</th><th>Conversation</th><th>Updated</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($tasks as $task): $name=trim(($task['first_name']??'').' '.($task['last_name']??'')) ?: ($task['email']??'Unknown'); ?>
<tr>
    <td><span class="badge <?php echo (int)$task['priority']<=2?'text-bg-danger':((int)$task['priority']===3?'text-bg-warning':'text-bg-secondary'); ?>">P<?php echo (int)$task['priority']; ?></span></td>
    <td><a class="fw-semibold text-decoration-none" href="dev_task.php?id=<?php echo (int)$task['id']; ?>"><?php echo h($task['title']); ?></a><div class="small text-muted">#<?php echo (int)$task['id']; ?> · <?php echo h(date('j M Y, H:i', strtotime($task['created_at']))); ?></div></td>
    <td><?php echo h($name); ?></td><td><?php echo (int)$task['message_count']; ?> message<?php echo (int)$task['message_count']===1?'':'s'; ?></td>
    <td><?php echo h(date('j M Y, H:i', strtotime($task['updated_at']))); ?></td>
    <td><span class="badge <?php echo $task['status']==='open'?'text-bg-success':'text-bg-secondary'; ?>"><?php echo ucfirst(h($task['status'])); ?></span></td>
</tr>
<?php endforeach; ?>
<?php if (!$tasks): ?><tr><td colspan="6" class="text-muted py-4 text-center">No <?php echo h($filter==='all'?'':$filter); ?> tasks found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php admin_layout_end(); ?>
