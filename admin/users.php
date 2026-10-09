<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

ensureDevTaskTables($pdo);

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageUsers = in_array($currentRole, ['developer', 'superadmin', 'admin', 'manager'], true);
$canManageRolesAndPasswords = roleIsSuperadminOrDeveloper($currentRole);
$canImpersonateUsers = in_array($currentRole, ['developer', 'superadmin', 'admin'], true);
if (!$canManageUsers) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageUsers) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage users.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete_user') {
            try {
                $outcome = deleteOrArchiveUser($pdo, (int)($_POST['user_id'] ?? 0), $currentUser);
                $successMessage = $outcome === 'archived'
                    ? 'User archived because transaction history or linked records exist. Sign-in is disabled.'
                    : 'User deleted.';
            } catch (Throwable $e) {
                $alerts[] = ['type'=>'danger', 'message'=>($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Could not delete or archive the user. No changes were saved.'];
            }
        } elseif ($action === 'update_details') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $generalEmailOptIn = !empty($_POST['general_email_opt_in']) ? 1 : 0;
            $rideNoticeOptIn = !empty($_POST['ride_notice_opt_in']) ? 1 : 0;
            $renewalReminderOptIn = !empty($_POST['renewal_reminder_opt_in']) ? 1 : 0;
            $isTester = !empty($_POST['is_tester']) ? 1 : 0;

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
                        $update = $pdo->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, general_email_opt_in = :general_opt_in, ride_notice_opt_in = :ride_notice_opt_in, renewal_reminder_opt_in = :renewal_opt_in, is_tester = :is_tester, updated_at = NOW() WHERE id = :id LIMIT 1');
                        $update->execute([
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':email' => $email,
                            ':general_opt_in' => $generalEmailOptIn,
                            ':ride_notice_opt_in' => $rideNoticeOptIn,
                            ':renewal_opt_in' => $renewalReminderOptIn,
                            ':is_tester' => $isTester,
                            ':id' => $userId,
                        ]);
                        if ((int)($currentUser['id'] ?? 0) === $userId && isset($_SESSION['user'])) {
                            $_SESSION['user']['first_name'] = $firstName;
                            $_SESSION['user']['last_name'] = $lastName;
                            $_SESSION['user']['email'] = $email;
                            $_SESSION['user']['general_email_opt_in'] = $generalEmailOptIn;
                            $_SESSION['user']['ride_notice_opt_in'] = $rideNoticeOptIn;
                            $_SESSION['user']['renewal_reminder_opt_in'] = $renewalReminderOptIn;
                            $_SESSION['user']['is_tester'] = $isTester;
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
            if (!$canManageRolesAndPasswords) {
                $alerts[] = ['type' => 'danger', 'message' => 'Only SuperAdmins can change user roles.'];
            } elseif ($role === 'developer' && !roleIsSuperadminOrDeveloper($currentRole)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Only a Developer or SuperAdmin can assign the Developer role.'];
            } elseif ($userId > 0 && updateUserRoleAndLevel($pdo, $userId, $role, $level, $alerts)) {
                adminAuditLog($pdo, 'users.change_role', $currentUser, 'user', $userId, ['new_role'=>$role]);
                $successMessage = 'User role updated.';
            }
        } elseif ($action === 'reset_password') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            if (!$canManageRolesAndPasswords) {
                $alerts[] = ['type' => 'danger', 'message' => 'Only SuperAdmins can reset passwords.'];
            } elseif ($userId > 0 && resetUserPassword($pdo, $userId, $newPassword, $alerts)) {
                adminAuditLog($pdo, 'users.reset_password', $currentUser, 'user', $userId);
                $successMessage = 'Password reset.';
            }
        } elseif ($action === 'act_as') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if (!$canImpersonateUsers) {
                $alerts[] = ['type' => 'danger', 'message' => 'Only SuperAdmins and Admins can impersonate standard users.'];
            } elseif ($targetUserId <= 0) {
                $alerts[] = ['type' => 'danger', 'message' => 'Invalid user.'];
            } elseif ((int)($currentUser['id'] ?? 0) === $targetUserId) {
                $alerts[] = ['type' => 'warning', 'message' => 'You are already signed in as this user.'];
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name, u.last_login_at
                        FROM users u
                        JOIN roles r ON r.id = u.role_id
                        WHERE u.id = :id AND u.is_archived=0
                        LIMIT 1
                    ");
                    $stmt->execute([':id' => $targetUserId]);
                    $target = $stmt->fetch();
                    if (!$target) {
                        $alerts[] = ['type' => 'danger', 'message' => 'User not found.'];
                    } elseif (strtolower((string)($target['role'] ?? '')) !== 'user') {
                        $alerts[] = ['type' => 'danger', 'message' => 'You can only act as a standard user.'];
                    } else {
                        // Store the real admin user, then switch the session user to the target.
                        // Admin area is blocked while acting-as (see `admin/_bootstrap.php`).
                        $_SESSION['act_as_original_user'] = $_SESSION['user'] ?? $currentUser;
                        $_SESSION['act_as_started_at'] = time();
                        adminAuditLog($pdo, 'users.impersonation_start', $currentUser, 'user', $targetUserId, ['target_email'=>(string)$target['email']]);
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
    'role'=>['label'=>'Role','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['developer'=>'Developer','superadmin'=>'SuperAdmin','admin'=>'Admin','manager'=>'Manager','organiser'=>'Organiser','user'=>'User']],
    'status'=>['label'=>'Status','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['active'=>'Active','archived'=>'Archived'],'value'=>static fn(array $r):string=>!empty($r['is_archived'])?'archived':'active'],
    'general'=>['label'=>'Gen','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['1'=>'Yes','0'=>'No'],'value'=>static fn(array $r):string=>!empty($r['general_email_opt_in'])?'1':'0'],
    'weekly'=>['label'=>'Week','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['1'=>'Yes','0'=>'No'],'value'=>static fn(array $r):string=>!empty($r['ride_notice_opt_in'])?'1':'0'],
    'renewal'=>['label'=>'Renew','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>['1'=>'Yes','0'=>'No'],'value'=>static fn(array $r):string=>!empty($r['renewal_reminder_opt_in'] ?? 1)?'1':'0'],
    'last_login'=>['label'=>'Last login','field'=>'last_login_at','sortable'=>true,'filter'=>'text','placeholder'=>'Search last login','form'=>$filterForm],
    'actions'=>['label'=>'Actions'],
];
$subscriptionColumns = ['general'=>'General news', 'weekly'=>'Weekly Ride Notice', 'renewal'=>'Renewal reminders'];
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

<style>
.users-list .subscription-column { width: 4.5rem; min-width: 4.5rem; text-align: center; }
.users-list .subscription-column .form-select { padding-left: .35rem; padding-right: 1.4rem; }
.users-list th[rowspan] { vertical-align: bottom; }
</style>
<div class="card-soft p-3">
    <?php echo admin_table_record_count($table,'user','users'); ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle users-list">
            <thead class="table-light">
                <tr>
                    <?php foreach ($tableColumns as $key=>$column): ?>
                        <?php if (isset($subscriptionColumns[$key])): ?>
                            <?php if ($key==='general'): ?><th colspan="3" scope="colgroup" class="text-center">Subscribed</th><?php endif; ?>
                        <?php else: ?>
                            <th rowspan="2" scope="col" class="<?php echo $key==='actions'?'text-end':''; ?>"><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($subscriptionColumns as $key=>$label): ?><th scope="col" class="subscription-column" title="<?php echo h($label); ?>"><?php echo admin_table_heading($key,$tableColumns[$key],$sortKey,$sortDir); ?></th><?php endforeach; ?>
                </tr>
                <tr class="admin-table-filter-row"><?php foreach($tableColumns as$key=>$column): ?><th class="<?php echo isset($subscriptionColumns[$key])?'subscription-column':''; ?>"><?php echo admin_table_filter($key,$column,$filters); ?></th><?php endforeach; ?></tr>
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
                        <td><?php echo h($tableColumns['role']['options'][$userRow['role']] ?? ucfirst($userRow['role'])); ?></td>
                        <td><?php echo !empty($userRow['is_archived']) ? 'Archived' : 'Active'; ?></td>
                        <?php foreach ($subscriptionColumns as $key=>$label): $subscribed = $tableColumns[$key]['value']($userRow)==='1'; ?>
                        <td class="subscription-column"><span title="<?php echo h($label . ': ' . ($subscribed ? 'Yes' : 'No')); ?>"><i class="fa-solid <?php echo $subscribed ? 'fa-check text-success' : 'fa-xmark text-muted'; ?>" aria-hidden="true"></i><span class="visually-hidden"><?php echo $subscribed ? 'Yes' : 'No'; ?></span></span></td>
                        <?php endforeach; ?>
                        <td class="text-muted small"><?php echo $lastLogin; ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-success has-icon" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo (int)$userRow['id']; ?>"><i class="fa-solid fa-pen-to-square btn-icon" aria-hidden="true"></i><span class="btn-label">Edit</span></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$allUsers): ?>
                    <tr><td colspan="9" class="text-muted">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php foreach ($allUsers as $userRow): ?>
<div class="modal fade" id="editUserModal<?php echo (int)$userRow['id']; ?>" tabindex="-1" aria-labelledby="editUserModalLabel<?php echo (int)$userRow['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
            <form method="POST" class="modal-content">
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
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="userTester<?php echo (int)$userRow['id']; ?>" name="is_tester" value="1" <?php echo !empty($userRow['is_tester']) ? 'checked' : ''; ?>><label class="form-check-label" for="userTester<?php echo (int)$userRow['id']; ?>">Member of the Test Group</label></div>
                            <div class="form-text">Only record a subscription where the user has agreed to receive it. Essential entry-related messages are separate.</div>
                        </div>
                    </div>
                    <?php if ($canManageRolesAndPasswords): ?>
                    <div class="border-top mt-3 pt-3">
                        <label class="form-label fw-semibold" for="user-role-<?php echo (int)$userRow['id']; ?>">User role / level</label>
                        <div class="d-flex flex-wrap gap-2">
                            <select class="form-select flex-grow-1" style="width:auto" id="user-role-<?php echo (int)$userRow['id']; ?>" name="role" form="roleUserForm<?php echo (int)$userRow['id']; ?>">
                                <?php foreach ($tableColumns['role']['options'] as $roleValue=>$roleLabel): ?>
                                <option value="<?php echo h($roleValue); ?>" <?php echo $userRow['role']===$roleValue?'selected':''; ?>><?php echo h($roleLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-success" type="submit" form="roleUserForm<?php echo (int)$userRow['id']; ?>">Save role</button>
                        </div>
                    </div>
                    <div class="border-top mt-3 pt-3">
                        <label class="form-label fw-semibold" for="user-password-<?php echo (int)$userRow['id']; ?>">Reset password</label>
                        <div class="d-flex flex-wrap gap-2">
                            <input type="password" class="form-control flex-grow-1" style="width:auto" id="user-password-<?php echo (int)$userRow['id']; ?>" name="new_password" form="passwordUserForm<?php echo (int)$userRow['id']; ?>" autocomplete="new-password" minlength="8" required placeholder="New password">
                            <button class="btn btn-outline-secondary" type="submit" form="passwordUserForm<?php echo (int)$userRow['id']; ?>">Reset password</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($canImpersonateUsers && ($userRow['role'] ?? '') === 'user' && empty($userRow['is_archived']) && (int)$userRow['id'] !== (int)$currentUser['id']): ?>
                    <div class="border-top mt-3 pt-3"><button class="btn btn-outline-secondary" type="submit" form="actAsUserForm<?php echo (int)$userRow['id']; ?>">Act as this user</button></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <?php if (roleIsSuperadminOrDeveloper($currentRole) && ($userRow['role'] ?? '') === 'user' && (int)$userRow['id'] !== (int)$currentUser['id'] && empty($userRow['is_archived'])): ?>
                    <button class="btn btn-danger me-auto" type="submit" form="deleteUserForm<?php echo (int)$userRow['id']; ?>" data-bs-dismiss="modal" data-confirm-style="danger" data-confirm-title="Delete user?" data-confirm-button="Delete or archive" data-confirm-message="Delete this user? If transaction history or linked records exist, the account will be archived instead and sign-in disabled.">Delete</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success">Save details</button>
                </div>
            </form>
    </div>
</div>
<?php if ($canManageRolesAndPasswords): ?>
<form method="post" id="roleUserForm<?php echo (int)$userRow['id']; ?>">
    <input type="hidden" name="action" value="update_user">
    <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
    <input type="hidden" name="level" value="0">
</form>
<form method="post" id="passwordUserForm<?php echo (int)$userRow['id']; ?>">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
</form>
<?php endif; ?>
<form method="post" id="actAsUserForm<?php echo (int)$userRow['id']; ?>">
    <input type="hidden" name="action" value="act_as">
    <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
</form>
<form method="post" id="deleteUserForm<?php echo (int)$userRow['id']; ?>">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" value="<?php echo (int)$userRow['id']; ?>">
</form>
<?php endforeach; ?>
<?php
admin_layout_end();
