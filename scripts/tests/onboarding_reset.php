<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/auth.php';
require dirname(__DIR__,2).'/onboarding_reset.php';
function resetCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function resetReject(callable $action,string $message):void{try{$action();}catch(RuntimeException $e){return;}throw new LogicException($message);}
$a=[];$pdo=createPdo(require dirname(__DIR__,2).'/config.php',$a);if(!$pdo)throw new RuntimeException('Database unavailable');
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table){$q=str_replace('`','``',$table);$pdo->exec("CREATE TEMPORARY TABLE onboarding_reset_seed LIKE `$q`");$pdo->exec("INSERT INTO onboarding_reset_seed SELECT * FROM `$q`");$pdo->exec("CREATE TEMPORARY TABLE `$q` LIKE onboarding_reset_seed");$pdo->exec("INSERT INTO `$q` SELECT * FROM onboarding_reset_seed");$pdo->exec('DROP TEMPORARY TABLE onboarding_reset_seed');resetCheck(str_contains($pdo->query("SHOW CREATE TABLE `$q`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Unsafe fixture table');}
$config=importWorkspaceConfig();$keep=(int)$config['protected_user_id'];$actor=['id'=>$keep,'role'=>'developer'];$host='dev-ildra.witecanvas.com:28086';
resetCheck(importWorkspaceAuthorize($pdo,$actor,$config,$host)===$keep,'Protected fixture authorisation failed');
resetReject(fn()=>importWorkspaceAuthorize($pdo,['id'=>$keep,'role'=>'admin'],$config,$host),'Non-Developer authorised');
resetReject(fn()=>importWorkspaceAuthorize($pdo,$actor,$config,'enduranceridingireland.com'),'Live host authorised');
resetReject(fn()=>onboardingResetPlan($pdo,$keep,'unknown'),'Unknown reset mode accepted');
$role=(int)$pdo->query("SELECT id FROM roles WHERE name='user' LIMIT 1")->fetchColumn();
$pdo->prepare('INSERT INTO users(email,password_hash,role_id,first_name,last_name) VALUES (?,?,?,?,?)')->execute(['reset-active@example.invalid',password_hash('fixture active password',PASSWORD_DEFAULT),$role,'Fresh','Identity']);$active=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users(email,password_hash,role_id,first_name,last_name,is_archived) VALUES (?,?,?,?,?,1)')->execute(['reset-archived@example.invalid',password_hash('fixture archived password',PASSWORD_DEFAULT),$role,'Archived','Identity']);$archived=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO people(owner_user_id,first_name,last_name,email,phone,address) VALUES (?,?,?,?,'01234 567890','Old private address')")->execute([$active,'Old','Person','old@example.invalid']);
foreach(['credit','debit'] as $direction)$pdo->prepare("INSERT INTO finance_transactions(user_id,type,direction,amount) VALUES (?,'fixture',?,25)")->execute([$active,$direction]);
$pdo->prepare('INSERT INTO user_credits(user_id,balance) VALUES (?,12.50)')->execute([$active]);
$beforeUsers=$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();$protected=$pdo->query('SELECT * FROM users WHERE id='.$keep)->fetch();
$preserved=[];foreach(['events','venues','event_ride_notes','news_articles','news_content_sections','media_batches','media_batch_images','event_pricing_rows','event_entry_components','ride_classes'] as $table)if(in_array($table,$tables,true))$preserved[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
$plan=onboardingResetPlan($pdo,$keep,'keep_users');
resetCheck(!isset($plan['delete']['users'])&&isset($plan['delete']['finance_transactions'])&&isset($plan['delete']['user_credits']),'Keep-users plan wrong');
resetCheck(!isset($plan['delete']['events'])&&!isset($plan['delete']['event_ride_notes'])&&!isset($plan['delete']['venues']),'Content in deletion plan');
$snapshot=importWorkspaceSnapshot($pdo,$plan);$pdo->beginTransaction();onboardingResetApply($pdo,$plan);
resetCheck($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()===$beforeUsers,'Retained users changed');
resetCheck((int)$pdo->query('SELECT COUNT(*) FROM people WHERE owner_user_id='.$archived)->fetchColumn()===0,'Archived user got fresh person');
$person=$pdo->query('SELECT * FROM people WHERE owner_user_id='.$active)->fetch();
resetCheck($person['first_name']==='Fresh'&&$person['last_name']==='Identity'&&$person['email']==='reset-active@example.invalid'&&empty($person['phone'])&&empty($person['address'])&&empty($person['member_number'])&&empty($person['general_email_opt_in']),'Fresh person retained old details');
foreach($preserved as $table=>$rows)resetCheck($pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll()===$rows,'Preserved content changed: '.$table);
foreach($plan['delete'] as $table=>$where)if($table!=='people')resetCheck((int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn()===0,'Operational data remains: '.$table);
$pdo->rollBack();resetCheck(importWorkspaceSnapshot($pdo,$plan)===$snapshot,'Rollback did not restore state');
$preview=['actor_id'=>$keep,'time'=>time(),'plan'=>$plan,'snapshot'=>$snapshot];
$pdo->exec("UPDATE users SET first_name='Changed after preview' WHERE id=$active");resetReject(fn()=>onboardingResetRun($pdo,$actor,$config,$host,$preview),'Stale retained-user preview accepted');
$originalUser=array_values(array_filter($beforeUsers,fn($row)=>(int)$row['id']===$active))[0];$pdo->prepare('UPDATE users SET first_name=?,updated_at=? WHERE id=?')->execute([$originalUser['first_name'],$originalUser['updated_at'],$active]);
$preview['snapshot']=importWorkspaceSnapshot($pdo,$plan);
$expired=$preview;$expired['time']=time()-901;resetReject(fn()=>onboardingResetRun($pdo,$actor,$config,$host,$expired),'Expired preview accepted');
$preview['time']=time();$backup=onboardingResetRun($pdo,$actor,$config,$host,$preview);$path=dirname(__DIR__,3).'/private/import-workspace-backups/'.$backup;
try{$lines=file($path,FILE_IGNORE_NEW_LINES);resetCheck((fileperms($path)&0777)===0600&&json_decode(end($lines),true)['backup_complete']===true,'Complete private backup missing');resetCheck($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()===$beforeUsers,'Backed-up reset changed users');}finally{unlink($path);}
$plan=onboardingResetPlan($pdo,$keep,'replace_users');$preview=['actor_id'=>$keep,'time'=>time(),'plan'=>$plan,'snapshot'=>importWorkspaceSnapshot($pdo,$plan)];
$backup=onboardingResetRun($pdo,$actor,$config,$host,$preview);$path=dirname(__DIR__,3).'/private/import-workspace-backups/'.$backup;
try{resetCheck((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1&&$pdo->query('SELECT * FROM users WHERE id='.$keep)->fetch()===$protected,'Protected-only reset failed');resetCheck((int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn()===0,'Replace-users reset recreated people');foreach($preserved as $table=>$rows){$actual=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();$columns=array_keys($plan['updates'][$table]??[]);foreach($rows as &$row)foreach($columns as $column)unset($row[$column]);unset($row);foreach($actual as &$row)foreach($columns as $column)unset($row[$column]);unset($row);resetCheck($actual===$rows,'Content changed during replace-users reset: '.$table);}foreach(testingResetCounts($pdo,$plan) as $count)resetCheck($count===0,'Data remained after reset');}finally{unlink($path);}
// Render the actual reset panel, without running its authenticated controller.
ini_set('session.save_path','/tmp');session_start();
$resetAvailable=true;
if(!empty($argv[1])&&is_dir($argv[1])&&str_starts_with(realpath($argv[1]),'/tmp/'))foreach(onboardingResetModes() as $mode=>$definition){$plan=onboardingResetPlan($pdo,$keep,$mode);$resetPreview=['token'=>'synthetic-preview-token','plan'=>$plan,'snapshot'=>importWorkspaceSnapshot($pdo,$plan)];ob_start();require dirname(__DIR__,2).'/admin/onboarding_reset_panel.php';$html=ob_get_clean();file_put_contents($argv[1].'/'.$mode.'.html',$html);chmod($argv[1].'/'.$mode.'.html',0600);}
echo "PASS: both reset modes, minimal-person recreation, user/password preservation, content/image retention, finance/shares/onboarding cleanup, rollback, stale/expired previews, dev-only access and full private backups. Temporary tables only; no real records reset.\n";
