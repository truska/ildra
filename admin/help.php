<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';
$isAdmin=in_array(strtolower((string)($currentUser['role']??'')),['superadmin','admin','manager'],true);
if(!$isAdmin){header('Location: index.php');exit;}
ensureHelpTables($pdo);
$view=(string)($_GET['view']??'articles');
if(!in_array($view,['articles','groups'],true))$view='articles';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 $action=(string)($_POST['action']??'');
 if($action==='save_group'&&saveHelpGroup($pdo,$_POST,$alerts)){$_SESSION['flash_success']='Help section saved.';header('Location: help.php?view=groups');exit;}
 if($action==='save_article_orders'){
  $orders=$_POST['display_order']??[];
  if(is_array($orders)){
   $stmt=$pdo->prepare('UPDATE help_articles SET display_order=:display_order WHERE id=:id');
   foreach($orders as $id=>$displayOrder){$id=(int)$id;if($id>0)$stmt->execute([':display_order'=>(int)$displayOrder,':id'=>$id]);}
  }
  $_SESSION['flash_success']='Help article order saved.';header('Location: help.php');exit;
 }
 if($action==='delete_article'){$stmt=$pdo->prepare('DELETE FROM help_articles WHERE id=:id');$stmt->execute([':id'=>(int)($_POST['id']??0)]);$_SESSION['flash_success']='Help article deleted.';header('Location: help.php');exit;}
}
$groups=fetchHelpGroups($pdo,false);
$articles=$pdo->query('SELECT a.*,g.name AS group_name FROM help_articles a LEFT JOIN help_groups g ON g.id=a.group_id ORDER BY a.display_order,a.title')->fetchAll();
$userLevelOptions=helpUserLevelOptions($pdo);
$filterForm='help-article-filter-form';
$groupOptions=['0'=>'Global'];foreach($groups as $group)$groupOptions[(string)$group['id']]=$group['name'];
$articleColumns=[
 'display_order'=>['label'=>'Order','sortable'=>true,'compare'=>'number','filter'=>'text','placeholder'=>'Order','search_min_length'=>1,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['display_order']],
 'title'=>['label'=>'Title','sortable'=>true,'filter'=>'text','placeholder'=>'Search title','form'=>$filterForm,'value'=>static fn(array $row):string=>(string)$row['title']],
 'article_group'=>['label'=>'Page group','sortable'=>true,'filter'=>'select','options'=>$groupOptions,'form'=>$filterForm,'value'=>static fn(array $row):string=>(string)($row['group_id']??0),'sort_value'=>static fn(array $row):string=>(string)($row['group_name']?:'Global')],
 'manuals'=>['label'=>'Manuals','sortable'=>true,'filter'=>'select','options'=>['admin'=>'Admin','user'=>'User','both'=>'Admin & User','none'=>'Neither'],'form'=>$filterForm,'value'=>static fn(array $row):string=>!empty($row['include_in_admin_manual'])&& !empty($row['include_in_user_manual'])?'both':(!empty($row['include_in_admin_manual'])?'admin':(!empty($row['include_in_user_manual'])?'user':'none'))],
 'audience'=>['label'=>'Audience','sortable'=>true,'filter'=>'text','placeholder'=>'Search audience','form'=>$filterForm,'value'=>static fn(array $row):string=>helpUserLevelLabel($userLevelOptions,(int)$row['min_user_level']).'–'.helpUserLevelLabel($userLevelOptions,$row['max_user_level']===null?null:(int)$row['max_user_level'])],
 'status'=>['label'=>'Status','sortable'=>true,'filter'=>'select','options'=>['1'=>'Published','0'=>'Draft'],'form'=>$filterForm,'value'=>static fn(array $row):string=>!empty($row['is_published'])?'1':'0','sort_value'=>static fn(array $row):string=>!empty($row['is_published'])?'Published':'Draft'],
];
$articleTable=admin_table_prepare($articles,$articleColumns,'display_order');
$articles=$articleTable['rows'];
$editGroup=null;$gid=(int)($_GET['group']??0);foreach($groups as $g)if((int)$g['id']===$gid)$editGroup=$g;
if(isset($_GET['new_group']))$editGroup=['id'=>0,'name'=>'','description'=>'','path_patterns'=>'','display_order'=>0,'is_active'=>1];
if($editGroup!==null)$view='groups';
admin_layout_start('Help','help');
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><div><div class="small text-muted">Contextual guidance by page and user level</div><h5 class="mb-0">Help</h5></div><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="account_intros.php">Account Help</a><?php if($view==='articles'): ?> <a class="btn btn-success" href="help_edit.php">Add help article</a><?php else: ?> <a class="btn btn-success" href="help.php?view=groups&amp;new_group=1">Add Help Group</a><?php endif; ?></div></div>
<?php if($alerts):foreach($alerts as $alert):?><div class="alert alert-<?php echo h($alert['type']); ?>"><?php echo h($alert['message']); ?></div><?php endforeach;endif;?>
<ul class="nav nav-tabs mb-4" aria-label="Help administration sections"><li class="nav-item"><a class="nav-link <?php echo $view==='articles'?'active':''; ?>" href="help.php">Articles <span class="badge bg-secondary ms-1"><?php echo count($articles); ?></span></a></li><li class="nav-item"><a class="nav-link <?php echo $view==='groups'?'active':''; ?>" href="help.php?view=groups">Help Groups <span class="badge bg-secondary ms-1"><?php echo count($groups); ?></span></a></li></ul>
<?php if($view==='articles'): ?>
<form method="get" id="<?php echo h($filterForm); ?>"></form>
<form method="post" id="help-article-order-form"><input type="hidden" name="action" value="save_article_orders"></form>
<div class="card-soft p-3"><div class="d-flex justify-content-between align-items-center gap-2 mb-2"><h6 class="mb-0">Help articles</h6><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="help.php">Clear filters</a><button class="btn btn-sm btn-success" form="help-article-order-form">Save order</button></div></div><div class="table-responsive"><table class="table table-sm align-middle admin-data-table"><thead class="table-light"><tr><?php foreach($articleColumns as $key=>$column):?><th><?php echo admin_table_heading($key,$column,$articleTable['sort_key'],$articleTable['sort_dir']); ?></th><?php endforeach;?><th></th></tr><tr class="admin-table-filter-row"><?php foreach($articleColumns as $key=>$column):?><th><?php echo admin_table_filter($key,$column,$articleTable['filters']); ?></th><?php endforeach;?><th></th></tr></thead><tbody><?php foreach($articles as $a):$minLevel=(int)$a['min_user_level'];$maxLevel=$a['max_user_level']===null?null:(int)$a['max_user_level'];?><tr><td><input class="form-control form-control-sm" form="help-article-order-form" type="number" name="display_order[<?php echo (int)$a['id']; ?>]" value="<?php echo (int)$a['display_order']; ?>" aria-label="Order for <?php echo h($a['title']); ?>"></td><td><?php echo h($a['title']); ?></td><td><?php echo h($a['group_name']?:'Global'); ?></td><td><?php if(!empty($a['include_in_admin_manual'])):?><span class="badge bg-success">Admin</span> <?php endif;?><?php if(!empty($a['include_in_user_manual'])):?><span class="badge bg-primary">User</span><?php endif;?><?php if(empty($a['include_in_admin_manual'])&&empty($a['include_in_user_manual'])):?><span class="text-muted">—</span><?php endif;?></td><td><?php echo h(helpUserLevelLabel($userLevelOptions,$minLevel)); ?>–<?php echo h(helpUserLevelLabel($userLevelOptions,$maxLevel)); ?></td><td><?php echo $a['is_published']?'Published':'Draft'; ?></td><td class="text-end"><a class="btn btn-sm btn-outline-success" href="help_edit.php?id=<?php echo (int)$a['id']; ?>">Edit</a> <form method="post" class="d-inline" onsubmit="return confirm('Delete this help article?')"><input type="hidden" name="action" value="delete_article"><input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach;?><?php if(!$articles):?><tr><td colspan="7" class="text-muted">No help articles yet.</td></tr><?php endif;?></tbody></table></div><?php echo admin_table_pagination($articleTable); ?></div>
<?php else: ?>
<?php if($editGroup):?><div class="card-soft p-4 mb-4"><h6><?php echo $editGroup['id']?'Edit':'Add'; ?> page group</h6><form method="post" class="row g-3"><input type="hidden" name="action" value="save_group"><input type="hidden" name="group_id" value="<?php echo (int)$editGroup['id']; ?>"><div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" required value="<?php echo h($editGroup['name']); ?>"></div><div class="col-md-6"><label class="form-label">Description</label><input class="form-control" name="description" value="<?php echo h($editGroup['description']); ?>"></div><div class="col-md-8"><label class="form-label">Page URL patterns</label><textarea class="form-control" name="path_patterns" rows="4" required placeholder="/account&#10;/member-login.php&#10;/admin/*.php"><?php echo h($editGroup['path_patterns']); ?></textarea><div class="form-text">One pattern per line. Use * as a wildcard.</div></div><div class="col-md-2"><label class="form-label">Order</label><input class="form-control" type="number" name="display_order" value="<?php echo (int)$editGroup['display_order']; ?>"></div><div class="col-md-2 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="group-active" <?php echo $editGroup['is_active']?'checked':'';?>><label class="form-check-label" for="group-active">Active</label></div></div><div><button class="btn btn-success">Save group</button> <a class="btn btn-outline-secondary" href="help.php?view=groups">Cancel</a></div></form></div><?php endif;?>
<div class="card-soft p-3"><h6>Page groups</h6><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Name</th><th>URL patterns</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($groups as $g):?><tr><td><?php echo h($g['name']); ?></td><td><small><?php echo nl2br(h($g['path_patterns'])); ?></small></td><td><?php echo $g['is_active']?'Active':'Hidden'; ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="help.php?view=groups&amp;group=<?php echo (int)$g['id']; ?>">Edit</a></td></tr><?php endforeach;?><?php if(!$groups):?><tr><td colspan="4" class="text-muted">No page groups yet.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif; ?>
<?php admin_layout_end(); ?>
