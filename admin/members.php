<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$canEditMemberships = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin'], true);
if (empty($_SESSION['membership_purchase_csrf'])) $_SESSION['membership_purchase_csrf'] = bin2hex(random_bytes(24));
$membershipPurchaseCsrf = (string)$_SESSION['membership_purchase_csrf'];
ensureMembershipTables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_membership_purchase') {
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $typeId = (int)($_POST['membership_type_id'] ?? 0);
    $year = (int)($_POST['membership_year'] ?? 0);
    $amount = trim((string)($_POST['amount'] ?? ''));
    $status = (string)($_POST['status'] ?? '');
    if (!$canEditMemberships) $alerts[] = ['type'=>'danger', 'message'=>'Only Admin and SuperAdmin users can edit memberships.'];
    elseif (!hash_equals($membershipPurchaseCsrf, (string)($_POST['csrf'] ?? ''))) $alerts[] = ['type'=>'danger', 'message'=>'Your session token expired. Please try again.'];
    elseif ($purchaseId <= 0 || $year < 2000 || $year > 2100 || !is_numeric($amount) || (float)$amount < 0 || !in_array($status, ['active','pending','expired'], true)) $alerts[] = ['type'=>'danger', 'message'=>'Enter a valid membership type, year, amount and status.'];
    else {
        $type = fetchMembershipTypeById($pdo, $typeId);
        if (!$type) $alerts[] = ['type'=>'danger', 'message'=>'Membership type not found.'];
        else {
            $update = $pdo->prepare('UPDATE membership_purchases SET membership_type_id=:type_id,membership_year=:year,amount=:amount,status=:status,allows_ride_entries_snapshot=:ride,allows_competitive_rides_snapshot=:competitive,has_voting_rights_snapshot=:voting,updated_at=NOW() WHERE id=:id LIMIT 1');
            $update->execute([':type_id'=>$typeId, ':year'=>$year, ':amount'=>number_format((float)$amount, 2, '.', ''), ':status'=>$status, ':ride'=>!empty($type['allows_ride_entries'])?1:0, ':competitive'=>!empty($type['allows_competitive_rides'])?1:0, ':voting'=>!empty($type['has_voting_rights'])?1:0, ':id'=>$purchaseId]);
            $_SESSION['flash_success'] = 'Membership updated.';
            header('Location: members.php'); exit;
        }
    }
}

$memberships = fetchMemberships($pdo);
$membershipTypes = fetchMembershipTypes($pdo, false);
$editingMembership = null;
$editId = (int)($_GET['edit'] ?? 0);
foreach ($memberships as $membership) if ((int)($membership['id'] ?? 0) === $editId) { $editingMembership = $membership; break; }
$filterForm = 'members-filter-form';

$typeOptions = [];
$statusOptions = [];
$yearOptions = [];
foreach ($memberships as $membership) {
    $type = trim((string)($membership['membership_name'] ?? ''));
    if ($type !== '') $typeOptions[$type] = $type;
    $status = trim((string)($membership['status'] ?? ''));
    if ($status !== '') $statusOptions[$status] = ucfirst($status);
    $year = (int)($membership['membership_year'] ?? 0);
    if ($year > 0) $yearOptions[(string)$year] = (string)$year;
}
natcasesort($typeOptions);
natcasesort($statusOptions);
krsort($yearOptions);

$tableColumns = [
    'membership_number' => ['label'=>'Membership No.', 'field'=>'member_number', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm],
    'member' => ['label'=>'Member', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => trim((string)($row['member_name'] ?? ''))],
    'email' => ['label'=>'Email', 'field'=>'user_email', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm],
    'type' => ['label'=>'Type', 'field'=>'membership_name', 'sortable'=>true, 'filter'=>'select', 'options'=>$typeOptions, 'form'=>$filterForm],
    'status' => ['label'=>'Status', 'field'=>'status', 'sortable'=>true, 'filter'=>'select', 'options'=>$statusOptions, 'form'=>$filterForm],
    'membership_year' => ['label'=>'Year', 'field'=>'membership_year', 'sortable'=>true, 'filter'=>'select', 'options'=>$yearOptions, 'form'=>$filterForm, 'compare'=>'number'],
    'purchased' => ['label'=>'Purchased', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => format_display_date($row['purchased_at'] ?? null, ''),
        'sort_value'=>static fn(array $row): string => (string)($row['purchased_at'] ?? '')],
    'amount' => ['label'=>'Amount', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
        'sort_value'=>static fn(array $row): float => (float)($row['amount'] ?? 0)],
    'actions' => ['label'=>'Actions', 'sortable'=>false],
];
$table = admin_table_prepare($memberships, $tableColumns, 'status');
$memberships = $table['rows'];

