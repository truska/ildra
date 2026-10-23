<?php
declare(strict_types=1);

function legacyBalanceEmail(string $email): string { return strtolower(trim($email)); }
function legacyBalancePence(string $value): int
{
    if (!preg_match('/^(-?)([0-9]{1,9})\.([0-9]{2})$/D',trim($value),$m)) throw new RuntimeException('Balances must have exactly two decimal places.');
    return ($m[1]==='-'?-1:1)*((int)$m[2]*100+(int)$m[3]);
}
function legacyBalanceDecimal(int $pence): string { return ($pence<0?'-':'').intdiv(abs($pence),100).'.'.str_pad((string)(abs($pence)%100),2,'0',STR_PAD_LEFT); }
function legacyBalanceSchema(PDO $pdo): void
{
    $sql=file_get_contents(__DIR__.'/migrations/2026-10-23-legacy-opening-balances.sql');
    foreach(preg_split('/;\s*(?:\R|$)/',$sql) as $statement) if(trim($statement)!=='')$pdo->exec($statement);
}
function legacyBalancesAvailable(PDO $pdo): bool
{
    try { $pdo->query('SELECT 1 FROM legacy_balances LIMIT 0');$pdo->query('SELECT 1 FROM legacy_balance_verifications LIMIT 0');return true; }
    catch(PDOException $e){return false;}
}
function legacyBalanceParse(string $path): array
{
    if(!is_file($path)||filesize($path)>10*1024*1024)throw new RuntimeException('Source CSV is missing or larger than 10 MB.');
    $bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('Source CSV could not be opened.');
    $handle=fopen('php://temp','w+b');fwrite($handle,preg_replace('/^\xEF\xBB\xBF/','',$bytes));rewind($handle);
    try{
        $header=fgetcsv($handle,0,';','"','');
        if(!$header)throw new RuntimeException('Source CSV is empty.');
        $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
        if($header!==['id','email','name','balance'])throw new RuntimeException('Expected CSV columns: id;email;name;balance.');
        $rows=[];$seenIds=[];$seenEmails=[];$credits=0;$debits=0;$line=1;
        while(($row=fgetcsv($handle,0,';','"',''))!==false){
            $line++;if($row===[null])continue;
            if(count($row)!==4)throw new RuntimeException('Invalid number of columns at CSV row '.$line.'.');
            [$id,$email,$name,$balance]=array_map('trim',$row);$email=legacyBalanceEmail($email);
            if(!ctype_digit($id)||strlen($id)>64||trim($id,'0')==='')throw new RuntimeException('Invalid source ID at CSV row '.$line.'.');
            $id=ltrim($id,'0');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Invalid email at CSV row '.$line.'.');
            if(!mb_check_encoding($name,'UTF-8')||mb_strlen($name)>255)throw new RuntimeException('Invalid name at CSV row '.$line.'.');
            if(isset($seenIds[$id])||isset($seenEmails[$email]))throw new RuntimeException('Duplicate source ID or email at CSV row '.$line.'. Reconcile duplicates before import.');
            $seenIds[$id]=$seenEmails[$email]=true;$source=legacyBalancePence($balance);$opening=-$source;
            if($opening===0)throw new RuntimeException('Zero balance at CSV row '.$line.'.');
            if($opening>0)$credits+=$opening;else $debits+=-$opening;
            $rows[]=['source_id'=>$id,'email'=>$email,'source_name'=>strtoupper($name)==='NULL'?'':$name,'source_balance'=>legacyBalanceDecimal($source),'opening_amount'=>legacyBalanceDecimal($opening)];
        }
        if(!$rows)throw new RuntimeException('No balances were found in the CSV.');
        return ['rows'=>$rows,'credits'=>$credits,'debits'=>$debits,'net'=>$credits-$debits,'sha256'=>hash('sha256',$bytes),'file_name'=>basename($path)];
    }finally{fclose($handle);}
}
function legacyBalanceImport(PDO $pdo,array $preview,array $actor,string $source='ildra-legacy'): array
{
    if(!in_array(strtolower((string)($actor['role']??'')),['developer','superadmin','admin'],true))throw new RuntimeException('Only finance administrators can import balances.');
    if(!preg_match('/^[a-z0-9-]{1,64}$/D',$source))throw new RuntimeException('Invalid source system.');
    $pdo->beginTransaction();
    try{
        $batch=$pdo->prepare('INSERT INTO legacy_balance_imports(source_system,file_sha256,file_name,row_count,imported_by) VALUES(:source,:sha,:name,:count,:actor) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $batch->execute([':source'=>$source,':sha'=>$preview['sha256'],':name'=>$preview['file_name'],':count'=>count($preview['rows']),':actor'=>(int)$actor['id']]);$importId=(int)$pdo->lastInsertId();
        $added=0;$unchanged=0;
        foreach($preview['rows']as$row){
            $find=$pdo->prepare('SELECT * FROM legacy_balances WHERE source_system=:source AND source_id=:source_id FOR UPDATE');$find->execute([':source'=>$source,':source_id'=>$row['source_id']]);$existing=$find->fetch();
            if($existing){
                foreach(['email','source_name','source_balance','opening_amount']as$key)if((string)($existing[$key]??'')!==$row[$key])throw new RuntimeException('Source record '.$row['source_id'].' differs from an earlier import. Existing balances have not been overwritten.');
                $unchanged++;continue;
            }
            $insert=$pdo->prepare('INSERT INTO legacy_balances(import_id,source_system,source_id,email,source_name,source_balance,opening_amount) VALUES(:batch,:source,:source_id,:email,:name,:balance,:opening)');
            $insert->execute([':batch'=>$importId,':source'=>$source,':source_id'=>$row['source_id'],':email'=>$row['email'],':name'=>$row['source_name'],':balance'=>$row['source_balance'],':opening'=>$row['opening_amount']]);$added++;
        }
        $pdo->commit();return compact('added','unchanged');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function legacyBalanceAllocate(PDO $pdo,int $legacyId,int $userId,?array $actor=null): array
{
    if($actor!==null&&!in_array(strtolower((string)($actor['role']??'')),['developer','superadmin','admin'],true))throw new RuntimeException('Only finance administrators can manually allocate balances.');
    $pdo->beginTransaction();
    try{
        $find=$pdo->prepare('SELECT id,email,is_archived FROM users WHERE id=:id FOR UPDATE');$find->execute([':id'=>$userId]);$user=$find->fetch();
        if(!$user||!empty($user['is_archived']))throw new RuntimeException('Select an active account.');
        $find=$pdo->prepare('SELECT * FROM legacy_balances WHERE id=:id FOR UPDATE');$find->execute([':id'=>$legacyId]);$row=$find->fetch();
        if(!$row)throw new RuntimeException('Opening balance not found.');
        if(!empty($row['allocated_user_id'])){if((int)$row['allocated_user_id']!==$userId)throw new RuntimeException('This balance has already been allocated to another account.');$pdo->commit();return $row;}
        if($actor===null){
            $email=legacyBalanceEmail((string)$user['email']);
            if($email!==$row['email'])throw new RuntimeException('The account email does not match this balance.');
            $verified=$pdo->prepare('SELECT id FROM legacy_balance_verifications WHERE user_id=:user AND email=:email AND verified_at IS NOT NULL');$verified->execute([':user'=>$userId,':email'=>$email]);
            if(!$verified->fetchColumn())throw new RuntimeException('Confirm your email address before transferring your opening balance.');
        }
        $amount=legacyBalancePence((string)$row['opening_amount']);
        $pdo->prepare('INSERT IGNORE INTO user_credits(user_id,balance) VALUES(:user,0)')->execute([':user'=>$userId]);
        $balance=$pdo->prepare('SELECT balance FROM user_credits WHERE user_id=:user FOR UPDATE');$balance->execute([':user'=>$userId]);$after=legacyBalancePence((string)$balance->fetchColumn())+$amount;
        $pdo->prepare('UPDATE user_credits SET balance=:balance,updated_at=NOW() WHERE user_id=:user')->execute([':balance'=>legacyBalanceDecimal($after),':user'=>$userId]);
        $meta=['legacy_balance_id'=>$legacyId,'legacy_source_system'=>$row['source_system'],'legacy_source_id'=>$row['source_id'],'legacy_source_balance'=>$row['source_balance'],'opening_balance'=>true,'allocation_method'=>$actor===null?'email':'manual','actor'=>$actor['email']??'verified email match'];
        $insert=$pdo->prepare('INSERT INTO finance_transactions(user_id,type,direction,amount,balance_after,reference,notes,metadata) VALUES(:user,:type,:direction,:amount,:balance,:reference,:notes,:metadata)');
        $insert->execute([':user'=>$userId,':type'=>$amount>0?'manual_credit':'manual_debit',':direction'=>$amount>0?'credit':'debit',':amount'=>$row['opening_amount'],':balance'=>legacyBalanceDecimal($after),':reference'=>'legacy-opening-'.$legacyId,':notes'=>'Opening balance transferred from previous ILDRA system',':metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)]);$transactionId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE legacy_balances SET allocated_user_id=:user,finance_transaction_id=:transaction,allocated_by=:actor,allocation_method=:method,allocated_at=NOW() WHERE id=:id')->execute([':user'=>$userId,':transaction'=>$transactionId,':actor'=>$actor['id']??null,':method'=>$actor===null?'email':'manual',':id'=>$legacyId]);
        $pdo->commit();$row['allocated_user_id']=$userId;$row['finance_transaction_id']=$transactionId;return $row;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function legacyBalanceAutoAllocate(PDO $pdo,array $user): void
{
    $email=legacyBalanceEmail((string)$user['email']);
    $verified=$pdo->prepare('SELECT id FROM legacy_balance_verifications WHERE user_id=:user AND email=:email AND verified_at IS NOT NULL');$verified->execute([':user'=>(int)$user['id'],':email'=>$email]);
    if(!$verified->fetchColumn())return;
    $find=$pdo->prepare('SELECT id FROM legacy_balances WHERE email=:email AND allocated_user_id IS NULL ORDER BY id');$find->execute([':email'=>$email]);
    foreach($find->fetchAll(PDO::FETCH_COLUMN)as$id)legacyBalanceAllocate($pdo,(int)$id,(int)$user['id']);
}
function legacyBalancePending(PDO $pdo,array $user): bool
{
    $find=$pdo->prepare('SELECT id FROM legacy_balances WHERE email=:email AND allocated_user_id IS NULL LIMIT 1');$find->execute([':email'=>legacyBalanceEmail((string)$user['email'])]);return (bool)$find->fetchColumn();
}
function legacyBalanceIssueToken(PDO $pdo,array $user): ?string
{
    if(!legacyBalancePending($pdo,$user))return null;
    $email=legacyBalanceEmail((string)$user['email']);
    $find=$pdo->prepare('SELECT sent_at,verified_at FROM legacy_balance_verifications WHERE user_id=:user AND email=:email');$find->execute([':user'=>(int)$user['id'],':email'=>$email]);$old=$find->fetch();
    if(!empty($old['verified_at'])){legacyBalanceAutoAllocate($pdo,$user);return null;}
    if(!empty($old['sent_at'])&&strtotime($old['sent_at'])>time()-60)throw new RuntimeException('Please wait a minute before requesting another confirmation email.');
    $token=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO legacy_balance_verifications(user_id,email,token_hash,expires_at,sent_at) VALUES(:user,:email,:hash,:expires,:sent) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),sent_at=VALUES(sent_at)')->execute([':user'=>(int)$user['id'],':email'=>$email,':hash'=>hash('sha256',$token),':expires'=>date('Y-m-d H:i:s',time()+86400),':sent'=>date('Y-m-d H:i:s')]);return $token;
}
function legacyBalanceVerify(PDO $pdo,array $user,string $token): void
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('This opening balance confirmation link is invalid.');
    $pdo->beginTransaction();
    try{
        $find=$pdo->prepare('SELECT email,is_archived FROM users WHERE id=:user FOR UPDATE');$find->execute([':user'=>(int)$user['id']]);$live=$find->fetch();
        $find=$pdo->prepare('SELECT * FROM legacy_balance_verifications WHERE user_id=:user AND token_hash=:hash FOR UPDATE');$find->execute([':user'=>(int)$user['id'],':hash'=>hash('sha256',$token)]);$row=$find->fetch();
        if(!$live||!empty($live['is_archived'])||!$row||$row['email']!==legacyBalanceEmail((string)$live['email'])||strtotime($row['expires_at'])<time())throw new RuntimeException('This confirmation link is expired or does not match the signed-in account.');
        $pdo->prepare('UPDATE legacy_balance_verifications SET verified_at=NOW(),token_hash=NULL WHERE id=:id')->execute([':id'=>$row['id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    legacyBalanceAutoAllocate($pdo,$user);
}
function legacyBalanceConfirmationUrl(string $siteBase,string $token,?array $server=null): string
{
    $server??=$_SERVER;
    $authority=(string)($server['HTTP_HOST']??$server['SERVER_NAME']??'');
    $host=strtolower((string)parse_url('http://'.$authority,PHP_URL_HOST));
    // Confirmation links must never be redirected through an arbitrary Host header.
    if(!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/iD',$authority)||!in_array($host,['dev-ildra.witecanvas.com','ildra.witecanvas.com','enduranceridingireland.com','www.enduranceridingireland.com','localhost','127.0.0.1'],true))throw new RuntimeException('The confirmation email could not be prepared for this site.');
    $secure=(!empty($server['HTTPS'])&&$server['HTTPS']!=='off')||(string)($server['SERVER_PORT']??'')==='443'||strtolower(trim(explode(',',(string)($server['HTTP_X_FORWARDED_PROTO']??''))[0]))==='https';
    return ($secure?'https':'http').'://'.$authority.rtrim($siteBase,'/').'/account?legacy_confirm='.rawurlencode($token);
}
function legacyBalanceSendConfirmation(PDO $pdo,array $user,string $siteBase): bool
{
    $token=legacyBalanceIssueToken($pdo,$user);if($token===null)return false;
    $url=legacyBalanceConfirmationUrl($siteBase,$token);
    $settings=getEmailSettings($pdo);$site=getSiteSettings($pdo);
    $body='<p>We found an opening balance for this email address from the previous ILDRA system.</p><p>Confirm your email to transfer it to your new account. Sign in to the same account before opening this link.</p>'.email_cta_button_html($url,'Confirm opening balance transfer').'<p>This link expires in 24 hours. If you did not request it, you can ignore this email.</p>';
    return send_logged_email($pdo,(string)$user['email'],'Confirm your ILDRA opening balance',wrap_user_email_html($site,$settings,$body),wrap_user_email_text($site,$settings,"Confirm your email to transfer your opening balance from the previous ILDRA system. Sign in to the same account, then open this link within 24 hours:\n".$url),['kind'=>'legacy_balance_confirmation','user_id'=>(int)$user['id']]);
}
