<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();require __DIR__.'/account_migration.php';
foreach(['horses','migration_horse_choices','migration_horse_reviews','baskets','record_notes','horse_logbook_types','horse_logbook_purchases'] as $table)migrationCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Unshadowed horse-choice table');
$pdo->exec("UPDATE migration_enrolments SET current_step='horses',horses_status='pending',shares_status='deferred',completed_at=NULL WHERE user_id=2");
$pdo->exec('DELETE FROM baskets');$pdo->exec('DELETE FROM horse_logbook_purchases');$pdo->exec('DELETE FROM horse_logbook_types');
$pdo->exec("INSERT INTO horse_logbook_types (id,name,cost,status,valid_year) VALUES (900,'Fixture logbook',7.50,'published',2020)");
$pdo->exec("INSERT INTO site_settings (setting_key,setting_value) VALUES ('purchasing_paused_at','0'),('purchasing_paused_seconds','0'),('membership_next_year_from','11-01') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
$pdo->exec("INSERT INTO horses (id,owner_user_id,name,dob,passport_issuer,flu_vac_date) VALUES (400,2,'First Fixture','2012-01-01','FIXTURE','2026-01-01'),(401,2,'Second Fixture',NULL,NULL,NULL),(402,3,'Other Account Fixture',NULL,NULL,NULL)");
$pdo->exec("INSERT INTO horses (id,owner_user_id,name,is_archived) VALUES (403,2,'Archived Fixture',1)");
$pdo->exec("INSERT INTO user_horse_links (user_id,horse_id,permission,status) VALUES (2,402,'select_only','approved')");
$preservedShares=[];foreach(['user_person_links','user_horse_links','share_requests'] as $t)$preservedShares[$t]=json_encode($pdo->query("SELECT * FROM `$t`")->fetchAll());
$qualification=(int)array_key_first(migrationHorseQualifications($pdo));
$details=['name'=>'First Fixture','owner_name'=>'Actual Owner','breed'=>'Arab','colour'=>'Bay','sex'=>'Mare','height_cm'=>'152','qualification_id'=>(string)$qualification,'year_of_birth'=>'2012','passport_number'=>'FIXTURE123'];
$offer=migrationLogbookOffer($pdo);$details['logbook_quote']=migrationLogbookQuote($offer);$year=(int)$offer['valid_year'];
$normal=['id'=>'fixture-booking','booking_type'=>'entry','event_title'=>'Preserved entry','price'=>'11.00'];
$pdo->prepare('INSERT INTO baskets (session_id,user_id,basket_json,last_added_at) VALUES (?,?,?,FROM_UNIXTIME(?))')->execute([session_id(),2,json_encode([$normal]),1000000000]);
$cart=static function()use($pdo):array{$s=$pdo->prepare('SELECT basket_json FROM baskets WHERE session_id=?');$s->execute([session_id()]);$json=$s->fetchColumn();return $json?json_decode($json,true):[];};
$logs=static fn(array $basket)=>array_values(array_filter($basket,static fn($i)=>($i['booking_type']??'')==='horse_logbook'));
migrationCheck(isset(migrationSaveHorses($pdo,2,[])['horse_choice_400']),'Next allowed an undecided horse');
foreach([1,402,403] as $hid)foreach(['keep','logbook','archive'] as $choice)migrationReject(fn()=>migrationChooseHorse($pdo,2,$hid,$choice,$details+['archive_confirmed'=>1]),'System/shared/archived horse action allowed');
migrationCheck(isset(migrationChooseHorse($pdo,2,400,'keep',['name'=>''])['name']),'Empty name confirmed');
migrationCheck(migrationChooseHorse($pdo,2,401,'keep',['name'=>'Second confirmed'])===[],'Name-only keep failed');
migrationCheck($pdo->query('SELECT breed FROM horses WHERE id=401')->fetchColumn()===null,'Keep invented horse details');
migrationCheck($cart()===[$normal],'Keep changed unrelated basket');
foreach([1,402,403] as $hid)migrationReject(fn()=>migrationRenameHorse($pdo,2,$hid,['name'=>'Unauthorised rename']),'System/shared/archived horse rename allowed');
migrationCheck(isset(migrationRenameHorse($pdo,2,401,['name'=>''])['name']),'Empty rename accepted');
migrationCheck(migrationRenameHorse($pdo,2,401,['name'=>'Renamed second'])===[],'Rename kept horse failed');
migrationCheck(migrationChooseHorse($pdo,2,401,'keep',[])===[],'Direct no-logbook choice failed');
$renamed=$pdo->query('SELECT * FROM horses WHERE id=401')->fetch();
migrationCheck($renamed['name']==='Renamed second','Direct no-logbook changed name');
migrationCheck(migrationHorseChoiceValid($pdo,$renamed,migrationHorseChoices($pdo,2)[401],$offer),'Rename invalidated kept choice');

$s=$pdo->prepare('SELECT UNIX_TIMESTAMP(last_added_at) FROM baskets WHERE session_id=?');$s->execute([session_id()]);migrationCheck((int)$s->fetchColumn()===1000000000,'Keep refreshed unrelated basket holds');
$invalid=$details;$invalid['breed']='';migrationCheck(isset(migrationChooseHorse($pdo,2,400,'logbook',$invalid)['horses[400][breed]']),'Buying did not validate required details');
foreach(['sex'=>'Invalid','height_cm'=>'15.2','qualification_id'=>'999999','year_of_birth'=>(string)(date('Y')+1),'passport_number'=>str_repeat('x',33)] as $field=>$value){$bad=$details;$bad[$field]=$value;migrationCheck(isset(migrationChooseHorse($pdo,2,400,'logbook',$bad)['horses[400]['.$field.']']),'Bad buy field accepted: '.$field);}
// A basket storage failure must roll back the saved horse, choice and review evidence.
$pdo->exec("ALTER TABLE baskets ADD CONSTRAINT fixture_block_logbook CHECK (LOCATE('horse_logbook',basket_json)=0)");
migrationReject(fn()=>migrationChooseHorse($pdo,2,400,'logbook',$details),'Basket write failure was ignored');
migrationCheck($pdo->query('SELECT breed FROM horses WHERE id=400')->fetchColumn()===null,'Failed basket left horse edits');
migrationCheck(!isset(migrationHorseChoices($pdo,2)[400]),'Failed basket left a decision');migrationCheck($cart()===[$normal],'Failed basket lost unrelated item');
$pdo->exec('ALTER TABLE baskets DROP CONSTRAINT fixture_block_logbook');
$details['owner_user_id']=999;$details['price']='0.01';
migrationCheck(migrationChooseHorse($pdo,2,400,'logbook',$details)===[],'Valid buy failed');
$savedNames=$pdo->query('SELECT name,owner_name FROM horses WHERE id=400')->fetch();
migrationCheck($savedNames['name']===$details['name'] && $savedNames['owner_name']===$details['owner_name'],'Horse and owner names mixed during onboarding');
migrationCheck($logs($cart())[0]['horse_name']===$details['name'],'Basket used owner name for horse');
$oldLine=$logs($cart())[0];
migrationCheck(migrationRenameHorse($pdo,2,400,['name'=>'Renamed logbook horse'])===[],'Logbook horse rename failed');
$renamed=$pdo->query('SELECT * FROM horses WHERE id=400')->fetch();
migrationCheck(migrationHorseChoiceValid($pdo,$renamed,migrationHorseChoices($pdo,2)[400],$offer),'Rename lost valid logbook choice');
migrationCheck($logs($cart())[0]['horse_name']==='Renamed logbook horse','Basket name not refreshed');
migrationCheck($logs($cart())[0]['price']===$oldLine['price'],'Rename changed logbook price');
migrationCheck(migrationRenameHorse($pdo,2,400,['name'=>$details['name']])===[],'Restore fixture horse name failed');

$items=$cart();migrationCheck($items[0]===$normal && count($logs($items))===1,'Basket contents wrong');$line=$logs($items)[0];
migrationCheck((int)$line['horse_id']===400 && (int)$line['logbook_year']===$year && (float)$line['price']===7.5,'Client price/year trusted');
migrationCheck((int)$pdo->query('SELECT owner_user_id FROM horses WHERE id=400')->fetchColumn()===2,'Buying transferred owner');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM horse_logbook_purchases')->fetchColumn()===0,'Adding to basket created a paid entitlement');
$kept=$pdo->query('SELECT dob,passport_issuer,flu_vac_date FROM horses WHERE id=400')->fetch();migrationCheck($kept===['dob'=>'2012-01-01','passport_issuer'=>'FIXTURE','flu_vac_date'=>'2026-01-01'],'Hidden legacy fields cleared');
migrationChooseHorse($pdo,2,400,'logbook',$details);migrationCheck(count($logs($cart()))===1,'Repeated Buy duplicated basket');
// Saved intent survives a new login session; normal unrelated entries stay in the old basket.
$oldSid=session_id();session_regenerate_id(true);unset($_SESSION['basket']);migrationSyncLogbookBasket($pdo,2);
migrationCheck(count($logs($cart()))===1,'Saved selection not restored into new session');
$s=$pdo->prepare('SELECT basket_json FROM baskets WHERE session_id=?');$s->execute([$oldSid]);migrationCheck(json_decode($s->fetchColumn(),true)===[$normal],'Moving pending logbook changed old booking item');
migrationSyncLogbookBasket($pdo,2);migrationCheck(count($logs($cart()))===1,'Restore duplicated basket');
$pdo->prepare('UPDATE baskets SET user_id=3 WHERE session_id=?')->execute([session_id()]);
migrationReject(fn()=>migrationChooseHorse($pdo,2,400,'keep',['name'=>'Wrong basket attempt']),'Another account basket was adopted');
$pdo->prepare('UPDATE baskets SET user_id=2 WHERE session_id=?')->execute([session_id()]);migrationCheck(count($logs($cart()))===1,'Rejected cross-account basket changed logbook lines');

migrationChooseHorse($pdo,2,400,'keep',['name'=>'First confirmed']);migrationCheck(count($logs($cart()))===0,'No purchase retained logbook line');
$details['name']='First confirmed';migrationChooseHorse($pdo,2,400,'logbook',$details);
$pdo->exec("UPDATE horse_logbook_types SET cost=8.50 WHERE id=900");migrationReject(fn()=>migrationChooseHorse($pdo,2,400,'logbook',$details),'Stale price quote accepted');
migrationCheck(isset(migrationSaveHorses($pdo,2,[])['horse_choice_400']),'Changed logbook price skipped review');
$offer=migrationLogbookOffer($pdo);$details['logbook_quote']=migrationLogbookQuote($offer);migrationChooseHorse($pdo,2,400,'logbook',$details);migrationCheck((float)$logs($cart())[0]['price']===8.5,'Newly confirmed price not used');
$pdo->exec("UPDATE horse_logbook_types SET status='draft' WHERE id=900");migrationReject(fn()=>migrationChooseHorse($pdo,2,400,'logbook',$details),'Unavailable logbook product was added');
$pdo->exec("UPDATE horse_logbook_types SET status='published' WHERE id=900");
// Current-year purchases are not added or duplicated, even when the user chooses Buy.
$pdo->prepare('INSERT INTO horse_logbook_purchases (purchased_by_user_id,horse_id,logbook_type_id,valid_year,amount,status) VALUES (2,401,900,?,8.50,?)')->execute([$year,'active']);
$second=$details;$second['name']='Second confirmed';migrationChooseHorse($pdo,2,401,'logbook',$second);
migrationCheck(count($logs($cart()))===1 && (int)$pdo->query('SELECT COUNT(*) FROM horse_logbook_purchases')->fetchColumn()===1,'Existing logbook was repurchased or recreated');
// Archive confirmation is required; failure to append its internal note must roll everything back.
migrationCheck(isset(migrationChooseHorse($pdo,2,400,'archive',[])['archive_confirmed']),'Archive without confirmation accepted');
$pdo->exec('ALTER TABLE record_notes ADD UNIQUE KEY fixture_note_once(record_type,record_id,action)');
$pdo->beginTransaction();appendRecordNote($pdo,'horse',400,'fixture@example.invalid','migration_horse_archived','Fixture conflict',2,bin2hex(random_bytes(16)));$pdo->commit();
migrationReject(fn()=>migrationChooseHorse($pdo,2,400,'archive',['archive_confirmed'=>1]),'Archive note failure ignored');
migrationCheck((int)$pdo->query('SELECT is_archived FROM horses WHERE id=400')->fetchColumn()===0 && count($logs($cart()))===1,'Archive failure partially changed horse/basket');
$pdo->exec('ALTER TABLE record_notes DROP INDEX fixture_note_once');$pdo->exec("DELETE FROM record_notes WHERE record_type='horse' AND record_id=400 AND note='Fixture conflict'");
migrationChooseHorse($pdo,2,400,'archive',['archive_confirmed'=>1]);migrationCheck((int)$pdo->query('SELECT is_archived FROM horses WHERE id=400')->fetchColumn()===1 && count($logs($cart()))===0,'Archive did not clear unpaid basket');
migrationCheck(count(recordNotesFind($pdo,['record_type'=>'horse','record_id'=>400,'action'=>'migration_horse_archived']))===1,'Archive note missing');
migrationReject(fn()=>migrationChooseHorse($pdo,2,400,'archive',['archive_confirmed'=>1]),'Archived horse action replayed');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM horse_logbook_purchases')->fetchColumn()===1,'Archive changed paid history');
// An ordinary account cannot Buy during purchasing maintenance; Keep remains available.
$uRole=(int)$pdo->query("SELECT id FROM roles WHERE name='user'")->fetchColumn();$uHash=password_hash('fixture',PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (id,email,password_hash,role_id) VALUES (4,'maintenance@example.invalid',?,?)")->execute([$uHash,$uRole]);
$pdo->exec("INSERT INTO horses (id,owner_user_id,name) VALUES (450,4,'Maintenance horse')");$pdo->exec("INSERT INTO migration_enrolments (user_id,person_id,current_step,started_at) VALUES (4,123,'horses',NOW())");
$_SESSION['user']=['id'=>4,'role'=>'user'];authBindCredentialSession(4,$uHash);session_regenerate_id(true);
$pdo->exec("UPDATE site_settings SET setting_value='1' WHERE setting_key='purchasing_paused_at'");
migrationReject(fn()=>migrationChooseHorse($pdo,4,450,'logbook',$details),'Paused purchase accepted');migrationCheck(migrationChooseHorse($pdo,4,450,'keep',['name'=>'Maintenance horse'])===[],'Paused purchase blocked Keep');
$pdo->exec("UPDATE site_settings SET setting_value='0' WHERE setting_key='purchasing_paused_at'");
$_SESSION['user']=['id'=>2,'role'=>'developer'];authBindCredentialSession(2,$new['password_hash']);session_regenerate_id(true);
migrationCheck(migrationSaveHorses($pdo,2,[])===[],'Resolved choices did not permit Next');migrationSaveShares($pdo,2);migrationFinish($pdo,2);
migrationCheck(!empty(migrationState($pdo,2)['completed_at']),'Completion failed after individual decisions');
foreach($preservedShares as $t=>$before)migrationCheck(json_encode($pdo->query("SELECT * FROM `$t`")->fetchAll())===$before,'Horse choices changed sharing records');
$reset=importWorkspacePlan($pdo,2);migrationCheck(isset($reset['delete']['migration_horse_choices']),'Reset misses per-horse choices');
echo "PASS: per-horse Buy/Keep/Archive, full buy validation, name-only retention, canonical price/year, atomic basket rollback, duplicate prevention, cross-session restoration, paid-history preservation, archive notes/rollback, ownership, purchasing pause, Next/Finish and reset policy. Temporary records only; no actual purchases.\n";
ob_end_flush();
