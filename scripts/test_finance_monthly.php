<?php
declare(strict_types=1);
require dirname(__DIR__) . '/finance.php';
require dirname(__DIR__) . '/finance_monthly.php';
require dirname(__DIR__) . '/stripe.php';
date_default_timezone_set('UTC');

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
function payment(int $id, string $ref, float $amount, string $date, ?float $fee = 1): array
{
    return ['id'=>$id,'reference'=>$ref,'amount'=>$amount,'created_at'=>$date,'paid_timestamp'=>strtotime($date.' UTC'),'metadata'=>['stripe_fee'=>$fee,'membership_years'=>'2099']];
}
function item(int $id, string $ref, string $type, float $price, ?int $event = null): array
{
    return ['id'=>$id,'booking_ref'=>$ref,'booking_type'=>$type,'price'=>$price,'event_id'=>$event];
}
$payments = [payment(1,'MIXED',100,'2026-12-15 12:00:00',3),payment(2,'BOOK',20,'2027-01-02 12:00:00',1),payment(3,'UNKNOWN',10,'2027-02-01 12:00:00',null)];
$items = [item(1,'MIXED','membership',50),item(2,'MIXED','horse_logbook',20),item(3,'MIXED','ride',30,36),item(4,'BOOK','horse_logbook',20),item(5,'CREDIT-ONLY','membership',100),item(6,'UNKNOWN','membership',10)];
$adjustments = [['id'=>10,'reference'=>'MIXED','type'=>'entry_credit','amount'=>5,'created_at'=>'2027-03-01 10:00:00','metadata'=>['booking_item_id'=>1]],
    ['id'=>11,'reference'=>'MIXED','type'=>'entry_stripe_refund','amount'=>-30,'created_at'=>'2027-03-01 10:00:00','metadata'=>['booking_item_id'=>3]],
    ['id'=>12,'reference'=>'BOOK','type'=>'refund','amount'=>-1,'created_at'=>'2027-03-01 10:00:00','metadata'=>[]]];
$reports = finance_month_build($payments,$items,$adjustments,[]);
check($reports['2026-12']['membership']===5000 && $reports['2026-12']['horse_logbook']===2000,'Mixed basket allocation');
check($reports['2026-12']['payments']===7000 && $reports['2026-12']['fees']===210 && $reports['2026-12']['refunds']===500,'Payments, fees or late refunds allocated incorrectly');
check($reports['2026-12']['net']===6290,'December net incorrect');
check($reports['2027-01']['refunds']===100,'Booking refund missing');
check(!isset($reports['2099-01']) && count($reports)===3,'Product year or credit-only purchase incorrectly creates month');
$ambiguous = finance_month_build([$payments[0],payment(4,'MIXED',10,'2027-01-10 12:00:00')],$items,$adjustments,[]);
check($ambiguous['2026-12']['reconciliation'] && $ambiguous['2027-01']['reconciliation'],'Ambiguous refund month must block collection');
check($reports['2027-02']['missing_fees']===1,'Unknown fee treated as confirmed zero');
check(finance_month_key(strtotime('2026-03-31 23:30:00 UTC'))==='2026-04','BST boundary month incorrect');
check(finance_month_key(strtotime('2026-10-31 23:30:00 UTC'))==='2026-10','GMT boundary month incorrect');
check(!finance_month_valid('2026-13') && !finance_month_valid('2026-1') && finance_month_valid('2026-01'),'Month validation');
check(array_sum(finance_month_allocate(101,['membership'=>1,'horse_logbook'=>1,'other'=>1]))===101,'Penny allocation lost money');
$overpaid=finance_month_build([$payments[0]],$items,$adjustments,[['id'=>20,'amount'=>-65,'metadata'=>['collection_scope'=>'memberships_logbooks','collection_month'=>'2026-12','stripe_status'=>'paid']]]);
check($overpaid['2026-12']['remaining']===-210,'Refund shortfall must stay visible');

