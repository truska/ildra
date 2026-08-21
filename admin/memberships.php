<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$canAdmin = in_array(($currentUser['role'] ?? ''), ['superadmin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$canAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage memberships.'];
    } elseif ($action === 'save_membership_type') {
        if (saveMembershipType($pdo, $_POST, $alerts)) {
            $successMessage = 'Membership type saved.';
        }
    } elseif ($action === 'delete_membership_type') {
        $typeId = (int)($_POST['membership_type_id'] ?? 0);
        if ($typeId > 0 && deleteMembershipType($pdo, $typeId, $alerts)) {
            $successMessage = 'Membership type deleted.';
        }
    }
    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: memberships.php');
    exit;
}

$membershipTypes = fetchMembershipTypes($pdo, false);

$filterForm = 'membership-types-filter-form';
$statusOptions = [];
foreach ($membershipTypes as $membershipType) {
    $status = trim((string)($membershipType['status'] ?? ''));
    if ($status !== '') $statusOptions[$status] = ucfirst($status);
}
natcasesort($statusOptions);
$tableColumns = [
    'name' => ['label'=>'Name', 'field'=>'name', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm],
    'sale_window' => ['label'=>'Sale window', 'sortable'=>true, 'filter'=>'text', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => format_display_date($row['sale_starts'] ?? null, '') . ' ' . format_display_date($row['sale_ends'] ?? null, ''),
        'sort_value'=>static fn(array $row): string => (string)($row['sale_starts'] ?? '')],
    'membership_year' => ['label'=>'Membership year', 'field'=>'membership_year', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm],
    'cost' => ['label'=>'Cost', 'sortable'=>true, 'filter'=>'text', 'compare'=>'number', 'form'=>$filterForm,
        'value'=>static fn(array $row): string => number_format((float)($row['cost'] ?? 0), 2, '.', ''),
        'sort_value'=>static fn(array $row): float => (float)($row['cost'] ?? 0)],
    'status' => ['label'=>'Status', 'field'=>'status', 'sortable'=>true, 'filter'=>'select', 'options'=>$statusOptions, 'form'=>$filterForm],
];
$table = admin_table_prepare($membershipTypes, $tableColumns, 'membership_year', 'desc');
$membershipTypes = $table['rows'];

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editingType = $editId ? fetchMembershipTypeById($pdo, $editId) : null;
if ($editingType) {
    $editingType['type'] = in_array(($editingType['type'] ?? ''), ['junior', 'senior'], true)
        ? $editingType['type']
        : 'senior';
}
$formValues = $editingType ?: [
    'id' => 0,
    'name' => '',
    'description' => '',
    'sale_starts' => '',
    'sale_ends' => '',
    'membership_year' => (int)date('Y'),
    'cost' => '0.00',
    'type' => 'senior',
    'status' => 'draft',
];

$panelMode = 'closed';
if (isset($_GET['panel']) && $_GET['panel'] === 'create') {
    $panelMode = 'create';
} elseif ($editingType) {
    $panelMode = 'edit';
}
$panelOpen = $panelMode !== 'closed';

