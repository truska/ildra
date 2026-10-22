<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['developer', 'superadmin', 'admin', 'manager'], true)) {
    header('Location: index.php');
    exit;
}
$groups = screenGuidanceGroups();
$definitions = screenGuidanceDefinitions();
$activeGroup = (string)($_POST['tab'] ?? $_GET['tab'] ?? 'accounts');
if (!isset($groups[$activeGroup])) $activeGroup = 'accounts';
$groupDefinitions = array_filter($definitions, static fn(array $definition): bool => $definition['group'] === $activeGroup);
$openItem = (string)($_POST['guidance_key'] ?? $_GET['item'] ?? '');
if (!isset($groupDefinitions[$openItem])) $openItem = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = isset($_POST['intros']) && is_array($_POST['intros']) ? $_POST['intros'] : [];
    if ($openItem === '' || count($posted) !== 1 || !array_key_exists($openItem, $posted) || array_diff_key($posted, $groupDefinitions)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Please save one guidance item from this tab at a time. Reload the page and try again.'];
    } elseif (saveAccountIntroModals($pdo, $_POST, $alerts)) {
        $_SESSION['flash_success'] = $groupDefinitions[$openItem]['label'] . ' saved.';
        header('Location: account_intros.php?tab=' . rawurlencode($activeGroup) . '&item=' . rawurlencode($openItem) . '#intro-' . rawurlencode($openItem));
        exit;
    }
}
$intros = fetchAccountIntroModals($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ((array)($_POST['intros'] ?? []) as $key => $postedIntro) {
        if (isset($groupDefinitions[$key]) && is_array($postedIntro)) {
            $intros[$key] = array_replace($intros[$key] ?? [], $postedIntro, ['is_active' => !empty($postedIntro['is_active'])]);
        }
    }
}
admin_layout_start('Screen Guidance', 'help_accounts');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">Information popups and text displayed on screens</div><h5 class="mb-0">Screen Guidance</h5></div>
    <a class="btn btn-outline-secondary" href="help.php">Help articles</a>
</div>
<p class="text-muted small">Each item below has one editing location. Existing information popups and on-page explanations are managed here; normal page content and product descriptions remain in their own editors.</p>
<ul class="nav nav-tabs admin-tabs mb-3" aria-label="Screen guidance areas">
    <?php foreach ($groups as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?php echo $activeGroup === $key ? 'active' : ''; ?>" <?php echo $activeGroup === $key ? 'aria-current="page"' : ''; ?> href="account_intros.php?tab=<?php echo h($key); ?>"><?php echo h($label); ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="card-soft p-4">
        <div class="accordion" id="screen-guidance-editor">
            <?php foreach ($groupDefinitions as $viewKey => $definition):
                $intro = $intros[$viewKey] ?? array_replace($definition, ['is_active' => 1]);
                $showsHeading = $definition['type'] !== 'inline';
                $isOpen = $openItem === $viewKey;
            ?>
                <div class="accordion-item">
                    <h2 class="accordion-header"><button class="accordion-button <?php echo $isOpen ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#intro-<?php echo h($viewKey); ?>" aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>" aria-controls="intro-<?php echo h($viewKey); ?>"><?php echo h($definition['label']); ?><span class="badge text-bg-light ms-2"><?php echo $definition['type'] === 'popup' ? 'Information popup' : 'Text on page'; ?></span></button></h2>
                    <div id="intro-<?php echo h($viewKey); ?>" class="accordion-collapse collapse <?php echo $isOpen ? 'show' : ''; ?>" data-bs-parent="#screen-guidance-editor">
                        <form method="post" class="accordion-body row g-3">
                            <input type="hidden" name="tab" value="<?php echo h($activeGroup); ?>">
                            <input type="hidden" name="guidance_key" value="<?php echo h($viewKey); ?>">
                            <div class="col-12"><p class="small text-muted mb-2"><?php echo h($definition['location']); ?></p><a class="btn btn-sm btn-outline-secondary" href="<?php echo h(($siteBase ?: '') . $definition['url']); ?>" target="_blank" rel="noopener">Open related page <span class="visually-hidden">(opens in a new tab)</span></a></div>
                            <?php if ($showsHeading): ?><div class="col-12"><label class="form-label fw-semibold" for="heading-<?php echo h($viewKey); ?>">Heading</label><input class="form-control" id="heading-<?php echo h($viewKey); ?>" name="intros[<?php echo h($viewKey); ?>][heading]" maxlength="200" required value="<?php echo h((string)$intro['heading']); ?>"></div><?php endif; ?>
                            <div class="col-12"><label class="form-label fw-semibold" for="body-<?php echo h($viewKey); ?>">Text</label><textarea class="form-control wysiwyg-field" id="body-<?php echo h($viewKey); ?>" name="intros[<?php echo h($viewKey); ?>][body_html]" rows="7"><?php echo h((string)$intro['body_html']); ?></textarea></div>
                            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="active-<?php echo h($viewKey); ?>" name="intros[<?php echo h($viewKey); ?>][is_active]" value="1" <?php echo !empty($intro['is_active']) ? 'checked' : ''; ?>><label class="form-check-label" for="active-<?php echo h($viewKey); ?>">Show this guidance</label></div></div>
                            <div class="col-12"><details><summary>Preview of loaded text</summary><div class="border rounded bg-light p-3 mt-2 screen-guidance"><?php if ($showsHeading): ?><h3 class="h5"><?php echo h((string)$intro['heading']); ?></h3><?php endif; ?><?php echo sanitize_rich_html((string)$intro['body_html']); ?></div></details></div>
                            <div class="col-12"><button class="btn btn-success" type="submit">Save <?php echo h($definition['label']); ?></button></div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
</div>
<?php render_tinymce_bootstrap(); ?>
<script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end(); ?>
