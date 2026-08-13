<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManagePeople = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManagePeople) {
    header('Location: index.php');
    exit;
}

ensureMembershipTables($pdo);

$rows = [];
if ($pdo) {
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.owner_user_id,
            p.member_number,
            p.first_name,
            p.last_name,
            p.dob,
            p.email,
            p.phone,
            p.is_archived,
            p.created_at,
            u.email AS owner_email
        FROM people p
        LEFT JOIN users u ON u.id = p.owner_user_id
        ORDER BY p.last_name ASC, p.first_name ASC, p.id ASC
    ");
    $rows = $stmt->fetchAll() ?: [];
}

$tableColumns = [
    'name'=>['label'=>'Person','sortable'=>true,'filter'=>'text','placeholder'=>'Search person','value'=>static fn(array $r):string=>trim((string)($r['last_name']??'').' '.(string)($r['first_name']??''))],
    'member_number'=>['label'=>'Member #','sortable'=>true,'filter'=>'text','placeholder'=>'Search #','compare'=>'number'],
    'dob'=>['label'=>'DOB','sortable'=>true],
    'email'=>['label'=>'Email','filter'=>'text','placeholder'=>'Search email','data_type'=>'email'],
    'phone'=>['label'=>'Phone','filter'=>'text','placeholder'=>'Search phone','data_type'=>'phone'],
    'owner'=>['label'=>'Owner (user)','field'=>'owner_email','sortable'=>true,'filter'=>'text','placeholder'=>'Search user','data_type'=>'email'],
    'status'=>['label'=>'Status','sortable'=>true,'filter'=>'select','options'=>['active'=>'Active','archived'=>'Archived'],'value'=>static fn(array $r):string=>!empty($r['is_archived'])?'archived':'active'],
    'created'=>['label'=>'Created','field'=>'created_at','sortable'=>true,'filter'=>'text','placeholder'=>'Search created','value'=>static fn(array $r):string=>format_display_datetime($r['created_at']??null,''),'sort_value'=>static fn(array $r):string=>(string)($r['created_at']??'')],
];
$table=admin_table_prepare($rows,$tableColumns,'name');$rows=$table['rows'];$filters=$table['filters'];$sortKey=$table['sort_key'];$sortDir=$table['sort_dir'];

admin_layout_start('People', 'people');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">People stored per account</div>
        <h5 class="mb-0">People</h5>
    </div>
</div>

<div class="card-soft p-3"><form method="get">
    <?php echo admin_table_record_count($table,'person','people'); ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <?php foreach($tableColumns as$key=>$column): ?><th><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th><?php endforeach; ?>
            </tr>
            <tr class="admin-table-filter-row"><?php foreach($tableColumns as$key=>$column): ?><th><?php echo admin_table_filter($key,$column,$filters); ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                $name = $name !== '' ? $name : '—';
                $memberNumber = $row['member_number'] !== null ? (string)$row['member_number'] : '—';
                $dob = $row['dob'] ? format_display_date((string)$row['dob'], '—') : '—';
                $status = !empty($row['is_archived']) ? 'Archived' : 'Active';
                $created = $row['created_at'] ? format_display_datetime((string)$row['created_at'], '—') : '—';
                ?>
                <tr>
                    <td class="fw-semibold"><?php echo h($name); ?></td>
                    <td class="text-muted small"><?php echo h($memberNumber); ?></td>
                    <td class="text-muted small"><?php echo h($dob); ?></td>
                    <td class="text-muted small"><?php echo admin_table_value($row['email'] ?? '', 'email'); ?></td>
                    <td class="text-muted small"><?php echo admin_table_value($row['phone'] ?? '', 'phone'); ?></td>
                    <td class="text-muted small"><?php echo admin_table_value($row['owner_email'] ?? '', 'email'); ?></td>
                    <td class="text-muted small"><?php echo h($status); ?></td>
                    <td class="text-muted small"><?php echo h($created); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-muted">No people yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_table_pagination($table); ?>
    <div class="mt-2 text-end"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="people.php">Clear</a></div>
</form></div>

<?php
admin_layout_end();
