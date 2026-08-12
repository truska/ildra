<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin'], true)) {
    header('Location: index.php');
    exit;
}
ensureAssetLibraryTable($pdo);

function library_slug_filename(string $value, string $extension): string
{
    return image_upload_slug(pathinfo($value, PATHINFO_FILENAME)) . '.' . strtolower($extension);
}

function library_store_pdf(array $file, string $filename, ?string &$error): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $error = 'Please select a PDF file.'; return null; }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { $error = 'PDF upload failed (code ' . (int)$file['error'] . ').'; return null; }
    if ((int)($file['size'] ?? 0) > 25 * 1024 * 1024) { $error = 'PDF files must be 25 MB or smaller.'; return null; }
    $tmp = (string)($file['tmp_name'] ?? '');
    $signature = $tmp !== '' ? @file_get_contents($tmp, false, null, 0, 5) : false;
    $mime = $tmp !== '' && function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) : '';
    if ($signature !== '%PDF-' || ($mime !== '' && !in_array($mime, ['application/pdf', 'application/x-pdf'], true))) {
        $error = 'The uploaded file is not a valid PDF.'; return null;
    }
    $dir = dirname(__DIR__) . '/filestore/files/library';
    if (!is_dir($dir) && !mkdir($dir, 02775, true)) { $error = 'Unable to create the PDF library folder.'; return null; }
    $target = $dir . '/' . basename($filename);
    if (!move_uploaded_file($tmp, $target) && !(PHP_SAPI === 'cli' && rename($tmp, $target))) { $error = 'Unable to save the PDF. Check filestore/files permissions.'; return null; }
    @chmod($target, 0664);
    return ['filename' => basename($filename), 'mime_type' => 'application/pdf', 'file_size' => filesize($target) ?: (int)($file['size'] ?? 0), 'available_sizes' => null];
}

