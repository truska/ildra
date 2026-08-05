<?php
declare(strict_types=1);

function defaultAdminMenuItems(): array
{
    $rows = [
        ['dashboard', 'Dashboard', 'index.php'],
        ['pages', 'Pages', 'pages.php'],
        ['events', 'Events', 'events.php'],
        ['venues', 'Venues', 'venues.php'],
        ['pricing_schemes', 'Pricing Schemes', 'pricing_schemes.php'],
        ['bookings', 'Bookings', 'bookings.php'],
        ['finance', 'Finance', 'finance.php'],
        ['email', 'Email', 'email.php'],
        ['entry_components', 'Entry Components', 'entry_components.php'],
        ['faqs', 'FAQs', 'faqs.php'],
        ['memberships', 'Memberships', 'memberships.php'],
        ['members', 'Members', 'members.php'],
        ['people', 'People', 'people.php'],
        ['horses', 'Horses', 'horses.php'],
        ['hero', 'Site Hero & Welcome', 'hero.php'],
        ['users', 'Users', 'users.php'],
        ['settings', 'Settings', 'settings.php'],
        ['menu', 'Menu', 'menu.php'],
    ];
    $out = [];
    foreach ($rows as $index => $row) {
        $out[] = [
            'id' => 0,
            'menu_key' => $row[0],
            'label' => $row[1],
            'href' => $row[2],
            'parent_id' => null,
            'display_order' => ($index + 1) * 10,
            'is_active' => 1,
            'required_roles' => implode(',', adminMenuFixedRoles($row[0])),
            'is_system' => 1,
        ];
    }
    return $out;
}

function adminMenuFixedRoles(string $key): array
{
    if (in_array($key, ['users', 'email', 'pricing_schemes', 'people', 'horses', 'menu'], true)) {
        return ['superadmin', 'admin'];
    }
    return ['superadmin', 'admin', 'organiser'];
}

function ensureAdminMenuTable(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_menu_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            menu_key VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            href VARCHAR(255) DEFAULT NULL,
            parent_id INT UNSIGNED DEFAULT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            required_roles VARCHAR(100) NOT NULL DEFAULT 'superadmin,admin,organiser',
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_admin_menu_parent_order (parent_id, display_order)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_menu_items')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO admin_menu_items
            (menu_key, label, href, parent_id, display_order, is_active, required_roles, is_system)
        VALUES
            (:menu_key, :label, :href, NULL, :display_order, 1, :required_roles, 1)
    ");
    foreach (defaultAdminMenuItems() as $item) {
        $stmt->execute([
            ':menu_key' => $item['menu_key'],
            ':label' => $item['label'],
            ':href' => $item['href'],
            ':display_order' => $item['display_order'],
            ':required_roles' => $item['required_roles'],
        ]);
    }
}

function fetchAdminMenuItems(?PDO $pdo, bool $activeOnly = true): array
{
    if (!$pdo) {
        return defaultAdminMenuItems();
    }
    try {
        ensureAdminMenuTable($pdo);
        $sql = 'SELECT * FROM admin_menu_items';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_order ASC, label ASC, id ASC';
        return $pdo->query($sql)->fetchAll() ?: defaultAdminMenuItems();
    } catch (PDOException $e) {
        return defaultAdminMenuItems();
    }
}

function adminMenuRoleAllowed(array $item, string $role): bool
{
    $role = strtolower(trim($role));
    $key = (string)($item['menu_key'] ?? '');
    $allowed = !empty($item['is_system'])
        ? adminMenuFixedRoles($key)
        : array_values(array_filter(array_map('trim', explode(',', strtolower((string)($item['required_roles'] ?? ''))))));
    return in_array($role, $allowed ?: ['superadmin', 'admin', 'organiser'], true);
}

function buildAdminMenuTree(array $items, string $role): array
{
    $allowed = [];
    foreach ($items as $index => $item) {
        if (adminMenuRoleAllowed($item, $role)) {
            $item['children'] = [];
            $treeId = (int)($item['id'] ?? 0);
            if ($treeId <= 0) {
                $treeId = -1 - $index;
            }
            $item['_tree_id'] = $treeId;
            $allowed[$treeId] = $item;
        }
    }
    foreach ($allowed as $treeId => $item) {
        $parentId = (int)($item['parent_id'] ?? 0);
        if ($parentId > 0 && !isset($allowed[$parentId])) {
            unset($allowed[$treeId]);
        }
    }
    foreach ($allowed as $id => $item) {
        $parentId = (int)($item['parent_id'] ?? 0);
        if ($parentId > 0 && isset($allowed[$parentId])) {
            $allowed[$parentId]['children'][] = $item;
        }
    }
    $roots = [];
    foreach ($allowed as $item) {
        $parentId = (int)($item['parent_id'] ?? 0);
        if ($parentId <= 0) {
            $roots[] = $item;
        }
    }
    return $roots;
}

