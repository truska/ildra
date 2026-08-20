<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

ensureDevTaskTables($pdo);
$requestedStatus = (string)($_GET['status'] ?? 'open');
$filter = in_array($requestedStatus, ['open','completed','future','closed','all'], true) ? $requestedStatus : 'open';
$params = [];
$where = '';
if ($filter === 'open') {
    $where = "WHERE t.status IN ('open','completed')";
} elseif ($filter !== 'all') {
    $where = 'WHERE t.status=:status';
    $params[':status']=$filter;
}
$stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.email,
    au.first_name AS assignee_first_name,au.last_name AS assignee_last_name,au.email AS assignee_email,
    (SELECT COUNT(*) FROM dev_task_messages m WHERE m.task_id=t.id) AS message_count,
    (SELECT GROUP_CONCAT(CONCAT_WS(' ',m.author_name,m.message) SEPARATOR ' ') FROM dev_task_messages m WHERE m.task_id=t.id) AS conversation_search
    FROM dev_tasks t
    LEFT JOIN users u ON u.id=t.created_by
    LEFT JOIN users au ON au.id=t.next_action_by $where
    ORDER BY CASE WHEN t.next_action_by IS NULL THEN 1 ELSE 0 END,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ',au.first_name,au.last_name)),''),au.email,''),
        t.priority ASC,
        CASE t.status WHEN 'open' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END,
        t.updated_at DESC");
$stmt->execute($params);
$tasks = $stmt->fetchAll() ?: [];
$counts = ['open'=>0,'completed'=>0,'future'=>0,'closed'=>0];
foreach ($pdo->query('SELECT status,COUNT(*) qty FROM dev_tasks GROUP BY status')->fetchAll() ?: [] as $row) $counts[$row['status']] = (int)$row['qty'];

