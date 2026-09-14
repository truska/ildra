<?php
declare(strict_types=1);

/**
 * Action-level restrictions are deliberately narrower than page access.
 * A missing or disabled restriction inherits the page's existing access rule.
 */
function defaultAdminActionRestrictions(): array
{
    return [
        ['finance.create_payout', 'finance', 'Create Stripe payout', 'Move an event balance from Stripe to the nominated account.', 1, 'admin', 10],
        ['finance.adjust_balance', 'finance', 'Adjust account balance', 'Manually credit or debit a user account balance.', 0, '', 20],
        ['finance.create_misc_payment', 'finance', 'Send miscellaneous payment request', 'Email a recipient a one-off Stripe payment request.', 1, 'admin', 25],
        ['memberships.change_logbook_rate', 'memberships', 'Change horse logbook rate', 'Change the annual horse logbook price or status.', 1, 'admin', 30],
        ['people.allocate_membership', 'people', 'Allocate membership', 'Grant an administrator-allocated membership.', 1, 'admin', 50],
        ['events.delete', 'events', 'Delete event', 'Permanently delete an event and its associated data.', 0, '', 80],
    ];
}

function ensureAdminActionRestrictionsTable(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_action_restrictions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        action_key VARCHAR(100) NOT NULL UNIQUE,
        page_key VARCHAR(64) NOT NULL,
        label VARCHAR(150) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        is_restricted TINYINT(1) NOT NULL DEFAULT 0,
        allowed_roles VARCHAR(100) NOT NULL DEFAULT '',
        display_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_admin_action_page_order (page_key, display_order)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $stmt = $pdo->prepare("INSERT IGNORE INTO admin_action_restrictions
        (action_key, page_key, label, description, is_restricted, allowed_roles, display_order)
        VALUES (:action_key, :page_key, :label, :description, :restricted, :roles, :display_order)");
    foreach (defaultAdminActionRestrictions() as [$key, $page, $label, $description, $restricted, $roles, $order]) {
        $stmt->execute([':action_key'=>$key, ':page_key'=>$page, ':label'=>$label, ':description'=>$description, ':restricted'=>$restricted, ':roles'=>$roles, ':display_order'=>$order]);
    }
}

function fetchAdminActionRestrictions(?PDO $pdo): array
{
    if (!$pdo) return [];
    ensureAdminActionRestrictionsTable($pdo);
    return $pdo->query('SELECT * FROM admin_action_restrictions ORDER BY page_key, display_order, label, id')->fetchAll() ?: [];
}

function adminActionAllowed(?PDO $pdo, string $actionKey, string $role): bool
{
    $role = strtolower(trim($role));
    if ($role === 'superadmin') return true; // SuperAdmin retains recovery access.
    if (!$pdo || $actionKey === '') return true;
    ensureAdminActionRestrictionsTable($pdo);
    $stmt = $pdo->prepare('SELECT is_restricted, allowed_roles FROM admin_action_restrictions WHERE action_key = :action_key LIMIT 1');
    $stmt->execute([':action_key'=>$actionKey]);
    $action = $stmt->fetch();
    if (!$action || empty($action['is_restricted'])) return true;
    $roles = array_filter(array_map('trim', explode(',', strtolower((string)$action['allowed_roles']))));
    return in_array($role, $roles, true);
}

function saveAdminActionRestriction(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) { $alerts[] = ['type'=>'danger', 'message'=>'Database unavailable.']; return false; }
    ensureAdminActionRestrictionsTable($pdo);
    $id = max(0, (int)($data['id'] ?? 0));
    $restricted = !empty($data['is_restricted']);
    $roles = array_values(array_intersect(['admin', 'manager', 'organiser'], (array)($data['allowed_roles'] ?? [])));
    if ($id <= 0) { $alerts[] = ['type'=>'danger', 'message'=>'Action restriction not found.']; return false; }
    if ($restricted && !$roles) { $alerts[] = ['type'=>'danger', 'message'=>'Choose at least one role, or turn off the separate restriction.']; return false; }
    $stmt = $pdo->prepare('UPDATE admin_action_restrictions SET is_restricted=:restricted, allowed_roles=:roles WHERE id=:id');
    $stmt->execute([':restricted'=>$restricted ? 1 : 0, ':roles'=>implode(',', $roles), ':id'=>$id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM admin_action_restrictions WHERE id=:id'); $check->execute([':id'=>$id]);
        if (!$check->fetchColumn()) { $alerts[] = ['type'=>'danger', 'message'=>'Action restriction not found.']; return false; }
    }
    return true;
}
