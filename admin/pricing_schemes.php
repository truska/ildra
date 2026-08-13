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

$appliesOptions = [];
foreach ($schemes as &$scheme) {
    $scheme['_row_count'] = $rowCounts[(int)($scheme['id'] ?? 0)] ?? 0;
    $scheme['_event_type_ids'] = [];
    $scheme['_applies_label'] = '';
    $labels = [];
    foreach ((array)($scheme['event_types'] ?? []) as $eventType) {
        $typeId = (int)($eventType['id'] ?? 0);
        $label = trim((string)($eventType['name'] ?? ''));
        if ($typeId > 0 && $label !== '') {
            $scheme['_event_type_ids'][] = $typeId;
            $appliesOptions[(string)$typeId] = $label;
            $labels[] = $label;
        }
    }
    $scheme['_applies_label'] = implode(', ', $labels);
}
unset($scheme);
natcasesort($appliesOptions);
$filterForm = 'pricing-schemes-filter-form';
$tableColumns = [
    'name' => ['label'=>'Name', 'field'=>'name', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm],
    'applies_to' => ['label'=>'Applies to', 'sortable'=>true, 'filter'=>'select', 'options'=>$appliesOptions, 'form'=>$filterForm,
        'value'=>static fn(array $row): string => (string)($row['_applies_label'] ?? ''),
        'filter_match'=>static fn(array $row, string $needle): bool => in_array((int)$needle, (array)($row['_event_type_ids'] ?? []), true)],
    'rows' => ['label'=>'Rows', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => (string)($row['_row_count'] ?? 0)],
];
$table = admin_table_prepare($schemes, $tableColumns, 'name');
$schemes = $table['rows'];

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

<form method="get" id="<?php echo h($filterForm); ?>"></form>
<div class="card-soft p-3">
    <?php echo admin_table_record_count($table, 'pricing scheme', 'pricing schemes'); ?>
    <div class="table-responsive">
        <table class="table table-sm admin-data-table align-middle">
            <thead class="table-light">
            <tr>
                <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
                <th class="text-end"></th>
            </tr>
            <tr class="admin-table-filter-row">
                <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th><?php endforeach; ?>
                <th class="text-end"><a class="btn btn-sm btn-outline-secondary" href="pricing_schemes.php">Clear</a></th>
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
                    <td><?php echo (int)($scheme['_row_count'] ?? 0); ?></td>
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
                <tr><td colspan="4" class="text-muted">No pricing schemes match these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_table_pagination($table); ?>
</div>
<?php
admin_layout_end();
