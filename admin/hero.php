<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$siteSettings = getSiteSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can update hero & welcome.'];
    } else {
        if (saveSiteSettings($pdo, $_POST, $alerts)) {
            $_SESSION['flash_success'] = 'Hero & welcome saved.';
            header('Location: hero.php');
            exit;
        }
    }
}

admin_layout_start('Site Hero & Welcome', 'hero');
?>
<div class="d-flex justify-content-end mb-3"><a class="btn btn-success" href="banner_images.php">Manage Banner Images</a></div>
<div class="card-soft p-4">
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Hero title</label>
                <input type="text" name="hero_title" class="form-control" value="<?php echo h($siteSettings['hero_title']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Hero subtitle</label>
                <input type="text" name="hero_subtitle" class="form-control" value="<?php echo h($siteSettings['hero_subtitle']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Hero tagline</label>
                <input type="text" name="hero_tagline" class="form-control" value="<?php echo h($siteSettings['hero_tagline']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">CTA label</label>
                <input type="text" name="hero_cta_label" class="form-control" value="<?php echo h($siteSettings['hero_cta_label']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">CTA link</label>
                <input type="text" name="hero_cta_url" class="form-control" value="<?php echo h($siteSettings['hero_cta_url']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Welcome title</label>
                <input type="text" name="welcome_title" class="form-control" value="<?php echo h($siteSettings['welcome_title']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Sponsor/partner image URL</label>
                <input type="text" name="sponsor_image_url" class="form-control" value="<?php echo h($siteSettings['sponsor_image_url']); ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Welcome copy</label>
                <textarea name="welcome_body" rows="3" class="form-control"><?php echo h($siteSettings['welcome_body']); ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Background image URL</label>
                <input type="text" name="background_image_url" class="form-control" value="<?php echo h($siteSettings['background_image_url']); ?>">
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-success">Save</button>
            <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
        </div>
    </form>
</div>
<?php
admin_layout_end();
