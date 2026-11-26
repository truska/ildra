<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
ob_start();require __DIR__.'/account_migration.php';ob_end_clean();
$root=dirname(__DIR__,2);
$bootstrap=file_get_contents($root.'/bootstrap.php');
preg_match_all("/require_once __DIR__ \\. '([^']+)';/",$bootstrap,$dependencies);
foreach ($dependencies[1] as $dependency) require_once $root.$dependency;
foreach (['baskets','bookings','booking_items','membership_purchases','horse_logbook_purchases','share_requests','user_person_links','user_horse_links'] as $table) $pdo->exec('DELETE FROM '.$table);
$pdo->exec("UPDATE users SET first_name='Dashboard',last_name='Buyer',auth_app_enabled=0,auth_app_secret=NULL WHERE id=2");
$pdo->exec("UPDATE people SET first_name='Dashboard',last_name='Rider',email='dashboard@example.invalid',is_archived=0 WHERE id=123");
$pdo->exec("INSERT INTO horses(id,owner_user_id,name,is_archived) VALUES (1981001,2,'Dashboard Horse',0)");
// Synthetic paid history exercises the previously separate overview sections.
$pdo->exec("INSERT INTO membership_types(id,name,cost,status,membership_year) VALUES (1981001,'Dashboard Membership',35,'published',2027)");
$pdo->exec("INSERT INTO membership_purchases(purchased_by_user_id,member_id,membership_type_id,membership_year,amount) VALUES (2,123,1981001,2027,35)");
$pdo->exec("INSERT INTO horse_logbook_types(id,name,cost,status,valid_year) VALUES (1981001,'Dashboard Logbook',7.5,'published',2027)");
$pdo->exec("INSERT INTO horse_logbook_purchases(purchased_by_user_id,horse_id,logbook_type_id,valid_year,amount) VALUES (2,1981001,1981001,2027,7.5)");
$pdo->exec("INSERT INTO bookings(id,booking_ref,user_id,contact_name,contact_email,total) VALUES (1981001,'DASHBOARD-FIXTURE',2,'Dashboard Buyer','dashboard@example.invalid',35)");
$pdo->exec("INSERT INTO booking_items(booking_id,booking_type,event_title,price,metadata) VALUES (1981001,'membership','Dashboard Membership',35,'{\"member_name\":\"Dashboard Rider\"}')");
$user=migrationRecipient($pdo,2);
$source=file_get_contents($root.'/account.php');
$source=str_replace("require __DIR__ . '/bootstrap.php';",'', $source);
// Shared site header declares request-scoped functions; it is outside this account-body fixture.
$source=str_replace("include __DIR__ . '/views/header.php';",'', $source);
$source=str_replace('__DIR__',var_export($root,true),$source);
// The bootstrap is intentionally replaced; handlers/views themselves run unchanged.
$render=static function(array $query,array $user,bool $fromOnboarding=false) use ($pdo,$source):string {
    $_GET=$query;$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/account.php';
    $_SESSION['user']=$user;
    if ($fromOnboarding) $_SESSION['onboarding_account_welcome_user_id']=(int)$user['id'];
    foreach (['migration_invite','legacy_balance_login_check','legacy_balance_confirmation','ride_notice_preferences_pending','prompt_person_completion'] as $key) unset($_SESSION[$key]);
    $currentUser=$user;$config=[];$alerts=[];$successMessage=null;
    ob_start();eval(substr($source,5));return (string)ob_get_clean();
};
$pdo->exec("INSERT INTO site_settings(setting_key,setting_value) VALUES ('onboarding_copy_thanks','<p>Fixture onboarding account welcome.</p>') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
$fixtures=['onboarding'=>[], 'after-onboarding'=>['onboarding'=>'1'], 'default'=>[], 'people'=>['view'=>'people'], 'horses'=>['view'=>'horses'], 'person-edit'=>['view'=>'people','person_id'=>123], 'horse-edit'=>['view'=>'horses','horse_id'=>1981001], 'shares'=>['view'=>'shares'], 'security'=>['view'=>'my-account']];
foreach ($fixtures as $key=>$query) {
    $html=$render($query,$user,$key==='onboarding');
    migrationCheck(str_contains($html,'id="onboardingAccountWelcomeModal"')===($key==='onboarding'),'Welcome shown outside onboarding or missing on arrival');
    if ($key==='onboarding') migrationCheck(str_contains($html,'Fixture onboarding account welcome.'),'Editable account welcome copy missing');
    if (in_array($key,['onboarding','after-onboarding','default','people','horses','person-edit','horse-edit'],true)) {
        migrationCheck(str_contains($html,'id="people"') && str_contains($html,'id="horses"'),'Dashboard missing a section: '.$key);
        migrationCheck(strpos($html,'id="people"')<strpos($html,'id="horses"'),'Horse section precedes People');
        migrationCheck(strpos($html,'id="my-memberships"')<strpos($html,'id="horses"'),'Membership history is not below People');
        migrationCheck(str_contains($html,'class="collapse" id="accountMembershipHistory"') && str_contains($html,'class="collapse" id="accountLogbookHistory"'),'Histories not initially collapsed');
        migrationCheck(substr_count($html,'id="personEditorModal"')===1 && substr_count($html,'id="horseEditorModal"')===1,'Editor modal duplicated/missing');
        migrationCheck(str_contains($html,'Dashboard Horse') && str_contains($html,'Dashboard Membership'),'Existing records missing');
        migrationCheck(str_contains($html,'Recent Purchases') && str_contains($html,'DASHBOARD-FIXTURE'),'Recent purchases missing');
    }
    preg_match('/<div class="d-flex flex-wrap gap-2 align-items-center account-summary-actions">(.*?)<\/div>/s',$html,$actions);
    migrationCheck(!str_contains($actions[1]??'','>People<') && !str_contains($actions[1]??'','>Horses<') && !str_contains($actions[1]??'','view=shares'),'Old view navigation still visible');
    migrationCheck(str_contains($actions[1]??'','/admin/index.php'),'Developer Admin link lost');
    if ($key==='shares') migrationCheck(str_contains($html,'id="shares"'),'Hidden Shares functionality was removed');
    if ($key==='security') migrationCheck(str_contains($html,'Save Login Details') && str_contains($html,'Change Password'),'Login/security settings lost');
    if (!empty($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')) { file_put_contents($argv[1].'/account-'.$key.'.html',$html);chmod($argv[1].'/account-'.$key.'.html',0600); }
}
$ordinary=$user;$ordinary['role']='user';$ordinary['level']=1;
$html=$render([],$ordinary);
preg_match('/<div class="d-flex flex-wrap gap-2 align-items-center account-summary-actions">(.*?)<\/div>/s',$html,$actions);
migrationCheck(!str_contains($actions[1]??'','/admin/'),'Ordinary user sees Admin');
$pdo->exec('DELETE FROM membership_purchases');$pdo->exec('DELETE FROM horse_logbook_purchases');$pdo->exec('DELETE FROM booking_items');$pdo->exec('DELETE FROM bookings');
$emptyHtml=$render([],$ordinary);
migrationCheck(str_contains($emptyHtml,'No memberships yet.') && str_contains($emptyHtml,'No purchases yet.'),'Empty history guidance missing');
migrationCheck(str_contains($emptyHtml,'id="people"') && str_contains($emptyHtml,'id="horses"'),'Empty histories hid the record sections');
if (!empty($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')) { file_put_contents($argv[1].'/account-unpaid.html',$emptyHtml);chmod($argv[1].'/account-unpaid.html',0600); }
echo "PASS: combined People/Horses dashboard, preserved editors/records, legacy URLs, collapsed history with empty states, hidden Shares navigation, retained security and Admin role visibility. Temporary fixtures only.\n";
