<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManagePeople = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManagePeople) {
    header('Location: index.php');
    exit;
}

ensureMembershipTables($pdo);

$rows = [];
if ($pdo) {
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.owner_user_id,
            p.member_number,
            p.first_name,
            p.last_name,
            p.dob,
            p.email,
            p.phone,
            p.is_archived,
            p.created_at,
            u.email AS owner_email
        FROM people p
        LEFT JOIN users u ON u.id = p.owner_user_id
        ORDER BY p.last_name ASC, p.first_name ASC, p.id ASC
    ");
    $rows = $stmt->fetchAll() ?: [];
}

$sortKey = $_GET['sort'] ?? 'name';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$sortFields = [
    'name' => ['last_name', 'first_name', 'id'],
    'member_number' => ['member_number', 'last_name', 'first_name', 'id'],
    'dob' => ['dob', 'last_name', 'first_name', 'id'],
    'owner' => ['owner_email', 'last_name', 'first_name', 'id'],
    'created' => ['created_at', 'id'],
    'status' => ['is_archived', 'last_name', 'first_name', 'id'],
];
$activeFields = $sortFields[$sortKey] ?? $sortFields['name'];

usort($rows, function (array $a, array $b) use ($activeFields, $sortDir): int {
    foreach ($activeFields as $field) {
        $va = $a[$field] ?? '';
        $vb = $b[$field] ?? '';
        $va = is_string($va) ? mb_strtolower($va) : $va;
        $vb = is_string($vb) ? mb_strtolower($vb) : $vb;
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

admin_layout_start('People', 'people');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">People stored per account</div>
        <h5 class="mb-0">People</h5>
    </div>
</div>

<div class="card-soft p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th><?php echo admin_sort_link('name', 'Person', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('member_number', 'Member #', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('dob', 'DOB', (string)$sortKey, (string)$sortDir); ?></th>
                <th>Email</th>
                <th>Phone</th>
                <th><?php echo admin_sort_link('owner', 'Owner (user)', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('status', 'Status', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('created', 'Created', (string)$sortKey, (string)$sortDir); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                $name = $name !== '' ? $name : '—';
                $memberNumber = $row['member_number'] !== null ? (string)$row['member_number'] : '—';
                $dob = $row['dob'] ? format_display_date((string)$row['dob'], '—') : '—';
                $status = !empty($row['is_archived']) ? 'Archived' : 'Active';
                $created = $row['created_at'] ? format_display_datetime((string)$row['created_at'], '—') : '—';
                ?>
                <tr>
                    <td class="fw-semibold"><?php echo h($name); ?></td>
                    <td class="text-muted small"><?php echo h($memberNumber); ?></td>
                    <td class="text-muted small"><?php echo h($dob); ?></td>
                    <td class="text-muted small"><?php echo h((string)($row['email'] ?? '—')); ?></td>
                    <td class="text-muted small"><?php echo h((string)($row['phone'] ?? '—')); ?></td>
                    <td class="text-muted small"><?php echo h((string)($row['owner_email'] ?? '—')); ?></td>
                    <td class="text-muted small"><?php echo h($status); ?></td>
                    <td class="text-muted small"><?php echo h($created); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-muted">No people yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
admin_layout_end();
