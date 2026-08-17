<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin'], true)) { header('Location: index.php'); exit; }
$pageId = max(0, (int)($_GET['page_id'] ?? $_POST['page_id'] ?? 0));
$page = $pageId ? fetchPageById($pdo, $pageId) : null;
if (!$page) { $_SESSION['flash_alerts'] = [['type'=>'danger','message'=>'Page not found.']]; header('Location: pages.php'); exit; }
function page_element_anchor(string $value): string { return image_upload_slug($value); }
ensurePageContentElementsTable($pdo);
$edit = (string)($_GET['edit'] ?? ''); $id = ctype_digit($edit) ? (int)$edit : 0;
$record = $id ? fetchPageContentElement($pdo, $id) : ($edit === 'new' ? ['id'=>0,'name'=>'','heading'=>'','body_html'=>'','content_type'=>'rich_text','layout'=>'auto','display_order'=>100,'show_on_web'=>1,'archived'=>0] : null);
if ($record && (int)($record['page_id'] ?? $pageId) !== $pageId) $record = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save'); $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM page_content_elements WHERE id=:id AND page_id=:page')->execute([':id'=>$id, ':page'=>$pageId]);
        $_SESSION['flash_success'] = 'Content section deleted.'; header('Location: page_elements.php?page_id='.$pageId); exit;
    }
    $name = trim((string)($_POST['name'] ?? '')); $heading = trim((string)($_POST['heading'] ?? ''));
    $anchor = page_element_anchor($heading ?: $name); $body = trim((string)($_POST['body_html'] ?? ''));
    $type = in_array($_POST['content_type'] ?? '', ['rich_text','membership_options','horse_logbook_information','ride_prices','current_events_calendar','past_events_calendar','faqs','news_list','awards_winners'], true) ? $_POST['content_type'] : 'rich_text';
    $layout = in_array($_POST['layout'] ?? '', ['auto','image_left','image_right','text_only'], true) ? $_POST['layout'] : 'auto';
    if ($name === '') $alerts[] = ['type'=>'danger','message'=>'Section name is required.'];
    $check = $pdo->prepare('SELECT id FROM page_content_elements WHERE page_id=:page AND anchor_slug=:anchor AND id<>:id LIMIT 1');
    $check->execute([':page'=>$pageId, ':anchor'=>$anchor, ':id'=>$id]);
    if ($check->fetchColumn()) $alerts[] = ['type'=>'danger','message'=>'That heading creates a duplicate section link.'];
    if (!$alerts) {
        $params = [':page'=>$pageId, ':name'=>$name, ':heading'=>$heading ?: null, ':anchor'=>$anchor, ':body'=>$body ?: null, ':type'=>$type, ':layout'=>$layout, ':order'=>(int)($_POST['display_order'] ?? 100), ':show'=>!empty($_POST['show_on_web']) ? 1 : 0, ':archived'=>!empty($_POST['archived']) ? 1 : 0];
        if ($id) { $params[':id']=$id; $sql='UPDATE page_content_elements SET name=:name,heading=:heading,anchor_slug=:anchor,body_html=:body,content_type=:type,layout=:layout,display_order=:order,show_on_web=:show,archived=:archived,updated_at=NOW() WHERE id=:id AND page_id=:page'; }
        else $sql='INSERT INTO page_content_elements(page_id,name,heading,anchor_slug,body_html,content_type,layout,display_order,show_on_web,archived) VALUES(:page,:name,:heading,:anchor,:body,:type,:layout,:order,:show,:archived)';
        $pdo->prepare($sql)->execute($params); $_SESSION['flash_success']='Content section saved.'; header('Location: page_elements.php?page_id='.$pageId); exit;
    }
    $record = array_merge($record ?: [], $_POST, ['id'=>$id]);
}
$elements = fetchPageContentElements($pdo, $pageId); $isEditor = (bool)$record;
admin_layout_start('Page Content Sections', 'pages');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Content Sections</div><h5 class="mb-0"><?php echo h($page['title']); ?></h5></div><div><a class="btn btn-outline-secondary" href="page_edit.php?id=<?php echo $pageId; ?>">Back to Page</a><?php if (!$isEditor): ?> <a class="btn btn-success" href="page_elements.php?page_id=<?php echo $pageId; ?>&edit=new">Add New</a><?php endif; ?></div></div>
<?php if ($isEditor): ?>
<div class="card-soft p-4"><form method="post" class="row g-3"><input type="hidden" name="page_id" value="<?php echo $pageId; ?>"><input type="hidden" name="id" value="<?php echo (int)($record['id'] ?? 0); ?>">
<div class="col-md-6"><label class="form-label">Internal name</label><input class="form-control" required name="name" value="<?php echo h($record['name'] ?? ''); ?>"></div><div class="col-md-6"><label class="form-label">Heading</label><input class="form-control" name="heading" value="<?php echo h($record['heading'] ?? ''); ?>"></div>
<div class="col-md-5"><label class="form-label">Section type</label><select class="form-select" name="content_type"><option value="rich_text" <?php echo ($record['content_type'] ?? 'rich_text') === 'rich_text' ? 'selected' : ''; ?>>Rich text</option><option value="membership_options" <?php echo ($record['content_type'] ?? 'rich_text') === 'membership_options' ? 'selected' : ''; ?>>Membership options (live data)</option><option value="horse_logbook_information" <?php echo ($record['content_type'] ?? 'rich_text') === 'horse_logbook_information' ? 'selected' : ''; ?>>Horse logbook information (live data)</option><option value="ride_prices" <?php echo ($record['content_type'] ?? 'rich_text') === 'ride_prices' ? 'selected' : ''; ?>>Ride prices (live data)</option><option value="current_events_calendar" <?php echo ($record['content_type'] ?? 'rich_text') === 'current_events_calendar' ? 'selected' : ''; ?>>Current events calendar (live data)</option><option value="past_events_calendar" <?php echo ($record['content_type'] ?? 'rich_text') === 'past_events_calendar' ? 'selected' : ''; ?>>Past events calendar (live data)</option><option value="faqs" <?php echo ($record['content_type'] ?? 'rich_text') === 'faqs' ? 'selected' : ''; ?>>FAQs (live data)</option><option value="news_list" <?php echo ($record['content_type'] ?? 'rich_text') === 'news_list' ? 'selected' : ''; ?>>News list (live data)</option><option value="awards_winners" <?php echo ($record['content_type'] ?? 'rich_text') === 'awards_winners' ? 'selected' : ''; ?>>Awards and past winners (live data)</option></select><div class="form-text">Live types show the current published details and prices from the database.</div></div>
<div class="col-12"><label class="form-label">Content / introduction</label><textarea class="form-control wysiwyg-field" rows="10" name="body_html"><?php echo h($record['body_html'] ?? ''); ?></textarea></div>
<div class="col-md-4"><label class="form-label">Layout</label><select class="form-select" name="layout"><?php foreach (['auto'=>'Automatic','image_left'=>'Image left','image_right'=>'Image right','text_only'=>'Text only'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo ($record['layout'] ?? 'auto') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Order</label><input class="form-control" type="number" name="display_order" value="<?php echo (int)($record['display_order'] ?? 100); ?>"></div><div class="col-md-2 form-check mt-5"><input class="form-check-input" type="checkbox" name="show_on_web" id="show" <?php echo !empty($record['show_on_web']) ? 'checked' : ''; ?>><label for="show">Show on web</label></div><div class="col-md-2 form-check mt-5"><input class="form-check-input" type="checkbox" name="archived" id="archive" <?php echo !empty($record['archived']) ? 'checked' : ''; ?>><label for="archive">Archived</label></div><div class="col-12"><button class="btn btn-success">Save Section</button> <a class="btn btn-outline-secondary" href="page_elements.php?page_id=<?php echo $pageId; ?>">Cancel</a></div></form></div>
<?php render_tinymce_bootstrap(); ?><script>tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php else: ?>
<div class="card-soft p-3"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Order</th><th>Name / heading</th><th>Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody><?php foreach ($elements as $element): ?><?php $typeLabels=['membership_options'=>'Membership options','horse_logbook_information'=>'Horse logbook information','ride_prices'=>'Ride prices','current_events_calendar'=>'Current events calendar','past_events_calendar'=>'Past events calendar','faqs'=>'FAQs','news_list'=>'News list','awards_winners'=>'Awards and past winners']; ?><tr><td><?php echo (int)$element['display_order']; ?></td><td><strong><?php echo h($element['name']); ?></strong><div class="small text-muted"><?php echo h($element['heading'] ?: ''); ?></div></td><td><?php echo h($typeLabels[$element['content_type'] ?? 'rich_text'] ?? 'Rich text'); ?></td><td><?php echo !empty($element['archived']) ? 'Archived' : (!empty($element['show_on_web']) ? 'Visible' : 'Hidden'); ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="page_elements.php?page_id=<?php echo $pageId; ?>&edit=<?php echo (int)$element['id']; ?>">Edit</a> <a class="btn btn-sm btn-outline-primary" href="element_images.php?element_id=<?php echo (int)$element['id']; ?>">Images</a> <form class="d-inline" method="post" onsubmit="return confirm('Delete this section?');"><input type="hidden" name="page_id" value="<?php echo $pageId; ?>"><input type="hidden" name="id" value="<?php echo (int)$element['id']; ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?><?php if (!$elements): ?><tr><td colspan="5" class="text-muted">No additional content sections yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; admin_layout_end();
