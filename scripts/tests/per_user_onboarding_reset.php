<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();require __DIR__.'/account_migration.php';
$actor=['id'=>2,'role'=>'developer'];$_SESSION['user']=$actor;$_SERVER['HTTP_HOST']='dev-ildra.witecanvas.com:28086';unset($_SESSION['auth_fresh_login_user_id']);
// Managing another rider must not hide the account holder's reset control.
$pdo->exec("INSERT INTO people(id,owner_user_id,email,first_name,last_name) VALUES (925,2,'additional-rider@example.invalid','Extra','Rider')");
migrationCheck((int)migrationPilotPerson($pdo,migrationRecipient($pdo,2))['id']===123,'Additional rider blocked unique account-holder match');
$pdo->exec("UPDATE people SET email='DAVID@TRUSKA.COM' WHERE id=925");
migrationReject(fn()=>migrationResetOnboarding($pdo,$actor,2,123),'Ambiguous matching riders allowed a reset');
migrationCheck(migrationState($pdo,2)['current_step']==='complete','Rejected ambiguous reset changed progress');
$pdo->exec("UPDATE people SET email='additional-rider@example.invalid' WHERE id=925");
$preserved=[];foreach(['users','people','horses','share_requests','user_person_links','user_horse_links','finance_transactions','user_credits','membership_purchases','horse_logbook_purchases','booking_items','bookings','baskets'] as $table)$preserved[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
$oldState=migrationState($pdo,2);migrationCheck(!empty($oldState['completed_at']),'Fixture account not complete');
$otherState=migrationState($pdo,3);
$tokenBefore=$pdo->query('SELECT token_hash FROM migration_invitations WHERE user_id=2 LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO migration_invitations(user_id,token_hash,email,credential_stamp,expires_at,campaign_revision,delivery_status) VALUES (2,?,'david@truska.com',?,?,1,'uncertain')")->execute([hash('sha256','synthetic-uncertain-reset'),hash('sha256',$new['password_hash']),auth_future_string(3600)]);
migrationReject(fn()=>migrationResetOnboarding($pdo,['id'=>2,'role'=>'admin'],2,123),'Admin allowed to use development reset');
migrationReject(fn()=>migrationResetOnboarding($pdo,$actor,2,999),'Changed Person mapping accepted');
migrationResetOnboarding($pdo,$actor,2,123);
$state=migrationState($pdo,2);migrationCheck($state['current_step']==='restart' && empty($state['started_at']) && empty($state['completed_at']) && empty($state['password_set_at']) && empty($state['user_confirmed_at']) && empty($state['person_confirmed_at']),'Setup progress not reset');
migrationCheck(!migrationPending($state),'Existing session was immediately forced out of admin');
foreach($preserved as $table=>$rows)migrationCheck($pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll()===$rows,'Operational record changed: '.$table);
migrationCheck(migrationState($pdo,3)===$otherState,'Other user progress changed');
migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM migration_invitations WHERE user_id=2 AND revoked_at IS NULL")->fetchColumn()===0,'Old onboarding link remains live');
migrationCheck((int)migrationDeliveryCounts($pdo,2)['uncertain']===0,'Old uncertain delivery prevents fresh reviewed invitation');
migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM record_notes WHERE action='migration_onboarding_reset' AND record_id=2")->fetchColumn()===1,'Reset audit missing');
$rows=array_column(migrationRecipients($pdo),null,'id');migrationCheck($rows[2]['status']==='Restart on next login' && $rows[2]['reason']==='','Reset account not eligible for selection');
// Existing invitation controls still issue a fresh usable link, using simulated transport.
$freshToken='';$transport=static function(array $user,array $payload,int $iid,string $redirect)use(&$freshToken):bool{preg_match('/invite=([a-f0-9]{64})/',$payload['html'],$matches);$freshToken=$matches[1]??'';return true;};
migrationCheck(migrationSendRecipient($pdo,1,2,$transport,true),'Fresh invitation after reset failed');
migrationCheck($freshToken!=='' && migrationInspectInvitation($pdo,$freshToken)!==null,'New invitation invalid');
migrationCheck(!migrationPending(migrationState($pdo,2)),'Sending unexpectedly forced current session into setup');
// Authentication binding emits a one-use signal for all login methods.
authBindCredentialSession(2,$new['password_hash']);migrationCheck($_SESSION['auth_fresh_login_user_id']===2,'Fresh login signal missing');
migrationActivateScheduledRestart($pdo,2);$state=migrationState($pdo,2);migrationCheck($state['current_step']==='password' && migrationPending($state),'Next login did not start at combined account page');
$after=$state;migrationActivateScheduledRestart($pdo,2);migrationCheck(migrationState($pdo,2)===$after,'Login repeatedly reset active progress');
// A fresh invitation can also start a reset without a prior normal sign-in.
migrationResetOnboarding($pdo,$actor,2,123);migrationSendRecipient($pdo,1,2,$transport,true);migrationAcceptInvitation($pdo,$freshToken,2);
migrationCheck(migrationState($pdo,2)['current_step']==='password' && migrationPending(migrationState($pdo,2)),'Fresh invitation did not start reset journey');
$bootstrap=file_get_contents(dirname(__DIR__,2).'/bootstrap.php');migrationCheck(str_contains($bootstrap,"migrationActivateScheduledRestart(\$pdo,(int)\$currentUser['id'])"),'Bootstrap activation hook missing');
if(!empty($argv[1])&&is_dir($argv[1])&&str_starts_with(realpath($argv[1]),'/tmp/')){
    $source=file_get_contents(dirname(__DIR__,2).'/admin/migration_campaign.php');
    $canResetOnboarding=true;$currentUser=$actor;$resetSelectedUid=2;
    $row=['id'=>2,'email'=>'fixture@example.invalid','person_id'=>123,'name'=>'Fixture User','reason'=>'','status'=>'Complete','sent'=>1];
    $a=strpos($source,'<tr><td><input class="form-check-input" type="checkbox" name="user_ids[]"');$b=strpos($source,'<?php endforeach;', $a);
    ob_start();eval('?>'.substr($source,$a,$b-$a));$rowHtml=ob_get_clean();
    $a=strpos($source,'<form method="post" id="reset-onboarding-');$b=strpos($source,'</form>',$a)+7;$resetRow=$row;
    ob_start();eval('?>'.substr($source,$a,$b-$a));$form=ob_get_clean();
    file_put_contents($argv[1].'/reset-user.html','<form id="select-recipients"><input name="subject" required value=""><table><tbody>'.$rowHtml.'</tbody></table></form>'.$form);
}
ob_end_clean();echo "PASS: per-user progress reset, next-login activation, fresh invitation/resend, retained identity/records/finance/baskets, other-user isolation, protected admin continuity and audit. Temporary fixtures only; no real reset or email.\n";
