<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/helpers.php';
require dirname(__DIR__,2).'/cms.php';
function calendarLabelCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$expected=['Pleasure Ride (PR)','Vetted Pleasure Ride (VPR)','Competitive Trail Ride (CTR)','Endurance Ride (ER)'];
$rows=[];foreach(['PR','PR','VPR','CTR','ER'] as $index=>$group)$rows[]=['enabled'=>1,'class_group'=>$group,'class_name'=>'Price variant '.$index];
$rows[]=['enabled'=>0,'class_group'=>'OTHER','class_name'=>'Disabled class'];
$before=$rows;$classes=class_names_from_pricing_rows($rows);
calendarLabelCheck(calendar_ride_type_descriptions($classes,defaultSiteSettings())===$expected,'Default descriptions or deduplication incorrect');
calendarLabelCheck($rows===$before,'Saved class data mutated');
calendarLabelCheck(calendar_ride_type_descriptions(['PR'],['calendar_ride_label_PR'=>'Custom PR wording'])===['Custom PR wording'],'Admin override ignored');
calendarLabelCheck(calendar_ride_type_descriptions(['Special class'],[])===['Special class'],'Other event class renamed');
calendarLabelCheck(calendar_ride_type_descriptions(class_names_from_classes_offered('[{"code":"PR"},{"label":"ER"}]'),[])===[$expected[0],$expected[3]],'Legacy fallback not expanded');
calendarLabelCheck(class_names_from_pricing_rows($rows)===['PR','VPR','CTR','ER'],'Shared non-calendar summaries changed');
$source=file_get_contents(dirname(__DIR__,2).'/page.php');calendarLabelCheck(substr_count($source,'$classes = calendar_ride_type_descriptions($classes, $siteSettings);')===2,'Current/past calendar not using labels');
foreach (['event.php'=>'$classesList', 'ride_report.php'=>'$classesList', 'site.php'=>'$classNames'] as $file=>$variable) {
    $source=file_get_contents(dirname(__DIR__,2).'/'.$file);
    calendarLabelCheck(str_contains($source,$variable.' = calendar_ride_type_descriptions('.$variable.', $siteSettings);'),'Public event summary missing full labels: '.$file);
}
echo "PASS: default/custom calendar descriptions, available types, duplicate price variants, disabled classes, legacy fallback and unchanged pricing/non-calendar summaries.\n";
