<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$eventTypes = fetchEventTypes($pdo);
$editParam = $_GET['edit'] ?? null;
$editId = is_numeric($editParam ?? '') ? (int)$editParam : 0;
$isCreate = $editParam === 'new';
$editComponent = $editId ? fetchEntryComponentById($pdo, $editId) : ($isCreate ? [] : null);
$showEditor = ($editComponent !== null || $isCreate || $_SERVER['REQUEST_METHOD'] === 'POST');
$isListView = !$showEditor;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage components.'];
    } else {
        $action = $_POST['action'] ?? 'save_component';
        if ($action === 'delete_component') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                deleteEntryComponent($pdo, $id, $alerts);
                $_SESSION['flash_success'] = 'Component deleted.';
                header('Location: entry_components.php');
                exit;
            }
        } else {
            $savedId = saveEntryComponent($pdo, $_POST, $alerts);
            if ($savedId) {
                $_SESSION['flash_success'] = 'Component saved.';
                header('Location: entry_components.php');
                exit;
            }
        }
    }
}

$components = fetchEntryComponents($pdo, null, false);

// Precompute allowed types label text for filtering, sorting and display.
foreach ($components as &$comp) {
    $allowedIds = parseAllowedEventTypeIds($comp['allowed_event_type_ids'] ?? []);
    if (!$allowedIds) {
        $comp['_allowed_count'] = 0;
        $comp['_allowed_label'] = 'None selected';
        continue;
    }
    $labels = [];
    foreach ($eventTypes as $type) {
        if (in_array((int)$type['id'], $allowedIds, true)) {
            $labels[] = (string)($type['name'] ?? '');
        }
    }
    $labels = array_values(array_filter($labels, fn($v) => trim($v) !== ''));
    $comp['_allowed_count'] = count($labels);
    $comp['_allowed_label'] = $labels ? implode(', ', $labels) : 'None selected';
}
unset($comp);
$filterForm = 'entry-components-filter-form';
$tableColumns = [
    'name' => ['label'=>'Name', 'field'=>'name', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm],
    'type' => ['label'=>'Type', 'field'=>'type', 'sortable'=>true, 'filter'=>'select', 'options'=>['product'=>'Product / Add-on', 'question'=>'Question / Info'], 'form'=>$filterForm],
    'price' => ['label'=>'Price', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => number_format((float)($row['price'] ?? 0), 2, '.', ''),
        'sort_value'=>static fn(array $row): float => (float)($row['price'] ?? 0)],
    'allowed' => ['label'=>'Allowed types', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => (string)($row['_allowed_label'] ?? 'None selected')],
];
$table = admin_table_prepare($components, $tableColumns, 'name');
$components = $table['rows'];

admin_layout_start('Entry Components', 'entry_components');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted"><?php echo $isListView ? 'Manage reusable products/questions for entry forms.' : (($editComponent && !empty($editComponent['id'])) ? 'Edit component' : 'Add component'); ?></div>
        <h5 class="mb-0">Entry Components</h5>
    </div>
    <div class="d-flex gap-2">
        <?php if ($isListView): ?>
            <a class="btn btn-success" href="entry_components.php?edit=new">Add component</a>
            <a class="btn btn-outline-secondary" href="events.php">Back to events</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="entry_components.php">Back to list</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isListView): ?>
    <form method="get" id="<?php echo h($filterForm); ?>"></form>
    <div class="card-soft p-3">
        <?php echo admin_table_record_count($table, 'component', 'components'); ?>
        <div class="table-responsive">
	            <table class="table table-sm admin-data-table align-middle">
	                <thead>
	                    <tr>
	                        <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
	                        <th></th>
	                    </tr>
	                    <tr class="admin-table-filter-row">
	                        <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th><?php endforeach; ?>
	                        <th class="text-end"><a class="btn btn-sm btn-outline-secondary" href="entry_components.php">Clear</a></th>
	                    </tr>
	                </thead>
	                <tbody>
	                    <?php foreach ($components as $comp): ?>
	                        <tr>
	                                <td><?php echo h($comp['name'] ?? ''); ?></td>
	                                <td><?php echo h($comp['type'] ?? ''); ?></td>
	                                <td><?php echo h(format_price($comp['price'] ?? 0)); ?></td>
	                                <td class="small text-muted">
	                                    <?php echo h((string)($comp['_allowed_label'] ?? 'None selected')); ?>
	                                </td>
	                                <td class="text-end">
	                                    <a class="btn btn-sm btn-outline-secondary" href="entry_components.php?edit=<?php echo (int)($comp['id'] ?? 0); ?>">Edit</a>
	                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this component?');">
                                        <input type="hidden" name="action" value="delete_component">
                                        <input type="hidden" name="id" value="<?php echo h((string)($comp['id'] ?? 0)); ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$components): ?><tr><td colspan="5" class="text-muted">No components match these filters.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo admin_table_pagination($table); ?>
    </div>
