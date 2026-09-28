<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($currentRole, ['developer', 'superadmin', 'admin', 'manager'], true)) {
    header('Location: index.php');
    exit;
}

ensureHorsesTables($pdo);
$horseId = max(0, (int)($_GET['id'] ?? $_POST['horse_id'] ?? 0));
$horse = null;
if ($pdo && $horseId > 1) {
    $stmt = $pdo->prepare('SELECT h.*, u.email AS owner_email FROM horses h LEFT JOIN users u ON u.id = h.owner_user_id WHERE h.id = :id LIMIT 1');
    $stmt->execute([':id' => $horseId]);
    $horse = $stmt->fetch() ?: null;
}
if (!$horse) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Horse not found.']];
    header('Location: horses.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedId = saveHorseForUser($pdo, (int)$horse['owner_user_id'], $_POST, $alerts, $horseId);
    if ($savedId && !$alerts) {
        $stmt = $pdo->prepare('UPDATE horses SET is_archived = :archived, updated_at = NOW() WHERE id = :id LIMIT 1');
        $stmt->execute([':archived' => !empty($_POST['is_archived']) ? 1 : 0, ':id' => $horseId]);
        $_SESSION['flash_success'] = 'Horse updated.';
        header('Location: horses.php#horse-' . $horseId);
        exit;
    }
    $horse = array_merge($horse, $_POST);
}

$qualifications = fetchHorseQualifications($pdo);
$heights = [102=>'10.0',112=>'11.0',122=>'12.0',127=>'12.2',132=>'13.0',137=>'13.2',142=>'14.0',145=>'14.1',147=>'14.2',150=>'14.3',152=>'15.0',155=>'15.1',157=>'15.2',160=>'15.3',163=>'16.0',165=>'16.1',168=>'16.2',170=>'16.3',173=>'17.0',175=>'17.1',178=>'17.2',180=>'17.3',183=>'18.0'];
admin_layout_start('Edit horse', 'horses');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">Horses</div><h5 class="mb-0">Edit horse</h5></div>
    <a class="btn btn-outline-secondary" href="horses.php">Back to horses</a>
</div>
<div class="card-soft p-4">
    <form method="post" class="row g-3">
        <input type="hidden" name="horse_id" value="<?php echo $horseId; ?>">
        <div class="col-12"><div class="small text-muted">Owned by: <?php echo h((string)($horse['owner_email'] ?? 'Unknown user')); ?></div></div>
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">Horse name</label><input class="form-control" name="name" required value="<?php echo h((string)($horse['name'] ?? '')); ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">Breed</label><input class="form-control" name="breed" value="<?php echo h((string)($horse['breed'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Colour</label><input class="form-control" name="colour" value="<?php echo h((string)($horse['colour'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Sex</label><select class="form-select" name="sex"><option value="">Not set</option><?php foreach (['Mare', 'Gelding', 'Stallion'] as $sex): ?><option value="<?php echo h($sex); ?>" <?php echo ($horse['sex'] ?? '') === $sex ? 'selected' : ''; ?>><?php echo h($sex); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Height</label><select class="form-select" name="height_cm"><option value="">Not set</option><?php foreach ($heights as $cm => $hands): ?><option value="<?php echo $cm; ?>" <?php echo (int)($horse['height_cm'] ?? 0) === $cm ? 'selected' : ''; ?>><?php echo $cm; ?> cm [<?php echo h($hands); ?> hh]</option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Year of birth</label><input class="form-control" name="year_of_birth" value="<?php echo h((string)($horse['year_of_birth'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Date of birth</label><input type="date" class="form-control" name="dob" value="<?php echo h((string)($horse['dob'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Flu vaccination date</label><input type="date" class="form-control" name="flu_vac_date" value="<?php echo h((string)($horse['flu_vac_date'] ?? '')); ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">Qualification</label><select class="form-select" name="qualification_id"><option value="">None</option><?php foreach ($qualifications as $qualification): ?><option value="<?php echo (int)$qualification['id']; ?>" <?php echo (int)($horse['qualification_id'] ?? 0) === (int)$qualification['id'] ? 'selected' : ''; ?>><?php echo h((string)$qualification['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3"><label class="form-label fw-semibold">Passport issuer</label><input class="form-control" name="passport_issuer" value="<?php echo h((string)($horse['passport_issuer'] ?? '')); ?>"></div>
        <div class="col-12 col-md-3"><label class="form-label fw-semibold">Passport number</label><input class="form-control" name="passport_number" value="<?php echo h((string)($horse['passport_number'] ?? '')); ?>"></div>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="horseArchived" name="is_archived" value="1" <?php echo !empty($horse['is_archived']) ? 'checked' : ''; ?>><label class="form-check-label" for="horseArchived">Archived</label></div></div>
        <div class="col-12"><button class="btn btn-success">Save changes</button> <a class="btn btn-outline-secondary" href="horses.php">Cancel</a></div>
    </form>
</div>
<?php admin_layout_end();