function adminMenuHref(array $item, string $adminBase): string
{
    $href = trim((string)($item['href'] ?? ''));
    if ($href === '') {
        return '#';
    }
    if (str_starts_with($href, '/')) {
        return $href;
    }
    return $adminBase . '/' . ltrim($href, '/');
}

function saveAdminMenuItem(?PDO $pdo, array $data, array &$alerts): ?int
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return null;
    }
    ensureAdminMenuTable($pdo);
    $id = max(0, (int)($data['id'] ?? 0));
    $label = trim((string)($data['label'] ?? ''));
    $kind = (string)($data['kind'] ?? 'link');
    $href = $kind === 'section' ? '' : trim((string)($data['href'] ?? ''));
    $parentId = max(0, (int)($data['parent_id'] ?? 0));
    $displayOrder = (int)($data['display_order'] ?? 0);
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $roles = array_values(array_intersect(['superadmin', 'admin', 'organiser'], (array)($data['required_roles'] ?? [])));

    if ($label === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Menu label is required.'];
    }
    if ($kind !== 'section' && ($href === '' || str_contains($href, '://') || str_starts_with($href, '//') || preg_match('/[\x00-\x1F<>"\']/', $href))) {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a safe internal link such as events.php.'];
    }
    if (!$roles) {
        $alerts[] = ['type' => 'danger', 'message' => 'Select at least one permitted role.'];
    }
    if ($parentId === $id && $id > 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'A menu item cannot be its own parent.'];
    }
    if ($parentId > 0) {
        $stmt = $pdo->prepare('SELECT parent_id FROM admin_menu_items WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $parentId]);
        $parent = $stmt->fetch();
        if (!$parent || (int)($parent['parent_id'] ?? 0) > 0) {
            $alerts[] = ['type' => 'danger', 'message' => 'Menu items can only be assigned to a top-level section.'];
        }
    }
    if ($alerts) {
        return null;
    }

    if ($id > 0) {
        $existingStmt = $pdo->prepare('SELECT menu_key, is_system, required_roles FROM admin_menu_items WHERE id = :id LIMIT 1');
        $existingStmt->execute([':id' => $id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            $alerts[] = ['type' => 'danger', 'message' => 'Menu item not found.'];
            return null;
        }
        $requiredRoles = !empty($existing['is_system'])
            ? (string)$existing['required_roles']
            : implode(',', $roles);
        $stmt = $pdo->prepare("
            UPDATE admin_menu_items
            SET label = :label, href = :href, parent_id = :parent_id,
                display_order = :display_order, is_active = :is_active, required_roles = :required_roles
            WHERE id = :id
        ");
        $stmt->execute([
            ':label' => $label,
            ':href' => $href !== '' ? $href : null,
            ':parent_id' => $parentId ?: null,
            ':display_order' => $displayOrder,
            ':is_active' => $isActive,
            ':required_roles' => $requiredRoles,
            ':id' => $id,
        ]);
        return $id;
    }

    $baseKey = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_') ?: 'item';
    $key = $baseKey;
    $suffix = 2;
    $check = $pdo->prepare('SELECT 1 FROM admin_menu_items WHERE menu_key = :menu_key LIMIT 1');
    do {
        $check->execute([':menu_key' => $key]);
        if (!$check->fetchColumn()) {
            break;
        }
        $key = $baseKey . '_' . $suffix++;
    } while ($suffix < 1000);
    $stmt = $pdo->prepare("
        INSERT INTO admin_menu_items
            (menu_key, label, href, parent_id, display_order, is_active, required_roles, is_system)
        VALUES
            (:menu_key, :label, :href, :parent_id, :display_order, :is_active, :required_roles, 0)
    ");
    $stmt->execute([
        ':menu_key' => $key,
        ':label' => $label,
        ':href' => $href !== '' ? $href : null,
        ':parent_id' => $parentId ?: null,
        ':display_order' => $displayOrder,
        ':is_active' => $isActive,
        ':required_roles' => implode(',', $roles),
    ]);
    return (int)$pdo->lastInsertId();
}

function deleteAdminMenuItem(?PDO $pdo, int $id, array &$alerts): bool
{
    if (!$pdo || $id <= 0) {
        return false;
    }
    ensureAdminMenuTable($pdo);
    $stmt = $pdo->prepare('SELECT is_system, (SELECT COUNT(*) FROM admin_menu_items c WHERE c.parent_id = m.id) AS child_count FROM admin_menu_items m WHERE m.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch();
    if (!$item || !empty($item['is_system'])) {
        $alerts[] = ['type' => 'danger', 'message' => 'Built-in menu items cannot be deleted; disable them instead.'];
        return false;
    }
    if ((int)($item['child_count'] ?? 0) > 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Move or delete this section’s child items first.'];
        return false;
    }
    $delete = $pdo->prepare('DELETE FROM admin_menu_items WHERE id = :id');
    $delete->execute([':id' => $id]);
    return true;
}
