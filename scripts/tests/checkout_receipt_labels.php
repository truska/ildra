<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/helpers.php';
$source=file_get_contents(dirname(__DIR__,2).'/checkout_complete.php');
$start=strpos($source,'<?php foreach (($order[\'items\'] ?? []) as $item): ?>');
if($start===false)throw new RuntimeException('Receipt items template not found');
$end=strpos($source,'<?php endforeach; ?>',$start)+strlen('<?php endforeach; ?>');
$template=substr($source,$start,$end-$start);
function checkReceipt(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
$items=[
 ['booking_type'=>'membership','event_title'=>'Old heading','price'=>'35.00','member_name'=>'Anne <Example>','membership_name'=>'Adult Membership'],
 ['booking_type'=>'membership','event_title'=>'Old stored heading','price'=>'20.00','metadata'=>['member_name'=>'Young Member','membership_name'=>'Youth Membership']],
 ['booking_type'=>'horse_logbook','event_title'=>'Old horse heading','price'=>'7.50','metadata'=>['horse_name'=>'Silver Horse']],
 ['booking_type'=>'entry','event_title'=>'Trail Ride','price'=>'15.00','metadata'=>['rider_name'=>'Rider Name','horse_name'=>'Entry Horse','class_label'=>'PR','contact_email'=>'rider@example.test']],
 ['booking_type'=>'membership','event_title'=>'Legacy membership heading','price'=>'30.00'],
];
$order=['items'=>$items];ob_start();eval('?>'.$template);$html=ob_get_clean();
foreach(['Membership: Anne &lt;Example&gt;','Type: Adult Membership','Membership: Young Member','Type: Youth Membership','Horse Logbook: Silver Horse','Type: Horse Logbook','Trail Ride (PR','Rider: Rider Name','Horse: Entry Horse','Contact: rider@example.test','Legacy membership heading','£7.50'] as $text)checkReceipt(str_contains($html,$text),'Receipt missing '.$text);
checkReceipt(!str_contains($html,'<Example>'),'Receipt name was not escaped');
checkReceipt(!str_contains($html,'Old stored heading'),'Stored metadata name ignored');
checkReceipt(substr_count($html,'list-group-item')===count($items),'Receipt lost an item');
echo "PASS: receipt names/types from basket and stored metadata, ride details, prices, legacy fallback and escaping. No database or payment calls.\n";
