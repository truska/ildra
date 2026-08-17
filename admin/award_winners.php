<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
if (!in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin','admin'], true)) { header('Location:index.php'); exit; }
ensureAwardsTables($pdo);
$awardId = (int)($_GET['award_id'] ?? 0); $award = null;
foreach (fetchAwards($pdo) as $row) if ((int)$row['id'] === $awardId) $award = $row;
if (!$award) { $_SESSION['flash_alerts']=[['type'=>'danger','message'=>'Award not found.']]; header('Location:awards.php'); exit; }
$winners = fetchAwardWinners($pdo, $awardId);
$editWinnerId = (int)($_GET['edit_winner'] ?? 0); $editWinner = null;
foreach ($winners as $winner) if ((int)$winner['id'] === $editWinnerId) $editWinner = $winner;
if ($editWinnerId > 0) { header('Location: award_winner_edit.php?id=' . $editWinnerId . '&award_id=' . $awardId); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $winnerId=(int)($_POST['winner_id']??0);$year=(int)($_POST['award_year']??0);$name=trim((string)($_POST['winner_name']??''));
    if($winnerId>0&&$year>=1900&&$name!==''){$pdo->prepare('UPDATE award_winners SET award_year=:year,winner_name=:name WHERE id=:id AND award_id=:award')->execute([':year'=>$year,':name'=>$name,':id'=>$winnerId,':award'=>$awardId]);$_SESSION['flash_success']='Winner updated.';header('Location:award_winners.php?award_id='.$awardId);exit;}
    $alerts[]=['type'=>'danger','message'=>'Enter a valid year and winner name.'];
}
admin_layout_start('Past Winners', 'awards');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Winner history</div><h5 class="mb-0"><?php echo h($award['name']); ?></h5></div><a class="btn btn-outline-secondary" href="awards.php">Back to Awards</a></div>
<?php if($editWinner): ?><div class="card-soft p-3 mb-3"><form method="post" class="row g-2 align-items-end"><input type="hidden" name="winner_id" value="<?php echo (int)$editWinner['id']; ?>"><div class="col-md-3"><label class="form-label">Year</label><input class="form-control" type="number" name="award_year" value="<?php echo (int)$editWinner['award_year']; ?>"></div><div class="col-md-6"><label class="form-label">Winner</label><input class="form-control" name="winner_name" value="<?php echo h($editWinner['winner_name']); ?>"></div><div class="col-md-3"><button class="btn btn-success">Save winner</button> <a class="btn btn-outline-secondary" href="award_winners.php?award_id=<?php echo $awardId; ?>">Cancel</a></div></form></div><?php endif; ?>
<div class="card-soft p-3"><div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>Year</th><th>Winner</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody><?php foreach($winners as $winner): ?><tr><td><?php echo (int)$winner['award_year']; ?></td><td class="fw-semibold"><?php echo h($winner['winner_name']); ?></td><td><?php echo $winner['is_archived']?'Archived':($winner['is_published']?'Published':'Hidden'); ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="awards.php?winners_for=<?php echo $awardId; ?>&edit_winner=<?php echo (int)$winner['id']; ?>">Edit</a></td></tr><?php endforeach; ?><?php if(!$winners): ?><tr><td colspan="4" class="text-muted">No winners recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php admin_layout_end();
