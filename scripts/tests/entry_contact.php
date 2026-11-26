<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
ob_start();require __DIR__.'/entry_horses.php';ob_end_clean();
$pdo->exec("UPDATE people SET phone='existing-phone',emergency_contact_name='Existing Name',emergency_contact_phone=NULL WHERE id IN (123,700002)");
$fields=['phone'=>'new-phone','emergency_contact_name'=>'New Name','emergency_contact_phone'=>'new-emergency-phone'];
foreach ([123,700002] as $personId) {
    $person=fetchPersonForUserById($pdo,2,$personId);$enriched=entryPeopleWithContactGaps($pdo,[$person])[0];
    migrationCheck($enriched['contact_missing']===['phone'=>false,'emergency_contact_name'=>false,'emergency_contact_phone'=>true],'Missing flags do not reflect canonical record');
    if($personId===700002) migrationCheck(empty($enriched['phone']) && empty($enriched['emergency_contact_name']),'Linked private details disclosed');
    migrationCheck(!entryUpdateRiderContact($pdo,2,$personId,$fields,false),'No consent updated profile');
    migrationCheck(entryUpdateRiderContact($pdo,2,$personId,$fields,true),'Missing contact field not saved');
    $s=$pdo->prepare('SELECT phone,emergency_contact_name,emergency_contact_phone FROM people WHERE id=?');$s->execute([$personId]);$row=$s->fetch();
    migrationCheck($row===['phone'=>'existing-phone','emergency_contact_name'=>'Existing Name','emergency_contact_phone'=>'new-emergency-phone'],'Existing field overwritten or missing field not saved');
    migrationCheck(!entryUpdateRiderContact($pdo,2,$personId,$fields,true),'Complete record was updated');
}
$pdo->exec('UPDATE people SET emergency_contact_phone=NULL WHERE id=700002');
$pdo->exec('UPDATE user_person_links SET revoked_at=NOW() WHERE user_id=2 AND person_id=700002');
migrationCheck(!entryUpdateRiderContact($pdo,2,700002,$fields,true),'Revoked link updated record');
migrationCheck(!entryUpdateRiderContact($pdo,700001,700002,$fields,true),'Unrelated user updated record');
$pdo->exec('UPDATE user_person_links SET revoked_at=NULL WHERE user_id=2 AND person_id=700002');
$pdo->exec('UPDATE people SET is_archived=1 WHERE id=700002');
migrationCheck(!entryUpdateRiderContact($pdo,2,700002,$fields,true),'Archived person updated');
$pdo->exec('UPDATE people SET is_archived=0 WHERE id=700002');
$html=$render();file_put_contents($argv[1].'/entry-contact.html',$html);chmod($argv[1].'/entry-contact.html',0600);
echo "PASS: canonical missing flags, masked linked details, explicit consent, owned/linked gap-only updates, unchanged existing values and revoked/unrelated/archived guards. Temporary tables only.\n";
