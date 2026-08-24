<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageUsers = in_array($currentRole, ['superadmin', 'admin', 'manager'], true);
if (!$canManageUsers) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageUsers) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage users.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_details') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $generalEmailOptIn = !empty($_POST['general_email_opt_in']) ? 1 : 0;
            $rideNoticeOptIn = !empty($_POST['ride_notice_opt_in']) ? 1 : 0;
            $renewalReminderOptIn = !empty($_POST['renewal_reminder_opt_in']) ? 1 : 0;

            if ($userId <= 0) {
                $alerts[] = ['type' => 'danger', 'message' => 'Invalid user.'];
            }
            if ($firstName === '' || $lastName === '') {
                $alerts[] = ['type' => 'danger', 'message' => 'Enter the user\'s first and last name.'];
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid email address.'];
            }

            if (!$alerts) {
                try {
                    $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                    $duplicate->execute([':email' => $email, ':id' => $userId]);
                    if ($duplicate->fetch()) {
                        $alerts[] = ['type' => 'danger', 'message' => 'That email address is already in use.'];
                    } else {
                        $update = $pdo->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, general_email_opt_in = :general_opt_in, ride_notice_opt_in = :ride_notice_opt_in, renewal_reminder_opt_in = :renewal_opt_in, updated_at = NOW() WHERE id = :id LIMIT 1');
                        $update->execute([
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':email' => $email,
                            ':general_opt_in' => $generalEmailOptIn,
                            ':ride_notice_opt_in' => $rideNoticeOptIn,
                            ':renewal_opt_in' => $renewalReminderOptIn,
                            ':id' => $userId,
                        ]);
                        if ((int)($currentUser['id'] ?? 0) === $userId && isset($_SESSION['user'])) {
                            $_SESSION['user']['first_name'] = $firstName;
                            $_SESSION['user']['last_name'] = $lastName;
                            $_SESSION['user']['email'] = $email;
                            $_SESSION['user']['general_email_opt_in'] = $generalEmailOptIn;
                            $_SESSION['user']['ride_notice_opt_in'] = $rideNoticeOptIn;
                            $_SESSION['user']['renewal_reminder_opt_in'] = $renewalReminderOptIn;
                        }
                        $successMessage = 'User details updated.';
                    }
                } catch (PDOException $e) {
                    $alerts[] = ['type' => 'danger', 'message' => 'Could not update the user details.'];
                }
            }
        } elseif ($action === 'update_user') {
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
    'role'=>['label'=>'Role','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['superadmin'=>'SuperAdmin','admin'=>'Admin','manager'=>'Manager','organiser'=>'Organiser','user'=>'User']],
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
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo (int)$userRow['id']; ?>">
                                    Edit details
                                </button>
                                <span><?php echo h($fullName); ?></span>
                            </div>
                        </td>
                        <td><?php echo admin_table_value($userRow['email'] ?? '', 'email'); ?></td>
                        <td>
                            <form class="d-flex gap-2 align-items-center" method="POST">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
                                <select name="role" class="form-select form-select-sm" style="width: 180px;">
                                    <option value="superadmin" <?php echo ($userRow['role'] === 'superadmin') ? 'selected' : ''; ?>>SuperAdmin</option>
                                    <option value="admin" <?php echo ($userRow['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    <option value="manager" <?php echo ($userRow['role'] === 'manager') ? 'selected' : ''; ?>>Manager</option>
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
<?php foreach ($allUsers as $userRow): ?>
<div class="modal fade" id="editUserModal<?php echo (int)$userRow['id']; ?>" tabindex="-1" aria-labelledby="editUserModalLabel<?php echo (int)$userRow['id']; ?>" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel<?php echo (int)$userRow['id']; ?>">Edit user details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_details">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">First name</label>
                            <input class="form-control" name="first_name" value="<?php echo h((string)($userRow['first_name'] ?? '')); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Last name</label>
                            <input class="form-control" name="last_name" value="<?php echo h((string)($userRow['last_name'] ?? '')); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Login email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo h((string)($userRow['email'] ?? '')); ?>" autocomplete="email" required>
                            <div class="form-text">This is the email address the user signs in with.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="userGeneralEmail<?php echo (int)$userRow['id']; ?>" name="general_email_opt_in" value="1" <?php echo !empty($userRow['general_email_opt_in']) ? 'checked' : ''; ?>><label class="form-check-label" for="userGeneralEmail<?php echo (int)$userRow['id']; ?>">Subscribed to general news and announcements</label></div>
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="userRideNotice<?php echo (int)$userRow['id']; ?>" name="ride_notice_opt_in" value="1" <?php echo !empty($userRow['ride_notice_opt_in']) ? 'checked' : ''; ?>><label class="form-check-label" for="userRideNotice<?php echo (int)$userRow['id']; ?>">Subscribed to the weekly Ride Notice</label></div>
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="userRenewalReminder<?php echo (int)$userRow['id']; ?>" name="renewal_reminder_opt_in" value="1" <?php echo !empty($userRow['renewal_reminder_opt_in'] ?? 1) ? 'checked' : ''; ?>><label class="form-check-label" for="userRenewalReminder<?php echo (int)$userRow['id']; ?>">Receives renewal reminders</label></div>
                            <div class="form-text">Only record a subscription where the user has agreed to receive it. Essential entry-related messages are separate.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success">Save details</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php
admin_layout_end();
