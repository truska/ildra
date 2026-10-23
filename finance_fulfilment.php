<?php
declare(strict_types=1);
require_once __DIR__ . '/finance_monthly.php';

function finance_fulfilment_data(PDO $pdo): array
{
    $ledger = $pdo->query("SELECT ft.*,UNIX_TIMESTAMP(ft.created_at) AS paid_timestamp,UNIX_TIMESTAMP(ft.created_at) AS requested_timestamp,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.email) AS person_name
        FROM finance_transactions ft LEFT JOIN users u ON u.id=ft.user_id ORDER BY ft.created_at,ft.id")->fetchAll() ?: [];
    $items = $pdo->query('SELECT bi.*,b.booking_ref FROM booking_items bi JOIN bookings b ON b.new_id=bi.booking_id')->fetchAll() ?: [];
    $events = $pdo->query('SELECT id,title,event_date FROM events ORDER BY event_date DESC,id DESC')->fetchAll() ?: [];
    return compact('ledger','items','events');
}

function finance_fulfilment_build(array $ledger, array $items, array $events): array
{
    $reports = ['events'=>[], 'miscellaneous'=>[]];
    $issues = [];
    $byRef = []; $byId = []; $eventMap = []; $paymentMap = [];
    foreach ($events as $event) $eventMap[(int)$event['id']] = $event;
    foreach ($items as $item) { $byRef[(string)$item['booking_ref']][]=$item; $byId[(int)$item['id']]=$item; }
    $addIssue = static function(array $tx, string $reason, int $amount = 0) use (&$issues): void {
        $issues[]=['transaction'=>$tx, 'reason'=>$reason, 'amount'=>$amount];
    };
    $ensure = static function(string $scope, string $bucket) use (&$reports, $eventMap): void {
        if (isset($reports[$scope][$bucket])) return;
        $reports[$scope][$bucket]=finance_month_empty($bucket);
        if ($scope==='events') $reports[$scope][$bucket]['label']=($eventMap[(int)$bucket]['title'] ?? ('Event #'.$bucket)) . (!empty($eventMap[(int)$bucket]['event_date']) ? ' · '.format_display_date($eventMap[(int)$bucket]['event_date']) : '');
    };
    $addPayment = static function(array $tx, string $scope, string $bucket, int $amount, int $fee, bool $known) use (&$reports,$ensure): void {
        $ensure($scope,$bucket);
        $reports[$scope][$bucket]['payments'] += $amount;
        $reports[$scope][$bucket]['fees'] += $fee;
        if (!$known) $reports[$scope][$bucket]['missing_fees']++;
        $tx['allocated_amount']=$amount; $tx['allocated_fee']=$fee; $tx['fee_known']=$known;
        $tx['report_kind']=($tx['type']==='payment_stripe_misc'?'Payment Request':'Entry payment');
        $reports[$scope][$bucket]['transactions'][]=$tx;
    };
    foreach ($ledger as $tx) {
        if (!in_array($tx['type'],['payment_stripe','payment_stripe_misc'],true)) continue;
        $meta=finance_month_metadata($tx);
        $amount=max(0,(int)round((float)$tx['amount']*100));
        $known=isset($meta['stripe_fee']) && is_numeric($meta['stripe_fee']) && (float)$meta['stripe_fee']>=0;
        $fee=$known?(int)round((float)$meta['stripe_fee']*100):0;
        if (!$known) $addIssue($tx,'Stripe fee missing; collection is blocked until reconciled.');
        if ($tx['type']==='payment_stripe_misc') {
            $linkedItem=$byId[(int)($meta['booking_item_id']??0)]??null;
            $eventId=(int)($meta['event_id']??0);
            if ($eventId > 0 && !empty($linkedItem['event_id']) && $eventId !== (int)$linkedItem['event_id']) { $addIssue($tx,'Payment Request event and entry links disagree.',$amount); continue; }
            if ($eventId <= 0) $eventId=(int)($linkedItem['event_id']??0);
            if ($eventId>0 && !isset($eventMap[$eventId])) { $addIssue($tx,'Payment Request links to a missing event.',$amount); continue; }
            $scope=$eventId>0?'events':'miscellaneous';
            $bucket=$eventId>0?(string)$eventId:finance_month_key((int)$tx['paid_timestamp']);
            $addPayment($tx,$scope,$bucket,$amount,$fee,$known);
            $paymentMap[(int)$tx['id']]=[$scope,$bucket,$meta];
            continue;
        }
        $weights=['membership'=>0,'horse_logbook'=>0,'other'=>0];
        foreach ($byRef[(string)$tx['reference']]??[] as $item) {
            $kind=!empty($item['event_id'])?'event:'.(int)$item['event_id']:(in_array($item['booking_type'],['membership','horse_logbook'],true)?$item['booking_type']:'other');
            $weights[$kind]=($weights[$kind]??0)+max(0,(int)round((float)$item['price']*100));
        }
        if (array_sum($weights)<=0) { $addIssue($tx,'Checkout has no matching priced booking items.',$amount); continue; }
        $parts=finance_month_allocate($amount,$weights); $fees=finance_month_allocate($fee,$weights);
        foreach ($parts as $kind=>$part) {
            if ($kind==='other' && $part>0) $addIssue($tx,'Checkout contains items outside events, memberships and logbooks.',$part);
            if (!str_starts_with((string)$kind,'event:')) continue;
            $eventId=(int)substr($kind,6);
            if (!isset($eventMap[$eventId])) { if($part>0)$addIssue($tx,'Checkout links to a missing event.',$part); continue; }
            $addPayment($tx,'events',(string)$eventId,$part,$fees[$kind],$known);
        }
    }
    foreach ($ledger as $tx) {
        if (!in_array($tx['type'],['entry_refund','entry_stripe_refund','entry_credit','manual_credit','manual_debit','refund'],true)) continue;
        $meta=finance_month_metadata($tx); $item=$byId[(int)($meta['booking_item_id']??0)]??null;
        $eventId=(int)($meta['event_id']??($item['event_id']??0));
        $amount=in_array($tx['type'],['manual_credit','manual_debit'],true)?(int)round((float)$tx['amount']*100):abs((int)round((float)$tx['amount']*100));
        $targets=[];
        if($eventId>0 && isset($eventMap[$eventId])) $targets[]=['events',(string)$eventId,$amount];
        elseif (!empty($meta['misc_payment_request_id'])) {
            foreach ($paymentMap as [$scope,$bucket,$paymentMeta]) if((int)($paymentMeta['misc_payment_request_id']??0)===(int)$meta['misc_payment_request_id']) $targets[]=[$scope,$bucket,$amount];
            if(count($targets)!==1){
                foreach($targets as[$scope,$bucket,$part])$reports[$scope][$bucket]['reconciliation']=true;
                $addIssue($tx,'Payment Request refund has no unique original receipt.');$targets=[];
            }
        } elseif ($tx['type']==='refund' && !$item && isset($byRef[(string)($tx['reference']??'')])) {
            $weights=[];
            foreach($byRef[(string)$tx['reference']]as $bookingItem){$key=!empty($bookingItem['event_id'])?'event:'.(int)$bookingItem['event_id']:'other';$weights[$key]=($weights[$key]??0)+max(0,(int)round((float)$bookingItem['price']*100));}
            foreach(finance_month_allocate($amount,$weights)as$key=>$part)if(str_starts_with((string)$key,'event:')&&isset($eventMap[(int)substr($key,6)]))$targets[]=['events',substr($key,6),$part];
        }
        foreach($targets as[$scope,$bucket,$part]){
            $ensure($scope,$bucket);$reports[$scope][$bucket]['refunds']+=$part;
            $tx['allocated_amount']=-$part;$tx['allocated_fee']=0;$tx['fee_known']=true;$tx['report_kind']='Refund / credit';
            $reports[$scope][$bucket]['transactions'][]=$tx;
        }
    }
    $seenReceipts=[];$duplicateIds=[];
    foreach($ledger as$tx){
        if(!in_array($tx['type'],['payment_stripe','payment_stripe_misc'],true))continue;
        $meta=finance_month_metadata($tx);$identity=(string)($meta['stripe_payment_intent']??$meta['stripe_session_id']??'');
        if($identity==='')continue;
        if(isset($seenReceipts[$identity])){$duplicateIds[(int)$tx['id']]=true;$duplicateIds[$seenReceipts[$identity]]=true;$addIssue($tx,'More than one local receipt has this Stripe payment reference.');}
        $seenReceipts[$identity]=(int)$tx['id'];
    }
    foreach($reports as&$groups){foreach($groups as&$group)foreach($group['transactions']as$tx)if(isset($duplicateIds[(int)$tx['id']]))$group['reconciliation']=true;unset($group);}unset($groups);
    foreach ($ledger as $tx) {
        if($tx['type']!=='stripe_payout')continue;
        $meta=finance_month_metadata($tx);
        $scope=(string)($meta['collection_scope']??(!empty($meta['event_id'])?'events':''));
        if(!in_array($scope,['events','miscellaneous'],true))continue;
        $bucket=(string)($scope==='events'?($meta['event_id']??''):($meta['collection_month']??''));
        if(($scope==='events'&&(!ctype_digit($bucket)||(int)$bucket<=0))||($scope==='miscellaneous'&&!finance_month_valid($bucket)))continue;
        $ensure($scope,$bucket);$status=(string)($meta['stripe_status']??'unknown');$amount=abs((int)round((float)$tx['amount']*100));
        if(in_array($status,['unknown','submitting'],true))$reports[$scope][$bucket]['reserved']+=$amount;
        elseif(!in_array($status,['failed','canceled'],true))$reports[$scope][$bucket]['collected']+=$amount;
        $tx['metadata']=$meta;$reports[$scope][$bucket]['payouts'][]=$tx;
    }
    foreach($reports as &$groups){foreach($groups as &$group){
        $group['net']=$group['payments']-$group['fees']-$group['refunds'];
        $group['remaining']=$group['net']-$group['collected']-$group['reserved'];
        usort($group['transactions'],static fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);
        usort($group['payouts'],static fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);
    }unset($group);krsort($groups);}unset($groups);
    return ['reports'=>$reports,'issues'=>$issues];
}

function finance_collection_reports(PDO $pdo,string $scope):array
{
    $data=finance_fulfilment_data($pdo);
    return finance_fulfilment_build($data['ledger'],$data['items'],$data['events'])['reports'][$scope]??[];
}

/** Fee reconciliation only reads Stripe and updates the receipt's fee metadata. */
function finance_receipt_fee(array $stripeConfig,array $tx,?callable $api=null):array
{
    $meta=finance_month_metadata($tx);
    $intent=(string)($meta['stripe_payment_intent']??'');
    if($intent==='')throw new RuntimeException('This receipt has no Stripe payment reference.');
    $api??=static fn($method,$path,$params)=>stripe_api_request($stripeConfig,$method,$path,$params);
    $response=$api('GET','/v1/payment_intents/'.rawurlencode($intent),['expand'=>['latest_charge.balance_transaction']]);
    if(empty($response['ok']))throw new RuntimeException($response['error']??'Could not retrieve Stripe fee.');
    $intentData=$response['data'];$charge=$intentData['latest_charge']??null;$balance=is_array($charge)?($charge['balance_transaction']??null):null;
    if(!is_array($balance)||!isset($balance['fee'],$balance['net'],$balance['amount']))throw new RuntimeException('Stripe has not supplied the fee yet. Try again after settlement.');
    $test=str_starts_with((string)($stripeConfig['secret_key']??''),'sk_test_');
    if((bool)($intentData['livemode']??false)===$test||(string)($balance['currency']??'')!==($stripeConfig['currency']??'gbp')||(int)$balance['amount']!==(int)round((float)$tx['amount']*100))throw new RuntimeException('Stripe receipt mode, currency or amount does not match this transaction.');
    $meta['stripe_fee']=(int)$balance['fee']/100;$meta['stripe_net']=(int)$balance['net']/100;
    $meta['stripe_charge_id']=(string)$charge['id'];$meta['stripe_balance_transaction_id']=(string)($balance['id']??'');
    $meta['stripe_fee_checked_at']=gmdate('c');
    return $meta;
}
