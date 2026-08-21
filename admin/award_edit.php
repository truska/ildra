<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager'], true)) {
    header('Location:index.php');
    exit;
}

ensureAwardsTables($pdo);
$id = (int)($_GET['id'] ?? 0);
$award = null;
foreach (fetchAwards($pdo) as $row) {
    if ((int)$row['id'] === $id) {
        $award = $row;
        break;
    }
}
$award = $award ?: [
    'id' => 0,
    'name' => '',
    'description_html' => '',
    'image_asset_id' => 0,
    'legacy_image_filename' => '',
    'display_order' => 100,
    'is_published' => 1,
    'is_archived' => 0,
];
$assets = fetchAssetLibrary($pdo, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $legacyImageFilename = trim((string)($award['legacy_image_filename'] ?? ''));
    if ($name === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Award name is required.'];
    }

    $upload = $_FILES['award_image'] ?? null;
    if (!$alerts && $upload && (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $uploadError = null;
        $result = image_upload_one($upload, [
            'section' => 'awards',
            'sizes' => ['original' => null, 'lg' => 1200, 'md' => 600, 'sm' => 300, 'xs' => 150],
            'rename' => true,
            'base_name' => $name,
        ], $uploadError);
        if (!$result) {
            $alerts[] = ['type' => 'danger', 'message' => $uploadError ?: 'Unable to upload award image.'];
        } else {
            $legacyImageFilename = (string)$result['filename'];
        }
    }

    if (!$alerts) {
        $data = [
            ':name' => $name,
            ':description' => trim((string)($_POST['description_html'] ?? '')) ?: null,
            ':asset' => (int)($_POST['image_asset_id'] ?? 0) ?: null,
            ':legacy_image' => $legacyImageFilename ?: null,
            ':order' => (int)($_POST['display_order'] ?? 100),
            ':published' => !empty($_POST['is_published']) ? 1 : 0,
            ':archived' => !empty($_POST['is_archived']) ? 1 : 0,
        ];
        if ($id) {
            $data[':id'] = $id;
            $sql = 'UPDATE award_catalog SET name=:name,description_html=:description,image_asset_id=:asset,legacy_image_filename=:legacy_image,display_order=:order,is_published=:published,is_archived=:archived WHERE id=:id';
        } else {
            $sql = 'INSERT INTO award_catalog(name,description_html,image_asset_id,legacy_image_filename,display_order,is_published,is_archived) VALUES(:name,:description,:asset,:legacy_image,:order,:published,:archived)';
        }
        $pdo->prepare($sql)->execute($data);
        $_SESSION['flash_success'] = 'Award saved.';
        header('Location:awards.php');
        exit;
    }
    $award = array_merge($award, $_POST, ['legacy_image_filename' => $legacyImageFilename]);
}

admin_layout_start($id ? 'Edit Award' : 'Add Award', 'awards');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Awards</div><h5 class="mb-0"><?php echo $id ? 'Edit award' : 'Add award'; ?></h5></div>
    <a class="btn btn-outline-secondary" href="awards.php">Back to Awards</a>
</div>

<div class="card-soft p-4">
    <form method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-6"><label class="form-label">Award name</label><input class="form-control" name="name" required value="<?php echo h($award['name']); ?>"></div>
        <div class="col-md-3"><label class="form-label">Order</label><input class="form-control" type="number" name="display_order" value="<?php echo (int)$award['display_order']; ?>"></div>
        <div class="col-md-3"><label class="form-label">Library image (optional)</label><select class="form-select" name="image_asset_id"><option value="0">Holding image</option><?php foreach ($assets as $asset): if (($asset['asset_type'] ?? '') !== 'image') continue; ?><option value="<?php echo (int)$asset['id']; ?>" <?php echo (int)$award['image_asset_id'] === (int)$asset['id'] ? 'selected' : ''; ?>><?php echo h($asset['title'] ?: $asset['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><label class="form-label">Award image upload</label><input class="form-control" type="file" name="award_image" accept="image/jpeg,image/png,image/gif,image/webp"><div class="form-text">Stores the original plus 1200px, 600px, 300px and 150px versions in the Awards image folder. Public award-image display will be added separately.</div></div>
        <div class="col-md-4"><label class="form-label">Current uploaded image</label><?php if (!empty($award['legacy_image_filename'])): ?><a class="d-block" href="<?php echo h(image_upload_public_path('awards', 'original', (string)$award['legacy_image_filename'])); ?>" target="_blank" rel="noopener"><img class="img-fluid border rounded" style="max-height:120px" src="<?php echo h(image_upload_public_path('awards', 'sm', (string)$award['legacy_image_filename'])); ?>" alt="Current award image"></a><?php else: ?><div class="form-control bg-light text-muted">No direct upload yet</div><?php endif; ?></div>
        <div class="col-12"><label class="form-label">Description</label><textarea class="form-control wysiwyg-field" name="description_html" rows="10"><?php echo h($award['description_html']); ?></textarea></div>
        <div class="col-12"><label class="me-3"><input type="checkbox" name="is_published" <?php echo !empty($award['is_published']) ? 'checked' : ''; ?>> Published</label><label><input type="checkbox" name="is_archived" <?php echo !empty($award['is_archived']) ? 'checked' : ''; ?>> Archived</label></div>
        <div><button class="btn btn-success">Save award</button> <a class="btn btn-outline-secondary" href="awards.php">Cancel</a></div>
    </form>
</div>
<?php render_tinymce_bootstrap(); ?>
<script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end();
