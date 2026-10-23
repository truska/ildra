<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../legacy_balances.php';
$role=strtolower((string)($currentUser['role']??''));
if(!in_array($role,['developer','superadmin','admin'],true)){http_response_code(403);exit('You do not have permission to view legacy opening balances.');}
$canAllocate=adminActionAllowed($pdo,'finance.adjust_balance',$role);
legacyBalanceSchema($pdo);ensure_finance_tables($pdo,$alerts);
$sourcePath=dirname(__DIR__,2).'/private/source-data/ildra-credits.csv';
$preview=null;$previewError='';
try{if(is_file($sourcePath))$preview=legacyBalanceParse($sourcePath);else $previewError='No source CSV is available at ../private/source-data/ildra-credits.csv.';}
catch(Throwable $e){$previewError=$e->getMessage();}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!$canAllocate)throw new RuntimeException('You do not have permission to import or allocate opening balances.');
        $action=(string)($_POST['action']??'');
        if($action==='import'){
            if(!$preview||!hash_equals($preview['sha256'],(string)($_POST['reviewed_sha']??'')))throw new RuntimeException('The source file changed. Review the current preview before importing.');
            if(empty($_POST['reviewed']))throw new RuntimeException('Confirm that you reviewed the file and have a database backup.');
            $result=legacyBalanceImport($pdo,$preview,$currentUser);
            $_SESSION['flash_success']=$result['added'].' opening balances imported as pending; '.$result['unchanged'].' unchanged. No account balances have been changed by this import.';
        }elseif($action==='allocate'){
            if(empty($_POST['confirmed']))throw new RuntimeException('Confirm the chosen account before allocating.');
            $row=legacyBalanceAllocate($pdo,(int)($_POST['legacy_id']??0),(int)($_POST['user_id']??0),$currentUser);
            $_SESSION['flash_success']='Opening balance allocated as finance transaction T-'.(int)$row['finance_transaction_id'].'.';
        }
    }catch(Throwable $e){$_SESSION['flash_alerts']=[['type'=>'danger','message'=>$e instanceof PDOException?'The opening balance operation could not be completed. No partial allocation has been retained.':$e->getMessage()]];}
    header('Location: legacy_balances.php');exit;
}
$users=$pdo->query("SELECT id,email,first_name,last_name FROM users WHERE is_archived=0 ORDER BY email,id")->fetchAll()?:[];
$rows=$pdo->query("SELECT lb.*,u.email allocated_email,u.first_name,u.last_name,a.email actor_email FROM legacy_balances lb LEFT JOIN users u ON u.id=lb.allocated_user_id LEFT JOIN users a ON a.id=lb.allocated_by ORDER BY lb.allocated_user_id IS NULL DESC,lb.id")->fetchAll()?:[];
$pendingCount=0;$pendingCredits=0;$pendingDebits=0;
foreach($rows as$row)if(!$row['allocated_user_id']){$pendingCount++;$amount=legacyBalancePence($row['opening_amount']);if($amount>0)$pendingCredits+=$amount;else $pendingDebits-=$amount;}
$money=static fn(string $amount):string=>(legacyBalancePence($amount)<0?'-':'').format_price(abs(legacyBalancePence($amount))/100);
admin_layout_start('Legacy Opening Balances','legacy_balances');
?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h5 class="mb-1">Legacy Opening Balances</h5><div class="small text-muted">Credits and debits from the previous system. Automatic allocation requires a matching, confirmed account email. Admins can allocate a balance to a different account after checking ownership.</div></div><a class="btn btn-sm btn-outline-secondary" href="finance.php">Finance</a></div>
<div class="card-soft p-3 mb-3"><strong><?php echo $pendingCount;?> pending</strong> · Credits <?php echo format_price($pendingCredits/100);?> · Debits <?php echo format_price($pendingDebits/100);?> · <?php echo count($rows)-$pendingCount;?> allocated</div>
<div class="card-soft p-3 mb-3"><h6>Balance register</h6><div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>Source ID</th><th>Name / original email</th><th>Opening balance</th><th>Status</th><th>Allocated to / by</th><th>Transaction</th><th>Manual allocation</th></tr></thead><tbody>
<?php foreach($rows as$row):$amount=legacyBalancePence($row['opening_amount']);?>
<tr><td><?php echo h($row['source_id']);?></td><td><?php echo h($row['source_name']?:'—');?><div class="small text-muted"><?php echo h($row['email']);?></div></td><td class="text-nowrap"><?php echo $money($row['opening_amount']);?><div class="small text-muted"><?php echo $amount>0?'Credit':'Debit owed';?></div></td><td><?php echo $row['allocated_user_id']?'Allocated':'Pending';?></td><td><?php if($row['allocated_user_id']):?><?php echo h(trim(($row['first_name']??'').' '.($row['last_name']??'')));?><div><?php echo h($row['allocated_email']??('Account #'.$row['allocated_user_id']));?></div><div class="small text-muted"><?php echo h($row['actor_email']?:'Verified email match');?> · <?php echo h(format_display_datetime($row['allocated_at'],''));?></div><?php else:?>—<?php endif;?></td><td><?php echo $row['finance_transaction_id']?'T-'.(int)$row['finance_transaction_id']:'—';?></td><td>
<?php if($canAllocate&&!$row['allocated_user_id']):?><form method="post"><?php echo csrf_input();?><input type="hidden" name="action" value="allocate"><input type="hidden" name="legacy_id" value="<?php echo (int)$row['id'];?>"><label class="visually-hidden" for="allocate-<?php echo (int)$row['id'];?>">Account to receive this opening balance</label><select class="form-select form-select-sm mb-1" id="allocate-<?php echo (int)$row['id'];?>" name="user_id" required><option value="">Select an account</option><?php foreach($users as$user):?><option value="<?php echo (int)$user['id'];?>"><?php echo h($user['email'].' — '.trim(($user['first_name']??'').' '.($user['last_name']??'')));?></option><?php endforeach;?></select><label class="small d-block mb-1"><input type="checkbox" name="confirmed" value="1" required> I checked ownership and the selected account</label><button class="btn btn-sm btn-outline-success">Allocate <?php echo $amount>0?'credit':'debit';?></button></form><?php else:?>—<?php endif;?></td></tr>
<?php endforeach;?><?php if(!$rows):?><tr><td colspan="7" class="text-muted">No balances have been imported. Review the source file below.</td></tr><?php endif;?></tbody></table></div></div>
<div class="card-soft p-3"><h6>Source file preview</h6><div class="small text-muted mb-3">../private/source-data/ildra-credits.csv · Original negative balances become positive credit; original positive balances become debit. Importing creates pending records and does not allocate them.</div>
<?php if($previewError!==''):?><div class="alert alert-warning"><?php echo h($previewError);?></div><?php elseif($preview):?>
<p><?php echo count($preview['rows']);?> rows · Credit <?php echo format_price($preview['credits']/100);?> · Debit <?php echo format_price($preview['debits']/100);?> · Net credit <?php echo format_price($preview['net']/100);?></p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Source ID</th><th>Name</th><th>Email</th><th>Original balance</th><th>New opening balance</th></tr></thead><tbody><?php foreach($preview['rows']as$row):?><tr><td><?php echo h($row['source_id']);?></td><td><?php echo h($row['source_name']);?></td><td><?php echo h($row['email']);?></td><td><?php echo h($row['source_balance']);?></td><td><?php echo $money($row['opening_amount']);?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($canAllocate):?><form method="post"><?php echo csrf_input();?><input type="hidden" name="action" value="import"><input type="hidden" name="reviewed_sha" value="<?php echo h($preview['sha256']);?>"><label class="d-block small mb-2"><input type="checkbox" name="reviewed" value="1" required> I have reviewed this preview and have a database backup</label><button class="btn btn-success">Import reviewed opening balances</button></form><?php endif;?>
<?php endif;?></div>
<?php admin_layout_end(); ?>
