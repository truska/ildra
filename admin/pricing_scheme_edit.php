<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
if (!$isAdmin) {
    admin_layout_start('Pricing scheme', 'pricing_schemes');
    echo '<div class="alert alert-danger">Only admins can manage pricing schemes.</div>';
    admin_layout_end();
    exit;
}

$schemeId = (int)($_GET['id'] ?? 0);
$scheme = $schemeId > 0 ? fetchPricingSchemeById($pdo, $schemeId) : null;
$eventTypes = fetchEventTypes($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedId = savePricingScheme($pdo, $_POST, $alerts, $schemeId > 0 ? $schemeId : null);
    if ($savedId) {
        $_SESSION['flash_success'] = 'Pricing scheme saved.';
        header('Location: pricing_scheme_edit.php?id=' . (int)$savedId);
        exit;
    }
}

$selectedEventTypeIds = [];
$rows = [];
if ($schemeId > 0 && $scheme) {
    $selectedEventTypeIds = fetchPricingSchemeEventTypeIds($pdo, $schemeId);
    $rows = fetchPricingSchemeRows($pdo, $schemeId);
}

// Default checkbox list is driven by event_types.default_pricing_scheme_id.
$defaultTypeIds = [];
foreach ($eventTypes as $t) {
    if ((int)($t['default_pricing_scheme_id'] ?? 0) === $schemeId) {
        $defaultTypeIds[] = (int)($t['id'] ?? 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedEventTypeIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['event_type_ids'] ?? [])))));
    $defaultTypeIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['default_for_type_ids'] ?? [])))));
    $scheme = [
        'id' => $schemeId,
        'name' => (string)($_POST['name'] ?? ''),
    ];
    // Rebuild rows from POST for redisplay on validation failure
    $rows = [];
    $ids = (array)($_POST['row_id'] ?? []);
    $sort = (array)($_POST['row_sort'] ?? []);
    $names = (array)($_POST['row_class_name'] ?? []);
    $codes = (array)($_POST['row_class_code'] ?? []);
    $prices = (array)($_POST['row_price'] ?? []);
    $member = (array)($_POST['row_is_member_price'] ?? []);
    $keys = array_keys($names);
    sort($keys);
    foreach ($keys as $i) {
        $rows[] = [
            'id' => (int)($ids[$i] ?? 0),
            'sort_order' => (int)($sort[$i] ?? (($i + 1) * 10)),
            'class_name' => (string)($names[$i] ?? ''),
            'class_code' => (string)($codes[$i] ?? ''),
            'price' => (string)($prices[$i] ?? ''),
            'is_member_price' => !empty($member[$i]) ? 1 : 0,
        ];
    }
}

admin_layout_start($schemeId > 0 ? 'Edit pricing scheme' : 'New pricing scheme', 'pricing_schemes');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Reusable class lists and prices</div>
        <h5 class="mb-0"><?php echo $schemeId > 0 ? 'Edit scheme' : 'New scheme'; ?></h5>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="pricing_schemes.php">Back to schemes</a>
    </div>
</div>

<form method="POST" class="card-soft p-4">
    <div class="mb-4">
        <label class="form-label fw-semibold" for="schemeName">Scheme name</label>
        <input class="form-control" id="schemeName" name="name" value="<?php echo h((string)($scheme['name'] ?? '')); ?>" required>
    </div>

    <div class="card-soft p-3 mb-4" style="box-shadow:none;">
        <div class="fw-semibold mb-1">Applies to event types</div>
        <div class="text-muted small mb-3">Select where this scheme can be used. Optional: mark it as the default for a type.</div>

        <div class="row g-3">
            <?php foreach ($eventTypes as $t): ?>
                <?php
                $tid = (int)($t['id'] ?? 0);
                $isChecked = in_array($tid, $selectedEventTypeIds, true);
                $isDefault = in_array($tid, $defaultTypeIds, true);
                ?>
                <div class="col-12 col-lg-4">
                    <div class="border rounded-3 p-3 h-100">
                        <div class="form-check mb-2">
                            <input class="form-check-input js-type-toggle" type="checkbox" id="type_<?php echo $tid; ?>" name="event_type_ids[]" value="<?php echo $tid; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="type_<?php echo $tid; ?>">
                                <?php echo h((string)($t['name'] ?? '')); ?>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input js-default-toggle" type="checkbox" id="default_<?php echo $tid; ?>" name="default_for_type_ids[]" value="<?php echo $tid; ?>" <?php echo $isDefault ? 'checked' : ''; ?> <?php echo $isChecked ? '' : 'disabled'; ?>>
                            <label class="form-check-label" for="default_<?php echo $tid; ?>">
                                Default for this type
                            </label>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card-soft p-3 mb-4" style="box-shadow:none;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <div class="fw-semibold">Pricing rows</div>
                <div class="text-muted small">Add one or more class rows. Member pricing is marked explicitly.</div>
            </div>
            <button class="btn btn-sm btn-outline-primary" type="button" id="addRowBtn">Add row</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle" id="rowsTable">
                <thead class="table-light">
                <tr>
                    <th style="width:110px;">Order</th>
                    <th style="width:130px;">Code (opt)</th>
                    <th>Class name</th>
                    <th style="width:140px;">Price (£)</th>
                    <th style="width:160px;">Member price?</th>
                    <th style="width:140px;">Junior ride?</th>
                    <th style="width:80px;"></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <?php $rows = [['id' => 0, 'sort_order' => 10, 'class_code' => '', 'class_name' => '', 'price' => '0', 'is_member_price' => 0, 'is_junior_ride' => 0]]; ?>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td>
                            <input type="hidden" data-row-field="id" name="row_id[<?php echo (int)$i; ?>]" value="<?php echo (int)($r['id'] ?? 0); ?>">
                            <input class="form-control form-control-sm" data-row-field="sort" name="row_sort[<?php echo (int)$i; ?>]" type="number" value="<?php echo (int)($r['sort_order'] ?? (($i + 1) * 10)); ?>" step="1">
                        </td>
                        <td>
                            <input class="form-control form-control-sm" data-row-field="code" name="row_class_code[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['class_code'] ?? '')); ?>" maxlength="32">
                        </td>
                        <td>
                            <input class="form-control form-control-sm" data-row-field="name" name="row_class_name[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['class_name'] ?? '')); ?>" required>
                        </td>
                        <td>
                            <input class="form-control form-control-sm" data-row-field="price" name="row_price[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['price'] ?? '0')); ?>" inputmode="decimal">
                        </td>
                        <td class="text-center">
                            <input class="form-check-input" data-row-field="member_checkbox" type="checkbox" name="row_is_member_price[<?php echo (int)$i; ?>]" value="1" <?php echo !empty($r['is_member_price']) ? 'checked' : ''; ?>>
                        </td>
                        <td class="text-center">
                            <input class="form-check-input" data-row-field="junior_checkbox" type="checkbox" name="row_is_junior_ride[<?php echo (int)$i; ?>]" value="1" <?php echo !empty($r['is_junior_ride']) ? 'checked' : ''; ?>>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger js-remove-row" type="button">Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-success" type="submit">Save scheme</button>
        <a class="btn btn-outline-secondary" href="pricing_schemes.php">Cancel</a>
    </div>
