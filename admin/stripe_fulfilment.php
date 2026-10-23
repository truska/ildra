<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../finance_fulfilment.php';
require_once __DIR__ . '/../bookings_store.php';
$currentRole=strtolower((string)($currentUser['role']??''));
if(!in_array($currentRole,['developer','superadmin','admin'],true)){http_response_code(403);exit('You do not have permission to view Stripe Fulfilment.');}
$canCreatePayout=adminActionAllowed($pdo,'finance.create_payout',$currentRole);
$stripeConfig=stripe_config($config);
$stripeIsTest=str_starts_with((string)($stripeConfig['secret_key']??''),'sk_test_');
ensure_finance_tables($pdo,$alerts);
ensure_bookings_tables($pdo);
ensureMiscPaymentTables($pdo);
$tabs=['events'=>'Events','memberships'=>'Memberships & Logbooks','miscellaneous'=>'Miscellaneous','reconciliation'=>'Reconciliation'];
$scopes=['events'=>'events','memberships'=>'memberships_logbooks','miscellaneous'=>'miscellaneous'];
$tab=(string)($_GET['tab']??'events');if(!isset($tabs[$tab]))$tab='events';

if(isset($_GET['stripe_balance'])){
    header('Content-Type: application/json');
    if(!$canCreatePayout||!$stripeIsTest){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Stripe collections require permission and test mode.']);exit;}
    $response=stripe_retrieve_balance($stripeConfig);
    if(empty($response['ok'])){http_response_code(502);echo json_encode(['ok'=>false,'error'=>$response['error']??'Could not read Stripe balance.']);exit;}
    $available=stripe_available_source_balance($response['data'],$stripeConfig['currency'],'card');$pending=stripe_pending_source_balance($response['data'],$stripeConfig['currency'],'card');
    echo json_encode(['ok'=>true,'available'=>$available,'pending'=>$pending,'balance'=>$available+$pending]);exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $action=(string)($_POST['action']??'');
    if(in_array($action,['collect_bucket','collect_membership_month','create_event_payout'],true)){
        $scope=(string)($_POST['collection_scope']??($action==='create_event_payout'?'events':'memberships_logbooks'));
        $returnTab=array_search($scope,$scopes,true)?:'events';
        $bucket=(string)($_POST['month']??$_POST['event_id']??'');
        $requestKey=(string)($_POST['request_key']??($action==='create_event_payout'?($_SESSION['finance_payout_key']??''):''));
        try{
            if(!$canCreatePayout)throw new RuntimeException('You do not have permission to create payouts.');
            if(!csrf_is_valid($_POST['csrf']??null))throw new RuntimeException('Your session token expired.');
            if(!isset($scopes[$returnTab])||$scopes[$returnTab]!==$scope)throw new RuntimeException('Invalid collection group.');
            if(!empty($_POST['refresh_collection'])){
                if (!empty($_POST['legacy_transaction_id']) && $scope === 'events') {
                    $pdo->beginTransaction();
                    $legacy=$pdo->prepare("SELECT * FROM finance_transactions WHERE id=:id AND type='stripe_payout' FOR UPDATE");
                    $legacy->execute([':id'=>(int)$_POST['legacy_transaction_id']]);$old=$legacy->fetch();
                    $oldMeta=$old?finance_month_metadata($old):[];
                    if(!$old||(string)($oldMeta['event_id']??'')!==$bucket||empty($oldMeta['stripe_payout_id']))throw new RuntimeException('Event collection not found.');
                    $oldMeta['collection_scope']='events';$oldMeta['collection_request_key']??=bin2hex(random_bytes(16));
                    $requestKey=$oldMeta['collection_request_key'];
                    $pdo->prepare('UPDATE finance_transactions SET metadata=:metadata WHERE id=:id')->execute([':metadata'=>json_encode($oldMeta,JSON_THROW_ON_ERROR),':id'=>$old['id']]);$pdo->commit();
                }
                $find=$pdo->prepare("SELECT id FROM finance_transactions WHERE type='stripe_payout' AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.collection_request_key'))=:key");$find->execute([':key'=>$requestKey]);
                if(!$find->fetchColumn())throw new RuntimeException('Collection not found.');
            }else{
                $issued=(string)($_SESSION['finance_collection_keys'][$scope][$bucket]??($scope==='memberships_logbooks'?($_SESSION['finance_month_keys'][$bucket]??''):($action==='create_event_payout'?($_SESSION['finance_payout_key']??''):'')));
                if($issued===''||$requestKey===''||!hash_equals($issued,$requestKey))throw new RuntimeException('This collection form has expired. Reload the page.');
            }
            $result=finance_month_collect($pdo,$stripeConfig,$bucket,(int)round(price_to_number($_POST['amount']??'0')*100),$requestKey,trim((string)($_POST['notes']??'')),(string)($currentUser['email']??'admin'),null,$scope);
            $meta=finance_month_metadata($result);unset($_SESSION['finance_collection_keys'][$scope][$bucket],$_SESSION['finance_month_keys'][$bucket]);
            if($action==='create_event_payout')unset($_SESSION['finance_payout_key']);
            if(!empty($meta['last_error']))$alerts[]=['type'=>'warning','message'=>$meta['last_error'].' Check the collection history before trying again.'];
            else $_SESSION['flash_success']='Test collection '.format_price(abs((float)$result['amount'])).' — '.(string)($meta['stripe_status']??'unknown').'.';
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$alerts[]=['type'=>'danger','message'=>$e instanceof PDOException?'The collection could not be recorded. Check its history before retrying.':$e->getMessage()];}
        if($alerts)$_SESSION['flash_alerts']=$alerts;
        header('Location: stripe_fulfilment.php?'.http_build_query(['tab'=>$returnTab,'month'=>$bucket,'include_collected'=>1]).'#month-detail');exit;
    }
    if($action==='reconcile_receipt_fee'){
        try{
            if(!$canCreatePayout)throw new RuntimeException('You do not have permission to reconcile Stripe fees.');
            $find=$pdo->prepare("SELECT * FROM finance_transactions WHERE id=:id AND type IN ('payment_stripe','payment_stripe_misc')");$find->execute([':id'=>(int)($_POST['transaction_id']??0)]);$tx=$find->fetch();
            if(!$tx)throw new RuntimeException('Receipt not found.');
            $feeMeta=finance_receipt_fee($stripeConfig,$tx);
            // Preserve metadata added concurrently; only merge verified fee fields.
            $pdo->beginTransaction();$lock=$pdo->prepare('SELECT metadata FROM finance_transactions WHERE id=:id FOR UPDATE');$lock->execute([':id'=>$tx['id']]);
            $current=finance_month_metadata(['metadata'=>$lock->fetchColumn()]);
            foreach(['stripe_fee','stripe_net','stripe_charge_id','stripe_balance_transaction_id','stripe_fee_checked_at']as$key)$current[$key]=$feeMeta[$key];
            $pdo->prepare('UPDATE finance_transactions SET metadata=:metadata WHERE id=:id')->execute([':metadata'=>json_encode($current,JSON_THROW_ON_ERROR),':id'=>$tx['id']]);$pdo->commit();
            $_SESSION['flash_success']='Stripe fee reconciled for T-'.(int)$tx['id'].'.';
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['flash_alerts']=[['type'=>'danger','message'=>$e instanceof PDOException?'The fee could not be saved.':$e->getMessage()]];}
        header('Location: stripe_fulfilment.php?tab=reconciliation');exit;
    }
}
$data=finance_fulfilment_data($pdo);$fulfilment=finance_fulfilment_build($data['ledger'],$data['items'],$data['events']);
admin_layout_start('Stripe Fulfilment','stripe_fulfilment');
?>
<div class="d-flex justify-content-between align-items-start mb-3"><div><h5 class="mb-1">Stripe Fulfilment</h5><div class="text-muted small">Collect Stripe funds, track collections and reconcile receipts.</div></div><a class="btn btn-sm btn-outline-secondary" href="finance.php">Finance transactions</a></div>
<ul class="nav nav-tabs admin-tabs mb-3"><?php foreach($tabs as$key=>$label):?><li class="nav-item"><a class="nav-link <?php echo $key===$tab?'active':''; ?>" <?php echo $key===$tab?'aria-current="page"':''; ?> href="stripe_fulfilment.php?tab=<?php echo h($key); ?>"><?php echo h($label); ?></a></li><?php endforeach;?></ul>
<?php
if($tab!=='reconciliation'){
    $collectionTab=$tab;$collectionScope=$scopes[$tab];$collectionTitle=$tabs[$tab];
    $collectionDescription=match($tab){
        'events'=>'Entry receipts and paid Payment Requests linked to each event share one collection balance.',
        'miscellaneous'=>'Paid Payment Requests without an event link, grouped by transaction month in Europe/London.',
        default=>'Stripe payments grouped by transaction month in Europe/London, regardless of the membership or logbook year.'
    };
    $monthlyReports=$tab==='memberships'?finance_month_reports($pdo):$fulfilment['reports'][$collectionScope];
    require __DIR__.'/stripe_fulfilment_groups.php';
}else{
    require __DIR__.'/stripe_fulfilment_reconciliation.php';
}
admin_layout_end();
