<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/migration_rollout.php';

function readHorseCsv(string $path): array
{
    $real=realpath($path);$private=realpath(dirname(__DIR__,2).'/private');
    if(!$real||!$private||!str_starts_with($real,$private.'/'))throw new RuntimeException('Horse CSV must be inside the private directory.');
    $bytes=file_get_contents($real);if($bytes===false || !preg_match('//u',$bytes))throw new RuntimeException('Readable UTF-8 CSV required.');
    $f=fopen('php://temp','w+');fwrite($f,$bytes);rewind($f);
    if(fread($f,3)!=="\xEF\xBB\xBF")rewind($f);
    try{
        $header=fgetcsv($f,0,',','"','');$aliases=['horse name'=>'name','name'=>'name','email address'=>'email','email'=>'email'];
        $header=array_map(static fn($v)=>$aliases[strtolower(trim($v))]??'', $header?:[]);$sorted=$header;sort($sorted);
        if($sorted!==['email','name'])throw new RuntimeException('Expected Horse name and Email address headers.');
        $rows=[];$seen=[];$names=[];$line=1;
        while(($values=fgetcsv($f,0,',','"',''))!==false){
            ++$line;if($values===[null])continue;
            if(count($values)!==2)throw new RuntimeException('Invalid column count at row '.$line);
            $row=array_combine($header,array_map('trim',$values));$row['email']=strtolower($row['email']);$row['row']=$line;
            if($row['name']==='' || mb_strlen($row['name'])>255 || preg_match('/[\x00-\x1f\x7f]/',$row['name']) || strlen($row['email'])>255 || !filter_var($row['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid horse/email at row '.$line);
            $normalName=mb_strtolower($row['name'],'UTF-8');$key=$row['email']."\0".$normalName;if(isset($seen[$key])||isset($names[$normalName]))throw new RuntimeException('Repeated horse name/account needs review at row '.$line);$seen[$key]=true;$names[$normalName]=true;$rows[]=$row;
        }
        if(!$rows)throw new RuntimeException('No horse rows.');
        return ['path'=>$real,'sha256'=>hash('sha256',$bytes),'rows'=>$rows];
    }finally{fclose($f);}
}

/** Names are conflict signals only. Reruns use the private receipt's immutable target IDs. */
function previewHorseCsv(PDO $pdo,array $delivery,?array $receipt=null):array
{
    if($receipt && (($receipt['source_sha256']??'')!==$delivery['sha256'] || count($receipt['records']??[])!==count($delivery['rows'])))throw new RuntimeException('Import receipt does not match this source.');
    $plan=[];
    foreach($delivery['rows'] as $index=>$source){
        $mapping=$receipt['records'][$index]??null;$horse=null;
        if($mapping){
            if(($mapping['source']??null)!==$source)throw new RuntimeException('Receipt row changed; review required.');
            $s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([(int)$mapping['user_id']]);$users=$s->fetchAll();
            $s=$pdo->prepare('SELECT * FROM horses WHERE id=?');$s->execute([(int)$mapping['horse_id']]);$horse=$s->fetch();
            if(count($users)!==1 || !$horse || (int)$horse['id']===1 || (int)$horse['owner_user_id']!==(int)$mapping['user_id'] || $horse['created_at']!==$mapping['horse_created_at'])throw new RuntimeException('Mapped target missing or ownership changed at row '.$source['row'].'. Review the receipt; do not recreate automatically.');
        }else{
            $s=$pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email))=?');$s->execute([$source['email']]);$users=$s->fetchAll();
            if(count($users)!==1 || !empty($users[0]['is_archived']))throw new RuntimeException('Email must match exactly one active account at row '.$source['row']);
            $s=$pdo->prepare('SELECT id FROM horses WHERE LOWER(TRIM(name))=LOWER(?)');$s->execute([$source['name']]);
            if($s->fetch())throw new RuntimeException('Existing horse name needs review at row '.$source['row'].'. No name-only merge or duplicate creation is allowed.');
        }
        $plan[]=['source'=>$source,'user'=>$users[0],'horse'=>$horse];
    }
    return $plan;
}

/** No schema setup, credentials, person records, memberships or logbook purchases are changed. */
function applyHorseCsv(PDO $pdo,array $plan):array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Horse import requires a transaction.');
    $result=[];
    foreach($plan as $p){
        $uid=(int)$p['user']['id'];$horse=$p['horse'];
        if(!$horse){
            $s=$pdo->prepare('INSERT INTO horses (owner_user_id,name) VALUES (?,?)');$s->execute([$uid,$p['source']['name']]);$hid=(int)$pdo->lastInsertId();
            $s=$pdo->prepare('SELECT * FROM horses WHERE id=?');$s->execute([$hid]);$horse=$s->fetch();
            if(!$horse || $hid===1 || (int)$horse['owner_user_id']!==$uid || $horse['name']!==$p['source']['name'])throw new RuntimeException('Imported horse verification failed.');
            foreach(['owner_name','dob','year_of_birth','breed','colour','qualification_id','passport_issuer','passport_number','sex','height_cm','flu_vac_date'] as $field)if($horse[$field]!==null)throw new RuntimeException('Unexpected default horse detail: '.$field);
        }
        $s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([$uid]);if($s->fetch()!==$p['user'])throw new RuntimeException('Existing user changed during horse import.');
        $result[]=['source'=>$p['source'],'user_id'=>$uid,'horse_id'=>(int)$horse['id'],'horse_created_at'=>$horse['created_at']];
    }
    return $result;
}

function horseImportTableHash(PDO $pdo,string $table):string
{
    $hashes=[];foreach($pdo->query('SELECT * FROM `'.str_replace('`','``',$table).'`') as $row)$hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));sort($hashes);return hash('sha256',implode('',$hashes));
}

