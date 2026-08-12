<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin','admin'], true)) { header('Location: index.php'); exit; }
$batch = mediaBatchGetOrCreate($pdo, 'site_header', 'site', 0, 'Site header banners', 'banners');
if (!$batch) { $alerts[]=['type'=>'danger','message'=>'Database unavailable.']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $batch) {
    $action = (string)($_POST['action'] ?? 'upload');
    if ($action === 'upload') {
        $errors=[]; $count=mediaBatchUpload($pdo,$batch,$_FILES['images']??[],['originals'=>null,'lg'=>1200,'md'=>600,'sm'=>300,'xs'=>150],$errors);
        foreach($errors as $error) $alerts[]=['type'=>'danger','message'=>$error];
        if ($count) { $_SESSION['flash_success']=$count . ($count===1?' image':' images') . ' uploaded.'; header('Location: banner_images.php'); exit; }
    } elseif ($action === 'save_images') {
        $allowedIds=array_column(mediaBatchImages($pdo,(int)$batch['id'],true),null,'id');
        foreach((array)($_POST['image']??[]) as $id=>$values) {
            $id=(int)$id; if(!isset($allowedIds[$id])) continue;
            $stmt=$pdo->prepare('UPDATE media_batch_images SET title=:title,alt_text=:alt,caption=:caption,display_order=:ord,archived=:archived,updated_at=NOW() WHERE id=:id AND batch_id=:batch');
            $stmt->execute([':title'=>trim((string)($values['title']??''))?:null,':alt'=>trim((string)($values['alt_text']??''))?:null,':caption'=>trim((string)($values['caption']??''))?:null,':ord'=>(int)($values['display_order']??100),':archived'=>!empty($values['archived'])?1:0,':id'=>$id,':batch'=>$batch['id']]);
        }
        $_SESSION['flash_success']='Banner images updated.'; header('Location: banner_images.php'); exit;
    }
}
$images=$batch?mediaBatchImages($pdo,(int)$batch['id'],true):[];
admin_layout_start('Banner Images','hero');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Site Hero &amp; Welcome</div><h5 class="mb-0">Banner Images</h5></div><a class="btn btn-outline-secondary" href="hero.php">Back to Hero &amp; Welcome</a></div>
<div class="card-soft p-4 mb-4"><h6>Upload images</h6><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload">
<label class="form-label">Select one or several images</label>
<label id="banner-drop-zone" class="d-block border border-2 border-secondary-subtle rounded-3 p-4 text-center bg-light" style="cursor:pointer;transition:border-color .15s,background-color .15s">
    <input class="visually-hidden" id="banner-images" type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
    <span class="d-block fs-5 fw-semibold">Drop images here</span>
    <span class="d-block text-muted">or click to browse</span>
    <span id="banner-file-summary" class="d-block small mt-2 text-success"></span>
</label>
<div class="form-text">Creates original, 1200px, 600px, 300px and 150px versions. New images are added to the end of this batch.</div><button class="btn btn-success mt-3">Upload Images</button></form></div>
<div class="card-soft p-3"><form method="post"><input type="hidden" name="action" value="save_images"><div class="small text-muted mb-3">The first active image by order is used as the site header banner. Change order values to select or rearrange images.</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Preview</th><th style="width:90px">Order</th><th>Title / alt text / caption</th><th>State</th></tr></thead><tbody>
<?php foreach($images as $index=>$image): ?><tr><td style="width:220px"><img src="<?php echo h(mediaBatchImageUrl($batch,$image,'sm')); ?>" alt="" style="width:200px;max-height:100px;object-fit:cover" class="rounded border"><?php if($index===0&&!$image['archived']): ?><div><span class="badge bg-success mt-1">Current banner</span></div><?php endif; ?></td><td><input class="form-control" type="number" name="image[<?php echo (int)$image['id']; ?>][display_order]" value="<?php echo (int)$image['display_order']; ?>"></td><td><input class="form-control form-control-sm mb-1" name="image[<?php echo (int)$image['id']; ?>][title]" value="<?php echo h($image['title']??''); ?>" placeholder="Title"><input class="form-control form-control-sm mb-1" name="image[<?php echo (int)$image['id']; ?>][alt_text]" value="<?php echo h($image['alt_text']??''); ?>" placeholder="Alternative text"><textarea class="form-control form-control-sm" name="image[<?php echo (int)$image['id']; ?>][caption]" rows="2" placeholder="Caption"><?php echo h($image['caption']??''); ?></textarea></td><td><div class="form-check"><input class="form-check-input" type="checkbox" name="image[<?php echo (int)$image['id']; ?>][archived]" value="1" id="arch-<?php echo (int)$image['id']; ?>" <?php echo !empty($image['archived'])?'checked':''; ?>><label class="form-check-label" for="arch-<?php echo (int)$image['id']; ?>">Archived</label></div></td></tr><?php endforeach; ?>
<?php if(!$images): ?><tr><td colspan="4" class="text-muted">No uploaded banner images yet. The existing default banner remains in use.</td></tr><?php endif; ?></tbody></table></div><?php if($images): ?><button class="btn btn-success">Save Images</button><?php endif; ?></form></div>
<script>
(function () {
    const zone = document.getElementById('banner-drop-zone');
    const input = document.getElementById('banner-images');
    const summary = document.getElementById('banner-file-summary');
    if (!zone || !input || !summary) return;
    function showFiles() {
        const files = Array.from(input.files || []);
        summary.textContent = files.length ? files.length + (files.length === 1 ? ' image selected: ' : ' images selected: ') + files.map(file => file.name).join(', ') : '';
    }
    ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, function (event) {
        event.preventDefault();
        zone.classList.add('border-success', 'bg-success-subtle');
    }));
    ['dragleave', 'drop'].forEach(type => zone.addEventListener(type, function (event) {
        event.preventDefault();
        zone.classList.remove('border-success', 'bg-success-subtle');
    }));
    zone.addEventListener('drop', function (event) {
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        input.files = event.dataTransfer.files;
        showFiles();
    });
    input.addEventListener('change', showFiles);
})();
</script>
<?php admin_layout_end();
