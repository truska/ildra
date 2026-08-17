<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && saveAccountIntroModals($pdo, $_POST, $alerts)) {
    $_SESSION['flash_success'] = 'Account introduction modals saved.';
    header('Location: account_intros.php');
    exit;
}
$intros = fetchAccountIntroModals($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intros']) && is_array($_POST['intros'])) {
    foreach ($_POST['intros'] as $key => $postedIntro) {
        if (isset($intros[$key]) && is_array($postedIntro)) $intros[$key] = array_merge($intros[$key], $postedIntro);
    }
}
$labels = ['people' => 'People', 'horses' => 'Horses', 'shares' => 'Shares', 'my-account' => 'My Account'];

admin_layout_start('Account Help', 'help_accounts');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">Help guidance</div><h5 class="mb-0">Account Help</h5></div>
    <a class="btn btn-outline-secondary" href="help.php">Back to Help</a>
</div>
<div class="card-soft p-4">
    <p class="text-muted small">These introductions appear from the information button on each account page. People and Horses also open automatically when the user has no records.</p>
    <form method="post">
        <div class="accordion" id="account-intro-editor">
            <?php foreach ($labels as $viewKey => $label): $intro = $intros[$viewKey] ?? ['heading' => '', 'body_html' => '', 'is_active' => 1]; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header"><button class="accordion-button <?php echo $viewKey === 'people' ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#intro-<?php echo h($viewKey); ?>"><?php echo h($label); ?></button></h2>
                    <div id="intro-<?php echo h($viewKey); ?>" class="accordion-collapse collapse <?php echo $viewKey === 'people' ? 'show' : ''; ?>" data-bs-parent="#account-intro-editor">
                        <div class="accordion-body row g-3">
                            <div class="col-12"><label class="form-label fw-semibold">Heading</label><input class="form-control" name="intros[<?php echo h($viewKey); ?>][heading]" required value="<?php echo h((string)$intro['heading']); ?>"></div>
                            <div class="col-12"><label class="form-label fw-semibold">HTML text</label><textarea class="form-control wysiwyg-field" name="intros[<?php echo h($viewKey); ?>][body_html]" rows="7" required><?php echo h((string)$intro['body_html']); ?></textarea></div>
                            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="active-<?php echo h($viewKey); ?>" name="intros[<?php echo h($viewKey); ?>][is_active]" value="1" <?php echo !empty($intro['is_active']) ? 'checked' : ''; ?>><label class="form-check-label" for="active-<?php echo h($viewKey); ?>">Show this introduction</label></div></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-3"><button class="btn btn-success">Save introductions</button> <a class="btn btn-outline-secondary" href="help.php">Cancel</a></div>
    </form>
</div>
<?php render_tinymce_bootstrap(); ?>
<script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end(); ?>