function writePrivateHorseJson(string $path,array $data):void
{
    $f=fopen($path,'x');if(!$f)throw new RuntimeException('Cannot create private receipt.');
    try{
        if(!chmod($path,0600))throw new RuntimeException('Private receipt permissions failed.');
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        if(fwrite($f,$json)!==strlen($json)||!fflush($f)||!fsync($f))throw new RuntimeException('Private receipt flush failed.');
    }catch(Throwable $e){unlink($path);throw $e;}finally{fclose($f);}
}

function runHorseCsv(array $argv):void
{
    $path=$argv[1]??'';$apply=($argv[2]??'')==='--apply';
    if(!$path || (isset($argv[2])&&!$apply) || count($argv)>3)throw new RuntimeException('Usage: php scripts/import_horses.php /private/file.csv [--apply]');
    $delivery=readHorseCsv($path);$alerts=[];$pdo=createPdo(require dirname(__DIR__,2).'/private/config.php',$alerts);if(!$pdo)throw new RuntimeException('Database unavailable.');
    $config=migrationAuthorizeImport($pdo);
    $dir=dirname(__DIR__,2).'/private/import-workspace-backups';$receiptPath=$dir.'/horse-source-'.$delivery['sha256'].'.json';
    $readReceipt=static function()use($receiptPath):?array{return is_file($receiptPath)?json_decode(file_get_contents($receiptPath),true,512,JSON_THROW_ON_ERROR):null;};
    $plan=previewHorseCsv($pdo,$delivery,$readReceipt());
    foreach($plan as $p)echo json_encode(['row'=>$p['source']['row'],'horse'=>$p['source']['name'],'managing_account'=>$p['source']['email'],'action'=>$p['horse']?'preserve mapped horse #'.$p['horse']['id']:'create horse','user_id'=>(int)$p['user']['id']],JSON_THROW_ON_ERROR)."\n";
    if(!$apply)return;
    if(!array_filter($plan,static fn($p)=>!$p['horse'])){echo "All source rows are already mapped; no changes made.\n";return;}
    if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Private backup directory unavailable.');
    if((int)$pdo->query("SELECT GET_LOCK('ildra-import-reset',0)")->fetchColumn()!==1)throw new RuntimeException('Another import/reset is running.');
    $f=null;$receiptWritten=false;
    try{
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        $tables=$pdo->query('SHOW TABLE STATUS')->fetchAll();
        foreach($tables as $table){
            if($table['Engine']!=='InnoDB')throw new RuntimeException('Full backup requires InnoDB tables.');
            $name=str_replace('`','``',$table['Name']);$s=$pdo->query("SELECT * FROM `$name` FOR UPDATE");while($s->fetch()){}
        }
        migrationAuthorizeImport($pdo);
        if(hash_file('sha256',$delivery['path'])!==$delivery['sha256'] || previewHorseCsv($pdo,$delivery,$readReceipt())!==$plan)throw new RuntimeException('Source or target changed since preview. Rerun.');
        $preserve=[];foreach($tables as $t)if($t['Name']!=='horses')$preserve[$t['Name']]=horseImportTableHash($pdo,$t['Name']);
        $existingHorses=$pdo->query('SELECT * FROM horses')->fetchAll();
        $backup=$dir.'/horses-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.jsonl';$f=fopen($backup,'x');if(!$f||!chmod($backup,0600))throw new RuntimeException('Private backup unavailable.');
        $write=static function(array $row)use($f):void{$line=json_encode($row,JSON_THROW_ON_ERROR)."\n";if(fwrite($f,$line)!==strlen($line))throw new RuntimeException('Backup write failed.');};
        $write(['format'=>'ildra-horse-import-v1','source_sha256'=>$delivery['sha256'],'created_at'=>date(DATE_ATOM)]);
        foreach($tables as $table){
            $name=str_replace('`','``',$table['Name']);$write(['table'=>$table['Name'],'schema'=>$pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1]]);
            foreach($pdo->query("SELECT * FROM `$name`") as $row)$write(['table'=>$table['Name'],'row'=>$row]);
        }
        $write(['backup_complete'=>true]);if(!fflush($f)||!fsync($f))throw new RuntimeException('Backup flush failed.');fclose($f);$f=null;
        $result=applyHorseCsv($pdo,$plan);
        foreach($preserve as $table=>$hash)if(horseImportTableHash($pdo,$table)!==$hash)throw new RuntimeException('Unrelated table changed: '.$table);
        foreach($existingHorses as $row){$s=$pdo->prepare('SELECT * FROM horses WHERE id=?');$s->execute([(int)$row['id']]);if($s->fetch()!==$row)throw new RuntimeException('An existing horse changed.');}
        writePrivateHorseJson($receiptPath,['format'=>'ildra-horse-source-map-v1','source_sha256'=>$delivery['sha256'],'backup'=>basename($backup),'records'=>$result]);$receiptWritten=true;
        $pdo->commit();
        echo 'Committed '.count(array_filter($plan,static fn($p)=>!$p['horse'])).' new horses. All other tables and existing horses unchanged. Backup: '.basename($backup)."\n";
    }catch(Throwable $e){
        $rolledBack=false;if($pdo->inTransaction()){$pdo->rollBack();$rolledBack=true;}
        if(is_resource($f))fclose($f);
        // Retain the mapping if commit outcome is unknown; target validation will require reconciliation.
        if($rolledBack&&$receiptWritten&&is_file($receiptPath))unlink($receiptPath);
        throw $e;
    }
    finally{$pdo->query("SELECT RELEASE_LOCK('ildra-import-reset')");}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){try{runHorseCsv($argv);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}}
