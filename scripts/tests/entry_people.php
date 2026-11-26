<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
ob_start();require __DIR__.'/account_migration.php';
require_once dirname(__DIR__,2).'/cms.php';require_once dirname(__DIR__,2).'/entry_people.php';
$role=(int)$pdo->query("SELECT id FROM roles WHERE name='user'")->fetchColumn();
$s=$pdo->prepare('INSERT INTO users(id,email,password_hash,role_id,first_name,last_name) VALUES (?,?,?,?,?,?)');
foreach ([700001=>['owner@example.invalid','Owner','Account'],700002=>['login-only@example.invalid','Login','Only'],700003=>['canonical@example.invalid','Canonical','Rider']] as $id=>[$email,$first,$last]) $s->execute([$id,$email,password_hash('fixture password',PASSWORD_DEFAULT),$role,$first,$last]);
$pdo->exec("INSERT INTO people(id,owner_user_id,first_name,last_name,email,phone,address,postcode,dob,junior_or_senior,emergency_contact_name,emergency_contact_phone) VALUES (700001,700001,'Existing','Rider','existing@example.invalid','private-phone','private-address','PRIVATE','1990-01-01','Senior','private-contact','private-emergency'),(700002,700003,'Canonical','Rider','old-canonical@example.invalid',NULL,NULL,NULL,NULL,'Senior',NULL,NULL)");
$details=['first_name'=>'New','last_name'=>'Rider','phone'=>'0123456789','address'=>'Fixture address','postcode'=>'bt1- 1aa!','junior_or_senior'=>'Senior','dob'=>'','emergency_contact_name'=>'Emergency Contact','emergency_contact_phone'=>'0123456790'];
$before=(int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn();
$found=entryResolvePerson($pdo,2,' EXISTING@EXAMPLE.INVALID ');
migrationCheck($found===['status'=>'ready','person_id'=>700001],'Existing rider not reused');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn()===$before,'Existing rider duplicated');
$linked=fetchPersonForUserById($pdo,2,700001);
migrationCheck(!empty($linked['is_linked']) && (int)$linked['owner_user_id']===700001,'Rider ownership transferred or link missing');
foreach (['dob','phone','address','postcode','emergency_contact_name','emergency_contact_phone'] as $field) migrationCheck(empty($linked[$field]),'Shared private field disclosed: '.$field);
migrationCheck(entryResolvePerson($pdo,2,'existing@example.invalid')['person_id']===700001,'Repeated lookup changed rider');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM user_person_links WHERE user_id=2 AND person_id=700001')->fetchColumn()===1,'Duplicate share link');
$lookup=entryResolvePerson($pdo,2,'new-rider@example.invalid');
migrationCheck($lookup['status']==='details' && (int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn()===$before,'Unmatched lookup created incomplete rider');
$missing=entryResolvePerson($pdo,2,'new-rider@example.invalid',[]);
foreach (['first_name','last_name','phone','address','postcode','junior_or_senior','emergency_contact_name','emergency_contact_phone'] as $field) migrationCheck(isset($missing['errors'][$field]),'Missing required field accepted: '.$field);
$young=$details;$young['dob']=date('Y-m-d');migrationCheck(isset(entryResolvePerson($pdo,2,'new-rider@example.invalid',$young)['errors']['dob']),'Under-five rider accepted');
$junior=$details;$junior['junior_or_senior']='Junior';migrationCheck(isset(entryResolvePerson($pdo,2,'new-rider@example.invalid',$junior)['errors']['dob']),'Junior without DOB accepted');
$created=entryResolvePerson($pdo,2,'new-rider@example.invalid',$details);
$person=fetchPersonForUserById($pdo,2,$created['person_id']);
migrationCheck($created['status']==='ready' && empty($person['is_linked']) && $person['phone']===$details['phone'],'New rider not owned or details lost');
migrationCheck($person['postcode']==='BT1 1AA','New rider postcode not normalized before storage');
migrationCheck(entryResolvePerson($pdo,2,'new-rider@example.invalid',$details)['person_id']===$created['person_id'],'Repeated create duplicated rider');
$s=$pdo->prepare('SELECT general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in FROM people WHERE id=?');$s->execute([$created['person_id']]);migrationCheck(array_sum($s->fetch())===0,'New rider opted into email without consent');
$canonical=entryResolvePerson($pdo,2,'canonical@example.invalid');migrationCheck($canonical['person_id']===700002,'Account-holder Person with changed email was duplicated');
$login=entryResolvePerson($pdo,2,'login-only@example.invalid');
$s=$pdo->prepare('SELECT owner_user_id,email FROM people WHERE id=?');$s->execute([$login['person_id']]);$row=$s->fetch();migrationCheck((int)$row['owner_user_id']===700002 && $row['email']==='login-only@example.invalid','Login-only match assigned to wrong owner');
// Another caller creating the email between lookup and submission must be reused.
entryResolvePerson($pdo,2,'race@example.invalid');$other=entryResolvePerson($pdo,700001,'race@example.invalid',$details);
migrationCheck(entryResolvePerson($pdo,2,'race@example.invalid',$details)['person_id']===$other['person_id'],'Stale lookup created a duplicate');
$pdo->exec("UPDATE user_person_links SET revoked_at=NOW() WHERE user_id=2 AND person_id=700001");
migrationReject(fn()=>entryResolvePerson($pdo,2,'existing@example.invalid'),'Revoked access silently re-enabled');
$pdo->exec("UPDATE people SET is_archived=1 WHERE id=700001");migrationReject(fn()=>entryResolvePerson($pdo,2,'existing@example.invalid'),'Archived rider duplicated/reused');
$pdo->exec("INSERT INTO people(owner_user_id,first_name,last_name,email) VALUES (700001,'Duplicate','One','duplicate@example.invalid'),(2,'Duplicate','Two','duplicate@example.invalid')");
migrationReject(fn()=>entryResolvePerson($pdo,2,'duplicate@example.invalid'),'Ambiguous email chose a rider');
migrationCheck(entryResolvePerson($pdo,2,'invalid email')['status']==='error','Invalid email accepted');
if (!empty($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')) {
    $root=dirname(__DIR__,2);
    $bootstrap=file_get_contents($root.'/bootstrap.php');
    preg_match_all("/require_once __DIR__ \\. '([^']+)';/",$bootstrap,$dependencies);
    foreach ($dependencies[1] as $dependency) require_once $root.$dependency;
    $isLoggedIn=true;$currentUser=migrationRecipient($pdo,2);$basePath='';
    ob_start();require dirname(__DIR__,2).'/views/entry_person_modal.php';$html=ob_get_clean();file_put_contents($argv[1].'/entry-person-modal.html',$html);chmod($argv[1].'/entry-person-modal.html',0600);
    $rideType=(int)$pdo->query("SELECT id FROM event_types WHERE LOWER(name)='ride' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO events(id,title,event_date,status,event_type_id,classes_offered,entry_open_at,non_member_entry_open_at,entry_close_at) VALUES (790001,'Email-first entry fixture',?,'published',?,?,?, ?,?)")->execute([date('Y-m-d',strtotime('+30 days')),$rideType,json_encode([['code'=>'PR','label'=>'Fixture Pleasure Ride','price'=>20]]),date('Y-m-d H:i:s',strtotime('-2 days')),date('Y-m-d H:i:s',strtotime('-1 day')),date('Y-m-d H:i:s',strtotime('+20 days'))]);
    $pdo->exec("INSERT INTO people(id,owner_user_id,first_name,last_name,email,junior_or_senior) VALUES (790001,700001,'Selected','Rider','selected@example.invalid','Senior')");
    $source=file_get_contents($root.'/event.php');
    $source=str_replace("require __DIR__ . '/bootstrap.php';",'', $source);
    $source=str_replace("include __DIR__ . '/views/header.php';",'', $source);
    $a=strpos($source,'function event_url(');$b=strpos($source,'$navItemEventsUrl =',$a);
    $source=substr($source,0,$a)."if (!function_exists('event_url')) {\n".substr($source,$a,$b-$a)."}\n".substr($source,$b);
    $source=str_replace('__DIR__',var_export($root,true),$source);
    $render=static function() use ($pdo,$source,$currentUser):string {
        $_GET=['id'=>790001];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/event.php';
        $config=[];$alerts=[];$successMessage=null;
        ob_start();eval(substr($source,5));return (string)ob_get_clean();
    };
    $beforeHtml=$render();
    migrationCheck(str_contains($beforeHtml,'id="prefillPerson"') && str_contains($beforeHtml,'value="another">Another Person'),'Entry rider action missing');
    migrationCheck(!str_contains($beforeHtml,'Manage People'),'Old Manage People link retained');
    $selected=entryResolvePerson($pdo,2,'selected@example.invalid');migrationCheck($selected['person_id']===790001,'Refresh fixture rider not linked');
    $afterHtml=$render();
    foreach (['entry-person-before'=>$beforeHtml,'entry-person-after'=>$afterHtml] as $name=>$html) { file_put_contents($argv[1].'/'.$name.'.html',$html);chmod($argv[1].'/'.$name.'.html',0600); }

}
ob_end_clean();echo "PASS: email-first reuse, automatic minimal sharing, owned rider creation, canonical login matching, field/age validation, stale lookup/idempotency, privacy masking and revoked/archived/ambiguous guards. Temporary tables only; no real records or email.\n";
