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
$rideClassOptions = ['PR', 'VPR', 'CTR', 'ER', 'OTHER'];

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
    $groups = (array)($_POST['row_class_group'] ?? []);
    $prices = (array)($_POST['row_price'] ?? []);
    $foreignPrices = (array)($_POST['row_foreign_recognition_price'] ?? []);
    $member = (array)($_POST['row_is_member_price'] ?? []);
    $junior = (array)($_POST['row_is_junior_ride'] ?? []);
    $keys = array_keys($names);
    sort($keys);
    foreach ($keys as $i) {
        $rows[] = [
            'id' => (int)($ids[$i] ?? 0),
            'sort_order' => (int)($sort[$i] ?? (($i + 1) * 10)),
            'class_name' => (string)($names[$i] ?? ''),
            'class_code' => (string)($codes[$i] ?? ''),
            'class_group' => (string)($groups[$i] ?? ''),
            'price' => (string)($prices[$i] ?? ''),
            'foreign_recognition_price' => (string)($foreignPrices[$i] ?? ''),
            'is_member_price' => !empty($member[$i]) ? 1 : 0,
            'is_junior_ride' => !empty($junior[$i]) ? 1 : 0,
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
                <div class="text-muted small">Foreign Price is optional on member-price rows; leave it blank to use the member rate.</div>
            </div>
            <button class="btn btn-sm btn-outline-primary" type="button" id="addRowBtn">Add row</button>
        </div>

        <div class="table-responsive">
            <style>
                #rowsTable .js-pricing-primary > td { padding-top: .75rem; }
                #rowsTable .js-pricing-secondary > td {
                    padding-bottom: .75rem;
                    border-bottom: 2px solid #6c757d;
                }
                #rowsTable thead tr:last-child > th {
                    border-bottom: 3px solid #212529;
                }
            </style>
            <table class="table table-sm align-middle" id="rowsTable">
                <colgroup><col style="width:12%"><col style="width:18%"><col style="width:36%"><col style="width:14%"><col style="width:20%"></colgroup>
                <thead class="table-light">
                    <tr>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-dark text-decoration-none js-row-sort" type="button" data-sort-field="sort" data-sort-type="number">Order <span>↕</span></button><input class="form-control form-control-sm mt-1 js-row-filter" type="search" data-filter-field="sort" placeholder="Search"></th>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-dark text-decoration-none js-row-sort" type="button" data-sort-field="group">Ride Class <span>↕</span></button><select class="form-select form-select-sm mt-1 js-row-filter" data-filter-field="group"><option value="">All</option><?php foreach ($rideClassOptions as $option): ?><option value="<?php echo h($option); ?>"><?php echo h($option); ?></option><?php endforeach; ?></select></th>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-dark text-decoration-none js-row-sort" type="button" data-sort-field="name">Class <span>↕</span></button><input class="form-control form-control-sm mt-1 js-row-filter" type="search" data-filter-field="name" placeholder="Search"></th>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-dark text-decoration-none js-row-sort" type="button" data-sort-field="price" data-sort-type="number">Price <span>↕</span></button><input class="form-control form-control-sm mt-1 js-row-filter" type="search" data-filter-field="price" placeholder="Search"></th>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-dark text-decoration-none js-row-sort" type="button" data-sort-field="foreign_price" data-sort-type="number">Foreign Price <span>↕</span></button><div class="d-flex gap-1 mt-1"><input class="form-control form-control-sm js-row-filter" type="search" data-filter-field="foreign_price" placeholder="Search"><button class="btn btn-sm btn-outline-secondary" type="button" id="clearRowFilters">Clear</button></div></th>
                    </tr>
                    <tr class="small text-muted fw-bold">
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-secondary text-decoration-none js-row-sort" type="button" data-sort-field="code">Code (optional) <span>↕</span></button><input class="form-control form-control-sm mt-1 js-row-filter" type="search" data-filter-field="code" placeholder="Search"></th>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-secondary text-decoration-none js-row-sort" type="button" data-sort-field="member_checkbox" data-sort-type="checked">Members <span>↕</span></button><select class="form-select form-select-sm mt-1 js-row-filter" data-filter-field="member_checkbox" data-filter-type="checked"><option value="">All</option><option value="1">Yes</option><option value="0">No</option></select></th>
                        <th><button class="btn btn-link btn-sm p-0 fw-bold text-secondary text-decoration-none js-row-sort" type="button" data-sort-field="junior_checkbox" data-sort-type="checked">Junior <span>↕</span></button><select class="form-select form-select-sm mt-1 js-row-filter" data-filter-field="junior_checkbox" data-filter-type="checked"><option value="">All</option><option value="1">Yes</option><option value="0">No</option></select></th>
                        <th></th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <?php $rows = [['id' => 0, 'sort_order' => 10, 'class_code' => '', 'class_group' => '', 'class_name' => '', 'price' => '0', 'foreign_recognition_price' => '', 'is_member_price' => 0, 'is_junior_ride' => 0]]; ?>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr class="js-pricing-primary">
                        <td><input type="hidden" data-row-field="id" name="row_id[<?php echo (int)$i; ?>]" value="<?php echo (int)($r['id'] ?? 0); ?>"><input class="form-control form-control-sm" data-row-field="sort" name="row_sort[<?php echo (int)$i; ?>]" type="number" value="<?php echo (int)($r['sort_order'] ?? (($i + 1) * 10)); ?>" step="1"></td>
                        <td><?php $selectedRideClass = strtoupper(trim((string)($r['class_group'] ?? ''))); ?><select class="form-select form-select-sm" data-row-field="group" name="row_class_group[<?php echo (int)$i; ?>]"><option value="">Select</option><?php foreach ($rideClassOptions as $option): ?><option value="<?php echo h($option); ?>" <?php echo $selectedRideClass === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option><?php endforeach; ?><?php if ($selectedRideClass !== '' && !in_array($selectedRideClass, $rideClassOptions, true)): ?><option value="<?php echo h($selectedRideClass); ?>" selected><?php echo h($selectedRideClass); ?> (existing)</option><?php endif; ?></select></td>
                        <td><input class="form-control form-control-sm" data-row-field="name" name="row_class_name[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['class_name'] ?? '')); ?>" required></td>
                        <td><input class="form-control form-control-sm" data-row-field="price" name="row_price[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['price'] ?? '0')); ?>" inputmode="decimal"></td>
                        <td><input class="form-control form-control-sm" data-row-field="foreign_price" name="row_foreign_recognition_price[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['foreign_recognition_price'] ?? '')); ?>" inputmode="decimal" placeholder="0.00"></td>
                    </tr>
                    <tr class="js-pricing-secondary border-bottom">
                        <td><input class="form-control form-control-sm" data-row-field="code" name="row_class_code[<?php echo (int)$i; ?>]" value="<?php echo h((string)($r['class_code'] ?? '')); ?>" maxlength="32"></td>
                        <td><label class="d-flex align-items-center gap-2 mb-0"><span>Members</span><input class="form-check-input mt-0" data-row-field="member_checkbox" type="checkbox" name="row_is_member_price[<?php echo (int)$i; ?>]" value="1" <?php echo !empty($r['is_member_price']) ? 'checked' : ''; ?>></label></td>
                        <td><label class="d-flex align-items-center gap-2 mb-0"><span>Junior</span><input class="form-check-input mt-0" data-row-field="junior_checkbox" type="checkbox" name="row_is_junior_ride[<?php echo (int)$i; ?>]" value="1" <?php echo !empty($r['is_junior_ride']) ? 'checked' : ''; ?>></label></td>
                        <td></td>
                        <td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-row" type="button">Remove</button></td>
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
    <tr class="js-pricing-primary">
        <td><input type="hidden" data-row-field="id" name="row_id[0]" value="0"><input class="form-control form-control-sm" data-row-field="sort" name="row_sort[0]" type="number" value="10" step="1"></td>
        <td><select class="form-select form-select-sm" data-row-field="group" name="row_class_group[0]"><option value="">Select</option><?php foreach ($rideClassOptions as $option): ?><option value="<?php echo h($option); ?>"><?php echo h($option); ?></option><?php endforeach; ?></select></td>
        <td><input class="form-control form-control-sm" data-row-field="name" name="row_class_name[0]" value="" required></td>
        <td><input class="form-control form-control-sm" data-row-field="price" name="row_price[0]" value="0" inputmode="decimal"></td>
        <td><input class="form-control form-control-sm" data-row-field="foreign_price" name="row_foreign_recognition_price[0]" value="" inputmode="decimal" placeholder="0.00"></td>
    </tr>
    <tr class="js-pricing-secondary border-bottom">
        <td><input class="form-control form-control-sm" data-row-field="code" name="row_class_code[0]" value="" maxlength="32"></td>
        <td><label class="d-flex align-items-center gap-2 mb-0"><span>Members</span><input class="form-check-input mt-0" data-row-field="member_checkbox" type="checkbox" name="row_is_member_price[0]" value="1"></label></td>
        <td><label class="d-flex align-items-center gap-2 mb-0"><span>Junior</span><input class="form-check-input mt-0" data-row-field="junior_checkbox" type="checkbox" name="row_is_junior_ride[0]" value="1"></label></td>
        <td></td>
        <td class="text-end"><button class="btn btn-sm btn-outline-danger js-remove-row" type="button">Remove</button></td>
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
        const filters = document.querySelectorAll('.js-row-filter');
        let activeSort = { field: '', direction: 'asc' };

        function pairFields(primary) {
            return [primary, primary.nextElementSibling];
        }

        function pairField(primary, field) {
            for (const row of pairFields(primary)) {
                const input = row && row.querySelector(`[data-row-field="${field}"]`);
                if (input) return input;
            }
            return null;
        }

        function pairValue(primary, field, type) {
            const input = pairField(primary, field);
            if (!input) return '';
            return type === 'checked' ? (input.checked ? '1' : '0') : String(input.value || '').trim();
        }

        function applyRowFilters() {
            tableBody.querySelectorAll('.js-pricing-primary').forEach((primary) => {
                let matches = true;
                filters.forEach((filter) => {
                    const needle = String(filter.value || '').trim().toLowerCase();
                    if (!needle) return;
                    const value = pairValue(primary, filter.dataset.filterField, filter.dataset.filterType).toLowerCase();
                    if (filter.tagName === 'SELECT' ? value !== needle : !value.includes(needle)) matches = false;
                });
                pairFields(primary).forEach((row) => { if (row) row.hidden = !matches; });
            });
        }

        function sortRows(field, type, button) {
            const direction = activeSort.field === field && activeSort.direction === 'asc' ? 'desc' : 'asc';
            activeSort = { field, direction };
            const rows = Array.from(tableBody.querySelectorAll('.js-pricing-primary'));
            rows.sort((left, right) => {
                const leftValue = pairValue(left, field, type);
                const rightValue = pairValue(right, field, type);
                const result = type === 'number' || type === 'checked'
                    ? (parseFloat(leftValue) || 0) - (parseFloat(rightValue) || 0)
                    : leftValue.localeCompare(rightValue, undefined, { numeric: true, sensitivity: 'base' });
                return direction === 'desc' ? -result : result;
            });
            rows.forEach((primary) => pairFields(primary).forEach((row) => tableBody.appendChild(row)));
            document.querySelectorAll('.js-row-sort span').forEach((arrow) => { arrow.textContent = '↕'; });
            button.querySelector('span').textContent = direction === 'asc' ? '↑' : '↓';
            syncRowIndexes();
        }

        function syncRowIndexes() {
            const rows = tableBody.querySelectorAll('.js-pricing-primary');
            rows.forEach((row, idx) => {
                const id = pairField(row, 'id');
                if (id) id.name = `row_id[${idx}]`;
                const sort = pairField(row, 'sort');
                if (sort) sort.name = `row_sort[${idx}]`;
                const code = pairField(row, 'code');
                if (code) code.name = `row_class_code[${idx}]`;
                const group = pairField(row, 'group');
                if (group) group.name = `row_class_group[${idx}]`;
                const name = pairField(row, 'name');
                if (name) name.name = `row_class_name[${idx}]`;
                const price = pairField(row, 'price');
                if (price) price.name = `row_price[${idx}]`;
                const member = pairField(row, 'member_checkbox');
                if (member) member.name = `row_is_member_price[${idx}]`;
                const foreignPrice = pairField(row, 'foreign_price');
                if (foreignPrice) foreignPrice.name = `row_foreign_recognition_price[${idx}]`;
                const junior = pairField(row, 'junior_checkbox');
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
                    const secondary = btn.closest('.js-pricing-secondary');
                    const primary = secondary && secondary.previousElementSibling;
                    if (secondary) secondary.remove();
                    if (primary) primary.remove();
                    syncRowIndexes();
                    applyRowFilters();
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
            applyRowFilters();
        });

        filters.forEach((filter) => filter.addEventListener(filter.tagName === 'SELECT' ? 'change' : 'input', applyRowFilters));
        document.querySelectorAll('.js-row-sort').forEach((button) => {
            button.addEventListener('click', () => sortRows(button.dataset.sortField, button.dataset.sortType || 'text', button));
        });
        document.getElementById('clearRowFilters').addEventListener('click', () => {
            filters.forEach((filter) => { filter.value = ''; });
            applyRowFilters();
        });
        tableBody.addEventListener('input', applyRowFilters);
        tableBody.addEventListener('change', applyRowFilters);
        tableBody.closest('form').addEventListener('invalid', (event) => {
            const row = event.target.closest('tr');
            if (!row || !row.hidden) return;
            filters.forEach((filter) => { filter.value = ''; });
            applyRowFilters();
        }, true);

        wireRemoveButtons();
        syncRowIndexes();
        applyRowFilters();
    })();
</script>
<?php
admin_layout_end();