</form>

<template id="rowTemplate">
    <tr>
        <td>
            <input type="hidden" data-row-field="id" name="row_id[0]" value="0">
            <input class="form-control form-control-sm" data-row-field="sort" name="row_sort[0]" type="number" value="10" step="1">
        </td>
        <td>
            <input class="form-control form-control-sm" data-row-field="code" name="row_class_code[0]" value="" maxlength="32">
        </td>
        <td>
            <input class="form-control form-control-sm" data-row-field="name" name="row_class_name[0]" value="" required>
        </td>
        <td>
            <input class="form-control form-control-sm" data-row-field="price" name="row_price[0]" value="0" inputmode="decimal">
        </td>
        <td class="text-center">
            <input class="form-check-input" data-row-field="member_checkbox" type="checkbox" name="row_is_member_price[0]" value="1">
        </td>
        <td class="text-center">
            <input class="form-check-input" data-row-field="junior_checkbox" type="checkbox" name="row_is_junior_ride[0]" value="1">
        </td>
        <td class="text-end">
            <button class="btn btn-sm btn-outline-danger js-remove-row" type="button">Remove</button>
        </td>
    </tr>
</template>

<script>
    (function () {
        const typeToggles = document.querySelectorAll('.js-type-toggle');
        typeToggles.forEach((cb) => {
            cb.addEventListener('change', () => {
                const tid = cb.value;
                const def = document.getElementById('default_' + tid);
                if (!def) return;
                def.disabled = !cb.checked;
                if (!cb.checked) def.checked = false;
            });
        });

        const addBtn = document.getElementById('addRowBtn');
        const tableBody = document.querySelector('#rowsTable tbody');
        const tpl = document.getElementById('rowTemplate');

        function syncRowIndexes() {
            const rows = tableBody.querySelectorAll('tr');
            rows.forEach((row, idx) => {
                const id = row.querySelector('[data-row-field="id"]');
                if (id) id.name = `row_id[${idx}]`;
                const sort = row.querySelector('[data-row-field="sort"]');
                if (sort) sort.name = `row_sort[${idx}]`;
                const code = row.querySelector('[data-row-field="code"]');
                if (code) code.name = `row_class_code[${idx}]`;
                const name = row.querySelector('[data-row-field="name"]');
                if (name) name.name = `row_class_name[${idx}]`;
                const price = row.querySelector('[data-row-field="price"]');
                if (price) price.name = `row_price[${idx}]`;
                const member = row.querySelector('[data-row-field="member_checkbox"]');
                if (member) member.name = `row_is_member_price[${idx}]`;
                const junior = row.querySelector('[data-row-field="junior_checkbox"]');
                if (junior) junior.name = `row_is_junior_ride[${idx}]`;
            });
        }

        function nextSortValue() {
            const inputs = tableBody.querySelectorAll('[data-row-field="sort"]');
            let max = 0;
            inputs.forEach((i) => { max = Math.max(max, parseInt(i.value || '0', 10) || 0); });
            return max > 0 ? max + 10 : 10;
        }

        function wireRemoveButtons() {
            tableBody.querySelectorAll('.js-remove-row').forEach((btn) => {
                btn.onclick = () => {
                    const row = btn.closest('tr');
                    if (row) row.remove();
                    syncRowIndexes();
                };
            });
        }

        addBtn.addEventListener('click', () => {
            const clone = tpl.content.cloneNode(true);
            const row = clone.querySelector('tr');
            const sortInput = row.querySelector('[data-row-field="sort"]');
            if (sortInput) sortInput.value = String(nextSortValue());
            tableBody.appendChild(clone);
            syncRowIndexes();
            wireRemoveButtons();
        });

        wireRemoveButtons();
        syncRowIndexes();
    })();
</script>
<?php
admin_layout_end();
