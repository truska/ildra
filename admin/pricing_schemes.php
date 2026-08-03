<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage pricing schemes.'];
    } elseif ($action === 'delete_scheme') {
        $schemeId = (int)($_POST['scheme_id'] ?? 0);
        if ($schemeId > 0 && deletePricingScheme($pdo, $schemeId, $alerts)) {
            $successMessage = 'Pricing scheme deleted.';
        }
    }

    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: pricing_schemes.php');
    exit;
}

$schemes = fetchPricingSchemes($pdo);

$rowCounts = [];
if ($pdo) {
    try {
        ensurePricingSchemeTables($pdo);
        $stmt = $pdo->query("SELECT scheme_id, COUNT(*) AS total FROM pricing_scheme_rows GROUP BY scheme_id");
        foreach ($stmt->fetchAll() as $row) {
            $rowCounts[(int)($row['scheme_id'] ?? 0)] = (int)($row['total'] ?? 0);
        }
    } catch (PDOException $e) {
        $rowCounts = [];
    }
}

$sortKey = (string)($_GET['sort'] ?? 'name');
$sortDir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$allowedSort = ['name', 'rows'];
if (!in_array($sortKey, $allowedSort, true)) {
    $sortKey = 'name';
}
usort($schemes, function (array $a, array $b) use ($sortKey, $sortDir, $rowCounts): int {
    $dir = $sortDir === 'asc' ? 1 : -1;
    if ($sortKey === 'rows') {
        $va = $rowCounts[(int)($a['id'] ?? 0)] ?? 0;
        $vb = $rowCounts[(int)($b['id'] ?? 0)] ?? 0;
        return ($va <=> $vb) * $dir;
    }
    $va = (string)($a['name'] ?? '');
    $vb = (string)($b['name'] ?? '');
    return strcmp($va, $vb) * $dir;
});

admin_layout_start('Pricing Schemes', 'pricing_schemes');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Reusable class lists and prices</div>
        <h5 class="mb-0">Pricing schemes</h5>
    </div>
    <div>
        <a class="btn btn-success" href="pricing_scheme_edit.php">Add new scheme</a>
    </div>
</div>

<div class="card-soft p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th><?php echo admin_sort_link('name', 'Name', $sortKey, $sortDir); ?></th>
                <th>Applies to</th>
                <th class="text-end"><?php echo admin_sort_link('rows', 'Rows', $sortKey, $sortDir); ?></th>
                <th class="text-end"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($schemes as $scheme): ?>
                <?php
                $sid = (int)($scheme['id'] ?? 0);
                $types = $scheme['event_types'] ?? [];
                ?>
                <tr>
                    <td class="fw-semibold"><?php echo h((string)($scheme['name'] ?? '')); ?></td>
                    <td>
                        <?php if ($types): ?>
                            <?php foreach ($types as $t): ?>
                                <?php
                                $label = (string)($t['name'] ?? '');
                                if (!empty($t['is_default'])) {
                                    $label .= ' (default)';
                                }
                                ?>
                                <span class="badge text-bg-light border me-1 mb-1"><?php echo h($label); ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo (int)($rowCounts[$sid] ?? 0); ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-success" href="pricing_scheme_edit.php?id=<?php echo $sid; ?>">Edit</a>
                        <?php if ($isAdmin): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this pricing scheme?');">
                                <input type="hidden" name="action" value="delete_scheme">
                                <input type="hidden" name="scheme_id" value="<?php echo $sid; ?>">
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$schemes): ?>
                <tr><td colspan="4" class="text-muted">No pricing schemes yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
admin_layout_end();
