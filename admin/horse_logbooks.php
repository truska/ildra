<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($currentRole, ['developer', 'superadmin', 'admin', 'manager'], true)) {
    header('Location: index.php');
    exit;
}
$canEditLogbooks = roleIsDeveloper($currentRole);

ensureHorsesTables($pdo);
ensureHorseLogbookTables($pdo);
if (empty($_SESSION['admin_horse_logbook_csrf'])) $_SESSION['admin_horse_logbook_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['admin_horse_logbook_csrf'];
$horseId = max(0, (int)($_GET['horse_id'] ?? $_POST['horse_id'] ?? 0));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_logbook') {
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $typeId = (int)($_POST['logbook_type_id'] ?? 0);
    $year = (int)($_POST['valid_year'] ?? 0);
    $amount = trim((string)($_POST['amount'] ?? ''));
    $status = (string)($_POST['status'] ?? '');
    if (!$canEditLogbooks) $alerts[] = ['type' => 'danger', 'message' => 'Only Developer users can edit horse logbooks.'];
    elseif (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $alerts[] = ['type' => 'danger', 'message' => 'Your form has expired. Please try again.'];
    elseif ($purchaseId <= 0 || !$typeId || $year < 2000 || $year > 2100 || !is_numeric($amount) || (float)$amount < 0 || !in_array($status, ['active', 'pending', 'expired'], true)) $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid logbook type, year, amount and status.'];
    elseif (!fetchHorseLogbookTypeById($pdo, $typeId)) $alerts[] = ['type' => 'danger', 'message' => 'Logbook type not found.'];
    else {
        try {
            $stmt = $pdo->prepare('UPDATE horse_logbook_purchases SET logbook_type_id=:type_id, valid_year=:year, amount=:amount, status=:status, updated_at=NOW() WHERE id=:id AND horse_id=:horse_id LIMIT 1');
            $stmt->execute([':type_id'=>$typeId, ':year'=>$year, ':amount'=>number_format((float)$amount, 2, '.', ''), ':status'=>$status, ':id'=>$purchaseId, ':horse_id'=>$horseId]);
            if ($stmt->rowCount() < 1) $alerts[] = ['type'=>'warning', 'message'=>'No logbook record was updated.'];
            else { $_SESSION['flash_success'] = 'Horse logbook updated.'; header('Location: horse_logbooks.php?horse_id=' . $horseId); exit; }
        } catch (PDOException $e) {
            $alerts[] = ['type' => 'danger', 'message' => 'Could not update this logbook. A logbook for that year may already exist.'];
        }
    }
}

$types = fetchHorseLogbookTypes($pdo, false);
$purchases = [];
if ($pdo) {
    $stmt = $pdo->prepare('SELECT hlp.*, hlt.name AS logbook_name, u.email AS purchaser_email FROM horse_logbook_purchases hlp LEFT JOIN horse_logbook_types hlt ON hlt.id=hlp.logbook_type_id LEFT JOIN users u ON u.id=hlp.purchased_by_user_id WHERE hlp.horse_id=:horse_id ORDER BY hlp.valid_year DESC, hlp.purchased_at DESC, hlp.id DESC');
    $stmt->execute([':horse_id'=>$horseId]);
    $purchases = $stmt->fetchAll() ?: [];
}
$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
foreach ($purchases as $purchase) if ((int)$purchase['id'] === $editId) { $editing = $purchase; break; }

admin_layout_start('Horse logbooks', 'horses');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><div class="small text-muted">Horse logbooks</div><h5 class="mb-0"><?php echo h((string)$horse['name']); ?></h5><div class="small text-muted">Owner: <?php echo h((string)($horse['owner_email'] ?? 'Unknown user')); ?></div></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="horse_edit.php?id=<?php echo $horseId; ?>">Edit horse</a><a class="btn btn-outline-secondary" href="horses.php#horse-<?php echo $horseId; ?>">Back to horses</a></div></div>
<section class="card-soft p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Year</th><th>Logbook</th><th>Status</th><th>Amount</th><th>Purchased</th><th>Purchaser</th><?php if ($canEditLogbooks): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead><tbody><?php foreach ($purchases as $purchase): ?><tr><td class="fw-semibold"><?php echo (int)$purchase['valid_year']; ?></td><td><?php echo h((string)($purchase['logbook_name'] ?? '—')); ?></td><td class="text-capitalize"><?php echo h((string)$purchase['status']); ?></td><td>£<?php echo h(number_format((float)$purchase['amount'], 2)); ?></td><td class="text-muted small"><?php echo h(format_display_datetime($purchase['purchased_at'] ?? null, '—')); ?></td><td class="small"><?php echo admin_table_value($purchase['purchaser_email'] ?? '', 'email'); ?></td><?php if ($canEditLogbooks): ?><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="horse_logbooks.php?horse_id=<?php echo $horseId; ?>&edit=<?php echo (int)$purchase['id']; ?>">Edit</a></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$purchases): ?><tr><td colspan="<?php echo $canEditLogbooks ? 7 : 6; ?>" class="text-muted">No logbooks have been purchased for this horse.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if ($editing && $canEditLogbooks): ?><div class="modal fade" id="editLogbookModal" tabindex="-1" aria-labelledby="editLogbookTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><input type="hidden" name="action" value="save_logbook"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="horse_id" value="<?php echo $horseId; ?>"><input type="hidden" name="purchase_id" value="<?php echo (int)$editing['id']; ?>"><div class="modal-header"><div><div class="small text-muted">Horse logbook</div><h5 class="modal-title" id="editLogbookTitle">Edit <?php echo h((string)$horse['name']); ?> — <?php echo (int)$editing['valid_year']; ?></h5></div><a class="btn-close" href="horse_logbooks.php?horse_id=<?php echo $horseId; ?>" aria-label="Close"></a></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Logbook type</label><select class="form-select" name="logbook_type_id"><?php foreach ($types as $type): ?><option value="<?php echo (int)$type['id']; ?>" <?php echo (int)$editing['logbook_type_id'] === (int)$type['id'] ? 'selected' : ''; ?>><?php echo h((string)$type['name']); ?> · <?php echo (int)$type['valid_year']; ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Year</label><input class="form-control" type="number" name="valid_year" min="2000" max="2100" value="<?php echo (int)$editing['valid_year']; ?>"></div><div class="col-md-3"><label class="form-label">Amount</label><input class="form-control" type="number" name="amount" min="0" step="0.01" value="<?php echo h(number_format((float)$editing['amount'], 2, '.', '')); ?>"></div><div class="col-12"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['active'=>'Active','pending'=>'Pending','expired'=>'Expired'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo $editing['status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div></div></div><div class="modal-footer"><a class="btn btn-outline-secondary" href="horse_logbooks.php?horse_id=<?php echo $horseId; ?>">Cancel</a><button class="btn btn-success">Save logbook</button></div></form></div></div><script>document.addEventListener('DOMContentLoaded',function(){const modal=document.getElementById('editLogbookModal');if(modal&&window.bootstrap)new bootstrap.Modal(modal).show();});</script><?php endif; ?>
<?php admin_layout_end();
