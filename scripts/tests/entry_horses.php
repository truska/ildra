<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
ob_start();require __DIR__.'/entry_people.php';ob_end_clean();
require_once dirname(__DIR__,2).'/entry_horses.php';
$year=(int)date('Y');$date=date('Y-m-d',strtotime('+30 days'));$settings=getSiteSettings($pdo);
$pdo->exec("INSERT INTO membership_types(id,name,membership_year,allows_ride_entries,allows_competitive_rides,status) VALUES (810001,'Fixture Rider',$year,1,1,'published')");
$pdo->exec("INSERT INTO membership_purchases(member_id,membership_type_id,membership_year,status) VALUES (123,810001,$year,'active')");
$pdo->exec("INSERT INTO people(id,owner_user_id,first_name,last_name,junior_or_senior) VALUES (810001,2,'Nonmember','Fixture','Senior'),(810002,2,'Recognised','Fixture','Senior')");
$pdo->exec("INSERT INTO horses(id,owner_user_id,name,is_archived) VALUES (810001,2,'Own Zeta',0),(810002,700001,'Linked Alpha',0),(810003,700003,'Other Beta',0),(810004,2,'No Logbook',0),(810005,700003,'Expired',0),(810006,700003,'Archived',1),(810007,700003,'External Horse',0)");
$type=(int)$pdo->query('SELECT id FROM horse_logbook_types LIMIT 1')->fetchColumn();
$s=$pdo->prepare("INSERT INTO horse_logbook_purchases(horse_id,logbook_type_id,valid_year,status) VALUES (?,?,?,'active')");
foreach ([810001,810002,810003,810006] as $id) $s->execute([$id,$type,$year]);$s->execute([810005,$type,$year-2]);
$pdo->exec("INSERT INTO user_horse_links(user_id,horse_id,permission,status) VALUES (2,810002,'select_only','approved')");
$s=$pdo->prepare("INSERT INTO external_recognition_applications(user_id,subject_type,person_id,horse_id,organisation_country_code,credential_number,valid_until,status) VALUES (2,?,?,?,'GB','FIXTURE',?,'verified')");
$s->execute(['rider',810002,null,$date]);$s->execute(['horse',null,810007,$date]);
$people=fetchMembersForUser($pdo,2);
$c=entryHorseCatalogue($pdo,2,$people,$date,$settings);
migrationCheck($c['own_by_person'][123]===[810001],'Own horse order incorrect');
migrationCheck($c['linked_by_person'][123]===[810002],'Linked horse group incorrect');
$ids=array_column($c['horses'],'id');
foreach ([810001,810002,810003,810007] as $id) migrationCheck(in_array($id,$ids,true),'Eligible horse missing');
foreach ([810004,810005,810006,1] as $id) migrationCheck(!in_array($id,$ids,true),'Ineligible horse offered');
foreach ($c['horses'] as $horse) migrationCheck(array_keys($horse)===['id','name','logbook','recognised'],'Private horse data exported');
migrationCheck(entryRiderCanChooseHorse($pdo,2,123,$date),'Member denied horse choice');
migrationCheck(entryRiderCanChooseHorse($pdo,2,810002,$date),'Recognised rider denied horse choice');
migrationCheck(!entryRiderCanChooseHorse($pdo,2,810001,$date),'Nonmember offered horse choice');
$count=(int)$pdo->query('SELECT COUNT(*) FROM horses')->fetchColumn();
migrationCheck(entryRememberHorse($pdo,2,123,810003,$date,$settings),'New selection did not link');
migrationCheck(!entryRememberHorse($pdo,2,123,810003,$date,$settings),'Repeated selection relinked');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM horses')->fetchColumn()===$count,'Selection duplicated horse');
migrationCheck((int)$pdo->query('SELECT owner_user_id FROM horses WHERE id=810003')->fetchColumn()===700003,'Selection transferred ownership');
$c=entryHorseCatalogue($pdo,2,$people,$date,$settings);
migrationCheck(in_array(810003,$c['linked_by_person'][123],true),'Selected horse not remembered above divider');
foreach ([[810001,810003],[123,810004],[123,810005],[123,810006],[123,1],[999999,810003]] as [$person,$horse]) migrationReject(fn()=>entryRememberHorse($pdo,2,$person,$horse,$date,$settings),'Invalid rider/horse accepted');
migrationCheck(entryRememberHorse($pdo,2,810002,810007,$date,$settings),'Recognised horse/rider selection failed');
$pdo->exec('UPDATE user_horse_links SET revoked_at=NOW() WHERE user_id=2 AND horse_id=810003');
migrationReject(fn()=>entryRememberHorse($pdo,2,123,810003,$date,$settings),'Revoked horse link reinstated');
$c=entryHorseCatalogue($pdo,2,$people,$date,$settings);migrationCheck(!in_array(810003,array_column($c['horses'],'id'),true),'Revoked horse offered');
$pdo->exec('DELETE FROM user_horse_links WHERE user_id=2 AND horse_id IN (810003,810007)');
if (isset($render)) {
    $html=$render();file_put_contents($argv[1].'/entry-horses.html',$html);chmod($argv[1].'/entry-horses.html',0600);
}
// Exercise the real POST controller as well as the selection endpoint helper.
$postSource=str_replace("header('Location: ' . \$basePath . '/basket');\n                exit;",'$redirected=true;return;',$source);
$postEntry=static function(int $person,int $horse,string $mode='registered',string $class='PR',array $existingBasket=[]) use ($pdo,$postSource,$currentUser):array {
    $_GET=['id'=>790001];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_NAME']='/event.php';
    $_POST=['horse_selection_mode'=>$mode,'action'=>'add_booking','person_id'=>$person,'horse_id'=>$horse,'horse_name'=>'Forged name','class_code'=>$class,'contact_email'=>'fixture@example.invalid','emergency_contact_name'=>'Fixture Contact','emergency_contact_phone'=>'0123456789'];
    $_SESSION['basket']=$existingBasket;$config=[];$alerts=[];$successMessage=null;$redirected=false;
    ob_start();try { eval(substr($postSource,5)); } finally { ob_end_clean(); }
    return ['redirected'=>$redirected,'basket'=>$basket,'alerts'=>$alerts];
};
$result=$postEntry(810001,810003);
migrationCheck($result['redirected'],'Nonmember entry failed: '.json_encode($result['alerts']));
$item=end($result['basket']);migrationCheck($item['metadata']['horse_id']===1 && $item['metadata']['horse_name']==='Not Registered','Nonmember forged horse accepted');
$result=$postEntry(123,1);migrationCheck(!$result['redirected'],'Member registered mode accepted Not Registered');
$result=$postEntry(123,810003,'not_registered');migrationCheck($result['redirected'],'Member switch-off entry failed');
$item=end($result['basket']);migrationCheck($item['metadata']['horse_id']===1 && $item['metadata']['horse_name']==='Not Registered','Member placeholder not canonical');
$result=$postEntry(123,810005);migrationCheck(!$result['redirected'] && !empty($result['alerts']),'Expired horse POST accepted');
$result=$postEntry(123,810003);migrationCheck($result['redirected'],'Member entry failed: '.json_encode($result['alerts']));
$item=end($result['basket']);migrationCheck($item['metadata']['horse_id']===810003 && $item['metadata']['horse_name']==='Other Beta','Canonical horse identity not used');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM user_horse_links WHERE user_id=2 AND horse_id=810003')->fetchColumn()===1,'Server fallback did not remember horse');
$pdo->prepare('UPDATE events SET classes_offered=? WHERE id=790001')->execute([json_encode([
    ['code'=>'PR','label'=>'Fixture Pleasure Ride','price'=>20],['code'=>'CTR','class_group'=>'CTR','label'=>'Fixture CTR','price'=>30],
])]);
$result=$postEntry(123,810003,'not_registered','CTR');
migrationCheck(!$result['redirected'] && str_contains(json_encode($result['alerts']),'valid ILDRA logbook'),'Switch off bypassed competitive horse qualification');
echo "PASS: horse catalogue grouping, membership/recognition eligibility, event-year logbooks, automatic idempotent linking, unchanged ownership, minimal data and revoked/expired/archived guards. Temporary tables only.\n";
