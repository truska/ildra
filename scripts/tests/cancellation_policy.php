<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();require __DIR__.'/entry_horses.php';ob_end_clean();
require_once dirname(__DIR__,2).'/admin_settings_audit.php';
$legacy=['ride_notes_footer_html'=>'<p>General text</p><h4>Cancellation Policy</h4><p>Custom policy</p><h4>Other section</h4><p>Keep this</p>'];
$split=separateCancellationPolicySettings($legacy);
migrationCheck($split['cancellation_policy_html']==='<p>Custom policy</p>','Custom policy not preserved');
migrationCheck($split['ride_notes_footer_html']==='<p>General text</p><h4>Other section</h4><p>Keep this</p>','Surrounding footer text lost');
migrationCheck(separateCancellationPolicySettings($split)===$split,'Policy separation not idempotent');
$split['cancellation_policy_html']='';migrationCheck(separateCancellationPolicySettings($split)['cancellation_policy_html']==='','Explicit empty policy replaced');
$alerts=[];$policy='<p>Shared cancellation wording fixture.</p>';
migrationCheck(adminSaveSiteSettings($pdo,['cancellation_policy_html'=>$policy,'ride_notes_footer_html'=>'<p>General footer fixture.</p>'],$alerts,$currentUser,'events'),'Central setting save failed');
$siteSettings=getSiteSettings($pdo);migrationCheck($siteSettings['cancellation_policy_html']===$policy,'Central policy not read back');
ob_start();require dirname(__DIR__,2).'/views/cancellation_policy_modal.php';$modal=ob_get_clean();
migrationCheck(substr_count($modal,'Shared cancellation wording fixture.')===1 && !str_contains($modal,'General footer fixture.'),'Modal policy duplicated or general footer included');
saveRideNotes($pdo,790001,['status'=>'published','ride_notes_html'=>'<p>Ride information</p>'],2);
$notesSource=file_get_contents(dirname(__DIR__,2).'/ride_notes.php');
$notesSource=str_replace("require __DIR__ . '/bootstrap.php';",'',$notesSource);
$notesSource=str_replace('__DIR__',var_export(dirname(__DIR__,2),true),$notesSource);
$notesRender=static function()use($pdo,$currentUser,$notesSource):string{$_GET=['event_id'=>790001];ob_start();eval(substr($notesSource,5));return ob_get_clean();};
$notesHtml=$notesRender();migrationCheck(substr_count($notesHtml,'Shared cancellation wording fixture.')===1 && substr_count($notesHtml,'General footer fixture.')===1,'Rider Notes missing or duplicating shared text');
$eventHtml=$render();migrationCheck(substr_count($eventHtml,'Shared cancellation wording fixture.')===1,'Event modal missing shared policy');
$alerts=[];migrationCheck(adminSaveSiteSettings($pdo,['cancellation_policy_html'=>'<p>Updated shared fixture.</p>'],$alerts,$currentUser,'events'),'Edit failed');
migrationCheck(str_contains($render(),'Updated shared fixture.') && str_contains($notesRender(),'Updated shared fixture.'),'Edit not reflected across both views');
echo "PASS: legacy/custom policy separation, surrounding text preservation, idempotence, explicit blank, audited Settings save and shared policy appearing once in event popup/Rider Notes; updates reach both. Temporary tables only.\n";
