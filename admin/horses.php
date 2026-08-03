<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageHorses = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManageHorses) {
    header('Location: index.php');
    exit;
}

ensureHorsesTables($pdo);

$rows = [];
if ($pdo) {
    $stmt = $pdo->query("
        SELECT
            h.id,
            h.owner_user_id,
            h.name,
            h.dob,
            h.year_of_birth,
            h.breed,
            h.colour,
            h.is_archived,
            h.created_at,
            u.email AS owner_email
        FROM horses h
        LEFT JOIN users u ON u.id = h.owner_user_id
        ORDER BY h.name ASC, h.id ASC
    ");
    $rows = $stmt->fetchAll() ?: [];
}

$sortKey = $_GET['sort'] ?? 'name';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$sortFields = [
    'name' => ['name', 'id'],
    'dob' => ['dob', 'name', 'id'],
    'breed' => ['breed', 'name', 'id'],
    'colour' => ['colour', 'name', 'id'],
    'owner' => ['owner_email', 'name', 'id'],
    'status' => ['is_archived', 'name', 'id'],
    'created' => ['created_at', 'id'],
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

admin_layout_start('Horses', 'horses');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Horses stored per account</div>
        <h5 class="mb-0">Horses</h5>
    </div>
</div>

<div class="card-soft p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th><?php echo admin_sort_link('name', 'Horse', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('dob', 'DOB', (string)$sortKey, (string)$sortDir); ?></th>
                <th>Year of birth</th>
                <th><?php echo admin_sort_link('breed', 'Breed', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('colour', 'Colour', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('owner', 'Owner (user)', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('status', 'Status', (string)$sortKey, (string)$sortDir); ?></th>
                <th><?php echo admin_sort_link('created', 'Created', (string)$sortKey, (string)$sortDir); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $name = trim((string)($row['name'] ?? ''));
                $name = $name !== '' ? $name : '—';
                $dob = $row['dob'] ? format_display_date((string)$row['dob'], '—') : '—';
                $yob = trim((string)($row['year_of_birth'] ?? ''));
                $yob = $yob !== '' ? $yob : '—';
                $breed = trim((string)($row['breed'] ?? ''));
                $breed = $breed !== '' ? $breed : '—';
                $colour = trim((string)($row['colour'] ?? ''));
                $colour = $colour !== '' ? $colour : '—';
                $status = !empty($row['is_archived']) ? 'Archived' : 'Active';
                $created = $row['created_at'] ? format_display_datetime((string)$row['created_at'], '—') : '—';
                ?>
                <tr>
                    <td class="fw-semibold"><?php echo h($name); ?></td>
                    <td class="text-muted small"><?php echo h($dob); ?></td>
                    <td class="text-muted small"><?php echo h($yob); ?></td>
                    <td class="text-muted small"><?php echo h($breed); ?></td>
                    <td class="text-muted small"><?php echo h($colour); ?></td>
                    <td class="text-muted small"><?php echo h((string)($row['owner_email'] ?? '—')); ?></td>
                    <td class="text-muted small"><?php echo h($status); ?></td>
                    <td class="text-muted small"><?php echo h($created); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-muted">No horses yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
admin_layout_end();
