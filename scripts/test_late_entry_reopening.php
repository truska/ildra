<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/cms.php';
date_default_timezone_set('Europe/London');
function checkLate(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$event=['status'=>'published','event_date'=>'2030-07-15','entry_open_at'=>'2030-06-01 10:00:00','entry_close_at'=>'2030-07-12 18:00:00','late_entry_open_at'=>'2030-07-13 00:00:00','late_entry_close_at'=>'2030-07-14 18:00:00','late_entries_enabled'=>1];
foreach(['2030-07-12 17:59:59'=>true,'2030-07-12 18:00:00'=>false,'2030-07-12 23:59:59'=>false,'2030-07-13 00:00:00'=>true,'2030-07-14 17:59:59'=>true,'2030-07-14 18:00:00'=>false] as $time=>$open) {
    checkLate(eventPricingEntriesOpen($event,strtotime($time))===$open,'Entry window incorrect at '.$time);
}
checkLate(!eventLateEntryWindowOpen(array_replace($event,['late_entries_enabled'=>0]),strtotime('2030-07-13 01:00:00')),'Disabled late entries opened');
checkLate(!eventLateEntryWindowOpen(array_replace($event,['status'=>'draft']),strtotime('2030-07-13 01:00:00')),'Draft late entries opened');
checkLate(!eventLateEntryWindowOpen(array_replace($event,['late_entry_open_at'=>'2030-07-12 17:00:00']),strtotime('2030-07-12 19:00:00')),'Reopen before close accepted');
$old=$event;unset($old['late_entry_open_at']);
checkLate(eventLateEntryOpeningTime($old)===strtotime('2030-07-13 00:00:00'),'Legacy six-hour fallback');
$settings=defaultSiteSettings();checkLate($settings['event_late_entry_reopen_hours']===6,'Default is not six');
$settings['event_entry_close_weekday']=5;$settings['event_entry_close_time']='22:30';$settings['event_late_entry_reopen_hours']=9;
$defaults=event_date_defaults('2030-07-15',$settings);
checkLate($defaults['late_entry_open_date']==='2030-07-13' && $defaults['late_entry_open_time']==='07:30','Custom reopening defaults/cross-midnight wrong');
$settings['event_entry_close_weekday']=6;$settings['event_entry_close_time']='23:00';$settings['event_late_entry_reopen_hours']=6;
foreach(['2026-03-30'=>'06:00','2026-10-26'=>'04:00'] as $rideDate=>$time) {
    $defaults=event_date_defaults($rideDate,$settings);
    checkLate($defaults['late_entry_open_time']===$time,'DST elapsed-hour default wrong for '.$rideDate);
}
$source=file_get_contents(dirname(__DIR__).'/event.php');preg_match('/^\$lateEntriesOpenNow = .*;$/m',$source,$assignment);
$publicLate=static function(array $event,bool $isFull,bool $entriesOpenNow,int $timestamp)use($assignment):bool {
    $now=(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Europe/London'));
    eval($assignment[0]);return $lateEntriesOpenNow;
};
checkLate(!$publicLate($event,true,false,strtotime('2030-07-13 01:00:00')),'Full event reopened');
checkLate(!$publicLate($event,false,false,strtotime('2030-07-12 20:00:00')),'Public entries opened during closed gap');
checkLate($publicLate($event,false,false,strtotime('2030-07-13 00:00:00')),'Public entries failed to reopen');
echo "Late-entry reopening: default/custom hours, exact boundaries, closed gap, disabled/full/draft events and DST passed.\n";
