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

$sortKey = $_GET['sort'] ?? 'email';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$sortFields = [
    'name' => ['first_name', 'last_name'],
    'email' => ['email'],
    'role' => ['role'],
    'last_login' => ['last_login_at'],
];
$activeFields = $sortFields[$sortKey] ?? ['email'];
usort($allUsers, function ($a, $b) use ($activeFields, $sortDir) {
    foreach ($activeFields as $field) {
        $va = $a[$field] ?? '';
        $vb = $b[$field] ?? '';
        if ($va == $vb) {
            continue;
        }
        if ($sortDir === 'asc') {
            return ($va < $vb) ? -1 : 1;
        }
        return ($va > $vb) ? -1 : 1;
    }
    return 0;
});

admin_layout_start('Users', 'users');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Manage users</div>
        <h5 class="mb-0">Users</h5>
    </div>
</div>

<div class="card-soft p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th><?php echo admin_sort_link('name', 'Name', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('email', 'Email', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('role', 'Role', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('last_login', 'Last login', (string)$sortKey, (string)$sortDir); ?></th>
                    <th></th>
                </tr>
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
                        <td><?php echo h($userRow['email']); ?></td>
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
