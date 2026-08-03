<?php
declare(strict_types=1);

if (!function_exists('admin_sort_link')) {
    require_once __DIR__ . '/../admin/table_sort.php';
}

$dashSortKey = $_GET['sort'] ?? 'name';
$dashSortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$dashAllowed = ['name', 'email', 'role', 'level', 'last_login'];
if (!in_array($dashSortKey, $dashAllowed, true)) {
    $dashSortKey = 'name';
}

if (isset($allUsers) && is_array($allUsers)) {
    usort($allUsers, function (array $a, array $b) use ($dashSortKey, $dashSortDir): int {
        $dir = $dashSortDir === 'asc' ? 1 : -1;
        if ($dashSortKey === 'level') {
            $va = (int)($a['level'] ?? 0);
            $vb = (int)($b['level'] ?? 0);
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($dashSortKey === 'last_login') {
            $va = (string)($a['last_login_at'] ?? '');
            $vb = (string)($b['last_login_at'] ?? '');
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($dashSortKey === 'role') {
            $va = mb_strtolower((string)($a['role'] ?? ''));
            $vb = mb_strtolower((string)($b['role'] ?? ''));
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($dashSortKey === 'email') {
            $va = mb_strtolower((string)($a['email'] ?? ''));
            $vb = mb_strtolower((string)($b['email'] ?? ''));
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        // name
        $va = mb_strtolower(trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? '')));
        $vb = mb_strtolower(trim((string)($b['first_name'] ?? '') . ' ' . (string)($b['last_name'] ?? '')));
        if ($va === $vb) {
            return 0;
        }
        return ($va < $vb ? -1 : 1) * $dir;
    });
}
?>

<div class="glass-card rounded-4 p-4 shadow-lift">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <div class="text-uppercase small text-secondary">Welcome back</div>
            <h4 class="fw-semibold mb-0">
                <?php echo h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?: 'User'); ?>
            </h4>
            <div class="text-secondary small"><?php echo h($currentUser['email'] ?? ''); ?></div>
        </div>
        <div class="text-end">
            <div class="badge-role mb-2 text-capitalize"><?php echo h($currentUser['role']); ?></div>
            <div class="text-secondary small">Level <?php echo h((string)$currentUser['level']); ?></div>
        </div>
    </div>
    <div class="divider"></div>
    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <div class="stat-chip">
                <div class="stat-icon">
                    <span aria-hidden="true">&#9889;</span>
                </div>
                <div>
                    <div class="text-secondary small">Activity score</div>
                    <div class="h6 mb-0">87</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="stat-chip">
                <div class="stat-icon">
                    <span aria-hidden="true">&#128200;</span>
                </div>
                <div>
                    <div class="text-secondary small">Success rate</div>
                    <div class="h6 mb-0">98%</div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-sm-4">
            <div class="shortcut-card h-100">
                <div class="fw-semibold">Projects</div>
                <div class="text-secondary small">View and manage</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="shortcut-card h-100">
                <div class="fw-semibold">Team</div>
                <div class="text-secondary small">Invite or assign</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="shortcut-card h-100">
                <div class="fw-semibold">Reports</div>
                <div class="text-secondary small">Export insights</div>
            </div>
        </div>
    </div>
    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
        <div class="divider"></div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">User management</div>
            <div class="text-secondary small"><?php echo count($allUsers); ?> users</div>
        </div>
	        <div class="table-responsive mb-3">
	            <table class="table table-sm align-middle mb-0">
	                <thead class="text-secondary small">
	                    <tr>
	                        <th scope="col"><?php echo admin_sort_link('name', 'Name', (string)$dashSortKey, (string)$dashSortDir); ?></th>
	                        <th scope="col"><?php echo admin_sort_link('email', 'Email', (string)$dashSortKey, (string)$dashSortDir); ?></th>
	                        <th scope="col"><?php echo admin_sort_link('role', 'Role', (string)$dashSortKey, (string)$dashSortDir); ?></th>
	                        <th scope="col"><?php echo admin_sort_link('level', 'Level', (string)$dashSortKey, (string)$dashSortDir); ?></th>
	                        <th scope="col"><?php echo admin_sort_link('last_login', 'Last login', (string)$dashSortKey, (string)$dashSortDir); ?></th>
	                    </tr>
	                </thead>
                <tbody class="small">
                    <?php foreach ($allUsers as $userRow): ?>
                        <?php
                        $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
                        $fullName = $fullName !== '' ? $fullName : '—';
                        $lastLogin = $userRow['last_login_at'] ? h($userRow['last_login_at']) : '—';
                        ?>
                        <tr>
                            <td><?php echo h($fullName); ?></td>
                            <td><?php echo h($userRow['email']); ?></td>
                            <td><span class="badge-role text-capitalize"><?php echo h($userRow['role']); ?></span></td>
                            <td><?php echo h((string)$userRow['level']); ?></td>
                            <td class="text-secondary"><?php echo $lastLogin; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$allUsers): ?>
                        <tr><td colspan="5" class="text-secondary text-center py-3">No users found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center">
        <div class="text-secondary small">Signed in with secure session</div>
        <a class="btn btn-outline-success btn-sm" href="?logout=1">Logout</a>
    </div>
</div>
