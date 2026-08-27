<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
if(!in_array(strtolower((string)($currentUser['role']??'')),['superadmin','admin','manager'],true)){header('Location: index.php');exit;}
$id=(int)($_GET['id']??$_POST['article_id']??0);
$article=$id?fetchHelpArticle($pdo,$id):null;
if(($_SERVER['REQUEST_METHOD']??'')==='POST' && saveHelpArticle($pdo,$_POST,$alerts)){$_SESSION['flash_success']='Help article saved.';header('Location: help.php');exit;}
$article=$article?:['id'=>0,'title'=>'','summary'=>'','body_html'=>'','keywords'=>'','group_id'=>null,'is_global'=>1,'include_in_user_manual'=>0,'include_in_admin_manual'=>0,'min_user_level'=>0,'max_user_level'=>null,'display_order'=>0,'is_published'=>1];
if(($_SERVER['REQUEST_METHOD']??'')==='POST')$article=array_merge($article,$_POST);
$groups=fetchHelpGroups($pdo,false);
$userLevelOptions=helpUserLevelOptions($pdo);
$minimumLevel=(int)($article['min_user_level']??0);
$maximumLevel=trim((string)($article['max_user_level']??''))===''?null:(int)$article['max_user_level'];
if(!isset($userLevelOptions[$minimumLevel]))$userLevelOptions[$minimumLevel]=helpUserLevelLabel($userLevelOptions,$minimumLevel);
if($maximumLevel!==null&&!isset($userLevelOptions[$maximumLevel]))$userLevelOptions[$maximumLevel]=helpUserLevelLabel($userLevelOptions,$maximumLevel);
ksort($userLevelOptions);
admin_layout_start($id?'Edit help article':'Add help article','help');
?>
<div class="d-flex justify-content-between mb-3"><div><div class="small text-muted">Help guidance</div><h5 class="mb-0"><?php echo $id?'Edit article':'New article'; ?></h5></div><a class="btn btn-outline-secondary" href="help.php">Back to help</a></div>
<?php foreach($alerts as $alert):?><div class="alert alert-<?php echo h($alert['type']); ?>"><?php echo h($alert['message']); ?></div><?php endforeach;?>
<div class="card-soft p-4"><form method="post" class="row g-3"><input type="hidden" name="article_id" value="<?php echo (int)$article['id']; ?>">
<div class="col-12"><label class="form-label fw-semibold">Question or task</label><input class="form-control" name="title" required maxlength="200" value="<?php echo h($article['title']); ?>" placeholder="How do I see my entry history?"></div>
<div class="col-12"><label class="form-label">Short summary</label><textarea class="form-control" name="summary" rows="2"><?php echo h($article['summary']); ?></textarea></div>
<div class="col-12"><label class="form-label fw-semibold">Instructions</label><textarea class="form-control wysiwyg-field" name="body_html" rows="12"><?php echo h($article['body_html']); ?></textarea></div>
<div class="col-md-7"><label class="form-label">Keywords</label><input class="form-control" name="keywords" value="<?php echo h($article['keywords']); ?>" placeholder="entries, bookings, history"><div class="form-text">Comma-separated words shown as search shortcuts.</div></div>
<div class="col-md-5"><label class="form-label">Where this help appears</label><select class="form-select" name="group_id"><option value="0">Global — available everywhere</option><?php foreach($groups as $g):?><option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$article['group_id']===(int)$g['id']?'selected':'';?>><?php echo h($g['name']); ?></option><?php endforeach;?></select></div>
<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_global" id="is-global" <?php echo !empty($article['is_global'])?'checked':'';?>><label class="form-check-label" for="is-global">Also show this article in General help</label><div class="form-text">Useful when a task should appear in its relevant page section and also be searchable globally.</div></div></div>
<div class="col-12"><div class="row g-2"><div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="include_in_user_manual" id="user-manual" <?php echo !empty($article['include_in_user_manual'])?'checked':'';?>><label class="form-check-label" for="user-manual">Include in User Manual</label></div></div><div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="include_in_admin_manual" id="admin-manual" <?php echo !empty($article['include_in_admin_manual'])?'checked':'';?>><label class="form-check-label" for="admin-manual">Include in Admin Manual</label></div></div></div><div class="form-text">Manual inclusion is separate from where contextual help appears and which user levels may read it.</div></div>
<div class="col-md-3"><label class="form-label">Available from</label><select class="form-select" name="min_user_level"><?php foreach($userLevelOptions as $level=>$label):?><option value="<?php echo (int)$level; ?>" <?php echo $minimumLevel===(int)$level?'selected':'';?>><?php echo h($label); ?></option><?php endforeach;?></select><div class="form-text">Lowest audience that can see this help.</div></div>
<div class="col-md-3"><label class="form-label">Available up to</label><select class="form-select" name="max_user_level"><option value="" <?php echo $maximumLevel===null?'selected':'';?>>No maximum</option><?php foreach($userLevelOptions as $level=>$label):?><option value="<?php echo (int)$level; ?>" <?php echo $maximumLevel===(int)$level?'selected':'';?>><?php echo h($label); ?></option><?php endforeach;?></select><div class="form-text">Optionally stop showing it above this audience.</div></div>
<div class="col-md-3"><label class="form-label">Display order</label><input class="form-control" type="number" name="display_order" value="<?php echo (int)$article['display_order']; ?>"></div>
<div class="col-md-3 pt-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_published" id="published" <?php echo !empty($article['is_published'])?'checked':'';?>><label class="form-check-label" for="published">Published</label></div></div>
<div class="col-12"><button class="btn btn-success">Save help article</button> <a class="btn btn-outline-secondary" href="help.php">Cancel</a></div></form></div>
<?php render_tinymce_bootstrap(); ?><script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end(); ?>
