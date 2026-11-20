<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../onboarding_copy.php';
if (!in_array($currentRole, ['developer', 'superadmin', 'admin', 'manager'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$definitions = onboardingCopyDefinitions();
$tab = is_string($_POST['tab'] ?? $_GET['tab'] ?? null) ? ($_POST['tab'] ?? $_GET['tab']) : 'password';
if (!isset($definitions[$tab])) $tab = 'password';
$settings = getSiteSettings($pdo);
$body = (string)($settings['onboarding_copy_' . $tab] ?? $definitions[$tab][1]);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $body = is_string($_POST['body_html'] ?? null) ? $_POST['body_html'] : '';
    try {
        if (!$pdo) throw new RuntimeException('Database unavailable.');
        $statement = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
        $statement->execute(['onboarding_copy_' . $tab, sanitize_rich_html($body)]);
        $_SESSION['flash_success'] = $definitions[$tab][0] . ' wording saved.';
        header('Location: onboarding.php?tab=' . rawurlencode($tab));
        exit;
    } catch (Throwable $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'The wording could not be saved. Please try again.'];
    }
}
admin_layout_start('Onboarding', 'onboarding');
?>
<h1 class="h4">Onboarding</h1>
<p class="text-muted">Edit the message displayed on each account setup page. Save each tab before moving to another. Leave the editor empty to show no message. Thank you wording also appears in the completion popup.</p>
<ul class="nav nav-tabs admin-tabs mb-3" aria-label="Onboarding pages">
<?php foreach ($definitions as $key => [$label]): ?>
<li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?> href="onboarding.php?tab=<?= h($key) ?>"><?= h($label) ?></a></li>
<?php endforeach; ?>
</ul>
<form method="post" class="card-soft p-4">
<?= csrf_input() ?>
<input type="hidden" name="tab" value="<?= h($tab) ?>">
<label for="onboarding-body" class="form-label fw-semibold"><?= h($definitions[$tab][0]) ?> message</label>
<textarea id="onboarding-body" class="form-control wysiwyg-field" name="body_html" rows="12"><?= h($body) ?></textarea>
<button type="submit" class="btn btn-success mt-3">Save <?= h($definitions[$tab][0]) ?></button>
</form>
<?php render_tinymce_bootstrap(); ?>
<script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end(); ?>
