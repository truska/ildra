<?php
// Included by pricing_scheme_edit.php after its normal permission check.
if (!isset($schemeId, $eventTypes, $alerts)) { http_response_code(404); exit; }
$classes=fetchMasterRideClasses($pdo);
$selected=$schemeId?array_map('intval',array_column(fetchMasterSchemeRows($pdo,$schemeId,true),'id')):[];
$orders=[];
foreach($schemeId?fetchMasterSchemeRows($pdo,$schemeId,true):[] as $row)$orders[(int)$row['id']]=(int)$row['sort_order'];
$selectedTypes=$schemeId?fetchPricingSchemeEventTypeIds($pdo,$schemeId):rideTypeIds($pdo);
$defaults=[];
foreach($eventTypes as $type)if($schemeId && (int)($type['default_pricing_scheme_id']??0)===$schemeId)$defaults[]=(int)$type['id'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $savedId=savePricingScheme($pdo,$_POST,$alerts,$schemeId?:null);
    if($savedId){$_SESSION['flash_success']='Ride pricing scheme saved.';header('Location: pricing_scheme_edit.php?id='.$savedId);exit;}
    $selected=array_map('intval',(array)($_POST['master_class_ids']??[]));$orders=(array)($_POST['master_sort']??[]);
    $selectedTypes=array_map('intval',(array)($_POST['event_type_ids']??[]));$defaults=array_map('intval',(array)($_POST['default_for_type_ids']??[]));$scheme['name']=(string)($_POST['name']??'');
}
admin_layout_start($schemeId?'Edit Ride Pricing Scheme':'New Ride Pricing Scheme','pricing_schemes');
?>
<div class="d-flex justify-content-between mb-3"><h5><?php echo $schemeId?'Edit Ride Pricing Scheme':'New Ride Pricing Scheme'; ?></h5><div><a class="btn btn-outline-secondary" href="pricing_schemes.php">Back To Schemes</a> <a class="btn btn-outline-success" href="ride_classes.php">Master Ride Classes &amp; Prices</a></div></div>
<form method="post" class="card-soft p-4"><?php echo csrf_input(); ?><label class="form-label" for="scheme-name">Scheme Name</label><input id="scheme-name" class="form-control mb-3" name="name" maxlength="190" required value="<?php echo h((string)($scheme['name']??'')); ?>">
<?php foreach($eventTypes as $type): if(!in_array((int)$type['id'],rideTypeIds($pdo),true))continue; $tid=(int)$type['id']; ?>
<div class="mb-3"><label><input type="checkbox" name="event_type_ids[]" value="<?php echo $tid; ?>" <?php echo in_array($tid,$selectedTypes,true)?'checked':''; ?>> Applies To <?php echo h($type['name']); ?></label> <label class="ms-3"><input type="checkbox" name="default_for_type_ids[]" value="<?php echo $tid; ?>" <?php echo in_array($tid,$defaults,true)?'checked':''; ?>> Default For <?php echo h($type['name']); ?></label></div>
<?php endforeach; ?>
<p class="text-muted">Enable the standard classes for this scheme. Prices and eligibility are managed in the master list. Existing events keep their saved rates until explicitly reapplied before entries open.</p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Enabled</th><th>Order</th><th>Class</th><th>Type</th><th>Rider</th><th>Entry</th><th>Price</th></tr></thead><tbody>
<?php foreach($classes as $row): $id=(int)$row['id']; ?><tr><td><input class="form-check-input" type="checkbox" name="master_class_ids[]" aria-label="Enable <?php echo h($row['class_name']); ?>" value="<?php echo $id; ?>" <?php echo in_array($id,$selected,true)?'checked':''; ?> <?php echo !$row['is_active']?'disabled':''; ?>><?php if(!$row['is_active'] && in_array($id,$selected,true)): ?><input type="hidden" name="master_class_ids[]" value="<?php echo $id; ?>"><?php endif; ?></td><td><input class="form-control form-control-sm" style="width:90px" type="number" min="0" name="master_sort[<?php echo $id; ?>]" aria-label="Order for <?php echo h($row['class_name']); ?>" value="<?php echo (int)($orders[$id]??$row['sort_order']); ?>"></td><td><?php echo h($row['class_name']); ?><?php if(!$row['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td><td><?php echo h($row['class_group']); ?></td><td><?php echo h(ucfirst($row['rider_eligibility'])); ?></td><td><?php echo $row['is_member_price']?'Member':'Non-Member'; ?></td><td><?php echo format_price($row['price']); ?></td></tr><?php endforeach; ?>
</tbody></table></div><button class="btn btn-success">Save Scheme</button> <a class="btn btn-outline-secondary" href="pricing_schemes.php">Cancel</a></form>
<?php admin_layout_end(); ?>