admin_layout_start('Memberships', 'memberships');
?>
<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .page-grid {
        display: grid;
        gap: 1rem;
        align-items: start;
    }
    .page-grid.drawer-only { grid-template-columns: 1fr; }
    .page-grid.closed { grid-template-columns: 1fr; }
    @media (max-width: 1100px) {
        .page-grid.drawer-only,
        .page-grid.closed { grid-template-columns: 1fr; }
        .drawer { position: static; }
    }
    .eyebrow {
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-size: 0.72rem;
        color: var(--text-muted);
        font-weight: 700;
    }
    .notes { color: var(--text-muted); font-size: 0.9rem; }
    .chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        background: #e8f5e9;
        border: 1px solid #d7eadb;
        color: #1f3b26;
        border-radius: 999px;
        padding: 0.35rem 0.8rem;
        font-weight: 700;
        font-size: 0.87rem;
    }
    .panel-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 0.75rem;
    }
    .modern-table table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .modern-table thead th {
        background: #f8faf7;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 0.85rem;
        color: #4c5a4c;
        border-bottom: 1px solid var(--border-soft);
    }
    .modern-table th, .modern-table td { padding: 0.85rem; }
    .modern-table tbody tr {
        border-bottom: 1px solid var(--border-soft);
        transition: transform 0.08s ease, box-shadow 0.08s ease, background 0.08s ease;
    }
    .modern-table tbody tr:hover {
        background: #f7fbf6;
        box-shadow: 0 6px 16px rgba(15, 47, 31, 0.06);
        transform: translateY(-1px);
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 700;
        background: #e8f5e9;
        color: #1c7c2c;
        border: 1px solid #d7eadb;
    }
    .status-pill.draft { background: #f1f3f6; border-color: #e1e6ed; color: #485260; }
    .action-menu { display: flex; gap: 0.35rem; justify-content: flex-end; }
    .drawer { position: sticky; top: 18px; }
    .drawer-shell { display: none; }
    .drawer-shell.is-open { display: block; }
    .fieldset {
        border: 1px solid var(--border-soft);
        background: #fafcf9;
        border-radius: 12px;
        padding: 0.85rem 0.85rem 0.35rem;
        margin-bottom: 0.75rem;
    }
    .fieldset .legend {
        font-weight: 800;
        font-size: 0.95rem;
        color: #1f4325;
        margin-bottom: 0.35rem;
    }
    .helper { font-size: 0.82rem; color: var(--text-muted); }
    .form-label { font-weight: 700; color: #1d2f1f; }
    .membership-meta { display: flex; gap: 0.65rem; flex-wrap: wrap; }
    .drawer-outer { animation: slideIn 180ms ease-out; }
    @keyframes slideIn {
        from { opacity: 0; transform: translateX(12px); }
        to { opacity: 1; transform: translateX(0); }
    }
    .catalog-card.hidden { display: none; }
</style>
<section class="page-header card-soft p-3">
    <div>
        <div class="eyebrow">Membership catalog</div>
        <div class="d-flex align-items-center gap-2">
            <h5 class="mb-0 fw-bold">Membership Types</h5>
            <span class="chip">Products for sale</span>
        </div>
        <div class="notes">Quickly scan offers, adjust sale windows, and publish with confidence.</div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-success px-3" href="memberships.php?panel=create" id="js-add-new">Add new</a>
    </div>
</section>

<div class="page-grid <?php echo $panelOpen ? 'drawer-only' : 'closed'; ?> mb-3" id="js-grid">
    <section class="card-soft p-3 catalog-card <?php echo $panelOpen ? 'hidden' : ''; ?>" id="catalog-card">
        <div class="panel-head">
            <div>
                <div class="eyebrow">Catalog</div>
                <h6 class="mb-1 fw-bold">Types at a glance</h6>
                <div class="notes">Hover rows for quick actions. Consistent spacing keeps scanning easy.</div>
            </div>
            <div class="membership-meta">
                <span class="chip"><?php echo (int)$table['pagination']['total']; ?> items</span>
            </div>
        </div>
        <form method="get" id="<?php echo h($filterForm); ?>"></form>
        <div class="modern-table table-responsive">
            <table class="table table-sm admin-data-table align-middle mb-0">
                <thead>
                    <tr>
                        <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
                        <th class="text-end">Actions</th>
                    </tr>
                    <tr class="admin-table-filter-row">
                        <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th><?php endforeach; ?>
                        <th class="text-end"><a class="btn btn-sm btn-outline-secondary" href="memberships.php">Clear</a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($membershipTypes as $type): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo h($type['name'] ?? ''); ?></td>
                            <td class="text-muted">
                                <div><?php echo h(format_display_date($type['sale_starts'] ?? null, '')); ?></div>
                                <div><?php echo h(format_display_date($type['sale_ends'] ?? null, '')); ?></div>
                            </td>
                            <td class="text-muted"><?php echo (int)($type['membership_year'] ?? 0); ?></td>
                            <td class="fw-semibold"><?php echo '£' . h(number_format((float)($type['cost'] ?? 0), 2)); ?></td>
                            <td>
                                <span class="status-pill <?php echo (($type['status'] ?? '') === 'draft') ? 'draft' : ''; ?>">
                                    <?php echo h($type['status'] ?? ''); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="action-menu">
                                    <a
                                        class="btn btn-sm btn-outline-secondary js-edit"
                                        href="memberships.php?id=<?php echo (int)$type['id']; ?>"
                                        data-id="<?php echo (int)$type['id']; ?>"
                                        data-name="<?php echo h($type['name'] ?? ''); ?>"
                                        data-description="<?php echo h($type['description'] ?? ''); ?>"
                                        data-sale-starts="<?php echo h($type['sale_starts'] ?? ''); ?>"
                                        data-sale-ends="<?php echo h($type['sale_ends'] ?? ''); ?>"
                                        data-membership-year="<?php echo (int)($type['membership_year'] ?? 0); ?>"
                                        data-cost="<?php echo h((string)($type['cost'] ?? '')); ?>"
                                        data-type="<?php echo h((string)($type['type'] ?? '')); ?>"
                                        data-status="<?php echo h((string)($type['status'] ?? 'draft')); ?>"
                                    >Edit</a>
                                    <?php if ($canAdmin): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this membership type?');">
                                            <input type="hidden" name="action" value="delete_membership_type">
                                            <input type="hidden" name="membership_type_id" value="<?php echo (int)$type['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$membershipTypes): ?>
                        <tr><td colspan="6" class="text-muted">No membership types match these filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo admin_table_pagination($table); ?>
    </section>

    <aside class="card-soft p-3 drawer drawer-outer drawer-shell <?php echo $panelOpen ? 'is-open' : 'is-hidden'; ?>" id="membership-drawer" data-mode="<?php echo h($panelMode); ?>">
        <div class="panel-head">
            <div>
                <div class="eyebrow" data-drawer-eyebrow>
                    <?php echo $panelMode === 'edit' ? 'Edit membership type' : 'Create membership type'; ?>
                </div>
                <h6 class="mb-1 fw-bold" data-drawer-title>
                    <?php echo $panelMode === 'edit' ? h($editingType['name']) : 'Create membership type'; ?>
                </h6>
                <div class="notes">Grouped fields keep the flow short; status stays secondary.</div>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
                <div class="status-pill <?php echo (($formValues['status'] ?? '') === 'draft') ? 'draft' : ''; ?>" data-status-pill>
                    <?php echo ucfirst($formValues['status'] ?? 'Draft'); ?>
                </div>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-close>Close</button>
            </div>
        </div>
        <form method="POST" class="mt-2" id="membership-form">
            <input type="hidden" name="action" value="save_membership_type">
            <input type="hidden" name="membership_type_id" value="<?php echo (int)($formValues['id'] ?? 0); ?>" id="field-id">

            <div class="fieldset">
                <div class="legend">Basics</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Annual Adult" value="<?php echo h($formValues['name']); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Short summary for admins"><?php echo h($formValues['description']); ?></textarea>
                        <div class="helper">Keep this concise; surfaces in the dashboard only.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Type key</label>
                        <select name="type" class="form-select">
                            <option value="junior" <?php echo (($formValues['type'] ?? '') === 'junior') ? 'selected' : ''; ?>>Junior</option>
                            <option value="senior" <?php echo (($formValues['type'] ?? '') === 'senior') ? 'selected' : ''; ?>>Senior</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="fieldset">
                <div class="legend">Dates</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Sale starts</label>
                        <input type="date" name="sale_starts" class="form-control" value="<?php echo h($formValues['sale_starts']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sale ends</label>
                        <input type="date" name="sale_ends" class="form-control" value="<?php echo h($formValues['sale_ends']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Membership year</label>
                        <input type="number" min="2000" max="2100" name="membership_year" class="form-control" value="<?php echo (int)$formValues['membership_year']; ?>" required>
                    </div>
                </div>
                <div class="helper mt-1">The membership runs from 1 January to 31 December of this year. The sale window is independent.</div>
            </div>

            <div class="fieldset">
                <div class="legend">Pricing & status</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Cost</label>
                        <input type="number" step="0.01" min="0" name="cost" class="form-control" placeholder="e.g. 45.00" value="<?php echo h((string)$formValues['cost']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="draft" <?php echo (($formValues['status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo (($formValues['status'] ?? '') === 'published') ? 'selected' : ''; ?>>Published</option>
                        </select>
                        <div class="helper">Publish when ready to sell.</div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-success px-3" type="submit" data-submit-label><?php echo $panelMode === 'edit' ? 'Update type' : 'Create type'; ?></button>
                <button class="btn btn-outline-secondary" type="button" data-revert <?php echo $panelMode === 'edit' ? '' : 'style="display:none"'; ?>>Revert changes</button>
                <button class="btn btn-outline-secondary" type="button" data-close>Cancel</button>
            </div>
        </form>
    </aside>
</div>

<script>
// Client-side toggling: add vs edit; hides catalog when drawer is open.
document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('js-grid');
    const drawer = document.getElementById('membership-drawer');
    const catalog = document.getElementById('catalog-card');
    const form = document.getElementById('membership-form');
    const addBtn = document.getElementById('js-add-new');
    const editBtns = Array.from(document.querySelectorAll('.js-edit'));
    const closeButtons = drawer ? Array.from(drawer.querySelectorAll('[data-close]')) : [];
    const revertBtn = drawer ? drawer.querySelector('[data-revert]') : null;
    const titleEl = drawer ? drawer.querySelector('[data-drawer-title]') : null;
    const eyebrowEl = drawer ? drawer.querySelector('[data-drawer-eyebrow]') : null;
    const statusPill = drawer ? drawer.querySelector('[data-status-pill]') : null;
    const statusSelect = form ? form.querySelector('select[name="status"]') : null;
    const submitBtn = form ? form.querySelector('[data-submit-label]') : null;

    if (!grid || !drawer || !form || !catalog) return;

    const fields = {
        id: form.querySelector('#field-id'),
        name: form.querySelector('input[name="name"]'),
        description: form.querySelector('textarea[name="description"]'),
        sale_starts: form.querySelector('input[name="sale_starts"]'),
        sale_ends: form.querySelector('input[name="sale_ends"]'),
        membership_year: form.querySelector('input[name="membership_year"]'),
        cost: form.querySelector('input[name="cost"]'),
        type: form.querySelector('select[name="type"]'),
        status: statusSelect,
    };

    const blankData = {
        mode: 'create',
        id: '0',
        name: '',
        description: '',
        sale_starts: '',
        sale_ends: '',
        membership_year: String(new Date().getFullYear()),
        cost: '0.00',
        type: 'senior',
        status: 'draft',
    };

    let originalData = null;

    const ucfirst = (val) => {
        if (!val) return '';
        return val.charAt(0).toUpperCase() + val.slice(1);
    };

    const updateStatusPill = (status) => {
        if (!statusPill) return;
        statusPill.textContent = ucfirst(status || 'Draft');
        statusPill.classList.toggle('draft', (status || 'draft') === 'draft');
    };

    const setLayoutOpen = () => {
        drawer.classList.add('is-open');
        drawer.classList.remove('is-hidden');
        drawer.classList.add('drawer-outer');
        grid.classList.add('drawer-only');
        grid.classList.remove('closed');
        catalog.classList.add('hidden');
    };

    const setLayoutClosed = () => {
        drawer.classList.remove('is-open');
        drawer.classList.add('is-hidden');
        grid.classList.remove('drawer-only');
        grid.classList.add('closed');
        catalog.classList.remove('hidden');
    };

    const applyData = (data) => {
        fields.id.value = data.id || '0';
        fields.name.value = data.name || '';
        fields.description.value = data.description || '';
        fields.sale_starts.value = data.sale_starts || '';
        fields.sale_ends.value = data.sale_ends || '';
        fields.membership_year.value = data.membership_year || String(new Date().getFullYear());
        fields.cost.value = data.cost || '';
        fields.type.value = data.type || '';
        fields.status.value = data.status || 'draft';

        if (titleEl) {
            titleEl.textContent = data.mode === 'edit' ? (data.name || 'Edit membership type') : 'Create membership type';
        }
        if (eyebrowEl) {
            eyebrowEl.textContent = data.mode === 'edit' ? 'Edit membership type' : 'Create membership type';
        }
        if (revertBtn) {
            revertBtn.style.display = data.mode === 'edit' ? '' : 'none';
        }
        if (submitBtn) {
            submitBtn.textContent = data.mode === 'edit' ? 'Update type' : 'Create type';
        }
        updateStatusPill(data.status);

        setLayoutOpen();
        drawer.dataset.mode = data.mode;
    };

    const closePanel = () => {
        setLayoutClosed();
    };

    const collectCurrent = (mode) => ({
        mode,
        id: fields.id.value || '0',
        name: fields.name.value || '',
        description: fields.description.value || '',
        sale_starts: fields.sale_starts.value || '',
        sale_ends: fields.sale_ends.value || '',
        membership_year: fields.membership_year.value || '',
        cost: fields.cost.value || '',
        type: fields.type.value || '',
        status: fields.status.value || 'draft',
    });

    const openCreate = () => {
        originalData = { ...blankData };
        applyData(blankData);
    };

    const openEdit = (btn) => {
        const rawType = btn.dataset.type || '';
        const safeType = (rawType === 'junior' || rawType === 'senior') ? rawType : 'senior';
        const data = {
            mode: 'edit',
            id: btn.dataset.id || '0',
            name: btn.dataset.name || '',
            description: btn.dataset.description || '',
            sale_starts: btn.dataset.saleStarts || '',
            sale_ends: btn.dataset.saleEnds || '',
            membership_year: btn.dataset.membershipYear || '',
            cost: btn.dataset.cost || '',
            type: safeType,
            status: btn.dataset.status || 'draft',
        };
        originalData = { ...data };
        applyData(data);
    };

    addBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        openCreate();
    });

    editBtns.forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            openEdit(btn);
        });
    });

    closeButtons.forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            closePanel();
        });
    });

    revertBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        if (!originalData) return;
        applyData(originalData);
    });

    if (drawer.classList.contains('is-open')) {
        const mode = drawer.dataset.mode === 'edit' ? 'edit' : (drawer.dataset.mode === 'create' ? 'create' : 'closed');
        originalData = collectCurrent(mode === 'edit' ? 'edit' : 'create');
        setLayoutOpen();
    } else {
        setLayoutClosed();
    }
});
</script>
<?php
admin_layout_end();
