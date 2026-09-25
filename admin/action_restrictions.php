<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (strtolower((string)($currentUser['role'] ?? '')) !== 'superadmin') {
    header('Location: index.php');
    exit;
}

ensureAdminActionRestrictionsTable($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (saveAdminActionRestriction($pdo, $_POST, $alerts)) {
        adminAuditLog($pdo, 'permissions.update_action_restriction', $currentUser, 'admin_action_restriction', (int)($_POST['id'] ?? 0), [
            'restricted'=>!empty($_POST['is_restricted']),
            'allowed_roles'=>array_values((array)($_POST['allowed_roles'] ?? [])),
        ]);
        $_SESSION['flash_success'] = 'Action restriction saved.';
        header('Location: action_restrictions.php');
        exit;
    }
}
$actions = fetchAdminActionRestrictions($pdo);
$pageLabels = [
    'finance'=>'Finance', 'memberships'=>'Memberships', 'people'=>'People', 'users'=>'Users',
    'events'=>'Events', 'email_campaigns'=>'Email Campaigns', 'email'=>'Email',
];
admin_layout_start('Action Restrictions', 'tech');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">SuperAdmin only</div><h5 class="mb-0">Action restrictions</h5></div>
    <a class="btn btn-outline-secondary" href="tech.php">Back to Tech</a>
</div>
<div class="alert alert-info small">An action with its separate restriction off inherits the access of its page. A restriction can only remove access; it cannot give access to a role that cannot open the page. SuperAdmin always retains access.</div>
<div class="card-soft p-3">
    <div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Page</th><th>Action</th><th>Separate restriction</th><th>Roles allowed when restricted</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($actions as $action): ?>
            <?php $allowed = array_filter(array_map('trim', explode(',', (string)$action['allowed_roles']))); $formId = 'action-' . (int)$action['id']; ?>
            <tr>
                <td class="text-nowrap"><?php echo h($pageLabels[$action['page_key']] ?? (string)$action['page_key']); ?></td>
                <td><div class="fw-semibold"><?php echo h((string)$action['label']); ?></div><div class="small text-muted"><?php echo h((string)$action['description']); ?><br><code><?php echo h((string)$action['action_key']); ?></code></div></td>
                <td><div class="form-check form-switch"><input form="<?php echo h($formId); ?>" class="form-check-input" type="checkbox" name="is_restricted" value="1" id="restrict-<?php echo (int)$action['id']; ?>" <?php echo !empty($action['is_restricted']) ? 'checked' : ''; ?>><label class="form-check-label" for="restrict-<?php echo (int)$action['id']; ?>"><?php echo !empty($action['is_restricted']) ? 'Restricted' : 'Inherits page'; ?></label></div></td>
                <td><form method="post" id="<?php echo h($formId); ?>"><input type="hidden" name="id" value="<?php echo (int)$action['id']; ?>">
                    <?php foreach (['admin'=>'Admin','manager'=>'Manager','organiser'=>'Organiser'] as $role=>$label): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allowed_roles[]" value="<?php echo h($role); ?>" id="<?php echo h($formId . '-' . $role); ?>" <?php echo in_array($role, $allowed, true) ? 'checked' : ''; ?>><label class="form-check-label" for="<?php echo h($formId . '-' . $role); ?>"><?php echo h($label); ?></label></div><?php endforeach; ?>
                    <div class="small text-muted mt-1">SuperAdmin always allowed.</div></form></td>
                <td class="text-end"><button form="<?php echo h($formId); ?>" class="btn btn-sm btn-success">Save</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php admin_layout_end(); ?>
