<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();
// Reuse the verified all-table temporary fixture; it never mutates installed records.
require __DIR__.'/account_migration.php';
function batchActor(PDO $pdo):void{
    $u=migrationPilotUser($pdo);$_SESSION['user']=['id'=>2,'role'=>'developer'];authBindCredentialSession(2,$u['password_hash']);
}
batchActor($pdo);
$pdo->exec("INSERT INTO site_settings (setting_key,setting_value) VALUES ('email_test_redirect_enabled','1'),('email_test_redirect_to','inbox@example.invalid') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
$userRole=(int)$pdo->query("SELECT id FROM roles WHERE name='user'")->fetchColumn();
foreach([4,5,6,8] as $uid){
    $pdo->prepare('INSERT INTO users (id,email,password_hash,role_id,first_name,last_name) VALUES (?,?,?,?,?,?)')->execute([$uid,'fixture'.$uid.'@example.invalid',password_hash('inaccessible fixture secret',PASSWORD_DEFAULT),$userRole,'Batch','Fixture '.$uid]);
    $pdo->prepare('INSERT INTO people (id,owner_user_id,email,first_name,last_name) VALUES (?,?,?,?,?)')->execute([200+$uid,$uid,($uid===8?'different':'fixture'.$uid).'@example.invalid','Batch','Fixture '.$uid]);
}
$adminRole=(int)$pdo->query("SELECT id FROM roles WHERE name='admin'")->fetchColumn();
$pdo->prepare("INSERT INTO users (id,email,password_hash,role_id) VALUES (7,'excluded-admin@example.invalid',?,?)")->execute([password_hash('fixture',PASSWORD_DEFAULT),$adminRole]);
$preview=migrationPreviewRecipients($pdo,'not_sent',[],false);
migrationCheck(array_column($preview,'id')===[4,5,6],'Unsent selector did not exclude completed, archived, admin and mismatched accounts');
migrationReject(fn()=>migrationPreviewRecipients($pdo,'selected',[4,999],false),'Unknown selected account ignored');
migrationReject(fn()=>migrationPreviewRecipients($pdo,'selected',[8],false),'Bad person match selected');
$batchTokens=[];$deliveries=[];
$delivery=static function(array $u,array $payload,int $iid,string $redirect)use(&$batchTokens,&$deliveries):bool{
    migrationCheck($redirect==='inbox@example.invalid','Unexpected redirect');
    preg_match('/invite=([a-f0-9]{64})/',$payload['text'],$m);migrationCheck(isset($m[1]),'Missing forwarded link');
    $batchTokens[(int)$u['id']]=$m[1];$deliveries[]=$u['email'];return true;
};
$one=$preview[0];
migrationCheck(migrationSendRecipient($pdo,1,4,$delivery,false,$one['snapshot'],'inbox@example.invalid'),'Selected send failed');
migrationCheck(count($deliveries)===1,'Selected send reached other recipients');
migrationCheck(array_column(migrationPreviewRecipients($pdo,'not_sent',[],false),'id')===[5,6],'Already sent account remained in unsent list');
migrationReject(fn()=>migrationSendRecipient($pdo,1,4,$delivery,false),'Duplicate send not blocked');
migrationReject(fn()=>migrationPreviewRecipients($pdo,'selected',[4],false),'Resend confirmation not required');
$oldToken=$batchTokens[4];$resend=migrationPreviewRecipients($pdo,'selected',[4],true)[0];
migrationCheck($resend['operation']==='Resend','Resend label incorrect');
migrationSendRecipient($pdo,1,4,$delivery,true,$resend['snapshot'],'inbox@example.invalid');migrationCheck(migrationInspectInvitation($pdo,$oldToken)===null,'Resend did not revoke older link');
$five=migrationPreviewRecipients($pdo,'selected',[5],false)[0];
$pdo->exec("UPDATE users SET first_name='Changed' WHERE id=5");migrationReject(fn()=>migrationSendRecipient($pdo,1,5,$delivery,false,$five['snapshot'],'inbox@example.invalid'),'Stale recipient sent');
$pdo->exec("UPDATE users SET first_name='Batch' WHERE id=5");
$pdo->exec("UPDATE site_settings SET setting_value='0' WHERE setting_key='email_test_redirect_enabled'");migrationReject(fn()=>migrationSendRecipient($pdo,1,5,$delivery,false),'Redirect-off send accepted');
$pdo->exec("UPDATE site_settings SET setting_value='1' WHERE setting_key='email_test_redirect_enabled'");
migrationReject(fn()=>migrationSendRecipient($pdo,1,5,$delivery,false,null,'another@example.invalid'),'Changed redirect accepted');
migrationCheck(!migrationSendRecipient($pdo,1,5,static fn()=>false),'Failed delivery reported success');
// Interrupted delivery remains excluded from automatic retry until reviewed.
$pdo->prepare('INSERT INTO migration_invitations (user_id,token_hash,email,credential_stamp,expires_at,campaign_revision,delivery_status) VALUES (6,?,?,?,?,1,?)')->execute([hash('sha256',random_bytes(32)),'fixture6@example.invalid',hash('sha256','fixture'),auth_future_string(3600),'uncertain']);
migrationCheck(array_column(migrationPreviewRecipients($pdo,'not_sent',[],false),'id')===[5],'Uncertain delivery automatically retried');
$pdo->exec("DELETE FROM migration_invitations WHERE user_id=6 AND delivery_status='uncertain'");

$retry=migrationPreviewRecipients($pdo,'not_sent',[],false);migrationCheck($retry[0]['operation']==='Retry','Failed delivery was not retryable');
foreach($retry as $r)migrationSendRecipient($pdo,1,$r['id'],$delivery,false,$r['snapshot'],'inbox@example.invalid');
migrationReject(fn()=>migrationPreviewRecipients($pdo,'not_sent',[],false),'All-not-sent selector repeated invitations');
// Opening never authenticates; confirmation POST authenticates only the invited ordinary user.
$_SESSION=[];
migrationCheck(migrationInspectInvitation($pdo,$batchTokens[4])!==null && empty($_SESSION['user']),'Opening link signed user in');
migrationAcceptInvitation($pdo,$batchTokens[4],4);
migrationCheck((int)$_SESSION['user']['id']===4 && $_SESSION['user']['role']==='user','Invitation signed in wrong account');
migrationCheck(migrationPending(migrationState($pdo,4)),'Invitation session is not gated');
migrationReject(fn()=>migrationAcceptInvitation($pdo,$batchTokens[6],6),'Another account session was silently replaced');
migrationCheck(migrationSavePassword($pdo,4,['new_password'=>'first chosen fixture password','confirm_password'=>'first chosen fixture password'])===[],'Ordinary imported user could not set initial password');
// Existing redirect account remains separate; authenticated recipient can resume, not restart.
migrationReject(fn()=>migrationAcceptInvitation($pdo,$batchTokens[4],4),'Consumed invitation replayed');
// Ordinary recipients can decline directly using their personal invitation, without an old password.
$_SESSION=[];migrationDecline($pdo,6,$batchTokens[6],true,1);
migrationCheck(migrationState($pdo,6)['current_step']==='declined' && empty($_SESSION['user']),'Anonymous invitation decline failed');
batchActor($pdo);
migrationCheck(migrationPilotUser($pdo)['is_protected']==1,'Protected account changed');
echo "PASS: selected/all-unsent recipients, eligibility, failed retries, explicit resends, snapshots, redirect lock, per-account tokens, no-login invitation setup, wrong-account refusal, initial password and invitation closure. Simulated transport and temporary records only.\n";

ob_end_flush();
