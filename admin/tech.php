<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (strtolower((string)($currentUser['role'] ?? '')) !== 'superadmin') {
    header('Location: index.php');
    exit;
}

admin_layout_start('Tech', 'tech');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Superadmin only</div><h5 class="mb-0">Technical tools</h5></div>
</div>
<div class="row g-4">
    <div class="col-md-6 col-xl-4">
        <div class="card-soft p-4 h-100">
            <div class="d-flex align-items-center gap-2 mb-2"><i class="fa-solid fa-server text-success" aria-hidden="true"></i><h6 class="mb-0">Live Email Settings</h6></div>
            <p class="small text-muted">Configure outbound delivery, SMTP, sender details, test redirects and send a controlled test email.</p>
            <a class="btn btn-outline-success" href="email.php?view=settings">Open email settings</a>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card-soft p-4 h-100">
            <div class="d-flex align-items-center gap-2 mb-2"><i class="fa-solid fa-folder-tree text-success" aria-hidden="true"></i><h6 class="mb-0">Storage Folders</h6></div>
            <p class="small text-muted">Create image size folders or file-storage folders, apply shared-write permissions and run the CMS write test.</p>
            <a class="btn btn-outline-success" href="image_folders.php">Open storage folders</a>
        </div>
    </div>
</div>
<?php admin_layout_end(); ?>