admin_layout_start('Members', 'members');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Memberships</div><h5 class="mb-0">Members (active &amp; expired)</h5></div>
</div>
<form method="get" id="<?php echo h($filterForm); ?>"></form>
<section class="card-soft p-3">
    <?php echo admin_table_record_count($table, 'membership', 'memberships'); ?>
    <div class="table-responsive">
        <table class="table table-sm admin-data-table align-middle mb-0">
            <thead>
                <tr>
                    <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
                </tr>
                <tr class="admin-table-filter-row">
                    <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($memberships as $membership): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo h((string)($membership['member_number'] ?? '—')); ?></td>
                        <td><?php echo h(trim((string)($membership['member_name'] ?? '')) ?: '—'); ?></td>
                        <td><?php echo admin_table_value($membership['user_email'] ?? '', 'email'); ?></td>
                        <td><?php echo h((string)($membership['membership_name'] ?? '')); ?></td>
                        <td><span class="text-capitalize"><?php echo h((string)($membership['status'] ?? '')); ?></span></td>
                        <td class="text-muted text-nowrap"><?php echo (int)($membership['membership_year'] ?? 0); ?></td>
                        <td class="text-muted text-nowrap"><?php echo h(format_display_date($membership['purchased_at'] ?? null, '')); ?></td>
                        <td class="fw-semibold text-nowrap">£<?php echo h(number_format((float)($membership['amount'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?php if ($canEditMemberships): ?><a class="btn btn-sm btn-outline-secondary" href="members.php?edit=<?php echo (int)$membership['id']; ?>">Edit</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$memberships): ?><tr><td colspan="9" class="text-muted">No memberships match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-end mt-2"><a class="btn btn-sm btn-outline-secondary" href="members.php">Clear filters</a></div>
    <?php echo admin_table_pagination($table); ?>
</section>
<?php if ($editingMembership && $canEditMemberships): ?>
<section class="card-soft p-4 mt-3"><div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Membership purchase</div><h5 class="mb-0">Edit <?php echo h((string)($editingMembership['member_name'] ?? 'member')); ?></h5></div><a class="btn btn-sm btn-outline-secondary" href="members.php">Close</a></div><form method="post" class="row g-3"><input type="hidden" name="action" value="save_membership_purchase"><input type="hidden" name="csrf" value="<?php echo h($membershipPurchaseCsrf); ?>"><input type="hidden" name="purchase_id" value="<?php echo (int)$editingMembership['id']; ?>"><div class="col-md-5"><label class="form-label">Membership type</label><select class="form-select" name="membership_type_id"><?php foreach ($membershipTypes as $type): ?><option value="<?php echo (int)$type['id']; ?>" <?php echo (int)$editingMembership['membership_type_id'] === (int)$type['id'] ? 'selected' : ''; ?>><?php echo h((string)$type['name']); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Year</label><input class="form-control" type="number" name="membership_year" min="2000" max="2100" value="<?php echo (int)$editingMembership['membership_year']; ?>"></div><div class="col-md-2"><label class="form-label">Amount</label><input class="form-control" type="number" name="amount" min="0" step="0.01" value="<?php echo h(number_format((float)$editingMembership['amount'], 2, '.', '')); ?>"></div><div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['active'=>'Active','pending'=>'Pending','expired'=>'Expired'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo ($editingMembership['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-12"><div class="form-text">Changing the membership type refreshes the stored ride, competitive-ride, and voting benefits for this purchase.</div><button class="btn btn-success">Save membership</button> <a class="btn btn-outline-secondary" href="members.php">Cancel</a></div></form></section>
<?php endif; ?>
<?php admin_layout_end(); ?>
