<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageHorses = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManageHorses) {
    header('Location: index.php');
    exit;
}

ensureHorsesTables($pdo);

$globalHorseId = 1;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_global_horse') {
    $globalHorseName = trim((string)($_POST['global_horse_name'] ?? ''));
    if ($globalHorseName === '') {
        $alerts[] = ['type'=>'danger', 'message'=>'The system horse label is required.'];
    } elseif ($pdo) {
        $stmt = $pdo->prepare('UPDATE horses SET name=:name, is_archived=0, updated_at=NOW() WHERE id=:id');
        $stmt->execute([':name'=>$globalHorseName, ':id'=>$globalHorseId]);
        $_SESSION['flash_success'] = 'System horse option updated.';
        header('Location: horses.php');
        exit;
    }
}

$rows = [];
$globalHorse = null;
if ($pdo) {
    $stmt = $pdo->query("
        SELECT
            h.id,
            h.owner_user_id,
            h.name,
            h.dob,
            h.year_of_birth,
            h.breed,
            h.colour,
            h.is_archived,
            h.created_at,
            u.email AS owner_email
        FROM horses h
        LEFT JOIN users u ON u.id = h.owner_user_id
        WHERE h.id <> 1
        ORDER BY h.name ASC, h.id ASC
    ");
    $rows = $stmt->fetchAll() ?: [];
    $stmt = $pdo->prepare('SELECT id,name,is_archived,updated_at FROM horses WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$globalHorseId]);
    $globalHorse = $stmt->fetch() ?: null;
}

$ownerOptions=[];
foreach($rows as$row){$owner=trim((string)($row['owner_email']??''));if($owner!=='')$ownerOptions[$owner]=$owner;}
natcasesort($ownerOptions);$ownerOptions=['__none__'=>'No owner']+$ownerOptions;
$tableColumns = [
    'name'=>['label'=>'Horse','sortable'=>true,'filter'=>'text','placeholder'=>'Search horse'],
    'dob'=>['label'=>'DOB','sortable'=>true,'filter'=>'text','placeholder'=>'Search DOB','value'=>static fn(array $r):string=>format_display_date($r['dob']??null,''),'sort_value'=>static fn(array $r):string=>(string)($r['dob']??'')],
    'year_of_birth'=>['label'=>'Year of birth','filter'=>'text','placeholder'=>'Search year'],
    'breed'=>['label'=>'Breed','sortable'=>true,'filter'=>'text','placeholder'=>'Search breed'],
    'colour'=>['label'=>'Colour','sortable'=>true,'filter'=>'text','placeholder'=>'Search colour'],
    'owner'=>['label'=>'Owner (user)','sortable'=>true,'filter'=>'select','options'=>$ownerOptions,'value'=>static fn(array $r):string=>trim((string)($r['owner_email']??''))?:'__none__'],
    'status'=>['label'=>'Status','sortable'=>true,'filter'=>'select','options'=>['active'=>'Active','archived'=>'Archived'],'value'=>static fn(array $r):string=>!empty($r['is_archived'])?'archived':'active'],
    'created'=>['label'=>'Created','sortable'=>true,'filter'=>'text','placeholder'=>'Search created','value'=>static fn(array $r):string=>format_display_datetime($r['created_at']??null,''),'sort_value'=>static fn(array $r):string=>(string)($r['created_at']??'')],
];
$table=admin_table_prepare($rows,$tableColumns,'name');$rows=$table['rows'];$filters=$table['filters'];$sortKey=$table['sort_key'];$sortDir=$table['sort_dir'];

admin_layout_start('Horses', 'horses');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Genuine horse records stored per account</div>
        <h5 class="mb-0">Registered horses</h5>
    </div>
</div>

<div class="card-soft p-3"><form method="get">
    <?php echo admin_table_record_count($table,'horse','horses'); ?>
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
                $name = trim((string)($row['name'] ?? ''));
                $name = $name !== '' ? $name : '—';
                $dob = $row['dob'] ? format_display_date((string)$row['dob'], '—') : '—';
                $yob = trim((string)($row['year_of_birth'] ?? ''));
                $yob = $yob !== '' ? $yob : '—';
                $breed = trim((string)($row['breed'] ?? ''));
                $breed = $breed !== '' ? $breed : '—';
                $colour = trim((string)($row['colour'] ?? ''));
                $colour = $colour !== '' ? $colour : '—';
                $status = !empty($row['is_archived']) ? 'Archived' : 'Active';
                $created = $row['created_at'] ? format_display_datetime((string)$row['created_at'], '—') : '—';
                ?>
                <tr>
                    <td class="fw-semibold"><?php echo h($name); ?></td>
                    <td class="text-muted small"><?php echo h($dob); ?></td>
                    <td class="text-muted small"><?php echo h($yob); ?></td>
                    <td class="text-muted small"><?php echo h($breed); ?></td>
                    <td class="text-muted small"><?php echo h($colour); ?></td>
                    <td class="text-muted small"><?php echo admin_table_value($row['owner_email'] ?? '', 'email'); ?></td>
                    <td class="text-muted small"><?php echo h($status); ?></td>
                    <td class="text-muted small"><?php echo h($created); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-muted">No horses yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_table_pagination($table); ?>
    <div class="mt-2 text-end"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="horses.php">Clear</a></div>
</form></div>

<div class="card-soft p-3 mt-3">
    <div class="mb-3">
        <div class="small text-muted">Entry form fallback</div>
        <h6 class="mb-1 fw-bold">System entry option</h6>
        <div class="small text-muted">This is not a registered horse record. It is the global option used when an entrant’s horse is not registered in the system.</div>
    </div>
    <?php if ($globalHorse): ?>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="save_global_horse">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Entry selector label</label>
                <input class="form-control" name="global_horse_name" required value="<?php echo h((string)$globalHorse['name']); ?>">
            </div>
            <div class="col-md-auto"><button class="btn btn-success">Save label</button></div>
            <div class="col-12 small text-muted">System record ID <?php echo (int)$globalHorse['id']; ?> · always available globally and excluded from registered-horse lists.</div>
        </form>
    <?php else: ?>
        <div class="alert alert-warning mb-0">The system horse record (ID 1) is missing. Event entries requiring the fallback option may not work.</div>
    <?php endif; ?>
</div>

<?php
admin_layout_end();
