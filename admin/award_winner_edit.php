<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin','admin','manager'], true)) { header('Location:index.php'); exit; }
ensureAwardsTables($pdo);
$id=(int)($_GET['id']??0); $awardId=(int)($_GET['award_id']??0); $winner=null;
foreach(fetchAwardWinners($pdo,$awardId) as $row) if((int)$row['id']===$id) $winner=$row;
if(!$winner){$_SESSION['flash_alerts']=[['type'=>'danger','message'=>'Winner not found.']];header('Location:awards.php');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){$year=(int)($_POST['award_year']??0);$name=trim((string)($_POST['winner_name']??''));if($year<1900||$name==='')$alerts[]=['type'=>'danger','message'=>'Enter a valid year and winner name.'];if(!$alerts){$pdo->prepare('UPDATE award_winners SET award_year=:year,winner_name=:name,is_published=:published,is_archived=:archived WHERE id=:id AND award_id=:award')->execute([':year'=>$year,':name'=>$name,':published'=>!empty($_POST['is_published'])?1:0,':archived'=>!empty($_POST['is_archived'])?1:0,':id'=>$id,':award'=>$awardId]);$_SESSION['flash_success']='Winner updated.';header('Location:award_winners.php?award_id='.$awardId);exit;}$winner=array_merge($winner,$_POST);}
$awardName='';foreach(fetchAwards($pdo) as $award)if((int)$award['id']===$awardId)$awardName=$award['name'];
admin_layout_start('Edit Award Winner','awards');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Past winners</div><h3 class="mb-1 fw-bold"><?php echo h($awardName); ?></h3><div class="text-muted small">Edit winner</div></div><a class="btn btn-outline-secondary" href="award_winners.php?award_id=<?php echo $awardId; ?>">Back to Past Winners</a></div>
<div class="card-soft p-4"><form method="post" class="row g-3"><div class="col-md-3"><label class="form-label">Year</label><input class="form-control" type="number" name="award_year" value="<?php echo (int)$winner['award_year']; ?>" required></div><div class="col-md-9"><label class="form-label">Winner</label><input class="form-control" name="winner_name" value="<?php echo h($winner['winner_name']); ?>" required></div><div class="col-12"><label class="me-3"><input type="checkbox" name="is_published" <?php echo !empty($winner['is_published'])?'checked':''; ?>> Published</label><label><input type="checkbox" name="is_archived" <?php echo !empty($winner['is_archived'])?'checked':''; ?>> Archived</label></div><div><button class="btn btn-success">Save winner</button> <a class="btn btn-outline-secondary" href="award_winners.php?award_id=<?php echo $awardId; ?>">Cancel</a></div></form></div>
<?php admin_layout_end();
