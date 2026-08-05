<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$roleKey = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($roleKey, ['superadmin', 'admin'], true)) {
    header('Location: ' . $adminBase . '/index.php');
    exit;
}

ensureAdminMenuTable($pdo);
$editParam = (string)($_GET['edit'] ?? '');
$editId = ctype_digit($editParam) ? (int)$editParam : 0;
$isCreate = $editParam === 'new';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        if (deleteAdminMenuItem($pdo, (int)($_POST['id'] ?? 0), $alerts)) {
            $_SESSION['flash_success'] = 'Menu item deleted.';
            header('Location: ' . $adminBase . '/menu.php');
            exit;
        }
    } else {
        $savedId = saveAdminMenuItem($pdo, $_POST, $alerts);
        if ($savedId) {
            $_SESSION['flash_success'] = 'Menu item saved.';
            header('Location: ' . $adminBase . '/menu.php');
            exit;
        }
    }
}

$items = fetchAdminMenuItems($pdo, false);
$itemsById = [];
foreach ($items as $item) {
    $itemsById[(int)$item['id']] = $item;
}
$editItem = $editId > 0 ? ($itemsById[$editId] ?? null) : ($isCreate ? [
    'id' => 0,
    'label' => '',
    'href' => '',
    'parent_id' => null,
    'display_order' => count($items) * 10 + 10,
    'is_active' => 1,
    'required_roles' => 'superadmin,admin,organiser',
    'is_system' => 0,
] : null);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $alerts) {
    $editItem = array_merge($editItem ?: [], $_POST);
}

$topLevelItems = array_values(array_filter($items, static fn(array $item): bool => (int)($item['parent_id'] ?? 0) === 0));
$childCounts = [];
foreach ($items as $item) {
    $parentId = (int)($item['parent_id'] ?? 0);
    if ($parentId > 0) {
        $childCounts[$parentId] = ($childCounts[$parentId] ?? 0) + 1;
    }
}

admin_layout_start('Menu', 'menu');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <div class="small text-muted">Manage the admin sidebar hierarchy, labels and order.</div>
        <h5 class="mb-0">Admin menu</h5>
    </div>
    <a class="btn btn-success" href="<?php echo h($adminBase); ?>/menu.php?edit=new">Add menu item</a>
</div>

<div class="row g-3">
    <div class="<?php echo $editItem !== null ? 'col-xl-7' : 'col-12'; ?>">
        <div class="card-soft p-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Label</th>
                            <th>Parent</th>
                            <th>Destination</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $id = (int)$item['id'];
                            $parentId = (int)($item['parent_id'] ?? 0);
                            $hasChildren = !empty($childCounts[$id]);
                            ?>
                            <tr>
                                <td class="text-muted"><?php echo (int)($item['display_order'] ?? 0); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo $parentId > 0 ? '↳ ' : ''; ?><?php echo h((string)$item['label']); ?></div>
                                    <div class="small text-muted"><?php echo h((string)$item['menu_key']); ?><?php echo !empty($item['is_system']) ? ' · built-in' : ''; ?></div>
                                </td>
                                <td class="small text-muted"><?php echo $parentId > 0 ? h((string)($itemsById[$parentId]['label'] ?? 'Unknown')) : 'Top level'; ?></td>
                                <td class="small"><?php echo $hasChildren ? '<span class="badge bg-secondary-subtle text-secondary">Expandable section</span>' : h((string)($item['href'] ?? '—')); ?></td>
                                <td><?php echo !empty($item['is_active']) ? '<span class="badge bg-success-subtle text-success">Visible</span>' : '<span class="badge bg-secondary-subtle text-secondary">Hidden</span>'; ?></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?php echo h($adminBase); ?>/menu.php?edit=<?php echo $id; ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($editItem !== null): ?>
        <?php
        $editRoles = array_values(array_filter(array_map('trim', explode(',', (string)($editItem['required_roles'] ?? '')))));
        $editKind = trim((string)($editItem['href'] ?? '')) === '' ? 'section' : 'link';
        ?>
        <div class="col-xl-5">
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <div class="fw-bold"><?php echo !empty($editItem['id']) ? 'Edit menu item' : 'Add menu item'; ?></div>
                        <div class="small text-muted">Items with children automatically render as expandable sections.</div>
                    </div>
                    <a class="btn-close" aria-label="Close" href="<?php echo h($adminBase); ?>/menu.php"></a>
                </div>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)($editItem['id'] ?? 0); ?>">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Label</label>
                        <input class="form-control" name="label" required maxlength="100" value="<?php echo h((string)($editItem['label'] ?? '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Item type</label>
                        <select class="form-select" name="kind" data-menu-kind>
                            <option value="link" <?php echo $editKind === 'link' ? 'selected' : ''; ?>>Link</option>
                            <option value="section" <?php echo $editKind === 'section' ? 'selected' : ''; ?>>Section heading</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Order</label>
                        <input class="form-control" type="number" name="display_order" value="<?php echo (int)($editItem['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-12" data-menu-href>
                        <label class="form-label fw-semibold">Internal destination</label>
                        <input class="form-control" name="href" value="<?php echo h((string)($editItem['href'] ?? '')); ?>" placeholder="events.php">
                        <div class="form-text">Admin-relative filename or a root-relative internal path.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Parent section</label>
                        <select class="form-select" name="parent_id">
                            <option value="0">Top level</option>
                            <?php foreach ($topLevelItems as $parent): ?>
                                <?php if ((int)$parent['id'] === (int)($editItem['id'] ?? 0)) { continue; } ?>
                                <option value="<?php echo (int)$parent['id']; ?>" <?php echo (int)($editItem['parent_id'] ?? 0) === (int)$parent['id'] ? 'selected' : ''; ?>><?php echo h((string)$parent['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-label fw-semibold">Visible to roles</div>
                        <?php foreach (['superadmin' => 'Superadmin', 'admin' => 'Admin', 'organiser' => 'Organiser'] as $role => $label): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="required_roles[]" value="<?php echo h($role); ?>" id="menu-role-<?php echo h($role); ?>" <?php echo in_array($role, $editRoles, true) ? 'checked' : ''; ?> <?php echo !empty($editItem['is_system']) ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="menu-role-<?php echo h($role); ?>"><?php echo h($label); ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!empty($editItem['is_system'])): ?>
                            <?php foreach ($editRoles as $role): ?><input type="hidden" name="required_roles[]" value="<?php echo h($role); ?>"><?php endforeach; ?>
                            <div class="form-text">Built-in role restrictions remain enforced in code.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="menu-active" <?php echo !empty($editItem['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="menu-active">Visible in menu</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-success" type="submit">Save menu item</button>
                        <a class="btn btn-outline-secondary" href="<?php echo h($adminBase); ?>/menu.php">Cancel</a>
                    </div>
                </form>
                <?php if (!empty($editItem['id']) && empty($editItem['is_system'])): ?>
                    <hr>
                    <form method="post" onsubmit="return confirm('Delete this menu item?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$editItem['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete menu item</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    (() => {
        const kind = document.querySelector('[data-menu-kind]');
        const hrefWrap = document.querySelector('[data-menu-href]');
        if (!kind || !hrefWrap) return;
        const sync = () => {
            const isSection = kind.value === 'section';
            hrefWrap.classList.toggle('d-none', isSection);
            const input = hrefWrap.querySelector('input[name="href"]');
            if (input) input.required = !isSection;
        };
        kind.addEventListener('change', sync);
        sync();
    })();
</script>
<?php
admin_layout_end();
