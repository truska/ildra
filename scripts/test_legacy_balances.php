<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/helpers.php';
require dirname(__DIR__).'/finance.php';
require dirname(__DIR__).'/legacy_balances.php';
date_default_timezone_set('Europe/London');
function legacyCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function legacyReject(callable $action,string $message): void { try{$action();}catch(RuntimeException $e){return;}throw new RuntimeException($message); }
class LegacyTestPDO extends PDO {
    public function exec(string $statement):int|false {
        $statement=preg_replace('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement);
        return parent::exec($statement);
    }
}
$config=require dirname(__DIR__).'/config.php';$db=$config['db'];
$pdo=new LegacyTestPDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TEMPORARY TABLE users(id INT PRIMARY KEY,email VARCHAR(190),is_archived TINYINT DEFAULT 0)");
$pdo->exec("INSERT INTO users VALUES(1,'credit@example.invalid',0),(2,'debit@example.invalid',0),(3,'other@example.invalid',0),(4,'archived@example.invalid',1),(5,'automatic-debit@example.invalid',0)");
$alerts=[];ensure_finance_tables($pdo,$alerts);legacyBalanceSchema($pdo);
$path=tempnam(sys_get_temp_dir(),'legacy-csv-');
$source="\xEF\xBB\xBF\"id\";\"email\";\"name\";\"balance\"\r\n\"1\";\" CREDIT@example.invalid \";\"Credit Person\";\"-25.00\"\r\n\"2\";\"debit@example.invalid\";NULL;\"17.00\"\r\n";
file_put_contents($path,$source);chmod($path,0600);
try {
    $preview=legacyBalanceParse($path);
    legacyCheck($preview['credits']===2500&&$preview['debits']===1700&&$preview['net']===800,'Sign conversion or totals wrong');
    legacyCheck($preview['rows'][0]['email']==='credit@example.invalid'&&$preview['rows'][1]['source_name']==='','Normalization wrong');
    $actor=['id'=>9,'role'=>'developer','email'=>'admin@example.invalid'];
    legacyCheck(legacyBalanceImport($pdo,$preview,$actor)['added']===2,'Import failed');
    legacyCheck(legacyBalanceImport($pdo,$preview,$actor)['unchanged']===2,'Repeated file duplicated balances');
    legacyCheck((int)$pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===0,'Import must not credit accounts');
    $changed=$preview;$changed['sha256']=str_repeat('a',64);$changed['rows'][0]['opening_amount']='99.00';
    legacyReject(fn()=>legacyBalanceImport($pdo,$changed,$actor),'Changed imported balance overwritten');
    legacyCheck((int)$pdo->query('SELECT COUNT(*) FROM legacy_balance_imports')->fetchColumn()===1,'Rejected import left a batch');
    legacyReject(fn()=>legacyBalanceImport($pdo,$preview,['id'=>3,'role'=>'manager']),'Manager imported balances');
    legacyReject(fn()=>legacyBalanceAllocate($pdo,1,1),'Unverified email received credit');
    $creditUser=['id'=>1,'email'=>'credit@example.invalid'];$debitUser=['id'=>2,'email'=>'debit@example.invalid'];
    $token=legacyBalanceIssueToken($pdo,$creditUser);
    legacyCheck(strlen($token)===64,'Verification token missing');
    $stored=$pdo->query('SELECT token_hash FROM legacy_balance_verifications WHERE user_id=1')->fetchColumn();
    legacyCheck($stored===hash('sha256',$token)&&$stored!==$token,'Plaintext token was stored');
    legacyReject(fn()=>legacyBalanceVerify($pdo,$debitUser,$token),'Other account used confirmation token');
    legacyReject(fn()=>legacyBalanceIssueToken($pdo,$creditUser),'Confirmation email rate limit missing');
    legacyBalanceVerify($pdo,$creditUser,$token);
    legacyCheck((float)$pdo->query('SELECT balance FROM user_credits WHERE user_id=1')->fetchColumn()===25.0,'Credit not allocated');
    legacyBalanceAutoAllocate($pdo,$creditUser);legacyBalanceAllocate($pdo,1,1,$actor);
    legacyCheck((int)$pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===1,'Repeated claim duplicated opening balance');
    legacyReject(fn()=>legacyBalanceVerify($pdo,$creditUser,$token),'Used token accepted');
    legacyReject(fn()=>legacyBalanceAllocate($pdo,1,3,$actor),'Allocated balance reassigned');
    legacyReject(fn()=>legacyBalanceAllocate($pdo,2,2,['id'=>3,'role'=>'manager']),'Manager allocated balance');
    legacyReject(fn()=>legacyBalanceAllocate($pdo,2,4,$actor),'Archived account received balance');
    $pdo->exec('INSERT INTO user_credits(user_id,balance) VALUES(3,10)');
    legacyBalanceAllocate($pdo,2,3,$actor);
    legacyCheck((float)$pdo->query('SELECT balance FROM user_credits WHERE user_id=3')->fetchColumn()===-7.0,'Manual debit did not reduce existing balance');
    $tx=$pdo->query("SELECT * FROM finance_transactions WHERE user_id=3")->fetch();
    legacyCheck($tx['type']==='manual_debit'&&$tx['direction']==='debit'&&(float)$tx['amount']===-17.0&&(float)$tx['balance_after']===-7.0,'Debit ledger wrong');
    $meta=json_decode($tx['metadata'],true);legacyCheck($meta['opening_balance']===true&&!isset($meta['event_id'])&&!isset($meta['collection_scope']),'Opening balance wrongly allocated to Stripe fulfilment');
    $allocated=$pdo->query('SELECT * FROM legacy_balances WHERE id=2')->fetch();
    legacyCheck((int)$allocated['allocated_user_id']===3&&(int)$allocated['allocated_by']===9&&$allocated['allocation_method']==='manual'&&$allocated['notification_seen_at']===null,'Manual ownership/audit/notification missing');
    $pdo->exec("INSERT INTO legacy_balances(import_id,source_system,source_id,email,source_name,source_balance,opening_amount) VALUES(1,'ildra-legacy','5','automatic-debit@example.invalid','',7,-7)");
    $automaticDebit=['id'=>5,'email'=>'AUTOMATIC-DEBIT@example.invalid'];
    $debitToken=legacyBalanceIssueToken($pdo,$automaticDebit);legacyBalanceVerify($pdo,$automaticDebit,$debitToken);
    legacyCheck((float)$pdo->query('SELECT balance FROM user_credits WHERE user_id=5')->fetchColumn()===-7.0,'Verified debit was not automatically transferred');
    // Duplicate email/ID and malformed financial values are rejected before a write.
    file_put_contents($path,"id;email;name;balance\n1;a@example.invalid;A;-1.00\n2;A@example.invalid;B;-2.00\n");
    legacyReject(fn()=>legacyBalanceParse($path),'Duplicate email accepted');
    file_put_contents($path,"id;email;name;balance\n1;a@example.invalid;A;1.234\n");
    legacyReject(fn()=>legacyBalanceParse($path),'Malformed currency accepted');
    // Failure while inserting ledger transaction must roll back the account balance and allocation.
    $pdo->exec("INSERT INTO legacy_balances(import_id,source_system,source_id,email,source_name,source_balance,opening_amount) VALUES(1,'ildra-legacy','3','other@example.invalid','',-5,5)");
    $id=(int)$pdo->lastInsertId();$pdo->exec('ALTER TABLE finance_transactions DROP COLUMN notes');
    legacyReject(fn()=>legacyBalanceAllocate($pdo,$id,3,$actor),'Failed ledger insert succeeded');
    legacyCheck((float)$pdo->query('SELECT balance FROM user_credits WHERE user_id=3')->fetchColumn()===-7.0,'Failed allocation changed balance');
    legacyCheck($pdo->query('SELECT allocated_user_id FROM legacy_balances WHERE id='.$id)->fetchColumn()===null,'Failed allocation retained recipient');
    // Email-change and expired token checks use a fresh pending fixture.
    $pdo->exec('ALTER TABLE finance_transactions ADD COLUMN notes VARCHAR(255) NULL');
    $pdo->exec("INSERT INTO legacy_balances(import_id,source_system,source_id,email,source_name,source_balance,opening_amount) VALUES(1,'ildra-legacy','4','debit@example.invalid','',-3,3)");
    $token=legacyBalanceIssueToken($pdo,$debitUser);$pdo->exec("UPDATE users SET email='changed@example.invalid' WHERE id=2");
    legacyReject(fn()=>legacyBalanceVerify($pdo,$debitUser,$token),'Email change allowed stale claim');
    $pdo->exec("UPDATE users SET email='debit@example.invalid' WHERE id=2");$pdo->exec("UPDATE legacy_balance_verifications SET expires_at='2020-01-01' WHERE user_id=2");
    legacyReject(fn()=>legacyBalanceVerify($pdo,$debitUser,$token),'Expired token accepted');
    $url=legacyBalanceConfirmationUrl('',str_repeat('a',64),['HTTP_HOST'=>'ildra.witecanvas.com','HTTP_X_FORWARDED_PROTO'=>'https']);
    legacyCheck(str_starts_with($url,'https://ildra.witecanvas.com/account?legacy_confirm='),'Confirmation URL lost its HTTPS environment');
    legacyReject(fn()=>legacyBalanceConfirmationUrl('',str_repeat('a',64),['HTTP_HOST'=>'attacker.example']),'Untrusted confirmation origin accepted');
    // Exercise the actual checkout guard before it computes a Stripe/payment amount.
    $checkout=file_get_contents(dirname(__DIR__).'/checkout.php');
    $start=strpos($checkout,'if ($pdo && legacyBalancesAvailable($pdo))');$end=strpos($checkout,'$navItemEventsUrl',$start);
    $gate=substr($checkout,$start,$end-$start);
    $gate=str_replace("header('Location: '.\$basePath.'/account'); exit;", "return 'account';",$gate);
    $runGate=static function(array $currentUser)use($pdo,$gate):string{$alerts=[];$basePath='';return eval('return (function() use ($pdo,$currentUser,&$alerts,$basePath) {'.$gate.'return "checkout";})();');};
    $_SESSION=['basket'=>[['price'=>10]]];
    legacyCheck($runGate($debitUser)==='account'&&!empty($_SESSION['legacy_balance_return_checkout'])&&count($_SESSION['basket'])===1,'Unverified opening balance did not hold checkout and preserve the basket');
    unset($_SESSION['legacy_balance_return_checkout'],$_SESSION['legacy_balance_login_check']);
    legacyCheck($runGate($creditUser)==='checkout'&&$runGate($automaticDebit)==='checkout','Allocated balances prevented normal checkout');
    preg_match('/\$paymentDue = max\(0\.0, \$totalAmount - \$userBalance\);/',$checkout,$amountCalculation);
    $totalAmount=30.0;$userBalance=25.0;eval($amountCalculation[0]);legacyCheck($paymentDue===5.0,'Opening credit not included in checkout amount');
    $totalAmount=10.0;$userBalance=-7.0;eval($amountCalculation[0]);legacyCheck($paymentDue===17.0,'Opening debit not included in checkout amount');
    echo "Legacy balances: import preview/signs, repeat imports, verified matching, one-time allocation, manual debit, audit, rate limits, email changes and rollback passed (temporary tables; no emails).\n";
}finally{unlink($path);}