<?php else: ?>
    <style>
        .section-card { background: #fff; border: 1px solid rgba(0,0,0,0.06); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1rem; box-shadow: 0 8px 20px rgba(0,0,0,0.04);}
        .section-title { font-weight: 700; margin-bottom: 0.25rem; }
        .section-sub { color: #6c757d; margin-bottom: 0.75rem; font-size: 0.95rem;}
        .sub-card { background: #f8f9f7; border: 1px solid rgba(0,0,0,0.05); border-radius: 14px; padding: 1rem 1.25rem; box-shadow: inset 0 1px 0 rgba(255,255,255,0.8), 0 8px 16px rgba(0,0,0,0.05); margin-top: 1rem; }
        .sub-card:first-of-type { margin-top: 0; }
        .sub-heading { font-weight: 700; margin-bottom: 0.15rem; }
        .sub-desc { color: #6c757d; margin-bottom: 0.75rem; font-size: 0.95rem; }
        .fixed-actions { position: sticky; bottom: 0; background: rgba(245,247,243,0.92); border-top: 1px solid rgba(0,0,0,0.06); padding: 0.75rem 0; backdrop-filter: blur(4px); }
        .is-invalid { border-color: #dc3545 !important; box-shadow: 0 0 0 1px rgba(220,53,69,0.2); }
        #allowedTypesBox.is-invalid { border-color: #dc3545 !important; box-shadow: 0 0 0 1px rgba(220,53,69,0.2); }
        .error-text { color: #dc3545; font-size: 0.9rem; }
    </style>
    <div class="card-soft p-4">
        <form method="POST" class="row g-3" novalidate>
            <input type="hidden" name="action" value="save_component">
            <input type="hidden" name="id" value="<?php echo h((string)($editComponent['id'] ?? 0)); ?>">
            <div class="col-12">
                <div class="sub-card">
                    <div class="sub-heading">Basic details</div>
                    <div class="sub-desc">Name, description, type, and input kind.</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required value="<?php echo h($editComponent['name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <?php $typeVal = $editComponent['type'] ?? 'product'; ?>
                                <option value="product" <?php echo ($typeVal === 'product') ? 'selected' : ''; ?>>Product / Add-on</option>
                                <option value="question" <?php echo ($typeVal === 'question') ? 'selected' : ''; ?>>Question / Info</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Input kind</label>
                            <select name="input_kind" class="form-select">
                                <?php $inputVal = $editComponent['input_kind'] ?? 'checkbox'; ?>
                                <option value="checkbox" <?php echo ($inputVal === 'checkbox') ? 'selected' : ''; ?>>Checkbox</option>
                                <option value="text" <?php echo ($inputVal === 'text') ? 'selected' : ''; ?>>Text input</option>
                                <option value="textarea" <?php echo ($inputVal === 'textarea') ? 'selected' : ''; ?>>Textarea</option>
                                <option value="quantity" <?php echo ($inputVal === 'quantity') ? 'selected' : ''; ?>>Quantity</option>
                                <option value="none" <?php echo ($inputVal === 'none') ? 'selected' : ''; ?>>No input (info only)</option>
                            </select>
                        </div>
                        <?php $hasCost = isset($editComponent['price']) && (float)$editComponent['price'] > 0; ?>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control wysiwyg-field" rows="3" placeholder="Shown on the entry form to explain this option"><?php echo h($editComponent['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="sub-card" id="pricing-card">
                    <div class="sub-heading d-flex justify-content-between align-items-center">
                        <span>Pricing</span>
                        <span class="small text-muted">Only for products/add-ons</span>
                    </div>
                    <div class="sub-desc">Choose how pricing applies to this item.</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Pricing mode</label>
                            <?php
                                $isRequiredFlag = !empty($editComponent['is_required']);
                                $pricingMode = 'no_price';
                                if ($hasCost && $isRequiredFlag) {
                                    $pricingMode = 'mandatory_price';
                                } elseif ($hasCost) {
                                    $pricingMode = 'optional_price';
                                } elseif ($isRequiredFlag) {
                                    $pricingMode = 'required_no_price';
                                }
                            ?>
                            <select class="form-select" id="pricing_mode">
                                <option value="no_price" <?php echo $pricingMode === 'no_price' ? 'selected' : ''; ?>>No price (optional)</option>
                                <option value="required_no_price" <?php echo $pricingMode === 'required_no_price' ? 'selected' : ''; ?>>No price (must accept)</option>
                                <option value="optional_price" <?php echo $pricingMode === 'optional_price' ? 'selected' : ''; ?>>Optional price (user opts in)</option>
                                <option value="mandatory_price" <?php echo $pricingMode === 'mandatory_price' ? 'selected' : ''; ?>>Mandatory price (always included)</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="price-group">
                            <label class="form-label">Price</label>
                            <div class="input-group">
                                <span class="input-group-text">£</span>
                                <input type="text" name="price" class="form-control" value="<?php echo h(isset($editComponent['price']) ? number_format((float)$editComponent['price'], 2) : '0.00'); ?>">
                            </div>
                        </div>
                    </div>
                    <!-- Hidden fields the model expects; kept in sync via JS -->
                    <input type="hidden" id="has_cost" name="has_cost" value="<?php echo $hasCost ? '1' : '0'; ?>">
                    <input type="hidden" id="is_required" name="is_required" value="<?php echo $isRequiredFlag ? '1' : '0'; ?>">
                </div>
                <div class="sub-card">
                    <div class="sub-heading">Allowed event types</div>
                    <div class="sub-desc">Select one or more event types this component can appear on.</div>
                    <div class="border rounded p-2" id="allowedTypesBox" style="max-height: 220px; overflow-y: auto; background:#fff;">
                        <?php
                        $allowedIds = parseAllowedEventTypeIds($editComponent['allowed_event_type_ids'] ?? []);
                        foreach ($eventTypes as $type):
                            $id = (int)($type['id'] ?? 0);
                            $checked = in_array($id, $allowedIds, true);
                        ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allowed_event_type_ids[]" value="<?php echo $id; ?>" id="et_<?php echo $id; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="et_<?php echo $id; ?>"><?php echo h($type['name'] ?? ''); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="error-text d-none" id="allowedTypesError">Select at least one event type.</div>
                </div>
                <div class="sub-card">
                    <div class="sub-heading">Preview</div>
                    <div class="sub-desc">Live preview of how this component appears on the entry form.</div>
                    <div class="section-card mb-0">
                        <div id="componentPreview" class="border rounded p-3 bg-light"></div>
                    </div>
                </div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-success"><?php echo ($editComponent && !empty($editComponent['id'])) ? 'Update' : 'Add'; ?></button>
                <a class="btn btn-outline-secondary" href="entry_components.php">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php render_tinymce_bootstrap(); ?>
<script>
    (function() {
        const typeSelect = document.querySelector('select[name="type"]');
        const hasCost = document.getElementById('has_cost');
        const priceGroup = document.getElementById('price-group');
        const isRequired = document.getElementById('is_required');
        const pricingCard = document.getElementById('pricing-card');
        const pricingMode = document.getElementById('pricing_mode');
        const previewBox = document.getElementById('componentPreview');
        const nameInput = document.querySelector('input[name="name"]');
        const descInput = document.querySelector('textarea[name="description"]');
        const priceInput = document.querySelector('input[name="price"]');
        const inputKind = document.querySelector('select[name="input_kind"]');
        const allowedTypeBox = document.getElementById('allowedTypesBox');
        const allowedTypeError = document.getElementById('allowedTypesError');
        const allowedTypeChecks = Array.from(document.querySelectorAll('input[name="allowed_event_type_ids[]"]'));
        const formEl = document.querySelector('form');

        if (!formEl) {
            return;
        }

        const syncPricingUI = () => {
            const typeVal = typeSelect?.value || 'product';
            let mode = pricingMode?.value || 'no_price';
            const inputVal = inputKind?.value || 'checkbox';
            // Hide pricing entirely for question/info components
            if (pricingCard) {
                pricingCard.style.display = typeVal === 'product' ? '' : 'none';
            }
            // Required without price only makes sense for checkboxes
            if (inputVal !== 'checkbox' && mode === 'required_no_price') {
                mode = 'no_price';
                if (pricingMode) pricingMode.value = 'no_price';
            }
            // Quantity is intended for optional priced add-ons.
            if (typeVal === 'product' && inputVal === 'quantity' && mode !== 'optional_price') {
                mode = 'optional_price';
                if (pricingMode) pricingMode.value = 'optional_price';
            }
            // Map pricing mode to the legacy flags the model expects
            if (typeVal !== 'product') {
                hasCost.value = '0';
                isRequired.value = mode === 'required_no_price' ? '1' : '0';
            } else {
                if (mode === 'mandatory_price') {
                    hasCost.value = '1';
                    isRequired.value = '1';
                } else if (mode === 'optional_price') {
                    hasCost.value = '1';
                    isRequired.value = '0';
                } else if (mode === 'required_no_price') {
                    hasCost.value = '0';
                    isRequired.value = '1';
                } else {
                    hasCost.value = '0';
                    isRequired.value = '0';
                }
            }
            if (priceGroup) {
                const priced = typeVal === 'product' && mode !== 'no_price' && mode !== 'required_no_price';
                priceGroup.style.display = priced ? '' : 'none';
            }
        };

        const renderPreview = () => {
            if (!previewBox) return;
            const label = nameInput?.value?.trim() || 'Component name';
            const desc = descInput?.value?.trim() || '';
            const typeVal = typeSelect?.value || 'product';
            const inputVal = inputKind?.value || 'checkbox';
            const mode = pricingMode?.value || 'no_price';
            const hasPrice = (typeVal === 'product') && (mode !== 'no_price');
            const priceVal = priceInput?.value || '0.00';
            const isReqProduct = (typeVal === 'product' && mode === 'mandatory_price');
            const isReqNoPrice = mode === 'required_no_price';
            const showSelector = typeVal === 'product' && mode === 'optional_price';

            let control = '';
            if (inputVal === 'none') {
                control = '';
            } else if (inputVal === 'quantity') {
                control = `
                    <div class="d-flex align-items-center gap-2">
                        <input class="form-control form-control-sm" type="number" min="0" step="1" value="0" disabled style="max-width: 110px;">
                        ${hasPrice ? `<span class="badge bg-light text-dark border">£${priceVal} each</span>` : ''}
                    </div>
                `;
            } else if (inputVal === 'checkbox') {
                if (isReqNoPrice) {
                    control = `
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" disabled checked>
                            <label class="form-check-label fw-semibold">Required to continue</label>
                        </div>
                        <div class="text-muted small">Must be accepted to continue.</div>
                    `;
                } else if (typeVal !== 'product') {
                    // Question/info checkbox: simple yes/no
                    control = `
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" disabled>
                            <label class="form-check-label">Select this option</label>
                        </div>
                    `;
                } else if (showSelector) {
                    control = `
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" disabled>
                            <label class="form-check-label fw-semibold">Add this option</label>
                            ${hasPrice ? `<span class="badge bg-light text-dark border ms-2">+£${priceVal}</span>` : ''}
                        </div>
                        <div class="text-muted small">Optional add-on. Tick to include.</div>
                    `;
                } else {
                    control = `
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="fw-semibold mb-0">${label}</div>
                            ${hasPrice ? `<span class="badge bg-light text-dark border">Included £${priceVal}</span>` : ''}
                        </div>
                        <div class="text-muted small mb-1">Required add-on, automatically included in your total.</div>
                    `;
                }
            } else if (inputVal === 'text') {
                control = `
                    <input class="form-control form-control-sm" type="text" disabled placeholder="Enter response">
                    ${hasPrice && !showSelector ? `<div class="small text-muted mt-1">+£${priceVal}</div>` : ''}
                `;
            } else {
                control = `
                    <textarea class="form-control form-control-sm" rows="2" disabled placeholder="Enter response"></textarea>
                    ${hasPrice && !showSelector ? `<div class="small text-muted mt-1">+£${priceVal}</div>` : ''}
                `;
            }

            previewBox.innerHTML = `
                <div class="border rounded p-3 bg-white">
                    <div class="fw-bold mb-1">${label}</div>
                    ${desc ? `<div class="text-muted small mb-2">${desc}</div>` : ''}
                    ${control}
                </div>
            `;
        };

        const attach = (el, evt, handler) => el?.addEventListener(evt, handler);

        attach(typeSelect, 'change', () => {
            syncPricingUI();
            renderPreview();
        });
        attach(pricingMode, 'change', () => {
            syncPricingUI();
            renderPreview();
        });
        attach(priceInput, 'input', renderPreview);
        attach(nameInput, 'input', renderPreview);
        attach(descInput, 'input', renderPreview);
        attach(inputKind, 'change', () => {
            syncPricingUI();
            renderPreview();
        });

        const initWysiwyg = () => {
            if (!window.tinymce) {
                return;
            }
            tinymce.init(window.ildraTinyMceConfig({
                selector: 'textarea.wysiwyg-field',
                setup: (editor) => {
                    editor.on('change keyup input', () => {
                        editor.save();
                        renderPreview();
                    });
                },
            }));
        };

        initWysiwyg();
        syncPricingUI();
        renderPreview();

        // basic enforcement similar to public form: highlight first problem and prevent submit
        formEl.addEventListener('submit', (e) => {
            let firstInvalid = null;
            // reset state
            [nameInput, priceInput].forEach((el) => el?.classList.remove('is-invalid'));
            allowedTypeError?.classList.add('d-none');
            allowedTypeBox?.classList.remove('is-invalid');

            if (nameInput && nameInput.value.trim() === '') {
                nameInput.classList.add('is-invalid');
                firstInvalid = firstInvalid || nameInput;
            }

            const typeVal = typeSelect?.value || 'product';
            const mode = pricingMode?.value || 'no_price';
            const priced = typeVal === 'product' && mode !== 'no_price';
            if (priced) {
                const priceVal = priceInput?.value || '';
                const priceNum = parseFloat(priceVal);
                if (!priceVal || Number.isNaN(priceNum)) {
                    priceInput?.classList.add('is-invalid');
                    firstInvalid = firstInvalid || priceInput;
                }
            }

            const hasAllowed = allowedTypeChecks.some((c) => c.checked);
            if (!hasAllowed) {
                allowedTypeError?.classList.remove('d-none');
                allowedTypeBox?.classList.add('is-invalid');
                allowedTypeChecks.forEach((c) => c.setAttribute('aria-invalid', 'true'));
                // focus first checkbox in the group so keyboard users can act immediately
                firstInvalid = firstInvalid || allowedTypeChecks[0] || allowedTypeBox;
            } else {
                allowedTypeError?.classList.add('d-none');
                allowedTypeBox?.classList.remove('is-invalid');
                allowedTypeChecks.forEach((c) => c.removeAttribute('aria-invalid'));
            }

            if (firstInvalid) {
                e.preventDefault();
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (firstInvalid.focus) {
                    firstInvalid.focus({ preventScroll: true });
                }
            }
        });

        // Keep allowed types validation responsive on change
        allowedTypeChecks.forEach((box) => {
            box.addEventListener('change', () => {
                const hasAllowed = allowedTypeChecks.some((c) => c.checked);
                if (hasAllowed) {
                    allowedTypeError?.classList.add('d-none');
                    allowedTypeBox?.classList.remove('is-invalid');
                    allowedTypeChecks.forEach((c) => c.removeAttribute('aria-invalid'));
                }
            });
        });
    })();
</script>

<?php
admin_layout_end();
?>