// Exercise actual SQL, reservations, duplicate submissions and Stripe outcomes with no network or persistent writes.
$mysql = in_array('--mysql', $argv ?? [], true);
if ($mysql) {
    require dirname(__DIR__) . '/db.php';
    $alerts = [];
    $pdo = createPdo(require dirname(__DIR__) . '/config.php', $alerts);
    if (!$pdo) throw new RuntimeException('Development test database unavailable.');
    $pdo->exec("SET time_zone='+00:00'");
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
    $pdo->sqliteCreateFunction('UNIX_TIMESTAMP',static fn($value)=>strtotime($value.' UTC'));
    $pdo->sqliteCreateFunction('CONCAT_WS',static fn($separator,...$values)=>implode($separator,array_filter($values,static fn($value)=>$value!==null)));
}
$create = $mysql ? 'CREATE TEMPORARY TABLE' : 'CREATE TABLE';
$idColumn = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$dateColumn = $mysql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : "TEXT DEFAULT (datetime('now'))";
$pdo->exec("$create users (id INTEGER,first_name TEXT,last_name TEXT,email TEXT)");
$pdo->exec("$create bookings (new_id INTEGER,booking_ref TEXT)");
$pdo->exec("$create booking_items (id INTEGER,booking_id INTEGER,booking_type TEXT,price DECIMAL(12,2),event_id INTEGER)");
$pdo->exec("$create finance_transactions (id $idColumn,user_id INTEGER,type VARCHAR(50),direction VARCHAR(10),amount DECIMAL(12,2),balance_after DECIMAL(12,2),reference VARCHAR(64),notes TEXT,metadata TEXT,created_at $dateColumn)");
$insert=$pdo->prepare('INSERT INTO finance_transactions (id,user_id,type,amount,reference,metadata,created_at) VALUES (?,1,?,?,?,?,?)');
foreach($payments as$p)$insert->execute([$p['id'],'payment_stripe',$p['amount'],$p['reference'],json_encode($p['metadata']),$p['created_at']]);
$bookingIds=['MIXED'=>1,'BOOK'=>2,'CREDIT-ONLY'=>3,'UNKNOWN'=>4];
foreach($bookingIds as$ref=>$id)$pdo->prepare('INSERT INTO bookings VALUES (?,?)')->execute([$id,$ref]);
foreach($items as$i)$pdo->prepare('INSERT INTO booking_items VALUES (?,?,?,?,?)')->execute([$i['id'],$bookingIds[$i['booking_ref']],$i['booking_type'],$i['price'],$i['event_id']]);
foreach($adjustments as$a)$insert->execute([$a['id'],$a['type'],$a['amount'],$a['reference'],json_encode($a['metadata']),$a['created_at']]);
check(finance_month_reports($pdo)['2026-12']['net']===6290,'Actual report SQL disagrees');
$config=['secret_key'=>'sk_test_mock','currency'=>'gbp'];
$created=0;$balance=100000;$calls=[];$remote=[];
$api=static function($method,$path,$params,$key)use(&$created,&$balance,&$calls,&$remote){
    $calls[]=[$method,$path,$params,$key];
    if($path==='/v1/balance')return ['ok'=>true,'data'=>['available'=>[['currency'=>'gbp','source_types'=>['card'=>$balance]]]]];
    if($method==='GET')return ['ok'=>true,'data'=>$remote[basename($path)]];
    $created++;$id='po_test_'.$created;
    $remote[$id]=['id'=>$id,'amount'=>$params['amount'],'currency'=>'gbp','livemode'=>false,'status'=>'pending','arrival_date'=>time()+86400];
    return ['ok'=>true,'data'=>$remote[$id]];
};
$key1=str_repeat('a',32);$key2=str_repeat('b',32);
$r=finance_month_collect($pdo,$config,'2026-12',2000,$key1,'First partial','admin@test.invalid',$api);
check($created===1 && $r['metadata']['stripe_status']==='pending','First partial failed');
check(finance_month_reports($pdo)['2026-12']['remaining']===4290,'Partial balance incorrect');
finance_month_collect($pdo,$config,'2026-12',2000,$key1,'Changed notes','admin@test.invalid',$api);
check($created===1,'Duplicate submission created another payout');
$r2=finance_month_collect($pdo,$config,'2026-12',4290,$key2,'Remainder','admin@test.invalid',$api);
check($created===2 && finance_month_reports($pdo)['2026-12']['remaining']===0,'Remainder failed');
rejected(fn()=>finance_month_collect($pdo,$config,'2026-12',1,str_repeat('c',32),'','admin',$api),'Overcollection accepted');
rejected(fn()=>finance_month_collect($pdo,['secret_key'=>'sk_live_mock'],'2027-01',1,str_repeat('d',32),'','admin',$api),'Live payout accepted');
rejected(fn()=>finance_month_collect($pdo,$config,'2027-02',1,str_repeat('d',32),'','admin',$api),'Unknown fees allowed payout');
$balance=50;
rejected(fn()=>finance_month_collect($pdo,$config,'2027-01',51,str_repeat('d',32),'','admin',$api),'Stripe balance limit ignored');
$balance=100000;
// Failed/cancelled payouts release funds only when confirmed by Stripe.
$remote[$r2['reference']]['status']='failed';
finance_month_collect($pdo,$config,'2026-12',0,$key2,'','admin',$api);
check(finance_month_reports($pdo)['2026-12']['remaining']===4290,'Failed payout did not release balance');
$definiteFailure=static fn($method,$path,$params,$key)=>$path==='/v1/balance'?['ok'=>true,'data'=>['available'=>[['currency'=>'gbp','source_types'=>['card'=>100000]]]]]:['ok'=>false,'status'=>400,'error'=>'Simulated rejection'];
$failed=finance_month_collect($pdo,$config,'2026-12',100,str_repeat('2',32),'','admin',$definiteFailure);
check(finance_month_metadata($failed)['stripe_status']==='failed' && finance_month_reports($pdo)['2026-12']['remaining']===4290,'Definite rejection should release reservation');
$timeout=static fn($method,$path,$params,$key)=>$path==='/v1/balance'?['ok'=>true,'data'=>['available'=>[['currency'=>'gbp','source_types'=>['card'=>100000]]]]]:['ok'=>false,'error'=>'Simulated timeout'];
$key3=str_repeat('e',32);
$unknown=finance_month_collect($pdo,$config,'2027-01',500,$key3,'Keep original notes','admin',$timeout);
$jan=finance_month_reports($pdo)['2027-01'];
check($jan['reserved']===500 && $unknown['metadata']['stripe_status']==='unknown','Timeout failed to reserve balance');
rejected(fn()=>finance_month_collect($pdo,$config,'2027-01',100,str_repeat('f',32),'','admin',$api),'Outstanding confirmation did not block another collection');
$retry=finance_month_collect($pdo,$config,'2027-01',999,$key3,'Different notes','admin',$api);
$last=end($calls);
check($last[2]['amount']===500 && $last[2]['metadata[admin_notes]']==='Keep original notes' && $last[3]==='ildra-month-'.$key3,'Retry changed stored Stripe parameters');
check(finance_month_reports($pdo)['2027-01']['reserved']===0,'Confirmed reservation not released');
// An uncertain request older than Stripe idempotency retention must not be reissued.
$key4=str_repeat('1',32);
finance_month_collect($pdo,$config,'2026-12',100,$key4,'','admin',$timeout);
$pdo->exec("UPDATE finance_transactions SET created_at='2020-01-01 00:00:00' WHERE reference='month-$key4'");
$before=count($calls);
rejected(fn()=>finance_month_collect($pdo,$config,'2026-12',100,$key4,'','admin',$api),'Old uncertain payout was resent');
check(count($calls)===$before,'Old uncertain payout contacted Stripe');
check($pdo->query('SELECT COUNT(*) FROM finance_transactions WHERE balance_after IS NOT NULL')->fetchColumn()==0,'Collection altered account credit');
echo "Monthly allocation, UK month boundaries, refunds, partial collections, duplicate requests, Stripe limits, failures and uncertain retries: passed.\n";
