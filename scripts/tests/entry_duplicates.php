<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();require __DIR__.'/entry_horses.php';ob_end_clean();
require_once dirname(__DIR__,2).'/entry_warnings.php';
$insert=$pdo->prepare('INSERT INTO booking_items(booking_id,event_id,metadata,is_withdrawn) VALUES (999999,?,?,?)');
$insert->execute([790001,json_encode(['person_id'=>123,'horse_id'=>810001,'is_member_price'=>true]),0]);
$insert->execute([790001,json_encode(['person_id'=>810001,'horse_id'=>810005]),1]);
$insert->execute([790002,json_encode(['person_id'=>810002,'horse_id'=>810007]),0]);
$basket=[['event_id'=>790001,'metadata'=>['person_id'=>700002,'horse_id'=>1]]];
$seen=entryExistingParticipants($pdo,790001,$basket);
migrationCheck(isset($seen['riders'][123],$seen['riders'][700002],$seen['horses'][810001]),'Saved/basket participants missed');
migrationCheck(!isset($seen['horses'][1]) && !isset($seen['riders'][810001]) && !isset($seen['horses'][810005]) && !isset($seen['riders'][810002]),'Placeholder, withdrawn or other event produced duplicate warning');
$pdo->exec("INSERT INTO event_pricing_rows(id,event_id,class_name,class_code,class_group,price,is_member_price,rider_eligibility) VALUES (810100,790001,'Fixture Member PR','PR','PR',20,1,'all'),(810101,790001,'Fixture Nonmember PR','PR-NON','PR',25,0,'all')");
foreach([1,2] as $attempt){
 $result=$postEntry(123,810001,'registered','810100');
 migrationCheck($result['redirected'],'Repeat member entry blocked: '.json_encode($result['alerts']));
 $entry=end($result['basket']);migrationCheck(!empty($entry['metadata']['is_member_price']) && (int)$entry['metadata']['person_id']===123,'Repeat member pricing lost');
}
$_SESSION['basket']=$basket;$html=$render();
preg_match('~<script type="application/json" id="entryRiderData">(.*?)</script>~s',$html,$match);$data=json_decode($match[1],true);
migrationCheck(!empty($data['eligibility'][123]) && !empty($data['entered_riders'][123]) && !empty($data['entered_horses'][810001]),'Duplicate flags or member eligibility wrong');
file_put_contents($argv[1].'/entry-duplicates.html',$html);chmod($argv[1].'/entry-duplicates.html',0600);
echo "PASS: saved/basket duplicate flags, withdrawn/other event/placeholder exclusions, repeat member-price POST accepted, eligible member rate retained. Temporary tables only.\n";
