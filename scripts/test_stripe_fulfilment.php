<?php
declare(strict_types=1);
require __DIR__.'/test_finance_monthly.php';
require dirname(__DIR__).'/helpers.php';
require dirname(__DIR__).'/finance_fulfilment.php';
require dirname(__DIR__).'/admin_menu.php';
$pdo->exec("$create events (id INTEGER,title TEXT,event_date TEXT)");
$pdo->exec("INSERT INTO events VALUES (36,'Shanes Castle','2027-01-03'),(37,'Other event','2027-02-01')");
$receipt=$pdo->prepare('INSERT INTO finance_transactions (id,type,amount,reference,metadata,created_at) VALUES (?,?,?,?,?,?)');
foreach([
    [100,'payment_stripe_misc',40,'cs_event',['event_id'=>36,'misc_payment_request_id'=>1000,'stripe_fee'=>1,'stripe_payment_intent'=>'pi_event'],'2027-04-01 12:00:00'],
    [101,'payment_stripe_misc',20,'cs_misc',['misc_payment_request_id'=>1001,'stripe_fee'=>0.6,'stripe_payment_intent'=>'pi_misc'],'2027-04-02 12:00:00'],
    [102,'payment_stripe_misc',10,'cs_item',['booking_item_id'=>3,'stripe_fee'=>0.3],'2027-04-01 12:00:00'],
    [103,'payment_stripe_misc',15,'cs_unknown',['stripe_payment_intent'=>'pi_unknown'],'2027-05-01 12:00:00'],
    [104,'payment_stripe_misc',12,'cs_orphan',['event_id'=>999,'stripe_fee'=>0.4],'2027-04-01 12:00:00'],
    [105,'payment_stripe',5,'NO-BOOKING',['stripe_fee'=>0.3],'2027-04-01 12:00:00'],
    [106,'stripe_payout',-5,'po_old',['event_id'=>36,'stripe_payout_id'=>'po_old','stripe_status'=>'pending'],'2027-04-01 12:00:00'],
    [107,'refund',-5,'cs_misc',['misc_payment_request_id'=>1001],'2027-05-10 12:00:00'],
]as$row)$receipt->execute([$row[0],$row[1],$row[2],$row[3],json_encode($row[4]),$row[5]]);
$data=finance_fulfilment_data($pdo);$result=finance_fulfilment_build($data['ledger'],$data['items'],$data['events']);
$event=$result['reports']['events'][36];$misc=$result['reports']['miscellaneous']['2027-04'];
check($event['payments']===8000 && $event['fees']===220 && $event['refunds']===3000,'Event entry and Payment Request totals wrong');
check($event['remaining']===4280 && $event['collected']===500,'Legacy event payouts not counted');
check($misc['payments']===2000 && $misc['fees']===60 && $misc['refunds']===500 && $misc['remaining']===1440,'Miscellaneous month/refund allocation wrong');
check($result['reports']['miscellaneous']['2027-05']['missing_fees']===1,'Missing miscellaneous fee not flagged');
check(array_sum(array_column($result['issues'],'amount'))===1700,'Unallocated receipt amount wrong');
check(count(array_filter($result['issues'],fn($issue)=>str_contains($issue['reason'],'fee missing')))===2,'Missing fee issues wrong'); // Existing UNKNOWN membership plus miscellaneous.
$createdBefore=$created;
$eventPayout=finance_month_collect($pdo,$config,'36',1000,str_repeat('3',32),'Event partial','admin',$api,'events');
$miscPayout=finance_month_collect($pdo,$config,'2027-04',1000,str_repeat('4',32),'Misc partial','admin',$api,'miscellaneous');
check($created===$createdBefore+2,'Event/misc collection not created');
check(finance_collection_reports($pdo,'events')[36]['remaining']===3280,'Event partial balance incorrect');
check(finance_collection_reports($pdo,'miscellaneous')['2027-04']['remaining']===440,'Misc partial balance incorrect');
check($eventPayout['metadata']['event_id']==='36' && !isset($eventPayout['metadata']['collection_month']),'Event payout metadata lost event link');
check($miscPayout['metadata']['collection_scope']==='miscellaneous' && $miscPayout['metadata']['collection_month']==='2027-04','Misc payout scope incorrect');
rejected(fn()=>finance_month_collect($pdo,$config,'2027-04',100,str_repeat('4',32),'','admin',$api,'memberships_logbooks'),'Request key reused across scopes');
rejected(fn()=>finance_month_collect($pdo,$config,'2027-05',100,str_repeat('5',32),'','admin',$api,'miscellaneous'),'Missing misc fee allowed collection');
// Totals must split mixed basket pennies exactly across all collection groups.
$odd=payment(300,'ODD',1.01,'2027-01-01 12:00:00',0.05);$odd['type']='payment_stripe';
$oddItems=[item(300,'ODD','membership',1),item(301,'ODD','horse_logbook',1),item(302,'ODD','ride',1,36),item(303,'ODD','ride',1,37)];
$oddMembers=finance_month_build([$odd],$oddItems,[],[]);
$oddEvents=finance_fulfilment_build([$odd],$oddItems,$data['events'])['reports']['events'];
check($oddMembers['2027-01']['payments']+array_sum(array_column($oddEvents,'payments'))===101,'Mixed allocation duplicates/loss of pennies');
check($oddMembers['2027-01']['fees']+array_sum(array_column($oddEvents,'fees'))===5,'Mixed fees duplicate/loss of pennies');
$odd['metadata']['stripe_payment_intent']='pi_duplicate';
$duplicate=$odd;$duplicate['id']=400;$duplicate['type']='payment_stripe_misc';$duplicate['reference']='cs_duplicate';$duplicate['metadata']['event_id']=36;
$duplicateGroups=finance_fulfilment_build([$odd,$duplicate],$oddItems,$data['events']);
check($duplicateGroups['reports']['events'][36]['reconciliation'],'Duplicate Stripe reference did not block event collection');
check(finance_month_build([$odd,$duplicate],$oddItems,[],[])['2027-01']['reconciliation'],'Duplicate Stripe reference across receipt routes did not block membership collection');
$feeApi=static fn($method,$path,$params)=>['ok'=>true,'data'=>['livemode'=>false,'latest_charge'=>['id'=>'ch_fee','balance_transaction'=>['id'=>'txn_fee','amount'=>2000,'currency'=>'gbp','fee'=>60,'net'=>1940]]]];
$meta=finance_receipt_fee($config,['amount'=>20,'metadata'=>['stripe_payment_intent'=>'pi_fee','event_id'=>36]],$feeApi);
check($meta['stripe_fee']===0.6 && $meta['stripe_net']===19.4 && $meta['event_id']===36,'Fee reconciliation discarded linkage or fee');
rejected(fn()=>finance_receipt_fee($config,['amount'=>21,'metadata'=>['stripe_payment_intent'=>'pi_fee']],$feeApi),'Mismatched Stripe receipt amount accepted');
rejected(fn()=>finance_receipt_fee(['secret_key'=>'sk_live_mock','currency'=>'gbp'],['amount'=>20,'metadata'=>['stripe_payment_intent'=>'pi_fee']],$feeApi),'Mismatched Stripe mode accepted');
check(adminMenuFixedRoles('stripe_fulfilment')===['superadmin','admin'],'Fulfilment menu too widely accessible');
$financeSource=file_get_contents(dirname(__DIR__).'/admin/finance.php');
check(!str_contains($financeSource,'data-finance-tab="events"') && !str_contains($financeSource,'data-finance-tab="memberships"'),'Collection tabs still on Finance');
echo "Stripe Fulfilment: event-linked/unlinked requests, fee reconciliation, refunds, legacy payouts, partial collections, scope isolation, exact allocation and menu separation passed.\n";
