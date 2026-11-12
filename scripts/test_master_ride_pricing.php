<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
date_default_timezone_set('Europe/London');
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/helpers.php';
require dirname(__DIR__).'/cms.php';
require dirname(__DIR__).'/ride_pricing_migration.php';
function rideCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
class RidePricingTestPDO extends LoggedPDO {
    public function exec(string $statement): int|false {
        $statement=preg_replace('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement);
        return parent::exec($statement);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        // information_schema does not enumerate connection-local temporary tables.
        if(str_contains($query,"table_name IN ('ride_classes','ride_scheme_classes','ride_pricing_state','event_pricing_locks')"))$query='SELECT 4';
        return parent::query($query,$fetchMode,...$args);
    }
}
$now=strtotime('2030-06-15 12:00:00');
$window=['status'=>'published','event_date'=>'2030-07-15','entry_open_at'=>'2030-06-15 11:00:00','non_member_entry_open_at'=>'2030-06-15 13:00:00','entry_close_at'=>'2030-06-15 14:00:00','late_entries_enabled'=>1,'late_entry_close_at'=>'2030-06-15 16:00:00'];
rideCheck(eventPricingEntriesOpen($window,$now),'Member window not protected');
$future=$window;$future['entry_open_at']='2030-06-15 13:00:00';
rideCheck(!eventPricingEntriesOpen($future,$now),'Late setting opened pricing protection before normal opening');
$closed=$window;$closed['entry_close_at']='2030-06-15 11:30:00';$closed['late_entries_enabled']=0;
rideCheck(!eventPricingEntriesOpen($closed,$now),'Closed window still open');
$closed['late_entries_enabled']=1;$closed['late_entry_open_at']='2030-06-15 11:45:00';
rideCheck(eventPricingEntriesOpen($closed,$now),'Late window not open');
$closed['late_entry_close_at']='2030-06-15 11:59:59';
rideCheck(!eventPricingEntriesOpen($closed,$now),'Expired late window still open');
$window['status']='draft';rideCheck(!eventPricingEntriesOpen($window,$now),'Draft counted as open');
$eventSource=file_get_contents(dirname(__DIR__).'/event.php');
preg_match('/^\$lateEntriesOpenNow = .*;$/m',$eventSource,$lateAssignment);
rideCheck(isset($lateAssignment[0]),'Public late-entry assignment not found');
$publicLate=static function(array $event)use($lateAssignment):bool {
    $now=new DateTimeImmutable();$isFull=false;$entriesOpenNow=false;
    $entryCloseDt=new DateTimeImmutable($event['entry_close_at']);$lateEntryCloseDt=new DateTimeImmutable($event['late_entry_close_at']);
    eval($lateAssignment[0]);return (bool)$lateEntriesOpenNow;
};
$publicFuture=$future;foreach(['entry_open_at'=>3600,'non_member_entry_open_at'=>7200,'entry_close_at'=>10800,'late_entry_close_at'=>14400] as $key=>$offset)$publicFuture[$key]=date('Y-m-d H:i:s',time()+$offset);
rideCheck(!$publicLate($publicFuture),'Public route offered late entry before normal opening');
$late=$publicFuture;$late['entry_open_at']=date('Y-m-d H:i:s',time()-7200);$late['entry_close_at']=date('Y-m-d H:i:s',time()-1800);$late['late_entry_open_at']=date('Y-m-d H:i:s',time()-900);
rideCheck($publicLate($late),'Public route refused a valid late-entry window');

$config=require dirname(__DIR__).'/config.php';$db=$config['db'];
$pdo=new RidePricingTestPDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STATEMENT_CLASS=>[LoggedPDOStatement::class,[]]]);
foreach(['pricing_schemes','pricing_scheme_rows','pricing_scheme_event_types','event_types','event_pricing_rows','events'] as $table){
    $definition=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$definition));
}
$pdo->exec("CREATE TEMPORARY TABLE booking_items(id INT PRIMARY KEY,event_id INT,status VARCHAR(20) DEFAULT 'active')");
ensureEntryWindowColumns($pdo);
ridePricingSchema($pdo);
$pdo->exec("INSERT INTO event_types(id,name,default_pricing_scheme_id,form_profile) VALUES(7,'Ride',11,'ride'),(8,'Dinner',33,'attendees')");
$pdo->exec("INSERT INTO pricing_schemes(id,name) VALUES(11,'Synthetic Master'),(22,'Synthetic PR Selection'),(33,'Synthetic Dinner')");
$pdo->exec('INSERT INTO pricing_scheme_event_types VALUES(11,7),(22,7),(33,8)');
$pdo->exec("INSERT INTO pricing_scheme_rows(id,scheme_id,sort_order,class_name,class_code,class_group,price,is_member_price,rider_eligibility) VALUES
(5,11,10,'Adult Test Ride','TEST-ADULT','PR',15,1,'senior'),
(9,11,20,'Junior Test Ride','TEST-JUNIOR','PR',15,1,'junior'),
(12,11,30,'CRT Up to 64km [Test]','CRT64-TEST','CTR',35,1,'all'),
(15,22,10,'Test Adult Selection',NULL,'PR',15,1,'senior'),
(16,22,20,'Test Junior Selection',NULL,'PR',13,1,'junior'),
(17,33,10,'Test Dinner','TEST-DINNER','OTHER',25,0,'all')");
$pdo->exec("INSERT INTO events(id,title,event_date,venue,status,entry_open_at,event_type_id) VALUES
(900,'Synthetic Open Event',CURDATE()+INTERVAL 30 DAY,'Fixture Venue','published',NOW()-INTERVAL 1 DAY,7),
(901,'Synthetic Draft Event',CURDATE()+INTERVAL 30 DAY,'Fixture Venue','draft',NOW()+INTERVAL 20 DAY,7)");
$pdo->exec('UPDATE events SET entry_close_at=NOW()+INTERVAL 1 DAY WHERE id=900');
$pdo->exec("INSERT INTO event_pricing_rows(event_id,sort_order,class_name,class_code,class_group,price,is_member_price,rider_eligibility,enabled) VALUES
(900,10,'Historical Open Class','HISTORICAL','PR',7.55,1,'senior',1),
(901,10,'Historical Draft Class','HISTORICAL-DRAFT','PR',8.55,1,'senior',1)");
$snapshot=fn()=>json_encode([$pdo->query('SELECT * FROM events ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM event_pricing_rows ORDER BY id')->fetchAll()]);
$before=$snapshot();
$parseAlerts=[];
$memberOnly=parseEventPricingRowsFromPost(['event_row_class_name'=>['r1'=>'Test Members CTR'],'event_row_class_code'=>['r1'=>'TEST-CTR'],'event_row_price'=>['r1'=>'35.00'],'event_row_is_member_price'=>['r1'=>1],'event_row_enabled'=>['r1'=>1]],$parseAlerts);
rideCheck(count($memberOnly)===1 && !$parseAlerts,'Members-only class selection incorrectly requires a non-member row');

$plan=buildRidePricingMigrationPlan($pdo,11);
rideCheck(count($plan['masters'])===3 && count($plan['selections'])===5,'Unexpected migration mapping');
rideCheck($plan['price_changes'][0]['old_price']==='13.00' && $plan['price_changes'][0]['master_price']==='15.00','Shared Junior Member price not reported');
rideCheck($plan['masters'][2]['class_name']==='CTR Up to 64km [Test]','CRT correction missing');
try {applyRidePricingMigration($pdo,11,str_repeat('0',64));throw new LogicException('Changed preview was accepted');}catch(RuntimeException $e){rideCheck(!$pdo->inTransaction(),'Stale preview left transaction open');}
$result=applyRidePricingMigration($pdo,11,$plan['source_sha256']);
rideCheck($result['classes']===3 && $result['selections']===5,'Migration counts');
rideCheck($snapshot()===$before,'Migration changed event snapshots');
rideCheck(eventPricingLocked($pdo,900),'Open event not locked');
rideCheck(!eventPricingLocked($pdo,901),'Draft event locked prematurely');
rideCheck(fetchPricingSchemeRows($pdo,22)[1]['price']==='15.00','PR scheme does not use master price');
rideCheck(fetchPricingSchemeRows($pdo,33)[0]['price']==='25.00','Non-ride pricing changed');
$classes=fetchMasterRideClasses($pdo);$adultId=(int)$classes[0]['id'];$juniorId=(int)$classes[1]['id'];
$pdo->prepare('UPDATE ride_classes SET price=21.00 WHERE id=?')->execute([$adultId]);
rideCheck(fetchPricingSchemeRows($pdo,11)[0]['price']==='21.00' && fetchPricingSchemeRows($pdo,22)[0]['price']==='21.00','Master edits not shared');
rideCheck($snapshot()===$before,'Master edit changed existing event prices');
$draftRows=fetchEventPricingRows($pdo,901);
$forged=$draftRows;$forged[0]['price']='0.01';
$preserved=preserveRideEventSnapshotRows($draftRows,$forged,['id'.$draftRows[0]['id']]);
rideCheck($preserved[0]['price']==='8.55','Ordinary draft edit rewrote its price snapshot');
rideCheck(copyPricingSchemeToEvent($pdo,11,901),'Draft reapply failed');
rideCheck(fetchEventPricingRows($pdo,901)[0]['price']==='21.00','Draft did not get current master price on explicit reapply');
$draftRows=fetchEventPricingRows($pdo,901);$forged=$draftRows;$forged[0]['price']='0.01';
rideCheck(resolveRideSchemeEventRows($pdo,11,$forged)[0]['price']==='21.00','Forged event price accepted during template apply');
$duplicated=buildDuplicatedEventPricingRows($pdo,['id'=>901,'event_type_id'=>7]);
rideCheck($duplicated[0]['price']==='21.00' && $duplicated[1]['rider_eligibility']==='junior','New duplicate lost current rates/eligibility');
$openBefore=fetchEventPricingRows($pdo,900);
rideCheck(!copyPricingSchemeToEvent($pdo,11,900),'Open event accepted template reapply');
rideCheck(!replaceEventPricingRows($pdo,900,fetchEventPricingRows($pdo,901),true),'Initial-price flag bypassed an existing event snapshot');
rideCheck(fetchEventPricingRows($pdo,900)===$openBefore,'Open prices or row IDs changed');
rideCheck(replaceEventPricingRows($pdo,900,$openBefore),'Unchanged open rows rejected');
rideCheck(fetchEventPricingRows($pdo,900)===$openBefore,'Ordinary save changed open row IDs');
// The obsolete permanent marker must not prevent correcting an unused test event.
$pdo->exec('INSERT INTO event_pricing_locks(event_id,locked_at) VALUES(900,NOW())');
$pdo->exec("UPDATE events SET status='draft' WHERE id=900");
rideCheck(!eventPricingLocked($pdo,900),'Unpublished event with no entries stayed locked');
$pdo->exec("UPDATE events SET status='published',entry_open_at=NOW()+INTERVAL 10 DAY,non_member_entry_open_at=NOW()+INTERVAL 11 DAY WHERE id=900");
rideCheck(!eventPricingLocked($pdo,900),'Future opening dates with no entries stayed locked');
rideCheck(fetchEventPricingRows($pdo,900)===$openBefore,'Changing dates automatically changed event prices');
rideCheck(copyPricingSchemeToEvent($pdo,11,900),'Rescheduled unused event rejected explicit template reapply');
$protectedRows=fetchEventPricingRows($pdo,900);
$pdo->exec("UPDATE events SET entry_open_at=NOW()-INTERVAL 2 DAY,non_member_entry_open_at=NOW()-INTERVAL 1 DAY,entry_close_at=NOW()-INTERVAL 1 HOUR,late_entries_enabled=0 WHERE id=900");
rideCheck(!eventPricingLocked($pdo,900),'Closed event without entries stayed locked');
$pdo->exec('UPDATE events SET late_entries_enabled=1,late_entry_open_at=NOW()-INTERVAL 30 MINUTE,late_entry_close_at=NOW()+INTERVAL 1 HOUR WHERE id=900');
rideCheck(eventPricingLocked($pdo,900),'Late-entry window did not block pricing changes');
$pdo->exec('UPDATE events SET late_entry_close_at=NOW()-INTERVAL 1 MINUTE WHERE id=900');
rideCheck(!eventPricingLocked($pdo,900),'Expired late-entry window stayed locked without entries');
$pdo->exec("INSERT INTO booking_items(id,event_id,status) VALUES(1,900,'withdrawn')");
rideCheck(eventPricingLocked($pdo,900),'Withdrawn entry failed to protect historical prices');
$pdo->exec("UPDATE events SET status='draft',entry_open_at=NOW()+INTERVAL 10 DAY WHERE id=900");
rideCheck(eventPricingLocked($pdo,900),'Unpublishing bypassed protection for a received entry');
$changedRows=$protectedRows;$changedRows[0]['price']='999.00';
rideCheck(!replaceEventPricingRows($pdo,900,$changedRows),'Received entry allowed changing prices');
$alerts=[];$copyId=copyPricingScheme($pdo,22,$alerts);
rideCheck($copyId!==null && !$alerts && count(fetchPricingSchemeRows($pdo,$copyId))===2,'Scheme copy lost master selections');
$alerts=[];$saved=savePricingScheme($pdo,['name'=>'Synthetic Selection Edited','event_type_ids'=>[7],'master_class_ids'=>[$juniorId],'row_price'=>['0.01']],$alerts,22);
rideCheck($saved===22 && count(fetchPricingSchemeRows($pdo,22))===1 && fetchPricingSchemeRows($pdo,22)[0]['price']==='15.00','Scheme accepted forged independent pricing');
$alerts=[];rideCheck(savePricingScheme($pdo,['name'=>'Invalid Selection','event_type_ids'=>[7],'master_class_ids'=>[999999]],$alerts,22)===null,'Unknown master class accepted');
rideCheck(count(fetchPricingSchemeRows($pdo,22))===1,'Invalid save changed selections');
$pdo->prepare('UPDATE ride_classes SET show_on_public=0 WHERE id=?')->execute([$juniorId]);
rideCheck(count(fetchMasterRideClasses($pdo,true))===2,'Public visibility ignored');
rideCheck(count(fetchPricingSchemeRows($pdo,22))===1,'Public visibility changed scheme selections');
$pdo->prepare('UPDATE ride_classes SET is_active=0 WHERE id=?')->execute([$juniorId]);
rideCheck(fetchPricingSchemeRows($pdo,22)===[],'Inactive master class still offered to new events');
$alerts=[];
rideCheck(savePricingScheme($pdo,['name'=>'Synthetic Inactive Selection','event_type_ids'=>[7],'master_class_ids'=>[$juniorId]],$alerts,22)===22,'Editing a scheme lost its inactive saved selection');
rideCheck(count(fetchMasterSchemeRows($pdo,22,true))===1,'Inactive mapping deleted instead of retained for later reactivation');

$repeat=applyRidePricingMigration($pdo,11,$plan['source_sha256']);
rideCheck($repeat['already_applied']===true && fetchMasterRideClasses($pdo)[0]['price']==='21.00','Rerun overwrote later master changes');
rideCheck(fetchEventPricingRows($pdo,900)===$protectedRows,'Later changes affected an event with a received entry');
echo "Master migration, shared pricing, scheme copy, tamper rejection, public visibility, draft reapply and current opening-window and recorded-entry checks passed. Temporary tables only.\n";

if(in_array('--render-fixture',$argv,true)) {
    $pdo->prepare('UPDATE ride_classes SET is_active=1,show_on_public=1 WHERE id=?')->execute([$juniorId]);
    $pdo->prepare('UPDATE ride_classes SET class_name=? WHERE id=?')->execute(['<script>window.masterInjected=1</script>',$adultId]);
    $_SESSION=[];$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
    function admin_layout_start(string $title,string $key): void {echo '<!doctype html><html><head><meta charset="utf-8"></head><body><main>';}
    function admin_layout_end(): void {echo '</main></body></html>';}
    $schemeId=22;$scheme=fetchPricingSchemeById($pdo,22);$eventTypes=fetchEventTypes($pdo);$alerts=[];
    ob_start();require dirname(__DIR__).'/admin/ride_scheme_form.php';$html=ob_get_clean();
    file_put_contents('/tmp/master-ride-scheme-fixture.html',$html);
    echo "Synthetic scheme form written to /tmp/master-ride-scheme-fixture.html\n";
    $source=file_get_contents(dirname(__DIR__).'/admin/event_edit.php');
    $sectionStart=strpos($source,'        <div class="section-card">'."\n".'            <div class="section-title">Classes &amp; Pricing</div>');
    $sectionEnd=strpos($source,'        <div class="section-card">'."\n".'            <div class="section-title">Event limits</div>',$sectionStart);
    $protectStart=strrpos($source,'<?php if(ridePricingReady($pdo)): ?><script>');
    $protectEnd=strpos($source,'<?php admin_layout_end();',$protectStart);
    $eventPricingRows=fetchEventPricingRows($pdo,901);
    $pricingSchemeOptions=[['id'=>11,'name'=>'Synthetic Master','event_type_ids'=>[7]]];
    foreach([false,true] as $pricingLocked){
        ob_start();
        echo '<!doctype html><html><meta charset="utf-8"><form><select name="event_type_id"><option value="7">Ride</option></select>';
        eval('?>'.substr($source,$sectionStart,$sectionEnd-$sectionStart));
        eval('?>'.substr($source,$protectStart,$protectEnd-$protectStart));
        echo '</form></html>';
        file_put_contents('/tmp/master-ride-event-'.($pricingLocked?'locked':'draft').'.html',ob_get_clean());
    }

}

// Exercise actual event INSERT/UPDATE parameter binding and reopening validation.
$eventData=['title'=>'Reopen fixture','event_date'=>'2030-07-15','event_type_id'=>8,'status'=>'draft','entry_close_date'=>'2030-07-12','entry_close_time'=>'18:00','late_entries_enabled'=>1,'late_entry_open_date'=>'2030-07-13','late_entry_open_time'=>'00:00','late_entry_close_date'=>'2030-07-14','late_entry_close_time'=>'18:00'];
$eventAlerts=[];$savedId=saveEvent($pdo,$eventData,$eventAlerts);
rideCheck($savedId!==false,'Saving reopening fixture failed: '.json_encode($eventAlerts));
$stored=$pdo->query('SELECT * FROM events WHERE id='.(int)$savedId)->fetch();
rideCheck($stored['late_entry_open_at']==='2030-07-13 00:00:00','Reopen timestamp not stored on INSERT');
$eventData['event_id']=$savedId;$eventData['late_entry_open_time']='01:00';
$eventAlerts=[];rideCheck(saveEvent($pdo,$eventData,$eventAlerts)!==false,'Updating stored reopening failed');
rideCheck($pdo->query('SELECT late_entry_open_at FROM events WHERE id='.(int)$savedId)->fetchColumn()==='2030-07-13 01:00:00','Reopen override not saved on UPDATE');
$eventData['late_entry_open_date']='2030-07-12';$eventData['late_entry_open_time']='17:00';
$eventAlerts=[];rideCheck(saveEvent($pdo,$eventData,$eventAlerts)===false,'Reopening before closing accepted');
$eventData['late_entry_open_date']='2030-07-15';
$eventAlerts=[];rideCheck(saveEvent($pdo,$eventData,$eventAlerts)===false,'Reopening after late close accepted');
$eventData['late_entry_open_date']='2030-02-30';
$eventAlerts=[];rideCheck(saveEvent($pdo,$eventData,$eventAlerts)===false,'Invalid reopening date accepted');
echo "Event reopening timestamp persistence and invalid-window validation passed. Temporary tables only.\n";

$pdo->exec('CREATE TEMPORARY TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(191) PRIMARY KEY,setting_value TEXT,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO site_settings(setting_key,setting_value) VALUES('event_late_entry_reopen_hours','9') ON DUPLICATE KEY UPDATE setting_value='9'");
unset($eventData['event_id'],$eventData['late_entry_open_date'],$eventData['late_entry_open_time']);
$eventData['title']='Reopening default fixture';$eventAlerts=[];
$defaultId=saveEvent($pdo,$eventData,$eventAlerts);
rideCheck($defaultId!==false,'Generating stored reopening default failed: '.json_encode($eventAlerts));
rideCheck($pdo->query('SELECT late_entry_open_at FROM events WHERE id='.(int)$defaultId)->fetchColumn()==='2030-07-13 03:00:00','Default not calculated from actual normal close plus nine hours');
$pdo->exec("UPDATE site_settings SET setting_value='12' WHERE setting_key='event_late_entry_reopen_hours'");
$eventData['event_id']=$defaultId;$eventAlerts=[];
rideCheck(saveEvent($pdo,$eventData,$eventAlerts)!==false,'Saving existing snapshot without new fields failed');
rideCheck($pdo->query('SELECT late_entry_open_at FROM events WHERE id='.(int)$defaultId)->fetchColumn()==='2030-07-13 03:00:00','Later setting change recalculated existing stored reopening');
echo "Global reopening default and existing-event snapshot preservation passed. Temporary tables only.\n";
$copyAlerts=[];$copyId=duplicateEventAsDraft($pdo,(int)$defaultId,'2031-08-18',$copyAlerts);
rideCheck($copyId!==false,'Copying event with reopening snapshot failed: '.json_encode($copyAlerts));
$copy=$pdo->query('SELECT * FROM events WHERE id='.(int)$copyId)->fetch();
rideCheck(strtotime($copy['late_entry_open_at'])-strtotime($copy['entry_close_at'])===12*3600,'Copied event did not generate reopening from current twelve-hour default');
rideCheck($copy['status']==='draft','Copied event not a draft');
echo "Copied event reopening default passed. Temporary tables only.\n";