$filterForm = 'dev-task-filter-form';
$raisedByOptions = [];
foreach ($tasks as $task) {
    $raisedByOptions[(string)$task['created_by']] = trim(($task['first_name'] ?? '').' '.($task['last_name'] ?? '')) ?: ($task['email'] ?? 'Unknown');
}
asort($raisedByOptions, SORT_NATURAL | SORT_FLAG_CASE);
$nextActionOptions = ['0'=>'Unassigned'];
foreach (devTaskAssignableUsers($pdo) as $user) $nextActionOptions[(string)$user['id']] = devTaskAuthorName($user);
$statusOptions = ['open'=>'Open','completed'=>'Completed','future'=>'Future','closed'=>'Closed'];
$tableColumns = [
    'priority' => ['label'=>'Priority','sortable'=>true,'compare'=>'number','form'=>$filterForm],
    'task' => ['label'=>'Task','sortable'=>true,'filter'=>'text','placeholder'=>'Search task','form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['title']],
    'next_action' => ['label'=>'Next action by','sortable'=>true,'filter'=>'select','options'=>$nextActionOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['next_action_by'] ?? 0),'sort_value'=>static fn(array $row):string=>empty($row['next_action_by'])?'1 Unassigned':'0 '.(trim(($row['assignee_first_name']??'').' '.($row['assignee_last_name']??'')) ?: ($row['assignee_email']??'Unknown'))],
    'raised_by' => ['label'=>'Raised by','sortable'=>true,'filter'=>'select','options'=>$raisedByOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['created_by'],'sort_value'=>static fn(array $row):string=>trim(($row['first_name']??'').' '.($row['last_name']??'')) ?: ($row['email']??'Unknown')],
    'conversation' => ['label'=>'Conversation','sortable'=>true,'filter'=>'text','placeholder'=>'Search conversation','form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['conversation_search']??''),'sort_value'=>static fn(array $row):int=>(int)$row['message_count'],'compare'=>'number'],
    'updated' => ['label'=>'Updated','sortable'=>true,'filter'=>'text','placeholder'=>'Search updated','form'=>$filterForm,'value'=>static fn(array $row):string=>date('j M Y, H:i',strtotime((string)$row['updated_at'])),'sort_value'=>static fn(array $row):string=>(string)$row['updated_at']],
    'task_status' => ['label'=>'Status','sortable'=>true,'filter'=>'select','options'=>$statusOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['status']],
];
$table = admin_table_prepare($tasks, $tableColumns, 'next_action');
$tasks = $table['rows'];

admin_layout_start('Dev Tasks', 'dev_tasks');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">Tester questions, faults and suggestions</div><h5 class="mb-0">Dev Tasks</h5></div>
    <a class="btn btn-success" href="dev_task.php"><i class="fa-solid fa-plus me-1"></i> New task</a>
</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm <?php echo $filter==='open'?'btn-success':'btn-outline-secondary'; ?>" href="?status=open">Open <span class="badge text-bg-light ms-1"><?php echo $counts['open']; ?></span></a>
        <a class="btn btn-sm <?php echo $filter==='completed'?'btn-success':'btn-outline-secondary'; ?>" href="?status=completed">Completed <span class="badge text-bg-light ms-1"><?php echo $counts['completed']; ?></span></a>
        <a class="btn btn-sm <?php echo $filter==='future'?'btn-success':'btn-outline-secondary'; ?>" href="?status=future">Future <span class="badge text-bg-light ms-1"><?php echo $counts['future']; ?></span></a>
        <a class="btn btn-sm <?php echo $filter==='closed'?'btn-success':'btn-outline-secondary'; ?>" href="?status=closed">Closed <span class="badge text-bg-light ms-1"><?php echo $counts['closed']; ?></span></a>
        <a class="btn btn-sm <?php echo $filter==='all'?'btn-success':'btn-outline-secondary'; ?>" href="?status=all">All</a>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="dev_tasks.php?status=<?php echo h($filter); ?>">Clear filters</a>
</div>
<form method="get" id="<?php echo h($filterForm); ?>"><input type="hidden" name="status" value="<?php echo h($filter); ?>"></form>
<div class="card-soft p-3"><?php echo admin_table_record_count($table,'task','tasks'); ?><div class="table-responsive"><table class="table table-striped table-sm admin-data-table align-middle mb-0">
<thead class="table-light"><tr><?php foreach($tableColumns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?></tr>
<tr class="admin-table-filter-row"><?php foreach($tableColumns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($tasks as $task): $name=trim(($task['first_name']??'').' '.($task['last_name']??'')) ?: ($task['email']??'Unknown'); $assigneeName=trim(($task['assignee_first_name']??'').' '.($task['assignee_last_name']??'')) ?: ($task['assignee_email']??'Unassigned'); ?>
<tr>
    <td><span class="badge <?php echo (int)$task['priority']<=2?'text-bg-danger':((int)$task['priority']===3?'text-bg-warning':'text-bg-secondary'); ?>">P<?php echo (int)$task['priority']; ?></span></td>
    <td><a class="fw-semibold text-decoration-none" href="dev_task.php?id=<?php echo (int)$task['id']; ?>"><?php echo h($task['title']); ?></a><div class="small text-muted">#<?php echo (int)$task['id']; ?> · <?php echo h(date('j M Y, H:i', strtotime($task['created_at']))); ?></div></td>
    <td><?php echo h($assigneeName); ?></td>
    <td><?php echo h($name); ?></td><td><?php echo (int)$task['message_count']; ?> message<?php echo (int)$task['message_count']===1?'':'s'; ?></td>
    <td><?php echo h(date('j M Y, H:i', strtotime($task['updated_at']))); ?></td>
    <td><span class="badge <?php echo $task['status']==='open'?'text-bg-success':($task['status']==='completed'?'text-bg-primary':($task['status']==='future'?'text-bg-warning':'text-bg-secondary')); ?>"><?php echo ucfirst(h($task['status'])); ?></span></td>
</tr>
<?php endforeach; ?>
<?php if (!$tasks): ?><tr><td colspan="7" class="text-muted py-4 text-center">No <?php echo h($filter==='all'?'':$filter); ?> tasks found.</td></tr><?php endif; ?>
</tbody></table></div><?php echo admin_table_pagination($table); ?></div>
<?php admin_layout_end(); ?>
