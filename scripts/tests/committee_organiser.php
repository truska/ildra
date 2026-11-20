<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/db.php';
require dirname(__DIR__, 2).'/cms.php';
$alerts=[];$pdo=createPdo(require dirname(__DIR__,2).'/config.php',$alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');
// Shadow every installed table before any application helper can write.
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $quoted=str_replace('`','``',$table);
    $pdo->exec("CREATE TEMPORARY TABLE committee_seed LIKE `$quoted`");
    $pdo->exec("INSERT INTO committee_seed SELECT * FROM `$quoted`");
    $pdo->exec("CREATE TEMPORARY TABLE `$quoted` LIKE committee_seed");
    $pdo->exec("INSERT INTO `$quoted` SELECT * FROM committee_seed");
    $pdo->exec('DROP TEMPORARY TABLE committee_seed');
    if (!str_contains($pdo->query("SHOW CREATE TABLE `$quoted`")->fetch(PDO::FETCH_NUM)[1], 'CREATE TEMPORARY TABLE')) throw new RuntimeException('Non-temporary fixture table.');
}
function committeeCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$type=(int)$pdo->query("SELECT id FROM event_types WHERE LOWER(name)='ride' LIMIT 1")->fetchColumn();
committeeCheck($type>0,'No ride type fixture.');
$data=['title'=>'Committee test ride','event_date'=>'2035-07-20','end_date'=>'2035-07-20','start_time'=>'09:00','end_time'=>'17:00','venue'=>'Fixture venue','event_type_id'=>$type,'status'=>'draft','organiser_user_id'=>'committee','emergency_phone'=>'','entry_close_date'=>'2035-07-10','entry_close_time'=>'18:00'];
$alerts=[];$id=saveEvent($pdo,$data,$alerts);
committeeCheck((int)$id>0 && !$alerts,'Committee with no emergency phone must save: '.json_encode($alerts));
$event=fetchEventById($pdo,(int)$id);
committeeCheck($event['organiser']==='Committee' && empty($event['organiser_user_id']),'Committee must not link a real login.');
committeeCheck(riderNotesNeedsEmergencyWarning($pdo,$event),'Missing number must warn.');
$eligible=fetchEligibleEventOrganisers($pdo);committeeCheck((bool)$eligible,'No organiser fixture.');
$user=$eligible[0];$uid=(int)$user['id'];
// Make one unambiguous matching person in the temporary fixtures.
$pdo->prepare('DELETE FROM people WHERE owner_user_id=?')->execute([$uid]);
$pdo->prepare('INSERT INTO people(owner_user_id,first_name,last_name,phone,is_archived) VALUES (?,?,?,?,0)')->execute([$uid,$user['first_name'],$user['last_name'],'01234 567890']);
$alerts=[];committeeCheck(saveEvent($pdo,array_replace($data,['event_id'=>$id,'organiser_user_id'=>$uid,'emergency_use_organiser'=>1]),$alerts)===$id,'Individual organiser must save.');
$event=fetchEventById($pdo,(int)$id);committeeCheck(!riderNotesNeedsEmergencyWarning($pdo,$event),'Usable organiser number must not warn.');
$alerts=[];committeeCheck(saveEvent($pdo,array_replace($data,['event_id'=>$id]),$alerts)===$id,'Switch from individual to Committee must save.');
$event=fetchEventById($pdo,(int)$id);committeeCheck($event['organiser']==='Committee' && empty($event['organiser_user_id']),'Committee must replace previous individual.');
$alerts=[];committeeCheck(saveEvent($pdo,array_replace($data,['event_id'=>$id,'emergency_phone'=>'+44 1234 567890']),$alerts)===$id,'Manual number must save.');
$event=fetchEventById($pdo,(int)$id);committeeCheck(!riderNotesNeedsEmergencyWarning($pdo,$event),'Manual number must suppress warning.');
$alerts=[];committeeCheck(saveEvent($pdo,array_replace($data,['event_id'=>$id,'emergency_phone'=>'not a phone']),$alerts)===false,'Invalid supplied number must still be rejected.');
committeeCheck(($alerts[0]['field']??'')==='emergency_phone','Invalid phone needs a field warning.');
$alerts=[];committeeCheck(saveEvent($pdo,array_replace($data,['event_id'=>$id,'organiser_user_id'=>$uid,'emergency_use_organiser'=>1]),$alerts)===$id,'Organiser must save again.');
$pdo->prepare("UPDATE people SET phone='' WHERE owner_user_id=?")->execute([$uid]);
$event=fetchEventById($pdo,(int)$id);committeeCheck(riderNotesNeedsEmergencyWarning($pdo,$event),'Missing organiser number must warn.');
$alerts=[];committeeCheck(saveEvent($pdo,array_replace($data,['event_id'=>$id,'organiser_user_id'=>$uid,'emergency_use_organiser'=>1]),$alerts)===$id,'No organiser phone must not prevent saving.');
echo "PASS: Committee creation/replacement, optional emergency number, organiser/manual number resolution and invalid supplied phone validation. Temporary tables only.\n";
