<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

ensureDevTaskTables($pdo);

$listStateKey = 'admin_dev_tasks_list_state';
$listStateFields = [
    'status', 'priority', 'task', 'next_action', 'raised_by', 'updated_by',
    'conversation', 'updated', 'task_status', 'sort', 'dir', 'p', 'per_page',
];
if (!$_GET && !empty($_SESSION[$listStateKey]) && is_array($_SESSION[$listStateKey])) {
    $_GET = $_SESSION[$listStateKey];
}
$listState = [];
foreach ($listStateFields as $field) {
    if (isset($_GET[$field]) && is_scalar($_GET[$field])) {
        $listState[$field] = (string)$_GET[$field];
    }
}
$_SESSION[$listStateKey] = $listState;

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
$stmt = $pdo->prepare("SELECT t.*, UNIX_TIMESTAMP(t.created_at) AS created_at_ts,
    UNIX_TIMESTAMP(t.updated_at) AS updated_at_ts, u.first_name, u.last_name, u.email,
    au.first_name AS assignee_first_name,au.last_name AS assignee_last_name,au.email AS assignee_email,
    uu.first_name AS updated_first_name,uu.last_name AS updated_last_name,uu.email AS updated_email,
    (SELECT COUNT(*) FROM dev_task_messages m WHERE m.task_id=t.id) AS message_count,
    (SELECT GROUP_CONCAT(CONCAT_WS(' ',m.author_name,m.message) SEPARATOR ' ') FROM dev_task_messages m WHERE m.task_id=t.id) AS conversation_search
    FROM dev_tasks t
    LEFT JOIN users u ON u.id=t.created_by
    LEFT JOIN users uu ON uu.id=t.updated_by
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
$priorityOptions = ['1'=>'1 — Urgent','2'=>'2 — High','3'=>'3 — Normal','4'=>'4 — Low','5'=>'5 — When possible'];
$updatedByOptions = [];
foreach ($tasks as $task) {
    $updatedById = (int)($task['updated_by'] ?? 0);
    if ($updatedById <= 0) continue;
    $updatedByOptions[(string)$updatedById] = trim(($task['updated_first_name']??'').' '.($task['updated_last_name']??'')) ?: ($task['updated_email']??'Unknown');
}
asort($updatedByOptions, SORT_NATURAL | SORT_FLAG_CASE);
$statusOptions = ['open'=>'Open','completed'=>'Completed','future'=>'Future','closed'=>'Closed'];
$tableColumns = [
    'priority' => ['label'=>'Priority','sortable'=>true,'compare'=>'number','filter'=>'select','options'=>$priorityOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['priority']],
    'task' => ['label'=>'Task','sortable'=>true,'filter'=>'text','placeholder'=>'Search task','form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['title']],
    'next_action' => ['label'=>'Next action by','sortable'=>true,'filter'=>'select','options'=>$nextActionOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['next_action_by'] ?? 0),'sort_value'=>static fn(array $row):string=>empty($row['next_action_by'])?'1 Unassigned':'0 '.(trim(($row['assignee_first_name']??'').' '.($row['assignee_last_name']??'')) ?: ($row['assignee_email']??'Unknown'))],
    'raised_by' => ['label'=>'Raised by','sortable'=>true,'filter'=>'select','options'=>$raisedByOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['created_by'],'sort_value'=>static fn(array $row):string=>trim(($row['first_name']??'').' '.($row['last_name']??'')) ?: ($row['email']??'Unknown')],
    'updated_by' => ['label'=>'Last edited by','sortable'=>true,'filter'=>'select','options'=>$updatedByOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['updated_by']??0),'sort_value'=>static fn(array $row):string=>trim(($row['updated_first_name']??'').' '.($row['updated_last_name']??'')) ?: ($row['updated_email']??'')],
    'conversation' => ['label'=>'Conversation','sortable'=>true,'filter'=>'text','placeholder'=>'Search conversation','form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['conversation_search']??''),'sort_value'=>static fn(array $row):int=>(int)$row['message_count'],'compare'=>'number'],
    'updated' => ['label'=>'Updated','sortable'=>true,'filter'=>'text','placeholder'=>'Search updated','form'=>$filterForm,'value'=>static fn(array $row):string=>date('j M Y, H:i',(int)$row['updated_at_ts']),'sort_value'=>static fn(array $row):int=>(int)$row['updated_at_ts'],'compare'=>'number'],
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
<style>
    .dev-task-record-count { margin-left:70px; }
    /* Each task deliberately occupies two table rows. Override Bootstrap's
       per-row stripe so those two rows remain one readable visual group. */
    .admin-data-table.table-striped > tbody.dev-task-group > tr > * { border-top:0; --bs-table-bg-type:transparent !important; }
    .admin-data-table .dev-task-title-row > td { padding-bottom:.2rem; padding-left:70px; }
    .admin-data-table .dev-task-details-row > td { padding-top:.2rem; }
    .admin-data-table.table-striped > tbody.dev-task-group.dev-task-group-striped > tr > * { --bs-table-bg-type:rgba(0,0,0,.05) !important; }
    @media (max-width: 575.98px) { .dev-task-record-count { margin-left:0; } .admin-data-table .dev-task-title-row > td { padding-left:.25rem; } }
</style>
<div class="card-soft p-3"><div class="dev-task-record-count"><?php echo admin_table_record_count($table,'task','tasks'); ?></div><div class="table-responsive"><table class="table table-striped table-sm admin-data-table align-middle mb-0">
<thead class="table-light"><tr><?php foreach($tableColumns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?></tr>
<tr class="admin-table-filter-row"><?php foreach($tableColumns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?></tr></thead>
<?php foreach ($tasks as $taskIndex=>$task): $name=trim(($task['first_name']??'').' '.($task['last_name']??'')) ?: ($task['email']??'Unknown'); $assigneeName=trim(($task['assignee_first_name']??'').' '.($task['assignee_last_name']??'')) ?: ($task['assignee_email']??'Unassigned'); $updatedBy=trim(($task['updated_first_name']??'').' '.($task['updated_last_name']??'')) ?: ($task['updated_email']??'Not recorded'); ?>
<tbody class="dev-task-group<?php echo $taskIndex % 2 === 0 ? ' dev-task-group-striped' : ''; ?>">
<tr class="dev-task-title-row"><td colspan="8"><a class="fw-semibold text-decoration-none" href="dev_task.php?id=<?php echo (int)$task['id']; ?>"><?php echo h($task['title']); ?></a></td></tr>
<tr class="dev-task-details-row">
    <td><span class="badge <?php echo (int)$task['priority']<=2?'text-bg-danger':((int)$task['priority']===3?'text-bg-warning':'text-bg-secondary'); ?>">P<?php echo (int)$task['priority']; ?></span></td>
    <td class="small text-muted">#<?php echo (int)$task['id']; ?> · <?php echo h(date('j M Y, H:i', (int)$task['created_at_ts'])); ?></td>
    <td><?php echo h($assigneeName); ?></td><td><?php echo h($name); ?></td><td><?php echo h($updatedBy); ?></td>
    <td><?php echo (int)$task['message_count']; ?> message<?php echo (int)$task['message_count']===1?'':'s'; ?></td>
    <td><?php echo h(date('j M Y, H:i', (int)$task['updated_at_ts'])); ?></td>
    <td><span class="badge <?php echo $task['status']==='open'?'text-bg-success':($task['status']==='completed'?'text-bg-primary':($task['status']==='future'?'text-bg-warning':'text-bg-secondary')); ?>"><?php echo ucfirst(h($task['status'])); ?></span></td>
</tr></tbody>
<?php endforeach; ?>
<?php if (!$tasks): ?><tbody><tr><td colspan="8" class="text-muted py-4 text-center">No <?php echo h($filter==='all'?'':$filter); ?> tasks found.</td></tr></tbody><?php endif; ?>
</table></div><?php echo admin_table_pagination($table); ?></div>
<?php admin_layout_end(); ?>
