<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/import_horses.php';
function horseCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function horseReject(callable $operation,string $why):void{try{$operation();}catch(RuntimeException $e){return;}throw new LogicException($why);}
$a=[];$pdo=createPdo(require dirname(__DIR__,3).'/private/config.php',$a);if(!$pdo)throw new RuntimeException('Database unavailable.');
foreach(['users','horses'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE horse_fixture_seed LIKE `$table`");$pdo->exec("CREATE TEMPORARY TABLE `$table` LIKE horse_fixture_seed");$pdo->exec('DROP TEMPORARY TABLE horse_fixture_seed');
    horseCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Unshadowed import table');
}
$pdo->exec("INSERT INTO users (id,email,password_hash,role_id,first_name,last_name,is_protected) VALUES (2,'fixture@example.invalid','unchanged',4,'Fixture','User',1)");
$pdo->exec("INSERT INTO horses (id,owner_user_id,name) VALUES (1,0,'Not Registered')");
$usersHash=horseImportTableHash($pdo,'users');$system=$pdo->query('SELECT * FROM horses WHERE id=1')->fetch();
$path=dirname(__DIR__,3).'/private/horse-import-fixture-'.bin2hex(random_bytes(6)).'.csv';$receiptFile=$path.'.json';
try{
    file_put_contents($path,"\xEF\xBB\xBF\"Horse name\",\"Email address\"\n Fixture Horse , FIXTURE@EXAMPLE.INVALID \nSecond Horse,fixture@example.invalid\n");chmod($path,0600);
    $delivery=readHorseCsv($path);horseCheck($delivery['rows'][0]['name']==='Fixture Horse' && $delivery['rows'][0]['email']==='fixture@example.invalid','Normalisation/BOM failed');
    $plan=previewHorseCsv($pdo,$delivery);horseCheck(count($plan)===2 && $plan[0]['horse']===null,'New horse preview failed');
    horseReject(fn()=>applyHorseCsv($pdo,$plan),'Nontransactional horse import accepted');
    $bad=$plan;$bad[1]['user']['email']='unexpected@example.invalid';$pdo->beginTransaction();
    try{horseReject(fn()=>applyHorseCsv($pdo,$bad),'User preservation failure ignored');}finally{$pdo->rollBack();}
    horseCheck((int)$pdo->query('SELECT COUNT(*) FROM horses')->fetchColumn()===1,'Failed batch left partial horses');
    $pdo->beginTransaction();$records=applyHorseCsv($pdo,$plan);$pdo->commit();
    $imported=$pdo->query("SELECT name,owner_name FROM horses WHERE name='Fixture Horse'")->fetch();
    horseCheck($imported && $imported['name']==='Fixture Horse' && $imported['owner_name']===null,'Horse name confused with owner name');
    horseCheck(count($records)===2 && (int)$pdo->query('SELECT COUNT(*) FROM horses')->fetchColumn()===3,'Import count incorrect');
    horseCheck(horseImportTableHash($pdo,'users')===$usersHash,'Protected user altered');horseCheck($pdo->query('SELECT * FROM horses WHERE id=1')->fetch()===$system,'System horse altered');
    $receipt=['source_sha256'=>$delivery['sha256'],'records'=>$records];writePrivateHorseJson($receiptFile,$receipt);
    horseCheck((fileperms($receiptFile)&0777)===0600,'Receipt is not private');
    $id=$records[0]['horse_id'];$pdo->prepare("UPDATE horses SET name='Corrected by owner',breed='Updated breed',is_archived=1 WHERE id=?")->execute([$id]);
    $repeat=previewHorseCsv($pdo,$delivery,$receipt);horseCheck($repeat[0]['horse']['name']==='Corrected by owner','Receipt did not anchor corrected horse');
    $horsesHash=horseImportTableHash($pdo,'horses');$pdo->beginTransaction();applyHorseCsv($pdo,$repeat);$pdo->commit();horseCheck(horseImportTableHash($pdo,'horses')===$horsesHash,'Rerun overwrote corrections or revived archived horse');
    $pdo->prepare('UPDATE horses SET owner_user_id=999 WHERE id=?')->execute([$id]);horseReject(fn()=>previewHorseCsv($pdo,$delivery,$receipt),'Ownership transfer accepted silently');$pdo->prepare('UPDATE horses SET owner_user_id=2 WHERE id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM horses WHERE id=?')->execute([$id]);horseReject(fn()=>previewHorseCsv($pdo,$delivery,$receipt),'Missing mapped horse recreated after reset');
    $changed=$delivery;$changed['rows'][0]['email']='unknown@example.invalid';horseReject(fn()=>previewHorseCsv($pdo,$changed),'Unmatched account accepted');
    $changed=$delivery;$changed['rows'][0]['name']='Second Horse';horseReject(fn()=>previewHorseCsv($pdo,$changed),'Existing name merged without receipt');
    $pdo->exec('UPDATE users SET is_archived=1 WHERE id=2');horseReject(fn()=>previewHorseCsv($pdo,$delivery),'Archived owner accepted');
    file_put_contents($path,"Horse name,Email address\nSame,fixture@example.invalid\n same ,OTHER@example.invalid\n");horseReject(fn()=>readHorseCsv($path),'Repeated name across accounts accepted');
    file_put_contents($path,"Horse name,Email address\nHorse,invalid\n");horseReject(fn()=>readHorseCsv($path),'Invalid email accepted');
    file_put_contents($path,"Horse name,Email address\n\"Horse, Comma\",fixture@example.invalid\n");horseCheck(readHorseCsv($path)['rows'][0]['name']==='Horse, Comma','Quoted CSV name failed');
    echo "PASS: private UTF-8/BOM CSV, normalisation, ownership, duplicates/conflicts, atomic rollback, protected user/system horse, private receipt, stable IDs, corrected/archived reruns and missing-map refusal. Temporary tables only; no real horse/user/logbook mutations.\n";
}finally{if(is_file($path))unlink($path);if(is_file($receiptFile))unlink($receiptFile);}
