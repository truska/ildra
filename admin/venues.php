<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

$editParam = $_GET['edit'] ?? null;
$editId = is_numeric($editParam ?? '') ? (int)$editParam : 0;
$isCreate = $editParam === 'new';
$editVenue = $editId ? fetchVenueById($pdo, $editId) : ($isCreate ? [] : null);
$showEditor = $isCreate || $editVenue !== null || $_SERVER['REQUEST_METHOD'] === 'POST';
$isListView = !$showEditor;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage venues.'];
    } else {
        $action = $_POST['action'] ?? 'save_venue';
        if ($action === 'delete_venue') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && deleteVenue($pdo, $id, $alerts)) {
                $_SESSION['flash_success'] = 'Venue deleted.';
                header('Location: venues.php');
                exit;
            }
        } else {
            $savedId = saveVenue($pdo, $_POST, $alerts);
            if ($savedId) {
                $_SESSION['flash_success'] = 'Venue saved.';
                header('Location: venues.php');
                exit;
            }
        }
    }
}

if ($editId > 0 && $editVenue === null) {
    $alerts[] = ['type' => 'warning', 'message' => 'Venue not found.'];
    $isListView = true;
    $showEditor = false;
}

$venues = fetchVenues($pdo);
$venueCount = count($venues);
$filterForm='venue-filter-form';
$tableColumns=[
    'name'=>['label'=>'Name','sortable'=>true,'filter'=>'text','placeholder'=>'Search name','form'=>$filterForm],
    'address'=>['label'=>'Address','sortable'=>true,'filter'=>'text','placeholder'=>'Search address','form'=>$filterForm],
    'postcode'=>['label'=>'Postcode','sortable'=>true,'filter'=>'text','placeholder'=>'Search postcode','form'=>$filterForm,'data_type'=>'postcode_map'],
    'updated'=>['label'=>'Updated','sortable'=>true,'filter'=>'text','placeholder'=>'Search updated','form'=>$filterForm,'value'=>static fn(array $r):string=>format_display_datetime($r['updated_at']??$r['created_at']??null,''),'sort_value'=>static fn(array $r):string=>(string)($r['updated_at']??$r['created_at']??'')],
    'actions'=>['label'=>'Actions'],
];
$table=admin_table_prepare($venues,$tableColumns,'name');$venues=$table['rows'];$filters=$table['filters'];$sortKey=$table['sort_key'];$sortDir=$table['sort_dir'];

admin_layout_start('Venues', 'venues');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted"><?php echo $isListView ? 'Manage venues for event setup.' : (($editVenue && !empty($editVenue['id'])) ? 'Edit venue' : 'Add venue'); ?></div>
        <h5 class="mb-0">Venues</h5>
    </div>
    <div class="d-flex gap-2">
        <?php if ($isListView): ?>
            <a class="btn btn-success" href="venues.php?edit=new">Add venue</a>
            <a class="btn btn-outline-secondary" href="events.php">Back to events</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="venues.php">Back to list</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isListView): ?>
    <form method="get" id="venue-filter-form" class="mb-2 text-end"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="venues.php">Clear</a></form>
    <div class="card-soft p-3">
        <?php echo admin_table_record_count($table,'venue','venues'); ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">Saved venues</div>
            <span class="badge bg-secondary"><?php echo (int)$venueCount; ?> total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <?php foreach($tableColumns as$key=>$column): ?><th class="<?php echo $key==='actions'?'text-end':''; ?>"><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th><?php endforeach; ?>
                    </tr>
                    <tr class="admin-table-filter-row"><?php foreach($tableColumns as$key=>$column): ?><th><?php echo admin_table_filter($key,$column,$filters); ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($venues as $venue): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo h((string)($venue['name'] ?? '')); ?></td>
                            <td class="text-muted small"><?php echo h((string)($venue['address'] ?? '—')); ?></td>
                            <td class="text-muted small"><?php echo admin_table_value($venue['postcode'] ?? '', 'postcode_map'); ?></td>
                            <td class="text-muted small">
                                <?php echo h(format_display_datetime($venue['updated_at'] ?? $venue['created_at'] ?? '', '—')); ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="venues.php?edit=<?php echo (int)($venue['id'] ?? 0); ?>">Edit</a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this venue?');">
                                    <input type="hidden" name="action" value="delete_venue">
                                    <input type="hidden" name="id" value="<?php echo h((string)($venue['id'] ?? 0)); ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$venues): ?>
                        <tr><td colspan="5" class="text-muted">No venues yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo admin_table_pagination($table); ?>
    </div>
<?php else: ?>
    <?php $v = $editVenue ?? []; ?>
    <div class="card-soft p-4">
        <form method="POST">
            <input type="hidden" name="action" value="save_venue">
            <input type="hidden" name="id" value="<?php echo h((string)($v['id'] ?? 0)); ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Venue name</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo h((string)($v['name'] ?? '')); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Postcode</label>
                    <input type="text" name="postcode" class="form-control" value="<?php echo h((string)($v['postcode'] ?? '')); ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" value="<?php echo h((string)($v['address'] ?? '')); ?>">
                    <div class="small text-muted mt-1">Optional. Shown for admins.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Google Maps URL</label>
                    <input type="url" name="google_url" class="form-control" value="<?php echo h((string)($v['google_url'] ?? '')); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Directions</label>
                    <textarea name="directions" rows="3" class="form-control"><?php echo h((string)($v['directions'] ?? '')); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" rows="3" class="form-control"><?php echo h((string)($v['notes'] ?? '')); ?></textarea>
                    <div class="small text-muted mt-1">Internal notes or description for this venue.</div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-success" type="submit">Save venue</button>
                <a class="btn btn-outline-secondary" href="venues.php">Cancel</a>
            </div>
        </form>
        <?php if (!empty($v['id'])): ?>
            <form method="POST" class="mt-3 text-end" onsubmit="return confirm('Delete this venue?');">
                <input type="hidden" name="action" value="delete_venue">
                <input type="hidden" name="id" value="<?php echo h((string)($v['id'] ?? 0)); ?>">
                <button class="btn btn-outline-danger" type="submit">Delete</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
admin_layout_end();
