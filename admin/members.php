<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$memberships = fetchMemberships($pdo);
$filterForm = 'members-filter-form';

$typeOptions = [];
$statusOptions = [];
$periodOptions = [];
foreach ($memberships as $membership) {
    $type = trim((string)($membership['membership_name'] ?? ''));
    if ($type !== '') $typeOptions[$type] = $type;
    $status = trim((string)($membership['status'] ?? ''));
    if ($status !== '') $statusOptions[$status] = ucfirst($status);
    $start = trim((string)($membership['starts_at'] ?? ''));
    $end = trim((string)($membership['ends_at'] ?? ''));
    $periodKey = $start . '|' . $end;
    $periodLabel = format_display_date($start ?: null, 'No start') . ' – ' . format_display_date($end ?: null, 'No end');
    $periodOptions[$periodKey] = $periodLabel;
}
natcasesort($typeOptions);
natcasesort($statusOptions);
asort($periodOptions);

$tableColumns = [
    'membership_number' => ['label'=>'Membership No.', 'field'=>'member_number', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm],
    'member' => ['label'=>'Member', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => trim((string)($row['member_name'] ?? ''))],
    'email' => ['label'=>'Email', 'field'=>'user_email', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm],
    'type' => ['label'=>'Type', 'field'=>'membership_name', 'sortable'=>true, 'filter'=>'select', 'options'=>$typeOptions, 'form'=>$filterForm],
    'status' => ['label'=>'Status', 'field'=>'status', 'sortable'=>true, 'filter'=>'select', 'options'=>$statusOptions, 'form'=>$filterForm],
    'period' => ['label'=>'Period', 'sortable'=>true, 'filter'=>'select', 'options'=>$periodOptions, 'form'=>$filterForm,
        'value'=>static fn(array $row): string => trim((string)($row['starts_at'] ?? '')) . '|' . trim((string)($row['ends_at'] ?? '')),
        'sort_value'=>static fn(array $row): string => (string)($row['starts_at'] ?? '')],
    'purchased' => ['label'=>'Purchased', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => format_display_date($row['purchased_at'] ?? null, ''),
        'sort_value'=>static fn(array $row): string => (string)($row['purchased_at'] ?? '')],
    'amount' => ['label'=>'Amount', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
        'sort_value'=>static fn(array $row): float => (float)($row['amount'] ?? 0)],
];
$table = admin_table_prepare($memberships, $tableColumns, 'status');
$memberships = $table['rows'];

admin_layout_start('Members', 'members');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><div class="small text-muted">Memberships</div><h5 class="mb-0">Members (active &amp; expired)</h5></div>
</div>
<form method="get" id="<?php echo h($filterForm); ?>"></form>
<section class="card-soft p-3">
    <?php echo admin_table_record_count($table, 'membership', 'memberships'); ?>
    <div class="table-responsive">
        <table class="table table-sm admin-data-table align-middle mb-0">
            <thead>
                <tr>
                    <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
                </tr>
                <tr class="admin-table-filter-row">
                    <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($memberships as $membership): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo h((string)($membership['member_number'] ?? '—')); ?></td>
                        <td><?php echo h(trim((string)($membership['member_name'] ?? '')) ?: '—'); ?></td>
                        <td><?php echo admin_table_value($membership['user_email'] ?? '', 'email'); ?></td>
                        <td><?php echo h((string)($membership['membership_name'] ?? '')); ?></td>
                        <td><span class="text-capitalize"><?php echo h((string)($membership['status'] ?? '')); ?></span></td>
                        <td class="text-muted text-nowrap"><?php echo h(format_display_date($membership['starts_at'] ?? null, '')); ?><br><?php echo h(format_display_date($membership['ends_at'] ?? null, '')); ?></td>
                        <td class="text-muted text-nowrap"><?php echo h(format_display_date($membership['purchased_at'] ?? null, '')); ?></td>
                        <td class="fw-semibold text-nowrap">£<?php echo h(number_format((float)($membership['amount'] ?? 0), 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$memberships): ?><tr><td colspan="8" class="text-muted">No memberships match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-end mt-2"><a class="btn btn-sm btn-outline-secondary" href="members.php">Clear filters</a></div>
    <?php echo admin_table_pagination($table); ?>
</section>
<?php admin_layout_end(); ?>
