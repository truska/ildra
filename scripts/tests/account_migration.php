<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/auth.php';
require dirname(__DIR__,2).'/email_campaigns.php';
require dirname(__DIR__,2).'/email.php';
require dirname(__DIR__,2).'/account_migration.php';
require_once dirname(__DIR__,2).'/import_workspace.php';
ini_set('session.save_path','/tmp');session_start();date_default_timezone_set('Europe/London');
function migrationCheck(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
function migrationReject(callable $fn,string $why): void { try{$fn();}catch(RuntimeException $e){return;}throw new LogicException($why); }
$a=[];$pdo=createPdo(require dirname(__DIR__,3).'/private/config.php',$a);if(!$pdo)throw new RuntimeException('Database unavailable');
// Every installed table is shadowed before invoking application helpers or mutations.
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table){
    $q=str_replace('`','``',$table);
    $pdo->exec("CREATE TEMPORARY TABLE migration_test_seed LIKE `$q`");
    $pdo->exec("INSERT INTO migration_test_seed SELECT * FROM `$q`");
    $pdo->exec("CREATE TEMPORARY TABLE `$q` LIKE migration_test_seed");
    $pdo->exec("INSERT INTO `$q` SELECT * FROM migration_test_seed");
    $pdo->exec('DROP TEMPORARY TABLE migration_test_seed');
    migrationCheck(str_contains($pdo->query("SHOW CREATE TABLE `$q`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Unshadowed table');
}
$sql=file_get_contents(dirname(__DIR__,2).'/migrations/2026-11-17-account-migration-pilot.sql');
foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement));
$notesSql=file_get_contents(dirname(__DIR__,2).'/migrations/2026-11-17-internal-record-notes.sql');
foreach(explode(';',$notesSql) as $statement)if(trim($statement)!=='')$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement));
foreach(['record_notes','migration_page_copy'] as $t){
    migrationCheck(str_contains($pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Notes table is not temporary');
    $pdo->exec("DELETE FROM `$t`");
}
$pdo->exec("INSERT INTO migration_page_copy (id,introduction,closure_explanation) VALUES (1,'Fixture welcome','Fixture closure explanation')");
$horseReviewSql=file_get_contents(dirname(__DIR__,2).'/migrations/2026-11-17-migration-horse-reviews.sql');
foreach(explode(';',$horseReviewSql) as $statement)if(trim($statement)!=='')$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement));
$horseChoiceSql=file_get_contents(dirname(__DIR__,2).'/migrations/2026-11-17-migration-horse-choices.sql');
foreach(explode(';',$horseChoiceSql) as $statement)if(trim($statement)!=='')$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement));
foreach(['migration_campaigns','migration_enrolments','migration_invitations','migration_horse_reviews','migration_horse_choices'] as $t){
    migrationCheck(str_contains($pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Migration table is not temporary');
    $pdo->exec("DELETE FROM `$t`");
}
// Preserve the legacy explicit-empty-page scenarios in this fixture suite.
$pdo->exec("INSERT INTO site_settings (setting_key,setting_value) VALUES ('onboarding_show_blank_pages','1') ON DUPLICATE KEY UPDATE setting_value='1'");
$pdo->exec('DELETE FROM users');$pdo->exec('DELETE FROM people');$pdo->exec('DELETE FROM horses WHERE id<>1');$pdo->exec('DELETE FROM auth_throttle');
$role=(int)$pdo->query("SELECT id FROM roles WHERE name='developer'")->fetchColumn();
$hash=password_hash('fixture original password',PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (id,email,password_hash,role_id,first_name,last_name,is_protected,auth_app_secret,auth_app_enabled) VALUES (2,'david@truska.com',?,?,'Fixture','Pilot',1,'UNCHANGED-SECRET',1)")->execute([$hash,$role]);
$pdo->exec("INSERT INTO people (id,owner_user_id,email,first_name,last_name) VALUES (123,2,'david@truska.com','Fixture','Pilot')");
$pdo->exec("INSERT INTO migration_campaigns (id,subject,body_html) VALUES (1,'Fixture migration','<p>Hello {{first_name}}. Test explanation.</p>')");
$_SERVER['HTTP_HOST']='dev-ildra.witecanvas.com';$_SERVER['REMOTE_ADDR']='192.0.2.245';
$_SESSION['user']=['id'=>2,'role'=>'developer'];authBindCredentialSession(2,$hash);
$original=migrationPilotUser($pdo);$token='';$sends=0;
$transport=static function(array $u,array $payload,int $id)use(&$token,&$sends):bool{
    migrationCheck($u['email']==='david@truska.com','Unexpected recipient');
    migrationCheck(str_contains($payload['text'],'https://dev-ildra.witecanvas.com:28086/migrate.php?invite='),'Development port missing');
    migrationCheck((bool)preg_match('/invite=([a-f0-9]{64})/',$payload['text'],$m),'No invitation token');
    $token=$m[1];$sends++;return true;
};
migrationReject(fn()=>migrationSendPilot($pdo,99,$transport),'Stale campaign sent');
migrationCheck(!migrationSendPilot($pdo,1,static fn()=>false),'Failed transport reported sent');
migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM migration_invitations WHERE delivery_status='failed' AND revoked_at IS NOT NULL")->fetchColumn()===1,'Failed delivery left live token');
migrationCheck(migrationSendPilot($pdo,1,$transport),'Send failed');
migrationCheck(migrationPilotUser($pdo)===$original,'Send changed user');
migrationCheck(!migrationPending(migrationState($pdo,2)),'Sending started onboarding');
$before=$pdo->query('SELECT * FROM migration_invitations')->fetch();
migrationCheck(migrationInspectInvitation($pdo,$token)!==null,'Inspection failed');
migrationCheck($pdo->query('SELECT * FROM migration_invitations')->fetch()===$before,'Opening consumed token');
migrationCheck(!str_contains(json_encode($before),$token),'Raw token stored');
$old=$token;migrationSendPilot($pdo,1,$transport);
migrationCheck(migrationInspectInvitation($pdo,$old)===null,'Resend retained old token');
$pdo->exec("UPDATE migration_invitations SET expires_at='2000-01-01' WHERE revoked_at IS NULL");
migrationCheck(migrationInspectInvitation($pdo,$token)===null,'Expired token accepted');
migrationSendPilot($pdo,1,$transport);
$_SESSION['act_as_original_user']=['id'=>2];migrationReject(fn()=>migrationAcceptInvitation($pdo,$token,2),'Impersonation accepted');unset($_SESSION['act_as_original_user']);
migrationReject(fn()=>migrationAcceptInvitation($pdo,$token,3),'Wrong account accepted');
migrationReject(fn()=>migrationSavePassword($pdo,2,['new_password'=>'fixture replacement password','confirm_password'=>'fixture replacement password']),'Password allowed before invitation acceptance');
migrationAcceptInvitation($pdo,$token,2);
migrationReject(fn()=>migrationAcceptInvitation($pdo,$token,2),'Token replay accepted');
migrationCheck(migrationPending(migrationState($pdo,2)),'Continue did not start flow');
// An unused resend and ordinary login/recovery links must die when the password changes.
migrationSendPilot($pdo,1,$transport);
createMagicLoginToken($pdo,2);createPasswordResetToken($pdo,2);
migrationReject(fn()=>migrationSaveDetails($pdo,2,'user',['first_name'=>'A','last_name'=>'B','email'=>'david@truska.com']),'Skipped password');
migrationCheck(isset(migrationSavePassword($pdo,2,['new_password'=>'short','confirm_password'=>'short'])['new_password']),'Short password accepted');
migrationCheck(isset(migrationSavePassword($pdo,2,['new_password'=>'fixture replacement password','confirm_password'=>'different password'])['confirm_password']),'Mismatched password accepted');
// A valid signed-in migration session sets the new password without knowledge of the old one.
migrationCheck(migrationSavePassword($pdo,2,['new_password'=>'fixture replacement password','confirm_password'=>'fixture replacement password'])===[],'Password save without old password failed');
migrationCheck(migrationInspectInvitation($pdo,$token)===null,'Credential change left invitation valid');
foreach(['auth_magic_tokens','auth_password_resets'] as $t)migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE user_id=2")->fetchColumn()===0,'Password change did not revoke '.$t);
$new=migrationPilotUser($pdo);migrationCheck(password_verify('fixture replacement password',$new['password_hash']),'Password not replaced');
foreach(['role_id','is_protected','auth_app_secret','auth_app_enabled','email'] as $f)migrationCheck($new[$f]===$original[$f],'Protected field changed: '.$f);
migrationCheck(migrationState($pdo,2)['current_step']==='user','Password progress lost');
migrationCheck(authValidateSession($pdo,$a),'Current session not rebound');
$_SESSION['auth_credential']['stamp']=hash('sha256','password-v1:'.$hash);
migrationCheck(!authValidateSession($pdo,$a),'Old credential session survived');
$_SESSION['user']=['id'=>2,'role'=>'developer'];authBindCredentialSession(2,$new['password_hash']);
$user=['first_name'=>'Fixture','last_name'=>'Pilot','email'=>'david@truska.com','ride_notice_opt_in'=>'1','general_email_opt_in'=>'0','renewal_reminder_opt_in'=>'0'];
migrationCheck(isset(migrationSaveDetails($pdo,2,'user',array_merge($user,['email'=>'changed@example.com']))['email']),'Login email changed');
migrationCheck(migrationSaveDetails($pdo,2,'user',$user)===[],'User save failed');
migrationCheck((int)$pdo->query('SELECT general_email_opt_in FROM users WHERE id=2')->fetchColumn()===0,'User step changed unasked preference');
migrationCheck(empty(migrationState($pdo,2)['user_preferences_json']),'User step invented preference evidence');
$incomplete=migrationSaveDetails($pdo,2,'person',$user);
foreach(['phone','address','postcode','junior_or_senior','emergency_contact_name','emergency_contact_phone'] as $f)migrationCheck(isset($incomplete[$f]),'Missing field not rejected: '.$f);
migrationCheck(migrationState($pdo,2)['current_step']==='person','Invalid details advanced progress');
$person=$user+['phone'=>'0123456789','address'=>'Fixture address','postcode'=>'AB1 2CD','junior_or_senior'=>'Junior','dob'=>'','emergency_contact_name'=>'Fixture Contact','emergency_contact_phone'=>'0123456790'];
migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$person)['dob']),'Junior missing DOB accepted');
$person['dob']='2026-02-30';migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$person)['dob']),'Impossible date accepted');
$person['dob']=(date('Y')-15).'-01-01';$person['junior_or_senior']='Senior';
$person['person_id']=999;$person['owner_user_id']=999;
$offPerson=$person;$offPerson['ride_notice_opt_in']=0;$offPerson['general_email_opt_in']=1;$offPerson['renewal_reminder_opt_in']=1;
migrationCheck(migrationSaveDetails($pdo,2,'person',$offPerson)===[],'Single preference off save failed');
foreach(['users'=>2,'people'=>123] as $table=>$id) migrationCheck((int)$pdo->query("SELECT general_email_opt_in+ride_notice_opt_in+renewal_reminder_opt_in FROM `$table` WHERE id=$id")->fetchColumn()===0,'Single preference did not disable all flags');
migrationBack($pdo,2);
migrationCheck(migrationSaveDetails($pdo,2,'person',$person)===[],'Person save failed');
foreach(['general_email_opt_in','ride_notice_opt_in','renewal_reminder_opt_in'] as $field) {
    migrationCheck((int)$pdo->query("SELECT `$field` FROM users WHERE id=2")->fetchColumn()===1,'Single preference not enabled on user');
    migrationCheck((int)$pdo->query("SELECT `$field` FROM people WHERE id=123")->fetchColumn()===1,'Single preference not enabled on person');
}
migrationCheck(migrationPreferences(['general_email_opt_in'=>1,'renewal_reminder_opt_in'=>1])===['general_email_opt_in'=>0,'ride_notice_opt_in'=>0,'renewal_reminder_opt_in'=>0],'Unchecked preference must clear all flags');
migrationCheck($pdo->query('SELECT junior_or_senior FROM people WHERE id=123')->fetchColumn()==='Junior','Age classification incorrect');
migrationCheck((int)$pdo->query('SELECT owner_user_id FROM people WHERE id=123')->fetchColumn()===2,'Ownership changed');
migrationBack($pdo,2);migrationCheck(migrationState($pdo,2)['current_step']==='person','Back failed');
migrationCheck(migrationSaveDetails($pdo,2,'person',$person)===[],'Person review after Back failed');
$pdo->exec("UPDATE people SET phone=NULL WHERE id=123");migrationReject(fn()=>migrationFinish($pdo,2),'Incomplete saved person finished');
$pdo->exec("UPDATE people SET phone='0123456789' WHERE id=123");
migrationCheck(migrationState($pdo,2)['current_step']==='horses','Person did not advance to Horses');
migrationCheck(migrationSaveHorses($pdo,2,[])===[],'No-horse Next failed');
migrationCheck(migrationState($pdo,2)['current_step']==='shares','Horse step did not advance to Shares');
migrationReject(fn()=>migrationFinish($pdo,2),'Shares placeholder skipped');
migrationSaveShares($pdo,2);migrationBack($pdo,2);migrationCheck(migrationState($pdo,2)['current_step']==='shares','Finish Back did not return to Shares');
migrationSaveShares($pdo,2);migrationFinish($pdo,2);
$state=migrationState($pdo,2);migrationCheck(!migrationPending($state) && !empty($state['completed_at']),'Completion failed');
migrationCheck($state['horses_status']==='none' && $state['shares_status']==='placeholder_seen','Horse/Shares progress not recorded');
migrationCheck(json_decode($state['user_preferences_json'],true)['general_email_opt_in']===1,'Preference evidence missing');
migrationCheck($state['user_preferences_json']===$state['person_preferences_json'],'Preference snapshots disagree');
foreach(['general_email_opt_in','ride_notice_opt_in','renewal_reminder_opt_in'] as $field) migrationCheck($pdo->query("SELECT `$field` FROM users WHERE id=2")->fetchColumn()===$pdo->query("SELECT `$field` FROM people WHERE id=123")->fetchColumn(),'Person choice not applied to matching user');
migrationReject(fn()=>migrationSendPilot($pdo,1,$transport),'Completed account re-enrolled');
foreach(['checkout.php','account.php','admin/campaigns.php','bookings.php'] as $script)migrationCheck(!migrationGuardAllows($script,[],'POST',['action'=>'save_user_details']),'Protected route allowed');
migrationCheck(migrationGuardAllows('account.php',['logout'=>1],'GET',[]),'Logout blocked');
migrationCheck(!migrationGuardAllows('account.php',['logout'=>1],'POST',[]),'POST logout bypass');
migrationCheck(migrationGuardAllows('help.php',[],'GET',[]),'Help blocked');
$reset=importWorkspacePlan($pdo,2);migrationCheck(isset($reset['delete']['migration_enrolments'],$reset['delete']['migration_invitations']),'Reset does not clear migration records');
// Closure is exercised with a disposable ordinary account, never the protected pilot.
migrationReject(fn()=>migrationDecline($pdo,2,$token,true,1),'Protected account closed');
$userRole=(int)$pdo->query("SELECT id FROM roles WHERE name='user'")->fetchColumn();
$declineHash=password_hash('disposable fixture password',PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (id,email,password_hash,role_id,first_name,last_name,general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in) VALUES (3,'decline@example.invalid',?,?,'Disposable','Fixture',1,1,1)")->execute([$declineHash,$userRole]);
$pdo->exec("INSERT INTO people (id,owner_user_id,email,first_name,last_name,general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in) VALUES (124,3,'decline@example.invalid','Disposable','Fixture',1,1,1)");
$pdo->exec("INSERT INTO migration_enrolments (user_id,person_id) VALUES (3,124)");
$declineToken=bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO migration_invitations (user_id,token_hash,email,credential_stamp,expires_at,campaign_revision) VALUES (3,?,?,?,?,1)')->execute([hash('sha256',$declineToken),'decline@example.invalid',hash('sha256',$declineHash),auth_future_string(3600)]);
$_SESSION['user']=['id'=>3,'role'=>'user'];authBindCredentialSession(3,$declineHash);
createMagicLoginToken($pdo,3);createPasswordResetToken($pdo,3);
migrationReject(fn()=>migrationDecline($pdo,3,$declineToken,false,1),'Missing closure confirmation accepted');
migrationReject(fn()=>migrationDecline($pdo,3,$declineToken,true,99),'Stale closure wording accepted');
migrationReject(fn()=>migrationDecline($pdo,3,str_repeat('a',64),true,1),'Invalid invitation accepted for closure');
$_SESSION['act_as_original_user']=['id'=>2];migrationReject(fn()=>migrationDecline($pdo,3,$declineToken,true,1),'Impersonated closure accepted');unset($_SESSION['act_as_original_user']);
$pdo->exec("INSERT INTO people (id,owner_user_id,email,first_name,last_name) VALUES (125,3,'other@example.invalid','Other','Fixture')");
migrationReject(fn()=>migrationDecline($pdo,3,$declineToken,true,1),'Multiple people silently closed');$pdo->exec('DELETE FROM people WHERE id=125');
// Force the second note to fail to prove the entire archive operation rolls back.
$pdo->exec('ALTER TABLE record_notes ADD UNIQUE KEY fixture_one_note(event_key)');
migrationReject(fn()=>migrationDecline($pdo,3,$declineToken,true,1),'Notes failure did not abort closure');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM record_notes')->fetchColumn()===0,'Partial closure note committed');
migrationCheck((int)$pdo->query('SELECT is_archived FROM users WHERE id=3')->fetchColumn()===0,'User archived despite note failure');
migrationCheck(migrationInspectInvitation($pdo,$declineToken)!==null,'Failed closure consumed invitation');
$pdo->exec('ALTER TABLE record_notes DROP INDEX fixture_one_note');
migrationDecline($pdo,3,$declineToken,true,1);
migrationCheck(empty($_SESSION['user']),'Closed account stayed signed in');
migrationCheck((int)$pdo->query('SELECT is_archived FROM users WHERE id=3')->fetchColumn()===1,'User was not archived');
migrationCheck((int)$pdo->query('SELECT is_archived FROM people WHERE id=124')->fetchColumn()===1,'Person was not archived');
migrationCheck((int)$pdo->query('SELECT general_email_opt_in+ride_notice_opt_in+renewal_reminder_opt_in FROM users WHERE id=3')->fetchColumn()===0,'Closed user kept email preferences');
migrationCheck((int)$pdo->query('SELECT general_email_opt_in+ride_notice_opt_in+renewal_reminder_opt_in FROM people WHERE id=124')->fetchColumn()===0,'Closed person kept email preferences');
migrationCheck(!migrationPending(migrationState($pdo,3)) && migrationState($pdo,3)['current_step']==='declined','Decline did not stop migration');
migrationCheck(migrationInspectInvitation($pdo,$declineToken)===null,'Closed account retained invitation');
foreach(['auth_magic_tokens','auth_password_resets'] as $t)migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE user_id=3")->fetchColumn()===0,'Closed account retained auth link');
$notes=recordNotesFind($pdo,['email'=>'decline@example.invalid']);migrationCheck(count($notes)===2,'Closure note pair missing');
migrationCheck($notes[0]['event_key']===$notes[1]['event_key'],'Closure notes are not correlated');
$evidence=json_decode($notes[0]['metadata_json'],true);migrationCheck(str_contains($evidence['confirmation_text'],'Fixture closure explanation'),'Confirmation text not recorded');
migrationCheck(count(recordNotesFind($pdo,['record_type'=>'person','record_id'=>124,'action'=>'migration_declined','from'=>date('Y-m-d'),'to'=>date('Y-m-d')]))===1,'Notes filters failed');
foreach(['user','manager',''] as $roleName)migrationCheck(!recordNotesCanRead($roleName),'Internal notes role leakage');
migrationCheck(recordNotesCanRead('admin'),'Admin notes access blocked');
$pdo->prepare('UPDATE users SET email=? WHERE id=3')->execute(['renamed@example.invalid']);migrationCheck(count(recordNotesFind($pdo,['email'=>'decline@example.invalid']))===2,'Email snapshot lost');
$oldAlerts=[];$_SESSION['user']=['id'=>3];authBindCredentialSession(3,$declineHash);migrationCheck(!authValidateSession($pdo,$oldAlerts),'Archived account session remained valid');
$_SESSION['user']=['id'=>2,'role'=>'developer'];authBindCredentialSession(2,$new['password_hash']);
$reset=importWorkspacePlan($pdo,2);migrationCheck(isset($reset['delete']['record_notes']),'Import reset notes policy missing');
echo "PASS: closure confirmation, protected account, stale wording, wrong token, impersonation, extra-person review, atomic rollback, paired private notes, archive/sign-out/token revocation, terminal decline, email snapshots and admin filters. Disposable temporary records only.\n";
echo "PASS: pilot-only delivery, preview/revision checks, no scanner activation, expiry, resend, replay, wrong account, impersonation, password proof/revocation, atomic progress, required fields, DOB, ownership, preferences, Back, completion, guards and reset policy. Temporary tables only; no real sends or account edits.\n";

// Render the actual shared view with synthetic state for headless browser checks.
if (isset($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')) {
    require dirname(__DIR__,2).'/views/migration_fields.php';
    $siteSettings=['hero_title'=>'Fixture ILDRA'];$basePath='';$general='';$completedNow=false;$invitation=null;$token='';$currentUser=['id'=>2];$uid=2;$pageCopy=migrationPageCopy($pdo);$closureComplete=false;$closureProtected=false;
    foreach (['password','user','person','horses','horses-none','horse-logbook','horse-keep','horse-archive','shares','finish','complete','complete-basket','welcome','protected-welcome','closed','anonymous-welcome','other-account-welcome'] as $fixtureStep) {
        $step=in_array($fixtureStep,['complete','complete-basket','welcome','protected-welcome','closed','anonymous-welcome','other-account-welcome'],true)?null:(in_array($fixtureStep,['horses-none','horse-logbook','horse-keep','horse-archive'],true)?'horses':$fixtureStep);
        $invitation=in_array($fixtureStep,['welcome','protected-welcome'],true)?['user_id'=>2,'email'=>'fixture@example.invalid']:null;
        $closureProtected=$fixtureStep==='protected-welcome';$closureComplete=$fixtureStep==='closed';
        $currentUser=['id'=>2];$uid=2;$canInvitationStart=false;
        if(in_array($fixtureStep,['anonymous-welcome','other-account-welcome'],true)) {
            $invitation=['user_id'=>4,'email'=>'ordinary@example.invalid'];$canInvitationStart=true;
            if($fixtureStep==='anonymous-welcome'){$currentUser=null;$uid=0;}
        }
        $state=['completed_at'=>in_array($fixtureStep,['complete','complete-basket'],true)?'2026-11-17 12:00:00':null,'person_id'=>123];
        $membershipOffers=[['id'=>900,'name'=>'Fixture Senior','description'=>'Adult membership information.','cost'=>'35.00'],['id'=>901,'name'=>'Fixture Junior','description'=>'Youth membership information.','cost'=>'20.00']];$membershipYear=2027;
        $values=$fixtureStep==='person'?$person:$user;$errors=[];
        if ($fixtureStep==='person') $errors=['phone'=>'Phone number is required.'];
        $horseRows=[];$horseQualifications=[1=>'Bronze',2=>'None'];$horseAction='';$selectedHorse=null;$horseChoiceStates=[];$logbookOffer=['id'=>900,'name'=>'Fixture logbook','valid_year'=>2027,'cost'=>'7.50'];
        if(in_array($fixtureStep,['horses','horse-logbook','horse-keep','horse-archive'],true)){
            $horseRows=[['id'=>400,'owner_user_id'=>2,'name'=>'Fixture Horse','owner_name'=>'Fixture Owner','breed'=>'Arab','colour'=>'Bay','sex'=>'Mare','height_cm'=>150,'qualification_id'=>2,'year_of_birth'=>'2012','passport_number'=>'']];
            $errors=['horses[400][passport_number]'=>'Passport number is required.'];
            $horseChoiceStates=[400=>['valid'=>false,'label'=>'Please choose an option']];
            if($fixtureStep!=='horses'){$horseAction=substr($fixtureStep,6);$selectedHorse=$horseRows[0];}
            if($fixtureStep==='horse-archive')$errors=['archive_confirmed'=>'Confirm that you want to archive this horse.'];
        }
        $completedNow=in_array($fixtureStep,['complete','complete-basket'],true);
        $_SESSION['basket']=$fixtureStep==='complete-basket'?[['booking_type'=>'horse_logbook','horse_id'=>400,'price'=>'7.50']]:[];
        ob_start();require dirname(__DIR__,2).'/views/migration_setup.php';$html=ob_get_clean();
        file_put_contents($argv[1].'/'.$fixtureStep.'.html',$html);chmod($argv[1].'/'.$fixtureStep.'.html',0600);
    }
}
