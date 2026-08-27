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
if (empty($_SESSION['advertising_list_csrf'])) $_SESSION['advertising_list_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['advertising_list_csrf'];

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
    if ($action === 'reorder') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $_SESSION['flash_alerts'] = [['type'=>'danger','message'=>'Your session token expired. Please try again.']];
        } else {
            $orderedIds=array_values(array_map('intval',(array)($_POST['item_order']??[])));$expectedIds=array_map(static fn(array $item):int=>(int)$item['id'],fetchAdvertising($pdo));$validIds=$orderedIds;sort($validIds);sort($expectedIds);
            if(count($orderedIds)!==count(array_unique($orderedIds))||$validIds!==$expectedIds)$_SESSION['flash_alerts']=[['type'=>'danger','message'=>'The advertising list changed before the new order was saved. Please try again.']];
            else try{$pdo->beginTransaction();$update=$pdo->prepare('UPDATE advertising SET display_order=:display_order WHERE id=:id');foreach($orderedIds as$index=>$itemId)$update->execute([':display_order'=>($index+1)*10,':id'=>$itemId]);$pdo->commit();$_SESSION['flash_success']='Advertising order saved.';}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['flash_alerts']=[['type'=>'danger','message'=>'Could not save the advertising order.']];}
        }
        header('Location: advertising.php');exit;
    }
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
    $filterForm='advertising-filter-form';
    $tableColumns = [
        'image'=>['label'=>'Image'],
        'name'=>['label'=>'Name','sortable'=>true,'filter'=>'text','placeholder'=>'Search name','form'=>$filterForm],
        'title'=>['label'=>'Title','sortable'=>true,'filter'=>'text','placeholder'=>'Search title','form'=>$filterForm],
        'target'=>['label'=>'Target','filter'=>'select','options'=>['_self'=>'Same window','_blank'=>'New tab'],'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['link_target']??'_blank')],
        'schedule'=>['label'=>'Schedule','filter'=>'text','placeholder'=>'YYYY-MM-DD','form'=>$filterForm,'value'=>static fn(array $row):string=>trim((string)($row['start_date']??'').' '.(string)($row['finish_date']??''))],
        'status'=>['label'=>'Status','filter'=>'select','options'=>['visible'=>'Visible','hidden'=>'Hidden','archived'=>'Archived'],'form'=>$filterForm,'value'=>static fn(array $row):string=>!empty($row['archived'])?'archived':(!empty($row['show_on_web'])?'visible':'hidden')],
        'order'=>['label'=>'Order','field'=>'display_order','sortable'=>true,'filter'=>'text','compare'=>'number','placeholder'=>'Order','form'=>$filterForm],
        'actions'=>['label'=>'Actions'],
    ];
    $allItems=fetchAdvertising($pdo);$table=admin_table_prepare($allItems,$tableColumns,'order');$items=$table['rows'];$filters=$table['filters'];$sortKey=$table['sort_key'];$sortDir=$table['sort_dir'];$hasFilters=count(array_filter($filters,static fn(string $value):bool=>$value!==''))>0;$canReorder=!$hasFilters&&$sortKey==='order'&&$sortDir==='asc'&&count($items)===count($allItems);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Manage scheduled promotions shown beside standard pages.</div><h5 class="mb-0">Advertising</h5></div>
    <a class="btn btn-success" href="advertising.php?edit=new">Add New</a>
</div>
<form method="get" id="advertising-filter-form"></form><div class="card-soft p-3"><?php echo admin_table_record_count($table,'item','items'); ?>
<?php if($items&&$canReorder):?><div class="d-none d-md-flex flex-wrap align-items-center gap-2 mb-3" id="advertising-reorder-toolbar"><button class="btn btn-sm btn-outline-success" type="button" id="advertising-reorder-start"><i class="fa-solid fa-arrow-down-up-across-line me-1"></i>Reorder</button><form method="post" class="d-none align-items-center gap-2 m-0" id="advertising-reorder-form"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="action" value="reorder"><span class="small text-muted">Drag the handles, then save.</span><button class="btn btn-sm btn-success">Save order</button><button class="btn btn-sm btn-outline-secondary" type="button" id="advertising-reorder-cancel">Cancel</button></form></div><?php elseif($items):?><div class="d-none d-md-flex mb-3"><a class="btn btn-sm btn-outline-success" href="advertising.php?sort=order&amp;dir=asc&amp;per_page=500"><i class="fa-solid fa-arrow-down-up-across-line me-1"></i>Reorder <span class="fw-normal">(reset before reordering)</span></a></div><?php endif;?>
<div class="table-responsive"><table class="table table-sm align-middle mb-0" id="advertising-order-table">
    <thead class="table-light">
        <tr><th class="d-none d-md-table-cell advertising-drag-column"></th><?php foreach($tableColumns as$key=>$column): ?><th class="<?php echo $key==='actions'?'text-end':''; ?>"><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th><?php endforeach; ?></tr>
        <tr class="admin-table-filter-row">
            <th class="d-none d-md-table-cell advertising-drag-column"></th><?php foreach($tableColumns as$key=>$column): ?><th class="<?php echo $key==='actions'?'text-end':''; ?>"><?php if($key==='actions'): ?><button class="btn btn-sm btn-outline-secondary" form="advertising-filter-form">Filter</button> <a class="btn btn-sm btn-link" href="advertising.php">Clear</a><?php else: echo admin_table_filter($key,$column,$filters); endif; ?></th><?php endforeach; ?>
        </tr>
    </thead>
    <tbody id="advertising-order-rows"><?php foreach ($items as $item): ?><tr data-item-id="<?php echo (int)$item['id']; ?>">
        <td class="d-none d-md-table-cell advertising-drag-column"><button class="advertising-drag-handle" type="button" disabled aria-label="Move <?php echo h($item['name']); ?>"><i class="fa-solid fa-grip-vertical"></i></button></td>
        <td><?php if (!empty($item['image'])): ?><img src="<?php echo h(image_upload_public_path('advertising', 'sm', $item['image'])); ?>" alt="" style="width:90px;max-height:55px;object-fit:contain"><?php else: ?>—<?php endif; ?></td>
        <td class="fw-semibold"><?php echo h($item['name']); ?></td><td class="small"><?php echo h($item['title'] ?: '—'); ?></td><td class="small"><?php echo ($item['link_target'] ?? '_blank') === '_self' ? 'Same window' : 'New tab'; ?></td>
        <td class="small"><?php echo h($item['start_date'] ?: 'Now'); ?> – <?php echo h($item['finish_date'] ?: 'No end date'); ?></td>
        <td><?php echo !empty($item['archived']) ? '<span class="badge bg-secondary">Archived</span>' : (!empty($item['show_on_web']) ? '<span class="badge bg-success">Visible</span>' : '<span class="badge bg-light text-dark border">Hidden</span>'); ?></td>
        <td><?php echo (int)$item['display_order']; ?></td>
        <td class="text-end"><div class="d-flex justify-content-end gap-1"><a class="btn btn-sm btn-outline-secondary" href="advertising.php?edit=<?php echo (int)$item['id']; ?>">Edit</a><?php if (empty($item['archived'])): ?><form method="post"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline-warning">Archive</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Permanently delete this advertising item?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></td>
    </tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="9" class="text-muted">No matching advertising items.</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php echo admin_table_pagination($table); ?>
<style>.advertising-drag-column{width:42px}.advertising-drag-handle{border:0;background:transparent;color:#98a098;padding:.35rem .55rem;border-radius:6px}.advertising-drag-handle:not(:disabled){color:#146118;cursor:grab;touch-action:none}.advertising-drag-handle:not(:disabled):active{cursor:grabbing}.advertising-reorder-active tr[data-item-id]{background:#f5faf3}.advertising-row-dragging{opacity:.55;box-shadow:0 4px 14px rgba(15,45,23,.16)}@media(max-width:767.98px){.advertising-drag-column,#advertising-reorder-toolbar{display:none!important}}</style>
<?php if($items&&$canReorder):?><script>(()=>{if(window.innerWidth<768)return;const table=document.getElementById('advertising-order-table'),rows=document.getElementById('advertising-order-rows'),start=document.getElementById('advertising-reorder-start'),form=document.getElementById('advertising-reorder-form'),cancel=document.getElementById('advertising-reorder-cancel');if(!table||!rows||!start||!form||!cancel)return;const handles=[...rows.querySelectorAll('.advertising-drag-handle')];let dragged=null,original=[];const finish=()=>{if(!dragged)return;dragged.classList.remove('advertising-row-dragging');dragged=null};start.addEventListener('click',()=>{original=[...rows.querySelectorAll('tr[data-item-id]')].map(row=>row.dataset.itemId);table.classList.add('advertising-reorder-active');handles.forEach(handle=>handle.disabled=false);start.classList.add('d-none');form.classList.remove('d-none');form.classList.add('d-flex')});cancel.addEventListener('click',()=>{const byId=new Map([...rows.querySelectorAll('tr[data-item-id]')].map(row=>[row.dataset.itemId,row]));original.forEach(id=>rows.appendChild(byId.get(id)));finish();handles.forEach(handle=>handle.disabled=true);table.classList.remove('advertising-reorder-active');form.classList.add('d-none');form.classList.remove('d-flex');start.classList.remove('d-none')});handles.forEach(handle=>{handle.addEventListener('pointerdown',event=>{if(handle.disabled)return;dragged=handle.closest('tr[data-item-id]');dragged.classList.add('advertising-row-dragging');handle.setPointerCapture(event.pointerId);event.preventDefault()});handle.addEventListener('pointermove',event=>{if(!dragged)return;const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('tr[data-item-id]');if(!target||target===dragged||target.parentElement!==rows)return;const rect=target.getBoundingClientRect();rows.insertBefore(dragged,event.clientY<rect.top+rect.height/2?target:target.nextSibling)});handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish)});form.addEventListener('submit',()=>{rows.querySelectorAll('tr[data-item-id]').forEach(row=>{const input=document.createElement('input');input.type='hidden';input.name='item_order[]';input.value=row.dataset.itemId;form.appendChild(input)})})})();</script><?php endif;?>
<?php endif; admin_layout_end(); ?>
