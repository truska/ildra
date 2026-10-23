<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/helpers.php';
require dirname(__DIR__,2).'/finance.php';
require dirname(__DIR__,2).'/misc_payments.php';

// Even the application's CREATE IF NOT EXISTS calls are redirected to temporary tables.
class ReceiptTestPDO extends PDO {
    public function exec(string $statement): int|false {
        $statement=preg_replace('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement);
        return parent::exec($statement);
    }
}
$config=require dirname(__DIR__,2).'/config.php';$db=$config['db'];
$pdo=new ReceiptTestPDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$alerts=[];ensure_finance_tables($pdo,$alerts);ensureMiscPaymentTables($pdo);
$insert=$pdo->prepare("INSERT INTO misc_payment_requests(id,request_token,recipient_email,description,amount,currency,status,stripe_session_id,event_id) VALUES (:id,:token,'test@example.invalid','Test request',20,'gbp','sent',:session,36)");
foreach([1,2]as$id)$insert->execute([':id'=>$id,':token'=>str_pad((string)$id,48,'0'),':session'=>'cs_test_'.$id]);
$session=['id'=>'cs_test_1','payment_status'=>'paid','amount_total'=>2000,'currency'=>'gbp','payment_intent'=>'pi_test_1','metadata'=>['misc_payment_request_id'=>1]];
$assert(miscPaymentComplete($pdo,$session,$alerts),'Paid request was not recorded');
$assert(miscPaymentComplete($pdo,$session,$alerts),'Repeat completion should succeed');
$assert((int)$pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===1,'Return/webhook replay duplicated receipt');
$tx=$pdo->query('SELECT * FROM finance_transactions')->fetch();$meta=json_decode($tx['metadata'],true);
$assert($tx['type']==='payment_stripe_misc'&&(float)$tx['amount']===20.0&&$tx['balance_after']===null,'Receipt must not change account credit');
$assert($meta['event_id']===36&&array_key_exists('stripe_fee',$meta)&&$meta['stripe_fee']===null,'Event linkage or unknown-fee marker lost');
$request=$pdo->query('SELECT * FROM misc_payment_requests WHERE id=1')->fetch();
$assert($request['status']==='paid'&&(int)$request['finance_transaction_id']===(int)$tx['id'],'Paid state and receipt did not commit together');
$session['id']='cs_test_2';$session['metadata']['misc_payment_request_id']=2;$session['amount_total']=2100;
$assert(!miscPaymentComplete($pdo,$session,$alerts),'Wrong payment amount accepted');
$session['amount_total']=2000;$session['currency']='eur';
$assert(!miscPaymentComplete($pdo,$session,$alerts),'Wrong currency accepted');
$session['currency']='gbp';$pdo->exec('ALTER TABLE finance_transactions DROP COLUMN notes');
$assert(!miscPaymentComplete($pdo,$session,$alerts),'Simulated insert failure should be reported');
$assert((int)$pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===1,'Failed receipt left partial ledger changes');
$assert($pdo->query('SELECT status FROM misc_payment_requests WHERE id=2')->fetchColumn()==='sent','Failed insert marked request paid');
echo "Payment Request receipts: atomic recording, replay protection, amount/currency checks, unknown fees and rollback passed (temporary MariaDB tables only).\n";
