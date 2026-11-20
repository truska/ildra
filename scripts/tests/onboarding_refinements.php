<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();require __DIR__.'/account_migration.php'; // All application tables are already shadowed.
foreach(['site_settings','migration_enrolments','users','horses','people','share_requests','user_person_links','user_horse_links'] as $table)migrationCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Unsafe fixture: '.$table);
function refinementOptions(PDO $pdo,bool $horses,bool $shares,bool $blank):void{
    $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach(['onboarding_horses_enabled'=>$horses,'onboarding_shares_enabled'=>$shares,'onboarding_show_blank_pages'=>$blank] as $key=>$value)$s->execute([$key,$value?'1':'0']);
}
function refinementState(PDO $pdo,string $step='person',string $horseStatus='pending',string $shareStatus='deferred'):void{
    $pdo->prepare('UPDATE migration_enrolments SET current_step=?,horses_status=?,shares_status=?,completed_at=NULL,started_at=NOW() WHERE user_id=2')->execute([$step,$horseStatus,$shareStatus]);
}
foreach(['share_requests','user_person_links','user_horse_links'] as $table)$pdo->exec('DELETE FROM '.$table);
$pdo->exec('DELETE FROM horses WHERE id<>1');
$_SESSION['user']=['id'=>2,'role'=>'developer'];$hashBefore=$pdo->query('SELECT password_hash FROM users WHERE id=2')->fetchColumn();authBindCredentialSession(2,$hashBefore);
refinementState($pdo,'password');refinementOptions($pdo,true,true,false);
$account=['first_name'=>'Refined','last_name'=>'Account','email'=>'david@truska.com','new_password'=>'combined fixture password','confirm_password'=>'combined fixture password'];
$magic=createMagicLoginToken($pdo,2);$reset=createPasswordResetToken($pdo,2);
$invalid=$account;$invalid['first_name']='';migrationCheck(isset(migrationSaveAccount($pdo,2,$invalid)['first_name']),'Combined form accepted missing identity');
$invalid=$account;$invalid['email']='changed@example.invalid';migrationCheck(isset(migrationSaveAccount($pdo,2,$invalid)['email']),'Combined form changed login email');
migrationCheck($pdo->query('SELECT password_hash FROM users WHERE id=2')->fetchColumn()===$hashBefore && migrationState($pdo,2)['current_step']==='password','Invalid identity partially saved password/progress');
migrationCheck(migrationSaveAccount($pdo,2,$account)===[],'Combined account save failed');
$state=migrationState($pdo,2);$userNow=$pdo->query('SELECT * FROM users WHERE id=2')->fetch();
migrationCheck($state['current_step']==='person' && $state['password_set_at'] && $state['user_confirmed_at'],'Combined form did not advance directly to People');
migrationCheck($userNow['first_name']==='Refined' && password_verify($account['new_password'],$userNow['password_hash']),'Combined identity/password not saved');
migrationCheck(authValidateSession($pdo,$a),'Combined save did not rebind own session');
foreach(['auth_magic_tokens','auth_password_resets'] as $table)migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE user_id=2')->fetchColumn()===0,'Combined save retained old tokens');
migrationCheck(array_keys(migrationVisibleSteps($pdo,2))===['password','person','finish'],'Empty stages not omitted by default');
migrationCheck(migrationSaveDetails($pdo,2,'person',$person)===[],'Person save failed with empty stages');
$state=migrationState($pdo,2);migrationCheck($state['current_step']==='finish' && $state['horses_status']==='skipped' && $state['shares_status']==='skipped','Empty stages did not skip automatically');
migrationBack($pdo,2);migrationCheck(migrationState($pdo,2)['current_step']==='person','Back landed on hidden page');
migrationBack($pdo,2);migrationCheck(migrationState($pdo,2)['current_step']==='password','People Back did not reach combined page');
// Both enabled but empty: the one shared switch exposes both pages.
refinementOptions($pdo,true,true,true);refinementState($pdo,'horses');
migrationCheck(array_keys(migrationVisibleSteps($pdo,2))===['password','person','horses','shares','finish'],'Shared blank switch did not show both empty pages');
migrationCheck(migrationSaveHorses($pdo,2,[])===[] && migrationState($pdo,2)['current_step']==='shares','Empty Horses Next failed');
migrationSaveShares($pdo,2);migrationCheck(migrationState($pdo,2)['current_step']==='finish','Empty Shares Next failed');
// The switches override non-empty data; enabling later restores required review.
$pdo->exec("INSERT INTO horses(id,owner_user_id,name) VALUES (920,2,'Refinement horse')");
refinementOptions($pdo,false,false,true);refinementState($pdo,'horses');
$state=migrationRefreshStages($pdo,2);migrationCheck($state['current_step']==='finish','Disabled pages not skipped with records');
refinementOptions($pdo,true,false,false);$state=migrationRefreshStages($pdo,2);migrationCheck($state['current_step']==='horses','Re-enabled Horses did not require review');
// Real shared records make Shares visible even when empty pages are hidden.
$pdo->prepare("INSERT INTO users(id,email,password_hash,role_id,first_name,last_name) VALUES (940,'active-share@example.invalid',?,?,'Shared','User')")->execute([password_hash('fixture share password',PASSWORD_DEFAULT),(int)$pdo->query("SELECT id FROM roles WHERE name='user' LIMIT 1")->fetchColumn()]);
$pdo->exec("INSERT INTO user_horse_links(user_id,horse_id,status) VALUES (940,920,'approved')");
refinementOptions($pdo,false,true,false);refinementState($pdo,'shares');migrationCheck(isset(migrationVisibleSteps($pdo,2)['shares']),'Existing outgoing share not detected');
$state=migrationRefreshStages($pdo,2);migrationCheck($state['current_step']==='shares','Shares visibility wrongly depends on Horses switch');
migrationSaveShares($pdo,2);migrationCheck(migrationState($pdo,2)['current_step']==='finish','Shares Next blocked by disabled Horses');
// Finished accounts remain terminal when admin changes switches.
refinementOptions($pdo,false,false,false);refinementState($pdo,'finish','skipped','skipped');migrationFinish($pdo,2);
refinementOptions($pdo,true,true,true);$state=migrationRefreshStages($pdo,2);migrationCheck(!empty($state['completed_at']) && $state['current_step']==='complete','Settings reopened completed account');
$sharesBefore=$pdo->query('SELECT * FROM user_horse_links')->fetchAll();
migrationCheck(count($sharesBefore)===1 && $sharesBefore[0]['status']==='approved','Routing changed sharing permissions');
if (!empty($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')) {
    refinementOptions($pdo,true,true,false);
    $source=file_get_contents(dirname(__DIR__,2).'/admin/onboarding.php');
    $source=str_replace("__DIR__.'/onboarding_reset_panel.php'",var_export(dirname(__DIR__,2).'/admin/onboarding_reset_panel.php',true),$source);
    $a=strpos($source,'$definitions=');$b=strpos($source,"admin_layout_start('Onboarding'",$a);
    $templateStart=strpos($source,'?>',$b)+2;$templateEnd=strpos($source,'<?php render_tinymce_bootstrap();',$templateStart);
    foreach(['settings','password'] as $tabFixture){
        $_GET=['tab'=>$tabFixture];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
        eval(substr($source,$a,$b-$a));
        ob_start();eval('?>'.substr($source,$templateStart,$templateEnd-$templateStart));$html=ob_get_clean();
        file_put_contents($argv[1].'/admin-'.$tabFixture.'.html',$html);chmod($argv[1].'/admin-'.$tabFixture.'.html',0600);
    }
}
ob_end_clean();echo "PASS: combined identity/password save, email/validation/session guards, shared blank switch, separate enable switches, conditional Back/Next, settings changes and completed-account preservation. Temporary fixtures only.\n";
