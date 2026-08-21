<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$role = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($role, ['superadmin', 'admin', 'manager'], true)) {
    header('Location: index.php');
    exit;
}
ensureAdvertisingTable($pdo);

$edit = (string)($_GET['edit'] ?? '');
$editId = ctype_digit($edit) ? (int)$edit : 0;
$isEditor = $edit === 'new' || $editId > 0;
$record = $editId > 0 ? fetchAdvertisingById($pdo, $editId) : ($edit === 'new' ? [
    'id' => 0, 'name' => '', 'title' => '', 'image' => '', 'url' => '', 'link_target' => '_blank', 'start_date' => '',
    'finish_date' => '', 'display_order' => 100, 'show_on_web' => 1, 'archived' => 0,
] : null);
if ($editId > 0 && !$record) {
    $alerts[] = ['type' => 'warning', 'message' => 'Advertising item not found.'];
    $isEditor = false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    $id = max(0, (int)($_POST['id'] ?? 0));
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM advertising WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $_SESSION['flash_success'] = 'Advertising item deleted.';
        header('Location: advertising.php');
        exit;
    }
    if ($action === 'archive') {
        $stmt = $pdo->prepare('UPDATE advertising SET archived = 1, show_on_web = 0, updated_at = NOW() WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $_SESSION['flash_success'] = 'Advertising item archived.';
        header('Location: advertising.php');
        exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $linkTarget = (string)($_POST['link_target'] ?? '_blank');
    if (!in_array($linkTarget, ['_self', '_blank'], true)) $linkTarget = '_blank';
    $start = trim((string)($_POST['start_date'] ?? ''));
    $finish = trim((string)($_POST['finish_date'] ?? ''));
    $existing = $id > 0 ? fetchAdvertisingById($pdo, $id) : null;
    $image = trim((string)($existing['image'] ?? ''));
    if ($name === '') $alerts[] = ['type' => 'danger', 'message' => 'Name is required.'];
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid link URL.'];
    if ($start !== '' && $finish !== '' && $finish < $start) $alerts[] = ['type' => 'danger', 'message' => 'Finish date cannot be before start date.'];
    $upload = $_FILES['image'] ?? null;
    if (!$alerts && $upload && (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $uploadError = null;
        $result = image_upload_one($upload, [
            'section' => 'advertising', 'sizes' => ['original' => null, 'md' => 600, 'sm' => 320],
            'rename' => !empty($_POST['rename_image']), 'base_name' => $name,
        ], $uploadError);
        if (!$result) $alerts[] = ['type' => 'danger', 'message' => $uploadError ?: 'Unable to upload image.'];
        else $image = $result['filename'];
    }
    if (!$alerts) {
        $data = [':name' => $name, ':title' => $title ?: null, ':image' => $image ?: null, ':url' => $url ?: null, ':target' => $linkTarget,
            ':start' => $start ?: null, ':finish' => $finish ?: null, ':ord' => (int)($_POST['display_order'] ?? 100),
            ':show' => !empty($_POST['show_on_web']) ? 1 : 0, ':archived' => !empty($_POST['archived']) ? 1 : 0];
        if ($id > 0) {
            $data[':id'] = $id;
            $stmt = $pdo->prepare('UPDATE advertising SET name=:name,title=:title,image=:image,url=:url,link_target=:target,start_date=:start,finish_date=:finish,display_order=:ord,show_on_web=:show,archived=:archived,updated_at=NOW() WHERE id=:id');
        } else {
            $stmt = $pdo->prepare('INSERT INTO advertising (name,title,image,url,link_target,start_date,finish_date,display_order,show_on_web,archived) VALUES (:name,:title,:image,:url,:target,:start,:finish,:ord,:show,:archived)');
        }
        $stmt->execute($data);
        $_SESSION['flash_success'] = 'Advertising item saved.';
        header('Location: advertising.php');
        exit;
    }
    $record = array_merge($record ?: [], $_POST, ['id' => $id, 'image' => $image]);
    $isEditor = true;
}

admin_layout_start($isEditor ? (!empty($record['id']) ? 'Edit Advertising' : 'Add Advertising') : 'Advertising', 'advertising');

if ($isEditor):
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Advertising</div><h5 class="mb-0"><?php echo !empty($record['id']) ? 'Edit item' : 'Add new item'; ?></h5></div>
    <a class="btn btn-outline-secondary" href="advertising.php">Back to list</a>
</div>
<div class="card-soft p-4">
    <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($record['id'] ?? 0); ?>">
        <div class="col-md-6"><label class="form-label fw-semibold">Name</label><input class="form-control" name="name" required value="<?php echo h($record['name'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Title / mouse-over text</label><input class="form-control" name="title" value="<?php echo h($record['title'] ?? ''); ?>"></div>
        <div class="col-md-8"><label class="form-label fw-semibold">Link URL</label><input class="form-control" type="url" name="url" value="<?php echo h($record['url'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Link target</label><select class="form-select" name="link_target"><option value="_self" <?php echo ($record['link_target'] ?? '_blank') === '_self' ? 'selected' : ''; ?>>Same window</option><option value="_blank" <?php echo ($record['link_target'] ?? '_blank') === '_blank' ? 'selected' : ''; ?>>New window / tab</option></select></div>
        <div class="col-md-8"><label class="form-label fw-semibold">Image</label><input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><div class="form-text">Creates original, 600px and 320px renditions.</div></div>
        <div class="col-md-4 d-flex align-items-end pb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="rename_image" value="1" id="rename-image" checked><label class="form-check-label" for="rename-image">Rename from record name</label></div></div>
        <?php if (!empty($record['image'])): ?><div class="col-12"><img class="img-fluid border rounded" style="max-height:180px" src="<?php echo h(image_upload_public_path('advertising', 'md', $record['image'])); ?>" alt=""></div><?php endif; ?>
        <div class="col-md-4"><label class="form-label fw-semibold">Start date</label><input class="form-control" type="date" name="start_date" value="<?php echo h($record['start_date'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Finish date</label><input class="form-control" type="date" name="finish_date" value="<?php echo h($record['finish_date'] ?? ''); ?>"><div class="form-text">Blank means no end date.</div></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Order</label><input class="form-control" type="number" name="display_order" value="<?php echo (int)($record['display_order'] ?? 100); ?>"></div>
        <div class="col-md-3 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="show_on_web" value="1" id="show-web" <?php echo !empty($record['show_on_web']) ? 'checked' : ''; ?>><label class="form-check-label" for="show-web">Show on web</label></div>
        <div class="col-md-3 form-check form-switch"><input class="form-check-input" type="checkbox" name="archived" value="1" id="archived" <?php echo !empty($record['archived']) ? 'checked' : ''; ?>><label class="form-check-label" for="archived">Archived</label></div>
        <div class="col-12 small text-muted">Created: <?php echo h(format_display_datetime($record['created_at'] ?? null, 'Not saved')); ?> · Modified: <?php echo h(format_display_datetime($record['updated_at'] ?? null, 'Not saved')); ?></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-success">Save item</button><a class="btn btn-outline-secondary" href="advertising.php">Cancel</a></div>
    </form>
</div>
<?php else:
    $tableColumns = [
        'order'=>['label'=>'Order','field'=>'display_order','sortable'=>true,'compare'=>'number'],
        'image'=>['label'=>'Image'],
        'name'=>['label'=>'Name','sortable'=>true,'filter'=>'text','placeholder'=>'Search name'],
        'title'=>['label'=>'Title','sortable'=>true,'filter'=>'text','placeholder'=>'Search title'],
        'target'=>['label'=>'Target','filter'=>'select','options'=>['_self'=>'Same window','_blank'=>'New tab'],'value'=>static fn(array $row):string=>(string)($row['link_target']??'_blank')],
        'schedule'=>['label'=>'Schedule','filter'=>'text','placeholder'=>'YYYY-MM-DD','value'=>static fn(array $row):string=>trim((string)($row['start_date']??'').' '.(string)($row['finish_date']??''))],
        'status'=>['label'=>'Status','filter'=>'select','options'=>['visible'=>'Visible','hidden'=>'Hidden','archived'=>'Archived'],'value'=>static fn(array $row):string=>!empty($row['archived'])?'archived':(!empty($row['show_on_web'])?'visible':'hidden')],
        'modified'=>['label'=>'Modified','field'=>'updated_at','sortable'=>true],
        'actions'=>['label'=>'Actions'],
    ];
    $table=admin_table_prepare(fetchAdvertising($pdo),$tableColumns,'order');$items=$table['rows'];$filters=$table['filters'];$sortKey=$table['sort_key'];$sortDir=$table['sort_dir'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Manage scheduled promotions shown beside standard pages.</div><h5 class="mb-0">Advertising</h5></div>
    <a class="btn btn-success" href="advertising.php?edit=new">Add New</a>
</div>
<div class="card-soft p-3"><?php echo admin_table_record_count($table,'item','items'); ?><form method="get" id="advertising-filter-form"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light">
        <tr><?php foreach($tableColumns as$key=>$column): ?><th class="<?php echo $key==='actions'?'text-end':''; ?>"><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th><?php endforeach; ?></tr>
        <tr class="admin-table-filter-row">
            <?php foreach($tableColumns as$key=>$column): ?><th class="<?php echo $key==='actions'?'text-end':''; ?>"><?php if($key==='actions'): ?><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="advertising.php">Clear</a><?php else: echo admin_table_filter($key,$column,$filters); endif; ?></th><?php endforeach; ?>
        </tr>
    </thead>
    <tbody><?php foreach ($items as $item): ?><tr>
        <td><?php echo (int)$item['display_order']; ?></td>
        <td><?php if (!empty($item['image'])): ?><img src="<?php echo h(image_upload_public_path('advertising', 'sm', $item['image'])); ?>" alt="" style="width:90px;max-height:55px;object-fit:contain"><?php else: ?>—<?php endif; ?></td>
        <td class="fw-semibold"><?php echo h($item['name']); ?></td><td class="small"><?php echo h($item['title'] ?: '—'); ?></td><td class="small"><?php echo ($item['link_target'] ?? '_blank') === '_self' ? 'Same window' : 'New tab'; ?></td>
        <td class="small"><?php echo h($item['start_date'] ?: 'Now'); ?> – <?php echo h($item['finish_date'] ?: 'No end date'); ?></td>
        <td><?php echo !empty($item['archived']) ? '<span class="badge bg-secondary">Archived</span>' : (!empty($item['show_on_web']) ? '<span class="badge bg-success">Visible</span>' : '<span class="badge bg-light text-dark border">Hidden</span>'); ?></td>
        <td class="small text-muted"><?php echo h(format_display_datetime($item['updated_at'] ?? null, '—')); ?></td>
        <td class="text-end"><div class="d-flex justify-content-end gap-1"><a class="btn btn-sm btn-outline-secondary" href="advertising.php?edit=<?php echo (int)$item['id']; ?>">Edit</a><?php if (empty($item['archived'])): ?><form method="post"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline-warning">Archive</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Permanently delete this advertising item?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></td>
    </tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="9" class="text-muted">No matching advertising items.</td></tr><?php endif; ?></tbody>
</table></div></form></div>
<?php echo admin_table_pagination($table); ?>
<?php endif; admin_layout_end(); ?>