$edit = (string)($_GET['edit'] ?? '');
$editId = ctype_digit($edit) ? (int)$edit : 0;
$isEditor = $edit === 'new' || $editId > 0;
$record = $editId ? fetchAssetLibraryById($pdo, $editId) : ($edit === 'new' ? [
    'id' => 0, 'name' => '', 'title' => '', 'description' => '', 'asset_type' => 'pdf', 'category' => '', 'filename' => '',
    'width_lg' => 1200, 'width_md' => 600, 'width_sm' => 300, 'width_xs' => 150,
    'show_in_selectors' => 1, 'display_order' => 100, 'archived' => 0,
] : null);
if ($editId && !$record) { $alerts[] = ['type' => 'warning', 'message' => 'Library item not found.']; $isEditor = false; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    $id = max(0, (int)($_POST['id'] ?? 0));
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM asset_library WHERE id=:id LIMIT 1'); $stmt->execute([':id' => $id]);
        $_SESSION['flash_success'] = 'Library record deleted. Its stored file has been retained to avoid breaking existing links.';
        header('Location: asset_library.php'); exit;
    }
    if ($action === 'archive') {
        $stmt = $pdo->prepare('UPDATE asset_library SET archived=1,show_in_selectors=0,updated_at=NOW() WHERE id=:id'); $stmt->execute([':id' => $id]);
        $_SESSION['flash_success'] = 'Library item archived.'; header('Location: asset_library.php'); exit;
    }

    $existing = $id ? fetchAssetLibraryById($pdo, $id) : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $type = in_array(($_POST['asset_type'] ?? ''), ['pdf', 'image'], true) ? (string)$_POST['asset_type'] : 'pdf';
    if ($name === '') $alerts[] = ['type' => 'danger', 'message' => 'Name is required.'];
    if ($existing && ($existing['asset_type'] ?? '') !== $type) $alerts[] = ['type' => 'danger', 'message' => 'Asset type cannot be changed. Add a new library item instead.'];
    $file = $_FILES['asset_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $hasUpload = (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if (!$existing && !$hasUpload) $alerts[] = ['type' => 'danger', 'message' => 'Please upload a file.'];
    $stored = $existing ?: ['filename' => '', 'mime_type' => null, 'file_size' => null, 'available_sizes' => null];
    $widths = [];
    foreach (['lg' => 1200, 'md' => 600, 'sm' => 300, 'xs' => 150] as $key => $default) {
        $raw = trim((string)($_POST['width_' . $key] ?? $default));
        $widths[$key] = $raw === '' ? null : max(1, (int)$raw);
    }
    if (!$alerts && $hasUpload) {
        $oldFilename = (string)($existing['filename'] ?? '');
        $stem = $oldFilename !== '' ? pathinfo($oldFilename, PATHINFO_FILENAME) : image_upload_slug($name);
        if ($type === 'pdf') {
            $uploadError = null;
            $stored = library_store_pdf($file, library_slug_filename($stem, 'pdf'), $uploadError) ?: $stored;
            if ($uploadError) $alerts[] = ['type' => 'danger', 'message' => $uploadError];
        } else {
            $sizes = ['original' => null]; foreach ($widths as $key => $width) if ($width !== null) $sizes[$key] = $width;
            $uploadError = null;
            $result = image_upload_one($file, ['section' => 'library', 'sizes' => $sizes, 'rename' => true, 'base_name' => $stem, 'overwrite' => true], $uploadError);
            if (!$result) $alerts[] = ['type' => 'danger', 'message' => $uploadError ?: 'Unable to upload image.'];
            else $stored = ['filename' => $result['filename'], 'mime_type' => (string)($file['type'] ?? ''), 'file_size' => (int)($file['size'] ?? 0), 'available_sizes' => implode(',', array_keys($result['sizes']))];
        }
    }
    if (!$alerts) {
        $data = [':name'=>$name, ':title'=>trim((string)($_POST['title'] ?? '')) ?: null, ':description'=>trim((string)($_POST['description'] ?? '')) ?: null,
            ':type'=>$type, ':category'=>trim((string)($_POST['category'] ?? '')) ?: null, ':filename'=>$stored['filename'],
            ':original'=>(string)($file['name'] ?? '') ?: ($existing['original_filename'] ?? null), ':mime'=>$stored['mime_type'], ':bytes'=>$stored['file_size'],
            ':lg'=>$widths['lg'], ':md'=>$widths['md'], ':sm'=>$widths['sm'], ':xs'=>$widths['xs'], ':sizes'=>$stored['available_sizes'],
            ':selectors'=>!empty($_POST['show_in_selectors']) ? 1 : 0, ':ord'=>(int)($_POST['display_order'] ?? 100), ':archived'=>!empty($_POST['archived']) ? 1 : 0];
        if ($id) { $data[':id']=$id; $sql='UPDATE asset_library SET name=:name,title=:title,description=:description,asset_type=:type,category=:category,filename=:filename,original_filename=:original,mime_type=:mime,file_size=:bytes,width_lg=:lg,width_md=:md,width_sm=:sm,width_xs=:xs,available_sizes=:sizes,show_in_selectors=:selectors,display_order=:ord,archived=:archived,updated_at=NOW() WHERE id=:id'; }
        else $sql='INSERT INTO asset_library (name,title,description,asset_type,category,filename,original_filename,mime_type,file_size,width_lg,width_md,width_sm,width_xs,available_sizes,show_in_selectors,display_order,archived) VALUES (:name,:title,:description,:type,:category,:filename,:original,:mime,:bytes,:lg,:md,:sm,:xs,:sizes,:selectors,:ord,:archived)';
        $pdo->prepare($sql)->execute($data); $_SESSION['flash_success'] = 'Library item saved.'; header('Location: asset_library.php'); exit;
    }
    $record = array_merge($record ?: [], $_POST, ['id'=>$id, 'filename'=>$stored['filename']]); $isEditor = true;
}

