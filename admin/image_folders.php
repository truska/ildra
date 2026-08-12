<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$role = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($role, ['superadmin', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

$sizes = ['original', 'lg', 'md', 'sm', 'xs'];
$imagesRoot = dirname(__DIR__) . '/filestore/images';
$createdPaths = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $requestedName = trim((string)($_POST['folder_name'] ?? ''));
    $folderName = image_upload_section($requestedName);

    if ($requestedName === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a folder name.'];
    } elseif ($folderName === 'image' && strtolower($requestedName) !== 'image') {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a folder name containing letters or numbers.'];
    } elseif (!is_dir($imagesRoot) || !is_writable($imagesRoot)) {
        $alerts[] = ['type' => 'danger', 'message' => 'The filestore images root is not writable by the CMS.'];
    } else {
        $sectionPath = $imagesRoot . '/' . $folderName;
        $paths = array_merge([$sectionPath], array_map(static fn(string $size): string => $sectionPath . '/' . $size, $sizes));
        foreach ($paths as $path) {
            if (!is_dir($path) && !mkdir($path, 02775, true)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Could not create ' . basename($path) . '.'];
                break;
            }
            @chmod($path, 02775);
            if (!is_writable($path)) {
                $alerts[] = ['type' => 'danger', 'message' => basename($path) . ' exists but is not writable by the CMS.'];
                break;
            }
            $createdPaths[] = str_replace(dirname(__DIR__) . '/', '', $path);
        }
        if (!$alerts) {
            $_SESSION['flash_success'] = 'Image structure created for “' . $folderName . '”.';
            header('Location: image_folders.php?folder=' . rawurlencode($folderName));
            exit;
        }
    }
}

$currentFolder = image_upload_section((string)($_GET['folder'] ?? ''));
$sections = [];
foreach (glob($imagesRoot . '/*', GLOB_ONLYDIR) ?: [] as $sectionPath) {
    $available = [];
    foreach ($sizes as $size) {
        if (is_dir($sectionPath . '/' . $size)) $available[] = $size;
    }
    $sections[] = ['name' => basename($sectionPath), 'sizes' => $available, 'writable' => is_writable($sectionPath)];
}

admin_layout_start('Image Folders', 'image_folders');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">CMS filestore utility</div><h5 class="mb-0">Create image structure</h5></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-soft p-4">
            <form method="post" class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold" for="folder-name">Folder name</label>
                    <input class="form-control" id="folder-name" name="folder_name" required placeholder="advertising">
                    <div class="form-text">The name is converted to safe lowercase characters and hyphens.</div>
                </div>
                <div class="col-12">
                    <div class="small text-muted mb-1">Creates:</div>
                    <code>filestore/images/[folder]/{original,lg,md,sm,xs}</code>
                </div>
                <div class="col-12"><button class="btn btn-success" type="submit">Create folders</button></div>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-soft p-3">
            <div class="fw-semibold mb-2">Current image sections</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Section</th><th>Size folders present</th><th>CMS writable</th></tr></thead>
                <tbody>
                    <?php foreach ($sections as $section): ?><tr class="<?php echo $currentFolder === $section['name'] ? 'table-success' : ''; ?>">
                        <td class="fw-semibold"><?php echo h($section['name']); ?></td>
                        <td class="small"><?php echo h($section['sizes'] ? implode(', ', $section['sizes']) : 'None of the standard sizes'); ?></td>
                        <td><?php echo $section['writable'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>'; ?></td>
                    </tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php admin_layout_end(); ?>
