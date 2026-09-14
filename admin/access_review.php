<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
if (strtolower((string)($currentUser['role'] ?? '')) !== 'superadmin') { header('Location: index.php'); exit; }

function access_review_action_label(string $action): string {
    return ucwords(str_replace(['_', '-'], ' ', $action));
}
function access_review_is_generic(string $action): bool {
    $key=strtolower(trim($action));
    return $key==='' || in_array($key, ['save','cancel','close','back','clear','filter','search','reset'], true);
}
function access_review_visible_label(string $html): string {
    $label=strip_tags(preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i','',$html));
    return trim(preg_replace('/\s+/',' ',$label) ?? '');
}
function access_review_page_actions(string $href): array {
    $path=parse_url($href,PHP_URL_PATH) ?: ''; $file=basename($path);
    if($file==='' || !preg_match('/^[a-z0-9_]+\.php$/i',$file)) return [];
    $sourcePath=__DIR__.'/'.$file; if(!is_file($sourcePath)) return [];
    $source=(string)file_get_contents($sourcePath); $found=[];
    $visibleSource=preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i','',$source) ?? '';
    if(preg_match_all('/<(?:a|button)\b[^>]*>([\s\S]*?)<\/(?:a|button)>/i',$visibleSource,$controls)){
        foreach($controls[1] as $control){$label=access_review_visible_label($control);$key=strtolower($label);if($label!==''&&!access_review_is_generic($key)&&mb_strlen($label)<=80)$found['control:'.$key]=$label;}
    }
    natcasesort($found); return array_values($found);
}

$items=fetchAdminMenuItems($pdo,false); $actionRows=fetchAdminActionRestrictions($pdo); $actionsByPage=[];
foreach($actionRows as$row)$actionsByPage[(string)$row['page_key']][]=$row;
admin_layout_start('Access Review', 'tech');
?>
<style>@media print{.sidebar,.topbar,.btn,.no-print{display:none!important}.main-content{margin:0!important;padding:0!important}.card-soft{box-shadow:none!important;border:0!important}.table{font-size:10pt}.access-review-note{display:none}}</style>
<div class="d-flex justify-content-between align-items-center gap-2 mb-3 no-print"><div><div class="small text-muted">SuperAdmin only</div><h5 class="mb-0">Access review worksheet</h5></div><div class="d-flex gap-2"><button class="btn btn-success" onclick="window.print()">Print</button><a class="btn btn-outline-secondary" href="tech.php">Back to Tech</a></div></div>
<div class="alert alert-info small access-review-note">This is an inventory worksheet, not an access-control change. Only exact generic controls such as Save, Close, Cancel, Back, Clear, Filter and Search are omitted. “Configured restriction” records actions already connected to the action-restrictions system.</div>
<div class="card-soft p-3"><h1 class="h5 mb-3">Admin menus and actions</h1><div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>Menu / page</th><th>Current menu roles</th><th>Visible buttons and links</th><th>Configured action restriction</th><th>Decision / notes</th></tr></thead><tbody><?php foreach($items as$item): $href=(string)($item['href']??'');$pageKey=(string)($item['menu_key']??'');$detected=access_review_page_actions($href);$configured=$actionsByPage[$pageKey]??[]; ?><tr><td><div class="fw-semibold"><?php echo h((string)$item['label']); ?></div><div class="small text-muted"><?php echo h($href?:'Section heading'); ?></div></td><td class="small"><?php echo h((string)($item['required_roles']??'')); ?></td><td class="small"><?php echo $detected?h(implode(' · ',$detected)):'—'; ?></td><td class="small"><?php foreach($configured as$row): ?><div><?php echo h((string)$row['label']); ?> — <?php echo !empty($row['is_restricted'])?'Restricted to '.h((string)$row['allowed_roles']):'Inherits page'; ?></div><?php endforeach; ?><?php if(!$configured): ?>—<?php endif; ?></td><td style="min-width:180px"></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php admin_layout_end(); ?>
