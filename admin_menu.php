<?php
declare(strict_types=1);

function defaultAdminMenuItems(): array
{
    $rows = [
        ['dashboard', 'Dashboard', 'index.php', 'fa-solid fa-gauge-high'],
        ['pages', 'Pages', 'pages.php', 'fa-solid fa-file-lines'],
        ['news', 'News', 'news.php', 'fa-solid fa-newspaper'],
        ['advertising', 'Advertising', 'advertising.php', 'fa-solid fa-rectangle-ad'],
        ['asset_library', 'Document & Image Library', 'asset_library.php', 'fa-solid fa-photo-film'],
        ['events', 'Events', 'events.php', 'fa-solid fa-calendar-days'],
        ['venues', 'Venues', 'venues.php', 'fa-solid fa-location-dot'],
        ['pricing_schemes', 'Pricing Schemes', 'pricing_schemes.php', 'fa-solid fa-tags'],
        ['bookings', 'Bookings', 'bookings.php', 'fa-solid fa-ticket'],
        ['finance', 'Finance', 'finance.php', 'fa-solid fa-sterling-sign'],
        ['email', 'Email', 'email.php', 'fa-solid fa-envelope'],
        ['entry_components', 'Entry Components', 'entry_components.php', 'fa-solid fa-puzzle-piece'],
        ['faqs', 'FAQs', 'faqs.php', 'fa-solid fa-circle-question'],
        ['help', 'Help', 'help.php', 'fa-solid fa-life-ring'],
        ['help_accounts', 'Account Help', 'account_intros.php', 'fa-solid fa-circle-info'],
        ['dev_tasks', 'Dev Tasks', 'dev_tasks.php', 'fa-solid fa-list-check'],
        ['memberships', 'Memberships', 'memberships.php', 'fa-solid fa-id-card'],
        ['members', 'Members', 'members.php', 'fa-solid fa-user-group'],
        ['awards', 'Awards', 'awards.php', 'fa-solid fa-trophy'],
        ['people', 'People', 'people.php', 'fa-solid fa-address-book'],
        ['horses', 'Horses', 'horses.php', 'fa-solid fa-horse'],
        ['users', 'Users', 'users.php', 'fa-solid fa-users-gear'],
        ['settings', 'Settings', 'settings.php', 'fa-solid fa-gear'],
        ['menu', 'Menu', 'menu.php', 'fa-solid fa-bars'],
    ];
    $out = [];
    foreach ($rows as $index => $row) {
        $out[] = [
            'id' => 0,
            'menu_key' => $row[0],
            'label' => $row[1],
            'href' => $row[2],
            'icon_class' => $row[3],
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
    if (in_array($key, ['users', 'email', 'pricing_schemes', 'people', 'horses', 'awards', 'menu', 'asset_library', 'help', 'help_accounts'], true)) {
        return ['superadmin', 'admin', 'manager'];
    }
    return ['superadmin', 'admin', 'manager', 'organiser'];
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
            icon_class VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-circle',
            parent_id INT UNSIGNED DEFAULT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            required_roles VARCHAR(100) NOT NULL DEFAULT 'superadmin,admin,manager,organiser',
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_admin_menu_parent_order (parent_id, display_order)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    if (!table_column_exists($pdo, 'admin_menu_items', 'icon_class')) {
        $pdo->exec("ALTER TABLE admin_menu_items ADD COLUMN icon_class VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-circle' AFTER href");
    }

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO admin_menu_items
            (menu_key, label, href, icon_class, parent_id, display_order, is_active, required_roles, is_system)
        VALUES
            (:menu_key, :label, :href, :icon_class, NULL, :display_order, 1, :required_roles, 1)
    ");
    foreach (defaultAdminMenuItems() as $item) {
        $stmt->execute([
            ':menu_key' => $item['menu_key'],
            ':label' => $item['label'],
            ':href' => $item['href'],
            ':icon_class' => $item['icon_class'],
            ':display_order' => $item['display_order'],
            ':required_roles' => $item['required_roles'],
        ]);
        $iconUpdate = $pdo->prepare("UPDATE admin_menu_items SET icon_class=:icon WHERE menu_key=:menu_key AND (icon_class='' OR icon_class='fa-solid fa-circle')");
        $iconUpdate->execute([':icon'=>$item['icon_class'], ':menu_key'=>$item['menu_key']]);
    }
    $pdo->exec("UPDATE admin_menu_items SET parent_id = NULL, label = 'Account Help', href = 'account_intros.php' WHERE menu_key = 'help_accounts'");
    $pdo->exec("UPDATE admin_menu_items SET is_active = 0 WHERE menu_key = 'hero'");
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
    return in_array($role, $allowed ?: ['superadmin', 'admin', 'manager', 'organiser'], true);
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
    $iconClass = trim((string)($data['icon_class'] ?? 'fa-solid fa-circle'));
    $parentId = max(0, (int)($data['parent_id'] ?? 0));
    $displayOrder = (int)($data['display_order'] ?? 0);
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $roles = array_values(array_intersect(['superadmin', 'admin', 'manager', 'organiser'], (array)($data['required_roles'] ?? [])));

    if ($label === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Menu label is required.'];
    }
    if (!preg_match('/^fa-(?:solid|regular|brands)(?: fa-[a-z0-9-]+)+$/', $iconClass)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid Font Awesome class, for example fa-solid fa-calendar-days.'];
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
            SET label = :label, href = :href, icon_class=:icon_class, parent_id = :parent_id,
                display_order = :display_order, is_active = :is_active, required_roles = :required_roles
            WHERE id = :id
        ");
        $stmt->execute([
            ':label' => $label,
            ':href' => $href !== '' ? $href : null,
            ':icon_class' => $iconClass,
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
            (menu_key, label, href, icon_class, parent_id, display_order, is_active, required_roles, is_system)
        VALUES
            (:menu_key, :label, :href, :icon_class, :parent_id, :display_order, :is_active, :required_roles, 0)
    ");
    $stmt->execute([
        ':menu_key' => $key,
        ':label' => $label,
        ':href' => $href !== '' ? $href : null,
        ':icon_class' => $iconClass,
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
