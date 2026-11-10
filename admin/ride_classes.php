<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
$isAdmin=(($currentUser['role']??'')==='admin') || (int)($currentUser['level']??0)>=4;
if(!$isAdmin){http_response_code(403);exit('Only administrators can manage ride classes.');}
if(!ridePricingReady($pdo)){admin_layout_start('Ride Classes & Prices','pricing_schemes');echo '<div class="alert alert-info">The master ride class migration must be applied before editing ride prices.</div>';admin_layout_end();exit;}
$id=max(0,(int)($_GET['id']??0));
$classes=fetchMasterRideClasses($pdo);
$byId=array_column($classes,null,'id');
if($id && !isset($byId[$id])){http_response_code(404);exit('Ride class not found.');}
$editing=$id>0 || isset($_GET['new']);
$record=$byId[$id]??['class_id'=>0,'class_name'=>'','class_code'=>'','class_group'=>'PR','distance_km'=>'','price'=>'','foreign_recognition_price'=>'','is_member_price'=>0,'is_junior_ride'=>0,'rider_eligibility'=>'all','sort_order'=>10,'is_active'=>1,'show_on_public'=>1];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if((int)($_POST['class_id']??0)!==$id){http_response_code(400);exit('Invalid ride class.');}
    $saved=saveMasterRideClass($pdo,$_POST,$alerts);
    if($saved){adminAuditLog($pdo,'ride_classes.save',$currentUser,'ride_class',$saved,['before'=>$byId[$id]??null,'after'=>array_intersect_key($_POST,array_flip(['class_name','class_code','class_group','distance_km','price','foreign_recognition_price','is_member_price','is_junior_ride','rider_eligibility','sort_order','is_active','show_on_public']))]);$_SESSION['flash_success']='Master ride class saved. Existing event prices are unchanged.';header('Location: ride_classes.php');exit;}
    $record=$_POST;$editing=true;
}
admin_layout_start('Ride Classes & Prices','pricing_schemes');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h5>Ride Classes &amp; Prices</h5><p class="text-muted mb-0">Standard prices and eligibility shared by every ride pricing scheme. Existing events retain their saved prices.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="pricing_schemes.php">Pricing Schemes</a><a class="btn btn-success" href="ride_classes.php?new=1">Add Ride Class</a></div></div>
<?php if($editing): ?>
<form method="post" class="card-soft p-4 mb-4"><?php echo csrf_input(); ?><input type="hidden" name="class_id" value="<?php echo $id; ?>"><div class="row g-3">
<div class="col-md-8"><label class="form-label" for="class_name">Class Name</label><input class="form-control" id="class_name" name="class_name" maxlength="190" required value="<?php echo h($record['class_name']); ?>"></div>
<div class="col-md-4"><label class="form-label" for="class_code">Code</label><input class="form-control" id="class_code" name="class_code" maxlength="32" required value="<?php echo h($record['class_code']); ?>"></div>
<div class="col-md-3"><label class="form-label" for="class_group">Ride Type</label><select class="form-select" id="class_group" name="class_group"><?php foreach(['PR','VPR','CTR','ER','OTHER'] as $group): ?><option <?php echo $record['class_group']===$group?'selected':''; ?>><?php echo h($group); ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label" for="distance_km">Distance (km, Optional)</label><input class="form-control" id="distance_km" name="distance_km" type="number" min="1" max="1000" value="<?php echo h((string)($record['distance_km']??'')); ?>"></div>
<div class="col-md-3"><label class="form-label" for="price">Price (£)</label><input class="form-control" id="price" name="price" type="number" min="0" step="0.01" required value="<?php echo h((string)$record['price']); ?>"></div>
<div class="col-md-3"><label class="form-label" for="foreign_price">Non-ILDRA Member Price (£)</label><input class="form-control" id="foreign_price" name="foreign_recognition_price" type="number" min="0" step="0.01" value="<?php echo h((string)($record['foreign_recognition_price']??'')); ?>"><div class="form-text">Optional on member rows; blank uses the member rate.</div></div>
<div class="col-md-4"><label class="form-label" for="eligibility">Rider Eligibility</label><select class="form-select" name="rider_eligibility" id="eligibility"><?php foreach(['all'=>'All','junior'=>'Junior','senior'=>'Senior'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $record['rider_eligibility']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label" for="sort_order">Display Order</label><input class="form-control" name="sort_order" id="sort_order" type="number" min="0" value="<?php echo (int)$record['sort_order']; ?>"></div>
<div class="col-12 d-flex gap-4 flex-wrap"><?php foreach(['is_member_price'=>'Members Only','is_junior_ride'=>'Accompanying Adult Required','is_active'=>'Active','show_on_public'=>'Show On Public Ride Pricing'] as $key=>$label): ?><label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="<?php echo $key; ?>" value="1" <?php echo !empty($record[$key])?'checked':''; ?>><?php echo $label; ?></label><?php endforeach; ?></div>
<div class="col-12"><button class="btn btn-success">Save Ride Class</button> <a class="btn btn-outline-secondary" href="ride_classes.php">Cancel</a></div></div></form>
<?php endif; ?>
<div class="card-soft p-3 table-responsive"><table class="table align-middle"><thead><tr><th>Order</th><th>Class</th><th>Type</th><th>Rider</th><th>Entry</th><th>Price</th><th>Public</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($classes as $row): ?><tr><td><?php echo (int)$row['sort_order']; ?></td><td><?php echo h($row['class_name']); ?><small class="d-block text-muted"><?php echo h($row['class_code']); ?></small></td><td><?php echo h($row['class_group']); ?></td><td><?php echo h(ucfirst($row['rider_eligibility'])); ?></td><td><?php echo $row['is_member_price']?'Member':'Non-Member'; ?></td><td><?php echo format_price($row['price']); ?></td><td><?php echo $row['show_on_public']?'Yes':'No'; ?></td><td><?php echo $row['is_active']?'Active':'Inactive'; ?></td><td><a class="btn btn-sm btn-outline-success" href="ride_classes.php?id=<?php echo (int)$row['id']; ?>">Edit</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php admin_layout_end(); ?>
