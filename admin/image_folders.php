<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$role = strtolower((string)($currentUser['role'] ?? ''));
if ($role !== 'superadmin') {
    header('Location: index.php');
    exit;
}

$sizes = ['original', 'lg', 'md', 'sm', 'xs'];
$storageRoots = [
    'images' => dirname(__DIR__) . '/filestore/images',
    'files' => dirname(__DIR__) . '/filestore/files',
];
$createdPaths = [];

function storageFolderIsWritable(string $path): bool
{
    if (!is_dir($path) || !is_writable($path)) {
        return false;
    }
    $probe = @tempnam($path, '.write-test-');
    if ($probe === false) {
        return false;
    }
    $written = @file_put_contents($probe, 'ok') !== false;
    @unlink($probe);
    return $written;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $requestedName = trim((string)($_POST['folder_name'] ?? ''));
    $folderName = image_upload_section($requestedName);
    $storageType = (string)($_POST['storage_type'] ?? 'images');
    $storageRoot = $storageRoots[$storageType] ?? null;
    $sectionPath = $storageRoot === null ? null : $storageRoot . '/' . $folderName;

    if ($requestedName === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a folder name.'];
    } elseif ($folderName === 'image' && strtolower($requestedName) !== 'image') {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a folder name containing letters or numbers.'];
    } elseif ($storageRoot === null) {
        $alerts[] = ['type' => 'danger', 'message' => 'The selected filestore root is not writable by the CMS.'];
    } elseif (is_dir($sectionPath) && !storageFolderIsWritable($sectionPath)) {
        $alerts[] = ['type' => 'danger', 'message' => 'The existing “' . $folderName . '” folder is not writable by the CMS.'];
    } elseif (!is_dir($sectionPath) && !storageFolderIsWritable($storageRoot)) {
        $alerts[] = ['type' => 'danger', 'message' => 'The selected filestore root is not writable by the CMS.'];
    } else {
        $paths = $storageType === 'images'
            ? array_merge([$sectionPath], array_map(static fn(string $size): string => $sectionPath . '/' . $size, $sizes))
            : [$sectionPath];
        foreach ($paths as $path) {
            if (!is_dir($path) && !mkdir($path, 02775, true)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Could not create ' . basename($path) . '.'];
                break;
            }
            @chmod($path, 02775);
            if (!storageFolderIsWritable($path)) {
                $alerts[] = ['type' => 'danger', 'message' => basename($path) . ' exists but failed the CMS write test.'];
                break;
            }
            $createdPaths[] = str_replace(dirname(__DIR__) . '/', '', $path);
        }
        if (!$alerts) {
            $_SESSION['flash_success'] = ucfirst($storageType) . ' structure created for “' . $folderName . '” and passed the CMS write test.';
            header('Location: image_folders.php?folder=' . rawurlencode($folderName) . '&storage=' . rawurlencode($storageType));
            exit;
        }
    }
}

$currentStorageType = (string)($_GET['storage'] ?? 'images');
if (!array_key_exists($currentStorageType, $storageRoots)) {
    $currentStorageType = 'images';
}
$storageRoot = $storageRoots[$currentStorageType];
$currentFolder = image_upload_section((string)($_GET['folder'] ?? ''));
$sections = [];
foreach (glob($storageRoot . '/*', GLOB_ONLYDIR) ?: [] as $sectionPath) {
    $available = [];
    if ($currentStorageType === 'images') {
        foreach ($sizes as $size) {
            if (is_dir($sectionPath . '/' . $size)) $available[] = $size;
        }
    }
    $sections[] = ['name' => basename($sectionPath), 'sizes' => $available, 'writable' => storageFolderIsWritable($sectionPath)];
}

admin_layout_start('Storage Folders', 'image_folders');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Superadmin technical utility</div><h5 class="mb-0">Create storage structure</h5></div>
    <a class="btn btn-outline-secondary" href="tech.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Tech</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-soft p-4">
            <form method="post" class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold" for="storage-type">Storage type</label>
                    <select class="form-select" id="storage-type" name="storage_type">
                        <option value="images" <?php echo $currentStorageType === 'images' ? 'selected' : ''; ?>>Images</option>
                        <option value="files" <?php echo $currentStorageType === 'files' ? 'selected' : ''; ?>>Files</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="folder-name">Folder name</label>
                    <input class="form-control" id="folder-name" name="folder_name" required placeholder="advertising">
                    <div class="form-text">The name is converted to safe lowercase characters and hyphens.</div>
                </div>
                <div class="col-12">
                    <div class="small text-muted mb-1">Creates:</div>
                    <code>Images: filestore/images/[folder]/{original,lg,md,sm,xs}</code><br>
                    <code>Files: filestore/files/[folder]</code>
                    <div class="form-text">Every created folder is set to shared-write mode and tested by the CMS before success is reported.</div>
                </div>
                <div class="col-12"><button class="btn btn-success" type="submit">Create folders</button></div>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-soft p-3">
            <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-semibold">Current <?php echo h($currentStorageType); ?> sections</div><div class="btn-group btn-group-sm"><a class="btn <?php echo $currentStorageType === 'images' ? 'btn-success' : 'btn-outline-success'; ?>" href="image_folders.php?storage=images">Images</a><a class="btn <?php echo $currentStorageType === 'files' ? 'btn-success' : 'btn-outline-success'; ?>" href="image_folders.php?storage=files">Files</a></div></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Section</th><th><?php echo $currentStorageType === 'images' ? 'Size folders present' : 'Storage path'; ?></th><th>CMS write test</th></tr></thead>
                <tbody>
                    <?php foreach ($sections as $section): ?><tr class="<?php echo $currentFolder === $section['name'] ? 'table-success' : ''; ?>">
                        <td class="fw-semibold"><?php echo h($section['name']); ?></td>
                        <td class="small"><?php echo h($currentStorageType === 'images' ? ($section['sizes'] ? implode(', ', $section['sizes']) : 'None of the standard sizes') : 'filestore/files/' . $section['name']); ?></td>
                        <td><?php echo $section['writable'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>'; ?></td>
                    </tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php admin_layout_end(); ?>
