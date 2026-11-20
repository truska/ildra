<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/admin_menu.php';
function roleIsDeveloper(string $role): bool { return $role === 'developer'; }
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function item(int $id, bool $divider = false, int $parent = 0, string $roles = 'admin,manager'): array {
    return ['id'=>$id, 'menu_key'=>'test_'.$id, 'item_type'=>$divider ? 'divider' : 'link',
        'parent_id'=>$parent, 'is_active'=>1, 'required_roles'=>$roles];
}
$items = [item(1,true), item(2), item(3,true), item(4,true), item(5,false,0,'admin'), item(6,true)];
check(array_column(buildAdminMenuTree($items, 'admin'), 'id') === [2,3,5], 'Trim outer and consecutive dividers');
check(array_column(buildAdminMenuTree($items, 'manager'), 'id') === [2], 'Trim after role filtering');
$items = [item(1), item(2,true,1), item(3,false,1), item(4,true,1), item(5,true,1), item(6,false,1,'admin'), item(7,true,1)];
check(array_column(buildAdminMenuTree($items, 'admin')[0]['children'], 'id') === [3,4,6], 'Clean child dividers independently');
check(array_column(buildAdminMenuTree($items, 'manager')[0]['children'], 'id') === [3], 'Clean children after role filtering');
check(buildAdminMenuTree([item(1,true),item(2,true)], 'admin') === [], 'Divider-only menu is empty');
$hidden = item(2); $hidden['is_active'] = 0;
$deleted = item(3); $deleted['is_deleted'] = 1;
check(buildAdminMenuTree([$hidden,$deleted], 'developer') === [], 'Inactive and deleted items stay hidden for developers');
check(buildAdminMenuTree([item(1,false,0,'admin'),item(2,false,1)], 'manager') === [], 'Parent role restrictions remain enforced');
check(count(buildAdminMenuTree(defaultAdminMenuItems(), 'developer')) === count(defaultAdminMenuItems()), 'Existing default menu retained');
check(!adminMenuRoleAllowed(['menu_key'=>'import_workspace','required_roles'=>'superadmin,admin,manager,organiser'], 'superadmin'), 'Import workspace cannot grant access through editable menu roles');
check(adminMenuRoleAllowed(['menu_key'=>'import_workspace'], 'developer'), 'Developer sees import workspace');
echo "Admin menu divider ordering, visibility and role filtering passed.\n";
