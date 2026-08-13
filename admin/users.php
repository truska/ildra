<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageUsers = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManageUsers) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageUsers) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage users.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $level = (int)($_POST['level'] ?? 0);
            if ($userId > 0 && updateUserRoleAndLevel($pdo, $userId, $role, $level, $alerts)) {
                $successMessage = 'User role updated.';
            }
        } elseif ($action === 'reset_password') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            if ($userId > 0 && resetUserPassword($pdo, $userId, $newPassword, $alerts)) {
                $successMessage = 'Password reset.';
            }
        } elseif ($action === 'act_as') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                $alerts[] = ['type' => 'danger', 'message' => 'Invalid user.'];
            } elseif ((int)($currentUser['id'] ?? 0) === $targetUserId) {
                $alerts[] = ['type' => 'warning', 'message' => 'You are already signed in as this user.'];
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name, u.last_login_at
                        FROM users u
                        JOIN roles r ON r.id = u.role_id
                        WHERE u.id = :id
                        LIMIT 1
                    ");
                    $stmt->execute([':id' => $targetUserId]);
                    $target = $stmt->fetch();
                    if (!$target) {
                        $alerts[] = ['type' => 'danger', 'message' => 'User not found.'];
                    } elseif (strtolower((string)($target['role'] ?? '')) === 'superadmin') {
                        $alerts[] = ['type' => 'danger', 'message' => 'You cannot act as a SuperAdmin user.'];
                    } else {
                        // Store the real admin user, then switch the session user to the target.
                        // Admin area is blocked while acting-as (see `admin/_bootstrap.php`).
                        $_SESSION['act_as_original_user'] = $_SESSION['user'] ?? $currentUser;
                        $_SESSION['act_as_started_at'] = time();
                        $target['level'] = (int)($target['level'] ?? 0);
                        $_SESSION['user'] = $target;
                        $_SESSION['flash_success'] = 'Now acting as ' . ($target['email'] ?? 'user') . '.';

                        header('Location: ../account');
                        exit;
                    }
                } catch (PDOException $e) {
                    $alerts[] = ['type' => 'danger', 'message' => 'Could not switch user right now.'];
                }
            }
        }
    }
    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: users.php');
    exit;
}

$allUsers = fetchAllUsersForAdmin($pdo, $alerts);

$filterForm='user-filter-form';
$tableColumns=[
    'name'=>['label'=>'Name','sortable'=>true,'filter'=>'text','placeholder'=>'Search name','form'=>$filterForm,'value'=>static fn(array $r):string=>trim((string)($r['first_name']??'').' '.(string)($r['last_name']??''))],
    'email'=>['label'=>'Email','sortable'=>true,'filter'=>'text','placeholder'=>'Search email','form'=>$filterForm,'data_type'=>'email'],
    'role'=>['label'=>'Role','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['superadmin'=>'SuperAdmin','admin'=>'Admin','organiser'=>'Organiser','user'=>'User']],
    'last_login'=>['label'=>'Last login','field'=>'last_login_at','sortable'=>true,'filter'=>'text','placeholder'=>'Search last login','form'=>$filterForm],
    'actions'=>['label'=>'Actions'],
];
$table=admin_table_prepare($allUsers,$tableColumns,'email');$allUsers=$table['rows'];$filters=$table['filters'];$sortKey=$table['sort_key'];$sortDir=$table['sort_dir'];

admin_layout_start('Users', 'users');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Manage users</div>
        <h5 class="mb-0">Users</h5>
    </div>
    <?php echo admin_table_pagination($table); ?>
</div>
<form method="get" id="user-filter-form" class="mb-2 text-end"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="users.php">Clear</a></form>

<div class="card-soft p-3">
    <?php echo admin_table_record_count($table,'user','users'); ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <?php foreach($tableColumns as$key=>$column): ?><th><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th><?php endforeach; ?>
                </tr>
                <tr class="admin-table-filter-row"><?php foreach($tableColumns as$key=>$column): ?><th><?php echo admin_table_filter($key,$column,$filters); ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $userRow): ?>
                    <?php
                    $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
                    $fullName = $fullName !== '' ? $fullName : '—';
                    $lastLogin = $userRow['last_login_at'] ? h($userRow['last_login_at']) : '—';
                    ?>
                    <tr>
                        <td><?php echo h($fullName); ?></td>
                        <td><?php echo admin_table_value($userRow['email'] ?? '', 'email'); ?></td>
                        <td>
                            <form class="d-flex gap-2 align-items-center" method="POST">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
                                <select name="role" class="form-select form-select-sm" style="width: 180px;">
                                    <option value="superadmin" <?php echo ($userRow['role'] === 'superadmin') ? 'selected' : ''; ?>>SuperAdmin</option>
                                    <option value="admin" <?php echo ($userRow['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    <option value="organiser" <?php echo ($userRow['role'] === 'organiser') ? 'selected' : ''; ?>>Organiser</option>
                                    <option value="user" <?php echo ($userRow['role'] === 'user') ? 'selected' : ''; ?>>User</option>
                                </select>
                                <input type="hidden" name="level" value="0">
                                <button class="btn btn-sm btn-outline-success">Save</button>
                            </form>
                        </td>
                        <td class="text-muted small"><?php echo $lastLogin; ?></td>
                        <td>
                            <div class="d-flex gap-2 align-items-center">
                            <form class="d-flex gap-2 align-items-center m-0" method="POST">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
                                <input type="password" name="new_password" class="form-control form-control-sm" placeholder="New password" minlength="8" required>
                                <button class="btn btn-sm btn-outline-secondary">Reset</button>
                            </form>
                            <?php if (strtolower((string)($userRow['role'] ?? '')) !== 'superadmin'): ?>
                                <form class="m-0" method="POST">
                                    <input type="hidden" name="action" value="act_as">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" <?php echo ((int)($currentUser['id'] ?? 0) === (int)($userRow['id'] ?? 0)) ? 'disabled' : ''; ?>>
                                        Act as
                                    </button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$allUsers): ?>
                    <tr><td colspan="6" class="text-muted">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
admin_layout_end();