admin_layout_start($isEditor ? (!empty($record['id']) ? 'Edit Library Item' : 'Add Library Item') : 'Document & Image Library', 'asset_library');
if ($isEditor): ?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Document &amp; Image Library</div><h5 class="mb-0"><?php echo !empty($record['id']) ? 'Edit item' : 'Add new item'; ?></h5></div><a class="btn btn-outline-secondary" href="asset_library.php">Back to list</a></div>
<div class="card-soft p-4"><form method="post" enctype="multipart/form-data" class="row g-3">
<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($record['id'] ?? 0); ?>">
<div class="col-md-6"><label class="form-label fw-semibold">Name</label><input class="form-control" name="name" required value="<?php echo h($record['name'] ?? ''); ?>"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Display title</label><input class="form-control" name="title" value="<?php echo h($record['title'] ?? ''); ?>"></div>
<div class="col-md-4"><label class="form-label fw-semibold">Asset type</label><select class="form-select" name="asset_type" id="asset-type" <?php echo !empty($record['id']) ? 'disabled' : ''; ?>><option value="pdf" <?php echo ($record['asset_type'] ?? '') === 'pdf' ? 'selected' : ''; ?>>PDF document</option><option value="image" <?php echo ($record['asset_type'] ?? '') === 'image' ? 'selected' : ''; ?>>General image</option></select><?php if (!empty($record['id'])): ?><input type="hidden" name="asset_type" value="<?php echo h($record['asset_type']); ?>"><?php endif; ?></div>
<div class="col-md-4"><label class="form-label fw-semibold">Category</label><input class="form-control" name="category" value="<?php echo h($record['category'] ?? ''); ?>" placeholder="Rules, Forms, General..."></div>
<div class="col-md-4"><label class="form-label fw-semibold">Order</label><input class="form-control" type="number" name="display_order" value="<?php echo (int)($record['display_order'] ?? 100); ?>"></div>
<div class="col-12"><label class="form-label fw-semibold">Description</label><textarea class="form-control" name="description" rows="2"><?php echo h($record['description'] ?? ''); ?></textarea></div>
<div class="col-12"><label class="form-label fw-semibold"><?php echo !empty($record['id']) ? 'Replacement file (optional)' : 'File'; ?></label><input class="form-control" type="file" name="asset_file" id="asset-file" <?php echo empty($record['id']) ? 'required' : ''; ?>><div class="form-text">A replacement overwrites the current asset using the same filename. PDFs: maximum 25 MB. Images: JPG, PNG, GIF or WebP.</div></div>
<div class="col-12 image-widths"><div class="fw-semibold mb-1">Image rendition widths</div><div class="small text-muted mb-2">Leave a width blank to skip that size. Original is always retained.</div><div class="row g-2"><?php foreach (['lg'=>1200,'md'=>600,'sm'=>300,'xs'=>150] as $key=>$default): ?><div class="col-6 col-md-3"><label class="form-label text-uppercase"><?php echo $key; ?></label><input class="form-control" type="number" min="1" name="width_<?php echo $key; ?>" value="<?php echo h((string)($record['width_'.$key] ?? $default)); ?>"></div><?php endforeach; ?></div></div>
<?php if (!empty($record['filename'])): ?><div class="col-12"><label class="form-label fw-semibold">Public URL</label><div class="input-group"><input id="current-url" class="form-control" readonly value="<?php echo h(assetLibraryPublicUrl($record)); ?>"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('current-url').value)">Copy URL</button></div></div><?php endif; ?>
<div class="col-md-3 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="show_in_selectors" value="1" id="selectors" <?php echo !empty($record['show_in_selectors']) ? 'checked' : ''; ?>><label class="form-check-label" for="selectors">Show in CMS selectors</label></div>
<div class="col-md-3 form-check form-switch"><input class="form-check-input" type="checkbox" name="archived" value="1" id="archived" <?php echo !empty($record['archived']) ? 'checked' : ''; ?>><label class="form-check-label" for="archived">Archived</label></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-success">Save item</button><a class="btn btn-outline-secondary" href="asset_library.php">Cancel</a></div></form></div>
<script>(function(){const type=document.getElementById('asset-type'), widths=document.querySelector('.image-widths'), file=document.getElementById('asset-file'); function sync(){const image=type.value==='image'; widths.style.display=image?'block':'none'; file.accept=image?'image/jpeg,image/png,image/gif,image/webp':'application/pdf,.pdf';} type.addEventListener('change',sync);sync();})();</script>
<?php else:
$filters=['name'=>trim((string)($_GET['name']??'')),'category'=>trim((string)($_GET['category']??'')),'type'=>trim((string)($_GET['type']??'')),'status'=>trim((string)($_GET['status']??''))];
$sortKey=(string)($_GET['sort']??'order'); $sortDir=strtolower((string)($_GET['dir']??'asc'))==='desc'?'desc':'asc'; $items=fetchAssetLibrary($pdo);
$items=array_values(array_filter($items,static function($i)use($filters){$status=!empty($i['archived'])?'archived':(!empty($i['show_in_selectors'])?'available':'hidden');return ($filters['name']===''||stripos((string)$i['name'],$filters['name'])!==false)&&($filters['category']===''||stripos((string)($i['category']??''),$filters['category'])!==false)&&($filters['type']===''||$i['asset_type']===$filters['type'])&&($filters['status']===''||$status===$filters['status']);}));
usort($items,static function($a,$b)use($sortKey,$sortDir){$map=['order'=>'display_order','name'=>'name','category'=>'category','type'=>'asset_type','modified'=>'updated_at'];$f=$map[$sortKey]??'display_order';$r=$f==='display_order'?((int)$a[$f]<=>(int)$b[$f]):strcasecmp((string)($a[$f]??''),(string)($b[$f]??''));return $sortDir==='desc'?-$r:$r;}); ?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Reusable PDFs and general site images.</div><h5 class="mb-0">Document &amp; Image Library</h5></div><a class="btn btn-success" href="asset_library.php?edit=new">Add New</a></div>
<div class="card-soft p-3"><form method="get"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th><?php echo admin_sort_link('order','Order',$sortKey,$sortDir); ?></th><th><?php echo admin_sort_link('name','Name',$sortKey,$sortDir); ?></th><th><?php echo admin_sort_link('type','Type',$sortKey,$sortDir); ?></th><th><?php echo admin_sort_link('category','Category',$sortKey,$sortDir); ?></th><th>URL</th><th>Status</th><th><?php echo admin_sort_link('modified','Modified',$sortKey,$sortDir); ?></th><th class="text-end">Actions</th></tr>
<tr class="admin-table-filter-row"><th></th><th><input class="form-control form-control-sm" name="name" value="<?php echo h($filters['name']); ?>" placeholder="Search"></th><th><select class="form-select form-select-sm" name="type"><option value="">All</option><option value="pdf" <?php echo $filters['type']==='pdf'?'selected':''; ?>>PDF</option><option value="image" <?php echo $filters['type']==='image'?'selected':''; ?>>Image</option></select></th><th><input class="form-control form-control-sm" name="category" value="<?php echo h($filters['category']); ?>"></th><th></th><th><select class="form-select form-select-sm" name="status"><option value="">All</option><?php foreach(['available'=>'Available','hidden'=>'Hidden','archived'=>'Archived'] as $v=>$l): ?><option value="<?php echo $v; ?>" <?php echo $filters['status']===$v?'selected':''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></th><th></th><th class="text-end"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="asset_library.php">Clear</a></th></tr></thead>
<tbody><?php foreach($items as $item): $url=assetLibraryPublicUrl($item); ?><tr><td><?php echo (int)$item['display_order']; ?></td><td><div class="fw-semibold"><?php echo h($item['name']); ?></div><div class="small text-muted"><?php echo h($item['title']??''); ?></div></td><td><span class="badge bg-light text-dark border"><?php echo $item['asset_type']==='pdf'?'PDF':'Image'; ?></span></td><td><?php echo h($item['category']?:'—'); ?></td><td style="min-width:240px"><div class="input-group input-group-sm"><input class="form-control" readonly value="<?php echo h($url); ?>"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy</button></div></td><td><?php echo !empty($item['archived'])?'<span class="badge bg-secondary">Archived</span>':(!empty($item['show_in_selectors'])?'<span class="badge bg-success">Available</span>':'<span class="badge bg-light text-dark border">Hidden</span>'); ?></td><td class="small text-muted"><?php echo h(format_display_datetime($item['updated_at']??null,'—')); ?></td><td class="text-end"><div class="d-flex justify-content-end gap-1"><a class="btn btn-sm btn-outline-secondary" href="asset_library.php?edit=<?php echo (int)$item['id']; ?>">Edit</a><?php if(empty($item['archived'])): ?><form method="post"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline-warning">Archive</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this library record? The file will be retained.');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></td></tr><?php endforeach; ?><?php if(!$items): ?><tr><td colspan="8" class="text-muted">No matching library items.</td></tr><?php endif; ?></tbody></table></div></form></div>
<?php endif; admin_layout_end();
