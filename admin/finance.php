<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';
require_once __DIR__ . '/../bookings_store.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageFinance = in_array($currentRole, ['superadmin', 'admin', 'manager'], true);
if (!$canManageFinance) {
    header('Location: index.php');
    exit;
}
$canCreatePayout = adminActionAllowed($pdo, 'finance.create_payout', $currentRole);
$canAdjustBalance = adminActionAllowed($pdo, 'finance.adjust_balance', $currentRole);
$canCreateMiscPayment = adminActionAllowed($pdo, 'finance.create_misc_payment', $currentRole);

ensure_finance_tables($pdo, $alerts);
$stripeConfig = stripe_config($config);
$stripeIsTest = str_starts_with((string)($stripeConfig['secret_key'] ?? ''), 'sk_test_');
$payoutSummary=$_SESSION['finance_payout_summary']??null;unset($_SESSION['finance_payout_summary']);
if(empty($_SESSION['finance_payout_csrf']))$_SESSION['finance_payout_csrf']=bin2hex(random_bytes(24));
if(empty($_SESSION['finance_payout_key']))$_SESSION['finance_payout_key']=bin2hex(random_bytes(16));
$financePayoutCsrf=(string)$_SESSION['finance_payout_csrf'];$financePayoutKey=(string)$_SESSION['finance_payout_key'];
if(empty($_SESSION['misc_payment_csrf']))$_SESSION['misc_payment_csrf']=bin2hex(random_bytes(24));
$miscPaymentCsrf=(string)$_SESSION['misc_payment_csrf']; ensureMiscPaymentTables($pdo);

function finance_event_payout_capacity(PDO $pdo,int $eventId):array{
    $payments=0.0;$fees=0.0;
    $stmt=$pdo->prepare("SELECT ft.amount,ft.metadata,SUM(CASE WHEN bi.event_id=:event_id THEN bi.price ELSE 0 END) event_amount,SUM(bi.price) booking_amount FROM finance_transactions ft JOIN bookings b ON b.booking_ref=ft.reference JOIN booking_items bi ON bi.booking_id=b.new_id WHERE ft.type='payment_stripe' GROUP BY ft.id,ft.amount,ft.metadata HAVING event_amount>0");$stmt->execute([':event_id'=>$eventId]);
    foreach($stmt->fetchAll()?:[] as$row){$total=(float)($row['booking_amount']??0);if($total<=0)continue;$share=min(1,max(0,(float)$row['event_amount']/$total));$payments+=(float)$row['amount']*$share;$meta=json_decode((string)($row['metadata']??''),true);$fees+=(is_array($meta)&&isset($meta['stripe_fee'])?(float)$meta['stripe_fee']:0)*$share;}
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(ABS(ft.amount)),0) FROM finance_transactions ft JOIN booking_items bi ON bi.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata,'$.booking_item_id')) AS UNSIGNED) WHERE ft.type IN ('entry_refund','entry_stripe_refund','entry_credit') AND bi.event_id=:event_id");$stmt->execute([':event_id'=>$eventId]);$refunds=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM finance_transactions WHERE type='stripe_payout' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.event_id')) AS UNSIGNED)=:event_id");$stmt->execute([':event_id'=>$eventId]);$paid=(float)$stmt->fetchColumn();
    $net=$payments-$refunds-$fees;return['payments'=>$payments,'refunds'=>$refunds,'stripe_fee'=>$fees,'net'=>$net,'paid'=>$paid,'remaining'=>max(0,$net-$paid)];
}

if(isset($_GET['stripe_balance'])){
    if (!$canCreatePayout) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'You do not have permission to create payouts.']); exit; }
    header('Content-Type: application/json');
    if(!$stripeIsTest){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Development payouts require Stripe test mode.']);exit;}
    $response=stripe_retrieve_balance($stripeConfig);if(!($response['ok']??false)){http_response_code(502);echo json_encode(['ok'=>false,'error'=>$response['error']??'Could not read Stripe balance.']);exit;}
    $available=stripe_available_source_balance($response['data']??[],$stripeConfig['currency']??'gbp','card');$pending=stripe_pending_source_balance($response['data']??[],$stripeConfig['currency']??'gbp','card');
    echo json_encode(['ok'=>true,'available'=>$available,'pending'=>$pending,'balance'=>$available+$pending,'currency'=>strtoupper((string)($stripeConfig['currency']??'gbp'))]);exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if($action==='create_event_payout'){
        $eventId=(int)($_POST['event_id']??0);$event=$eventId>0?fetchEventById($pdo,$eventId):null;$amount=price_to_number($_POST['amount']??0);$notes=trim((string)($_POST['notes']??''));
        if(!$canCreatePayout)$alerts[]=['type'=>'danger','message'=>'You do not have permission to create payouts.'];
        elseif(!hash_equals($financePayoutCsrf,(string)($_POST['csrf']??'')))$alerts[]=['type'=>'danger','message'=>'Your session token expired. Please try again.'];
        elseif(!$stripeIsTest)$alerts[]=['type'=>'danger','message'=>'Development payouts are locked to Stripe test mode.'];
        elseif(!$event)$alerts[]=['type'=>'danger','message'=>'Event not found.'];
        else{
            $capacity=finance_event_payout_capacity($pdo,$eventId);$balanceResponse=stripe_retrieve_balance($stripeConfig);$stripeAvailable=($balanceResponse['ok']??false)?stripe_available_source_balance($balanceResponse['data']??[],$stripeConfig['currency']??'gbp','card'):0.0;$maximum=min((float)$capacity['remaining'],$stripeAvailable);
            if(!($balanceResponse['ok']??false))$alerts[]=['type'=>'danger','message'=>$balanceResponse['error']??'Could not check the Stripe balance.'];
            elseif($amount<=0||$amount>$maximum+0.0001)$alerts[]=['type'=>'danger','message'=>'The payout must be greater than zero and no more than '.format_price($maximum).'.'];
            else{$descriptor=preg_replace('/[^A-Za-z0-9 .-]+/',' ',(string)($event['title']??''));$descriptor=trim(preg_replace('/\s+/',' ',$descriptor));if($descriptor==='')$descriptor='ILDRA EVENT '.$eventId;$descriptor=substr($descriptor,0,22);$idempotencyKey='ildra-event-'.$eventId.'-'.$financePayoutKey;
                $payoutResponse=stripe_create_payout($stripeConfig,['amount'=>(int)round($amount*100),'currency'=>$stripeConfig['currency']??'gbp','source_type'=>'card','description'=>'ILDRA event payout: '.(string)($event['title']??''),'statement_descriptor'=>$descriptor,'metadata[event_id]'=>$eventId,'metadata[event_title]'=>substr((string)($event['title']??''),0,500),'metadata[admin_notes]'=>substr($notes,0,500)],$idempotencyKey);
                if(!($payoutResponse['ok']??false))$alerts[]=['type'=>'danger','message'=>$payoutResponse['error']??'Stripe could not create the payout.'];
                else{$payout=$payoutResponse['data']??[];$financeAlerts=[];if(record_finance_transaction($pdo,['user_id'=>null,'type'=>'stripe_payout','amount'=>-$amount,'reference'=>(string)($payout['id']??''),'notes'=>$notes!==''?$notes:'Event payout to nominated bank account','metadata'=>['event_id'=>$eventId,'event_title'=>$event['title']??'','stripe_payout_id'=>$payout['id']??'','stripe_status'=>$payout['status']??'pending','statement_descriptor'=>$descriptor,'livemode'=>$payout['livemode']??false,'actor'=>$currentUser['email']??'admin']],$financeAlerts)){$_SESSION['flash_success']='Test payout '.format_price($amount).' created for '.(string)$event['title'].'.';$_SESSION['finance_payout_summary']=['event_title'=>(string)($event['title']??''),'requested_at'=>date('Y-m-d H:i:s'),'arrival_date'=>(int)($payout['arrival_date']??0),'amount'=>$amount,'statement_descriptor'=>$descriptor,'stripe_payout_id'=>(string)($payout['id']??''),'status'=>(string)($payout['status']??'pending')];unset($_SESSION['finance_payout_key']);}else$alerts=array_merge($alerts,$financeAlerts);}
            }
        }
        if($alerts)$_SESSION['flash_alerts']=$alerts;header('Location: finance.php?tab=events');exit;
    } elseif ($action === 'create_misc_payment') {
        $email=strtolower(trim((string)($_POST['recipient_email']??''))); $description=trim((string)($_POST['description']??'')); $amount=price_to_number($_POST['amount']??0);
        if(!$canCreateMiscPayment)$alerts[]=['type'=>'danger','message'=>'You do not have permission to send miscellaneous payment requests.'];
        elseif(!hash_equals($miscPaymentCsrf,(string)($_POST['csrf']??'')))$alerts[]=['type'=>'danger','message'=>'Your session token expired. Please try again.'];
        elseif(!stripe_is_enabled($stripeConfig))$alerts[]=['type'=>'danger','message'=>'Stripe is not configured.'];
        elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)||$description===''||$amount<=0)$alerts[]=['type'=>'danger','message'=>'Enter a valid email address, description, and amount greater than zero.'];
        else { $token=bin2hex(random_bytes(24)); $insert=$pdo->prepare("INSERT INTO misc_payment_requests (request_token,recipient_email,description,amount,currency,status,created_by_user_id) VALUES (:token,:email,:description,:amount,:currency,'sent',:user)"); $insert->execute([':token'=>$token,':email'=>$email,':description'=>substr($description,0,255),':amount'=>number_format($amount,2,'.',''),':currency'=>$stripeConfig['currency']??'gbp',':user'=>(int)$currentUser['id']]); $requestId=(int)$pdo->lastInsertId();
            $scheme=auth_cookie_secure()?'https':'http'; $host=(string)($_SERVER['HTTP_HOST']??''); $successUrl=$scheme.'://'.$host.($siteBase?:'').'/misc_payment_complete.php?session_id={CHECKOUT_SESSION_ID}';
            $params=['mode'=>'payment','customer_email'=>$email,'success_url'=>$successUrl,'cancel_url'=>$scheme.'://'.$host.($siteBase?:'').'/admin/finance.php?misc_cancelled=1','line_items'=>[['price_data'=>['currency'=>$stripeConfig['currency']??'gbp','unit_amount'=>(int)round($amount*100),'product_data'=>['name'=>'ILDRA payment request','description'=>substr($description,0,255)]],'quantity'=>1]],'metadata'=>['misc_payment_request_id'=>(string)$requestId,'request_token'=>$token]];
            $response=stripe_create_checkout_session($stripeConfig,$params); $session=(array)($response['data']??[]); $sessionId=trim((string)($session['id']??'')); $checkoutUrl=trim((string)($session['url']??''));
            if(empty($response['ok'])||$sessionId===''||$checkoutUrl===''){ $pdo->prepare("UPDATE misc_payment_requests SET status='failed' WHERE id=:id")->execute([':id'=>$requestId]); $alerts[]=['type'=>'danger','message'=>'Could not create the Stripe payment request. '.($response['error']??'')]; }
            else { $pdo->prepare('UPDATE misc_payment_requests SET stripe_session_id=:session,stripe_checkout_url=:url,email_sent_at=NOW() WHERE id=:id')->execute([':session'=>$sessionId,':url'=>$checkoutUrl,':id'=>$requestId]); $settings=getEmailSettings($pdo); $siteSettings=getSiteSettings($pdo); $inner='<p>Please use the secure link below to pay <strong>'.h(format_price($amount)).'</strong>.</p><p>'.nl2br(h($description)).'</p><p><a href="'.h($checkoutUrl).'" style="display:inline-block;padding:10px 16px;background:#146118;color:#fff;text-decoration:none;border-radius:6px">Pay securely</a></p><p>If the button does not work, copy this link into your browser:<br><a href="'.h($checkoutUrl).'">'.h($checkoutUrl).'</a></p>'; $sent=send_logged_email($pdo,$email,'Payment request: '.substr($description,0,120),wrap_user_email_html($siteSettings,$settings,$inner),"Payment request\n\n".$description."\nAmount: ".format_price($amount)."\nPay securely: ".$checkoutUrl,['type'=>'misc_payment_request','misc_payment_request_id'=>$requestId,'stripe_session_id'=>$sessionId]); if(!$sent)$alerts[]=['type'=>'warning','message'=>'The Stripe link was created, but the email could not be sent. Check Email logs.']; else $successMessage='Payment request emailed to '.$email.'.'; }
        }
        if($alerts)$_SESSION['flash_alerts']=$alerts;
        if($successMessage)$_SESSION['flash_success']=$successMessage;
        header('Location: finance.php?tab=requests'); exit;
    } elseif ($action === 'adjust_balance') {
        if (!$canAdjustBalance) { $alerts[] = ['type'=>'danger','message'=>'You do not have permission to adjust account balances.']; }
        $userId = (int)($_POST['user_id'] ?? 0);
        $direction = $_POST['direction'] === 'debit' ? 'debit' : 'credit';
        $amountRaw = $_POST['amount'] ?? '0';
        $reason = trim((string)($_POST['reason'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $kind = $_POST['kind'] ?? 'manual_credit';

        $amount = price_to_number($amountRaw);
        if ($userId <= 0) {
            $alerts[] = ['type' => 'danger', 'message' => 'Select a user.'];
        }
        if ($amount <= 0) {
            $alerts[] = ['type' => 'danger', 'message' => 'Enter an amount greater than zero.'];
        }
        if (!$alerts) {
            $signedAmount = $direction === 'debit' ? -1 * $amount : $amount;
            $type = $direction === 'debit' ? 'manual_debit' : $kind;
            if (record_finance_transaction($pdo, [
                'user_id' => $userId,
                'type' => $type,
                'amount' => $signedAmount,
                'reference' => $reference !== '' ? $reference : 'admin-adjustment',
                'notes' => $reason !== '' ? $reason : null,
                'metadata' => ['actor' => $currentUser['email'] ?? 'admin'],
            ], $alerts)) {
                $successMessage = 'Balance updated.';
            }
        }
        if ($alerts) {
            $_SESSION['flash_alerts'] = $alerts;
        }
        if ($successMessage) {
            $_SESSION['flash_success'] = $successMessage;
        }
        header('Location: finance.php');
        exit;
    }
}

$allUsers = fetchAllUsersForAdmin($pdo, $alerts);
$balances = fetch_credit_balances($pdo, 500);
$miscPaymentRequests = [];
if ($canCreateMiscPayment && $pdo) {
    $miscPaymentRequests = $pdo->query("SELECT * FROM misc_payment_requests ORDER BY created_at DESC, id DESC LIMIT 250")->fetchAll() ?: [];
}
function finance_transaction_type_label(string $type): string {
    return match ($type) {
        'payment_stripe_misc' => 'Stripe payment — miscellaneous request',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}
function finance_transaction_notes(array $transaction): string {
    $notes = (string)($transaction['notes'] ?? '');
    $meta = $transaction['metadata'] ?? [];
    if (is_array($meta) && ($transaction['type'] ?? '') === 'entry_refund' && !empty($meta['actor_name'])) return 'Entry refunded and withdrawn by admin (' . (string)$meta['actor_name'] . ')';
    if (is_array($meta) && ($transaction['type'] ?? '') === 'payment_stripe_misc') {
        $recipient = trim((string)($meta['recipient_email'] ?? ''));
        return ($recipient !== '' ? 'Payment request to ' . $recipient . ' — ' : '') . $notes;
    }
    return $notes;
}
function finance_transaction_movement(array $transaction): string {
    return match ((string)($transaction['type'] ?? '')) {
        'payment_stripe', 'payment_stripe_misc', 'payment_simulated' => 'Money received',
        'stripe_payout' => 'Payout',
        'entry_refund', 'entry_stripe_refund', 'refund' => 'Refund',
        'checkout' => 'Internal checkout',
        'manual_credit', 'manual_debit' => 'Account adjustment',
        default => finance_transaction_type_label((string)($transaction['type'] ?? '')),
    };
}
function finance_transaction_applies_to(array $transaction, array $eventLabels, array $bookingEventLabels = [], array $bookingKinds = []): string {
    $type=(string)($transaction['type'] ?? ''); $meta=is_array($transaction['metadata'] ?? null)?$transaction['metadata']:[];
    if ($type === 'payment_stripe_misc') return 'Miscellaneous';
    $eventIds=[]; if(!empty($meta['event_id']))$eventIds[]=(int)$meta['event_id'];
    if(!empty($meta['event_ids']))foreach(explode(',',(string)$meta['event_ids'])as$id)if((int)$id>0)$eventIds[]=(int)$id;
    $eventIds=array_values(array_unique($eventIds));
    if($eventIds){$labels=[];foreach($eventIds as$id)$labels[]=$eventLabels[$id]??('Event #'.$id);return 'Event: '.implode(', ',$labels);}
    $bookingReference=(string)($transaction['reference']??'');
    if($bookingReference!==''&&!empty($bookingEventLabels[$bookingReference]))return 'Event: '.$bookingEventLabels[$bookingReference];
    if(!empty($meta['membership_years'])){
        $kinds=$bookingKinds[$bookingReference]??[];
        return in_array('horse_logbook',$kinds,true)?'Logbook '.str_replace(',', ', ', (string)$meta['membership_years']):'Membership '.str_replace(',', ', ', (string)$meta['membership_years']);
    }
    if($type==='checkout'||$type==='payment_stripe')return !empty($transaction['reference'])?'Booking: '.(string)$transaction['reference']:'Website booking';
    return 'General finance';
}
$sortKey = $_GET['sort'] ?? 'when';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$transactionsDisplayed = fetch_finance_transactions($pdo, 500, (string)$sortKey, strtoupper($sortDir));
$transactionEvents = fetchEvents($pdo, false); $transactionEventLabels=[];
foreach($transactionEvents as$transactionEvent){
    $eventId=(int)$transactionEvent['id']; $eventTitle=(string)($transactionEvent['title']??('Event #'.$eventId)); $eventDate=(string)($transactionEvent['event_date']??'');
    $shortDate=$eventDate!==''?date('d/m/y',strtotime($eventDate)):'';
    $transactionEventLabels[$eventId]=$eventTitle.($shortDate!==''?' · '.$shortDate:'');
}
$transactionBookingEventLabels=[];
if($pdo){try{$bookingEventRows=$pdo->query('SELECT b.booking_ref, GROUP_CONCAT(DISTINCT bi.event_id ORDER BY bi.event_id SEPARATOR ",") AS event_ids FROM bookings b JOIN booking_items bi ON bi.booking_id=b.new_id WHERE bi.event_id IS NOT NULL GROUP BY b.booking_ref')->fetchAll()?:[];foreach($bookingEventRows as$bookingEventRow){$labels=[];foreach(explode(',',(string)$bookingEventRow['event_ids'])as$eventId){$eventId=(int)$eventId;if($eventId>0)$labels[]=$transactionEventLabels[$eventId]??('Event #'.$eventId);}if($labels)$transactionBookingEventLabels[(string)$bookingEventRow['booking_ref']]=implode(', ',array_unique($labels));}}catch(PDOException $e){$transactionBookingEventLabels=[];}}
$transactionBookingKinds=[];
if($pdo){try{$bookingKindRows=$pdo->query('SELECT b.booking_ref, GROUP_CONCAT(DISTINCT bi.booking_type SEPARATOR ",") AS booking_types FROM bookings b JOIN booking_items bi ON bi.booking_id=b.new_id GROUP BY b.booking_ref')->fetchAll()?:[];foreach($bookingKindRows as$bookingKindRow)$transactionBookingKinds[(string)$bookingKindRow['booking_ref']]=array_filter(explode(',',(string)$bookingKindRow['booking_types']));}catch(PDOException $e){$transactionBookingKinds=[];}}
$transactionUserOptions=[];$transactionTypeOptions=[];$transactionAppliesToOptions=[];
foreach($transactionsDisplayed as$tx){
    $uid=(string)($tx['user_id']??'');$name=trim((string)($tx['first_name']??'').' '.(string)($tx['last_name']??''));$email=trim((string)($tx['email']??''));
    if($uid!=='')$transactionUserOptions[$uid]=$name!==''?$name.($email!==''?' ('.$email.')':''):($email?:'User #'.$uid);
    $type=(string)($tx['type']??'');if($type!==''){$movement=finance_transaction_movement($tx);$transactionTypeOptions[$movement]=$movement;}
    $appliesTo=finance_transaction_applies_to($tx,$transactionEventLabels,$transactionBookingEventLabels,$transactionBookingKinds); $transactionAppliesToOptions[$appliesTo]=$appliesTo;
}
natcasesort($transactionUserOptions);natcasesort($transactionTypeOptions);natcasesort($transactionAppliesToOptions);
$transactionFilterForm='transaction-filter-form';
$transactionColumns=[
    'when'=>['label'=>'When','sortable'=>true,'filter'=>'text','placeholder'=>'Search when','form'=>$transactionFilterForm,'value'=>static fn(array $r):string=>format_display_datetime($r['created_at']??null,''),'sort_value'=>static fn(array $r):string=>(string)($r['created_at']??'')],
    'user'=>['label'=>'User','sortable'=>true,'filter'=>'select','form'=>$transactionFilterForm,'options'=>$transactionUserOptions,'value'=>static fn(array $r):string=>(string)($r['user_id']??'')],
    'type'=>['label'=>'Movement','sortable'=>true,'filter'=>'select','form'=>$transactionFilterForm,'options'=>$transactionTypeOptions,'value'=>static fn(array $r): string => finance_transaction_movement($r)],
    'applies_to'=>['label'=>'Applies to','filter'=>'select','form'=>$transactionFilterForm,'options'=>$transactionAppliesToOptions,'value'=>static fn(array $r): string => finance_transaction_applies_to($r, $GLOBALS['transactionEventLabels'] ?? [], $GLOBALS['transactionBookingEventLabels'] ?? [], $GLOBALS['transactionBookingKinds'] ?? [])],
    'amount'=>['label'=>'Amount','sortable'=>true,'filter'=>'text','placeholder'=>'Search amount','form'=>$transactionFilterForm,'compare'=>'number'],
    'balance'=>['label'=>'Balance after','field'=>'balance_after','sortable'=>true,'filter'=>'text','placeholder'=>'Search balance','form'=>$transactionFilterForm,'compare'=>'number'],
    'reference'=>['label'=>'Reference','sortable'=>true,'filter'=>'text','placeholder'=>'Search reference','form'=>$transactionFilterForm],
    'notes'=>['label'=>'Notes','sortable'=>true,'filter'=>'text','placeholder'=>'Search notes','form'=>$transactionFilterForm,'value'=>static fn(array $r): string => finance_transaction_notes($r)],
];
$transactionTable=admin_table_prepare($transactionsDisplayed,$transactionColumns,'when','desc');$transactionsDisplayed=$transactionTable['rows'];$transactionFilters=$transactionTable['filters'];$sortKey=$transactionTable['sort_key'];$sortDir=$transactionTable['sort_dir'];
$events = $transactionEvents;
$eventStats = [];
$eventRefunds = [];
$eventPayments = [];
$eventStripeFees = [];
$eventPayouts = [];
$eventSortKey = $_GET['event_sort'] ?? 'date';
$eventSortDir = strtolower($_GET['event_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

if ($pdo) {
    ensure_bookings_tables($pdo);
    if ($events) {
        try {
            $stmt = $pdo->query("
                SELECT
                    event_id,
                    COUNT(*) AS entry_total,
                    SUM(CASE WHEN COALESCE(is_withdrawn, 0) = 1 THEN 1 ELSE 0 END) AS withdrawn_total,
                    SUM(price) AS gross_fees
                FROM booking_items
                GROUP BY event_id
            ");
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $eventId = (int)($row['event_id'] ?? 0);
                if ($eventId <= 0) {
                    continue;
                }
                $eventStats[$eventId] = [
                    'entries' => (int)($row['entry_total'] ?? 0),
                    'withdrawn' => (int)($row['withdrawn_total'] ?? 0),
                    'gross' => (float)($row['gross_fees'] ?? 0),
                ];
            }
        } catch (PDOException $e) {
            $eventStats = [];
        }

        if (ensure_finance_tables($pdo)) {
            try {
                $stmt = $pdo->query("
                    SELECT
                        bi.event_id AS event_id,
                        SUM(ABS(ft.amount)) AS refunds_total
                    FROM finance_transactions ft
                    JOIN booking_items bi
                        ON bi.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.booking_item_id')) AS UNSIGNED)
                    WHERE ft.type IN ('entry_refund','entry_stripe_refund','entry_credit')
                    GROUP BY bi.event_id
                ");
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    $eventId = (int)($row['event_id'] ?? 0);
                    if ($eventId <= 0) {
                        continue;
                    }
                    $eventRefunds[$eventId] = (float)($row['refunds_total'] ?? 0);
                }
            } catch (PDOException $e) {
                $eventRefunds = [];
            }

            try {
                $stmt = $pdo->query("
                    SELECT ft.amount, ft.metadata, bi.event_id, SUM(bi.price) AS event_amount,
                           (SELECT SUM(all_items.price) FROM booking_items all_items WHERE all_items.booking_id = bi.booking_id) AS booking_amount
                    FROM finance_transactions ft
                    JOIN bookings b ON b.booking_ref = ft.reference
                    JOIN booking_items bi ON bi.booking_id = b.new_id
                    WHERE ft.type = 'payment_stripe'
                    GROUP BY ft.id, bi.event_id, ft.amount, ft.metadata, bi.booking_id
                ");
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    $eventId = (int)($row['event_id'] ?? 0);
                    $bookingAmount = (float)($row['booking_amount'] ?? 0);
                    if ($eventId <= 0 || $bookingAmount <= 0) continue;
                    $share = min(1, max(0, (float)($row['event_amount'] ?? 0) / $bookingAmount));
                    $eventPayments[$eventId] = ($eventPayments[$eventId] ?? 0) + ((float)$row['amount'] * $share);
                    $meta = json_decode((string)($row['metadata'] ?? ''), true);
                    $stripeFee = is_array($meta) && isset($meta['stripe_fee']) ? (float)$meta['stripe_fee'] : 0.0;
                    $eventStripeFees[$eventId] = ($eventStripeFees[$eventId] ?? 0) + ($stripeFee * $share);
                }
            } catch (PDOException $e) {
                $eventPayments = [];
                $eventStripeFees = [];
            }
            try{$stmt=$pdo->query("SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.event_id')) AS UNSIGNED) event_id,SUM(ABS(amount)) payout_total FROM finance_transactions WHERE type='stripe_payout' GROUP BY event_id");foreach($stmt->fetchAll()?:[] as$row)$eventPayouts[(int)$row['event_id']]=(float)$row['payout_total'];}catch(PDOException $e){$eventPayouts=[];}
        }
    }
}

function finance_event_sort_link(string $key, string $label, string $currentKey, string $currentDir): string
{
    $dir = ($currentKey === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '↕';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? '↑' : '↓';
    }
    $query = $_GET;
    $query['event_sort'] = $key;
    $query['event_dir'] = $dir;
    $query['tab'] = 'events';
    $url = '?' . http_build_query($query);
    return '<a class="text-decoration-none text-dark sort-link" href="' . h($url) . '">'
        . h($label)
        . '<span class="sort-arrow" aria-hidden="true">' . h($arrow) . '</span>'
        . '</a>';
}

if ($events) {
    $events = array_values(array_filter($events, static function(array $event) use ($eventPayments, $eventRefunds): bool {
        $id = (int)($event['id'] ?? 0);
        return abs((float)($eventPayments[$id] ?? 0)) > 0.0001 || abs((float)($eventRefunds[$id] ?? 0)) > 0.0001;
    }));
    $allowedEventSort = ['date', 'title', 'type', 'transactions', 'stripe_fee', 'net', 'paid_out', 'balance'];
    if (!in_array($eventSortKey, $allowedEventSort, true)) {
        $eventSortKey = 'date';
    }
    usort($events, function (array $a, array $b) use ($eventSortKey, $eventSortDir, $eventPayments, $eventRefunds, $eventStripeFees, $eventPayouts): int {
        $dir = $eventSortDir === 'asc' ? 1 : -1;
        $va = '';
        $vb = '';
        if ($eventSortKey === 'date') {
            $va = (string)($a['event_date'] ?? '');
            $vb = (string)($b['event_date'] ?? '');
        } elseif ($eventSortKey === 'title') {
            $va = mb_strtolower((string)($a['title'] ?? ''));
            $vb = mb_strtolower((string)($b['title'] ?? ''));
        } elseif ($eventSortKey === 'type') {
            $va = mb_strtolower((string)($a['event_type_name'] ?? ''));
            $vb = mb_strtolower((string)($b['event_type_name'] ?? ''));
        } elseif (in_array($eventSortKey, ['transactions', 'stripe_fee', 'net', 'paid_out', 'balance'], true)) {
            $ida = (int)($a['id'] ?? 0);
            $idb = (int)($b['id'] ?? 0);
            $paymentA = (float)($eventPayments[$ida] ?? 0.0);
            $paymentB = (float)($eventPayments[$idb] ?? 0.0);
            $refundA = (float)($eventRefunds[$ida] ?? 0.0);
            $refundB = (float)($eventRefunds[$idb] ?? 0.0);
            $feeA = (float)($eventStripeFees[$ida] ?? 0.0);
            $feeB = (float)($eventStripeFees[$idb] ?? 0.0);
            if ($eventSortKey === 'transactions') {
                $va = (string)($paymentA - $refundA);
                $vb = (string)($paymentB - $refundB);
            } elseif ($eventSortKey === 'stripe_fee') {
                $va = (string)$feeA;
                $vb = (string)$feeB;
            } elseif ($eventSortKey === 'paid_out') {
                $va = (string)(float)($eventPayouts[$ida] ?? 0.0);
                $vb = (string)(float)($eventPayouts[$idb] ?? 0.0);
            } elseif ($eventSortKey === 'balance') {
                $va = (string)max(0, $paymentA - $refundA - $feeA - (float)($eventPayouts[$ida] ?? 0.0));
                $vb = (string)max(0, $paymentB - $refundB - $feeB - (float)($eventPayouts[$idb] ?? 0.0));
            } else {
                $va = (string)($paymentA - $refundA - $feeA);
                $vb = (string)($paymentB - $refundB - $feeB);
            }
        }

        if (is_numeric($va) && is_numeric($vb)) {
            $na = (float)$va;
            $nb = (float)$vb;
            if ($na === $nb) {
                return 0;
            }
            return ($na < $nb ? -1 : 1) * $dir;
        }
        if ($va === $vb) {
            return 0;
        }
        return ($va < $vb ? -1 : 1) * $dir;
    });
}

admin_layout_start('Finance', 'finance');
?>
<style>
    .finance-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
    @media (min-width: 992px) {
        .finance-grid { grid-template-columns: 1.2fr 1fr; }
    }
    .pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #f0f5f0;
        color: #0f2d17;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .pill.negative { color: #a61b3f; background: #fde7ed; }
    .pill.positive { color: #0b6c29; background: #e7f6eb; }
    .pill.neutral { color: #0f2d17; background: #eef1ed; }
    .finance-tabs {
        display: inline-flex;
        gap: 0.5rem;
        background: #f0f3ed;
        padding: 6px;
        border-radius: 12px;
        margin-bottom: 1rem;
    }
    .finance-tab {
        border: 1px solid transparent;
        padding: 8px 14px;
        border-radius: 10px;
        font-weight: 700;
        color: #0f1f0f;
        background: transparent;
    }
    .finance-tab.active {
        background: #ffffff;
        border-color: var(--border-soft);
        box-shadow: 0 6px 18px rgba(0,0,0,0.06);
    }
    .finance-section { display: none; }
    .finance-section.active { display: block; }
    .finance-events-table thead th { text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.03em; white-space: nowrap; }
    .finance-events-table td { vertical-align: middle; }
    .finance-events-actions { display: inline-flex; gap: 0.4rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Manage credits, balances, and transactions</div>
        <h5 class="mb-0">Finance</h5>
    </div>
    <div class="finance-tabs" role="tablist" aria-label="Finance sections">
        <button class="finance-tab active" data-finance-tab="transactions" type="button" role="tab" aria-selected="true">Transactions</button>
        <button class="finance-tab" data-finance-tab="events" type="button" role="tab" aria-selected="false">Events</button>
        <button class="finance-tab" data-finance-tab="credits" type="button" role="tab" aria-selected="false">Credits</button>
        <button class="finance-tab" data-finance-tab="balances" type="button" role="tab" aria-selected="false">Balances</button>
        <?php if ($canCreateMiscPayment): ?><button class="finance-tab" data-finance-tab="requests" type="button" role="tab" aria-selected="false">Payment requests</button><?php endif; ?>
    </div>
</div>

<?php if ($canAdjustBalance): ?><div class="finance-grid finance-section" data-finance-section="credits">
    <section class="card-soft p-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Credits</div>
                <h6 class="mb-0 fw-bold">Adjust user credit</h6>
                <div class="text-muted small">Add or reduce a user’s credit balance. All changes are logged.</div>
            </div>
        </div>
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="adjust_balance">
            <div class="col-12">
                <label class="form-label">User</label>
                <select name="user_id" class="form-select" required>
                    <option value="">Select user...</option>
                    <?php foreach ($allUsers as $user): ?>
                        <?php
                        $uid = (int)($user['id'] ?? 0);
                        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        $label = $name !== '' ? $name . ' (' . ($user['email'] ?? '') . ')' : ($user['email'] ?? 'User #' . $uid);
                        ?>
                        <option value="<?php echo $uid; ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Amount</label>
                <input type="number" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Direction</label>
                <select name="direction" class="form-select">
                    <option value="credit">Credit</option>
                    <option value="debit">Reduce credit</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Transaction type</label>
                <select name="kind" class="form-select">
                    <option value="manual_credit">Manual credit</option>
                    <option value="refund">Refund</option>
                    <option value="manual_debit">Manual debit</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Reason (optional)</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. goodwill credit, adjustment, refund">
            </div>
            <div class="col-12">
                <label class="form-label">Reference (optional)</label>
                <input type="text" name="reference" class="form-control" placeholder="Booking ref, invoice, etc">
            </div>
            <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-success">Save adjustment</button>
            </div>
        </form>
    </section>
</div>
<?php endif; ?>

<?php if ($canCreateMiscPayment): ?><section class="card-soft p-3 finance-section" data-finance-section="requests"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><div class="small text-muted text-uppercase fw-bold">One-off payments</div><h6 class="mb-1">Payment requests</h6><div class="text-muted small">Requests are listed here until paid, failed, cancelled, or expired. Paid requests also appear in Transactions.</div></div><button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#newMiscPaymentModal"><i class="fa-solid fa-plus me-1"></i>New Request</button></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Created</th><th>Recipient</th><th>Description</th><th class="text-end">Amount</th><th>Status</th><th>Sent</th><th>Paid</th></tr></thead><tbody><?php foreach($miscPaymentRequests as $request): $status=strtolower((string)($request['status']??'sent')); $statusLabel=match($status){'paid'=>'Completed','failed'=>'Failed','cancelled'=>'Cancelled','expired'=>'Expired',default=>'Pending'}; $statusClass=match($status){'paid'=>'bg-success-subtle text-success','failed'=>'bg-danger-subtle text-danger','cancelled','expired'=>'bg-secondary-subtle text-secondary',default=>'bg-warning-subtle text-warning-emphasis'}; ?><tr><td class="small text-muted"><?php echo h(format_display_datetime($request['created_at']??null,'')); ?></td><td><?php echo h((string)$request['recipient_email']); ?></td><td><?php echo h((string)$request['description']); ?></td><td class="text-end"><?php echo format_price((float)$request['amount']); ?></td><td><span class="badge <?php echo h($statusClass); ?>"><?php echo h($statusLabel); ?></span></td><td class="small text-muted"><?php echo !empty($request['email_sent_at'])?h(format_display_datetime($request['email_sent_at'],'')):'—'; ?></td><td class="small text-muted"><?php echo !empty($request['paid_at'])?h(format_display_datetime($request['paid_at'],'')):'—'; ?></td></tr><?php endforeach; ?><?php if(!$miscPaymentRequests): ?><tr><td colspan="7" class="text-muted">No payment requests have been created yet.</td></tr><?php endif; ?></tbody></table></div></section>
<div class="modal fade" id="newMiscPaymentModal" tabindex="-1" aria-labelledby="newMiscPaymentModalLabel" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content"><input type="hidden" name="action" value="create_misc_payment"><input type="hidden" name="csrf" value="<?php echo h($miscPaymentCsrf); ?>"><div class="modal-header"><h5 class="modal-title" id="newMiscPaymentModalLabel">New payment request</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="small text-muted">The recipient receives a secure Stripe-hosted payment link by email.</p><div class="mb-3"><label class="form-label">Recipient email</label><input class="form-control" type="email" name="recipient_email" required></div><div class="mb-3"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255" required></div><div><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text">£</span><input class="form-control" type="number" name="amount" min="0.01" step="0.01" required></div></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Email payment request</button></div></form></div></div><?php endif; ?>

<section class="card-soft p-3 finance-section" data-finance-section="balances">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Balances</div>
            <h6 class="mb-0 fw-bold">User credit balances</h6>
            <div class="text-muted small">Top balances first.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>User</th>
                    <th class="text-end">Balance</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($balances as $bal): ?>
                    <?php
                    $name = trim(($bal['first_name'] ?? '') . ' ' . ($bal['last_name'] ?? ''));
                    $email = $bal['email'] ?? '';
                    $label = $name !== '' ? $name : ($email ?: ('User #' . $bal['user_id']));
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo h($label); ?></div>
                            <div class="text-muted small"><?php echo h($email ?: ('User #' . $bal['user_id'])); ?></div>
                        </td>
                        <td class="text-end fw-semibold"><?php echo '£' . number_format((float)$bal['balance'], 2); ?></td>
                        <td class="text-muted small"><?php echo h(format_display_datetime($bal['updated_at'] ?? null, '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$balances): ?>
                    <tr><td colspan="3" class="text-muted">No balances yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card-soft p-3 mt-3 finance-section" data-finance-section="events">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Events</div>
            <h6 class="mb-0 fw-bold">Stripe money by event</h6>
            <div class="text-muted small">Only events with recorded Stripe payments or refunds/credits are shown. Checkout ledger entries are excluded.</div>
        </div>
        <div class="small text-end" id="stripe-balance-summary"><div class="text-muted">Stripe balance: checking…</div><div class="text-muted">Stripe available: checking…</div></div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 finance-events-table">
            <thead class="table-light">
                <tr>
                    <th><?php echo finance_event_sort_link('title', 'Event', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th><?php echo finance_event_sort_link('date', 'Date', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th><?php echo finance_event_sort_link('type', 'Type', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('transactions', '£ Total', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('stripe_fee', '£ Stripe', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('net', '£ NET', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('paid_out', '£ Paid Out', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('balance', '£ Balance', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <?php
                    $eventId = (int)($event['id'] ?? 0);
                    $payments = (float)($eventPayments[$eventId] ?? 0.0);
                    $refunds = (float)($eventRefunds[$eventId] ?? 0.0);
                    $stripeFee = (float)($eventStripeFees[$eventId] ?? 0.0);
                    $transactionTotal = $payments - $refunds;
                    $net = $transactionTotal - $stripeFee;
                    $paidOut=(float)($eventPayouts[$eventId]??0);$remaining=max(0,$net-$paidOut);
                    $statementDescriptor=preg_replace('/[^A-Za-z0-9 .-]+/',' ',(string)($event['title']??''));$statementDescriptor=trim(preg_replace('/\s+/',' ',$statementDescriptor));if($statementDescriptor==='')$statementDescriptor='ILDRA EVENT '.$eventId;$statementDescriptor=substr($statementDescriptor,0,22);
                    $dateLabel = $event['event_date'] ?: 'Date TBC';
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo h($event['title'] ?? 'Untitled'); ?></td>
                        <td><?php echo h($dateLabel); ?></td>
                        <td class="text-muted"><?php echo h($event['event_type_name'] ?? ''); ?></td>
                        <td class="text-end"><?php echo format_price($transactionTotal); ?></td>
                        <td class="text-end"><?php echo format_price($stripeFee); ?></td>
                        <td class="text-end fw-semibold"><?php echo format_price($net); ?></td>
                        <td class="text-end"><?php echo format_price($paidOut); ?></td>
                        <td class="text-end fw-semibold"><?php echo format_price($remaining); ?></td>
                        <td class="text-end">
                            <div class="finance-events-actions">
                                <a class="btn btn-sm btn-outline-success" href="finance_event.php?event_id=<?php echo $eventId; ?>">View</a>
                                <?php if ($canCreatePayout): ?><button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#collectStripeModal" data-event-id="<?php echo $eventId; ?>" data-event-title="<?php echo h($event['title'] ?? 'Untitled'); ?>" data-descriptor="<?php echo h($statementDescriptor); ?>" data-event-max="<?php echo h(number_format($remaining,2,'.','')); ?>" data-paid="<?php echo h(number_format($paidOut,2,'.','')); ?>" <?php echo !$stripeIsTest||!in_array($currentRole,['superadmin','admin'],true)?'disabled':''; ?>>Collect from Stripe</button><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$events): ?>
                    <tr><td colspan="9" class="text-muted">No events have recorded Stripe money movements yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade" id="collectStripeModal" tabindex="-1" aria-labelledby="collectStripeModalLabel" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content" id="collect-payout-form"><input type="hidden" name="action" value="create_event_payout"><input type="hidden" name="csrf" value="<?php echo h($financePayoutCsrf); ?>"><input type="hidden" name="event_id" id="collect-event-id"><div class="modal-header"><h5 class="modal-title" id="collectStripeModalLabel">Collect from Stripe</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-warning mb-3">Stripe test mode — no real money will move.</div><div class="mb-3"><label class="form-label">Ride / event</label><div class="form-control bg-light" id="collect-event-title"></div></div><div class="row g-3 mb-3"><div class="col-6"><div class="small text-muted">Event available</div><div class="fw-semibold" id="collect-event-available">—</div><div class="small text-muted" id="collect-already-paid"></div></div><div class="col-6"><div class="small text-muted">Stripe available balance</div><div class="fw-semibold" id="collect-stripe-balance">Checking…</div></div></div><div class="mb-3"><label class="form-label" for="collect-amount">Amount to collect</label><div class="input-group"><span class="input-group-text">£</span><input class="form-control" id="collect-amount" name="amount" type="number" min="0.01" step="0.01" required></div><div class="form-text" id="collect-maximum"></div></div><div><label class="form-label" for="collect-notes">Notes</label><textarea class="form-control" id="collect-notes" name="notes" rows="3" maxlength="255"></textarea></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" id="collect-submit" disabled>Collect</button></div></form></div></div>

<section class="card-soft p-3 mt-3 finance-section" data-finance-section="transactions">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Transactions</div>
            <h6 class="mb-0 fw-bold">Recent finance activity</h6>
            <div class="text-muted small">Checkouts, refunds, and adjustments are logged here.</div>
        </div>
    </div>
    <form method="get" id="transaction-filter-form" class="mb-2 text-end"><input type="hidden" name="tab" value="transactions"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a class="btn btn-sm btn-link" href="finance.php?tab=transactions">Clear</a></form>
    <?php echo admin_table_record_count($transactionTable,'transaction','transactions'); ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <?php foreach($transactionColumns as$key=>$column): ?><th><?php echo admin_table_heading($key,$column,$sortKey,$sortDir); ?></th><?php endforeach; ?>
                </tr>
                <tr class="admin-table-filter-row"><?php foreach($transactionColumns as$key=>$column): ?><th><?php echo admin_table_filter($key,$column,$transactionFilters); ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($transactionsDisplayed as $tx): ?>
                    <?php
                    $name = trim(($tx['first_name'] ?? '') . ' ' . ($tx['last_name'] ?? ''));
                    $email = $tx['email'] ?? '';
                    $label = $name !== '' ? $name : ($email ?: ($tx['user_id'] ? ('User #' . $tx['user_id']) : 'Guest'));
                    $amountVal = (float)$tx['amount'];
                    $isPositive = $amountVal > 0;
                    $isNegative = $amountVal < 0;
                    $amountPill = $isPositive ? 'positive' : ($isNegative ? 'negative' : 'neutral');
                    $notesText = finance_transaction_notes($tx);
                    $meta = $tx['metadata'] ?? [];
                    $referenceText = (string)($tx['reference'] ?? '');
                    $referenceTitle = '';
                    if (($tx['type'] ?? '') === 'payment_stripe_misc' && is_array($meta)) {
                        $requestId = (int)($meta['misc_payment_request_id'] ?? 0);
                        if ($requestId > 0) {
                            $referenceTitle = $referenceText !== '' ? 'Stripe Checkout reference: ' . $referenceText : '';
                            $referenceText = 'MPR-' . $requestId;
                        }
                    }
                    if (($tx['type'] ?? '') === 'stripe_payout') {
                        $referenceTitle = $referenceText !== '' ? 'Stripe payout reference: ' . $referenceText : '';
                        $referenceText = 'PYO-' . (int)($tx['id'] ?? 0);
                    }
                    ?>
                    <tr>
                        <td class="text-muted small"><?php echo h(format_display_datetime($tx['created_at'] ?? null, '')); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($label); ?></div>
                            <?php if ($email): ?><div class="text-muted small"><?php echo admin_table_value($email, 'email'); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo h(finance_transaction_movement($tx)); ?></td>
                        <td class="small"><?php echo h(finance_transaction_applies_to($tx, $transactionEventLabels, $transactionBookingEventLabels, $transactionBookingKinds)); ?></td>
                        <td>
                            <span class="pill <?php echo $amountPill; ?>">
                                <?php echo $isPositive ? '+' : ($isNegative ? '-' : ''); ?>£<?php echo number_format(abs($amountVal), 2); ?>
                            </span>
                        </td>
                        <td><?php echo $tx['balance_after'] !== null ? '£' . number_format((float)$tx['balance_after'], 2) : '—'; ?></td>
                        <td><?php if ($referenceTitle !== ''): ?><span title="<?php echo h($referenceTitle); ?>" class="text-decoration-underline text-decoration-style-dotted"><?php echo h($referenceText); ?></span><?php else: ?><?php echo h($referenceText); ?><?php endif; ?></td>
                        <td class="text-muted small"><?php echo h($notesText); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactionsDisplayed): ?>
                    <tr><td colspan="7" class="text-muted">No transactions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_table_pagination($transactionTable); ?>

<?php if(is_array($payoutSummary)): ?><div class="modal fade" id="payoutCompleteModal" tabindex="-1" aria-labelledby="payoutCompleteModalLabel" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="payoutCompleteModalLabel">Payout requested</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><dl class="row mb-0"><dt class="col-sm-5">Ride / event</dt><dd class="col-sm-7"><?php echo h($payoutSummary['event_title']??''); ?></dd><dt class="col-sm-5">Date requested</dt><dd class="col-sm-7"><?php echo h(format_display_datetime($payoutSummary['requested_at']??null,'')); ?></dd><dt class="col-sm-5">Expected payout date</dt><dd class="col-sm-7"><?php echo !empty($payoutSummary['arrival_date'])?h(date('j M Y',(int)$payoutSummary['arrival_date'])):'Stripe has not supplied a date'; ?></dd><dt class="col-sm-5">Amount</dt><dd class="col-sm-7"><?php echo format_price((float)($payoutSummary['amount']??0)); ?></dd><dt class="col-sm-5">Bank reference</dt><dd class="col-sm-7"><?php echo h($payoutSummary['statement_descriptor']??''); ?></dd><dt class="col-sm-5">Stripe reference</dt><dd class="col-sm-7 text-break"><?php echo h($payoutSummary['stripe_payout_id']??''); ?></dd><dt class="col-sm-5">Status</dt><dd class="col-sm-7 text-capitalize"><?php echo h($payoutSummary['status']??''); ?></dd></dl></div><div class="modal-footer"><button class="btn btn-success" type="button" data-bs-dismiss="modal">Done</button></div></div></div></div><?php endif; ?>
<script>
    (() => {
        const tabs = document.querySelectorAll('[data-finance-tab]');
        const sections = document.querySelectorAll('[data-finance-section]');
        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab') || 'transactions';
        const showSection = (key) => {
            tabs.forEach(tab => {
                const isActive = tab.dataset.financeTab === key;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            sections.forEach(section => {
                section.classList.toggle('active', section.dataset.financeSection === key);
            });
        };
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const key = tab.dataset.financeTab;
                showSection(key);
                const nextParams = new URLSearchParams(window.location.search);
                nextParams.set('tab', key);
                const nextUrl = window.location.pathname + '?' + nextParams.toString();
                window.history.replaceState({}, '', nextUrl);
            });
        });
        showSection(initialTab);

        const collectModal = document.getElementById('collectStripeModal');
        collectModal?.addEventListener('show.bs.modal', async event => {
            const button = event.relatedTarget;
            const eventMaximum = Number(button?.dataset.eventMax || 0);
            const paid = Number(button?.dataset.paid || 0);
            document.getElementById('collect-event-id').value = button?.dataset.eventId || '';
            const eventTitle = button?.dataset.eventTitle || '';
            const descriptor = button?.dataset.descriptor || '';
            document.getElementById('collect-event-title').textContent = eventTitle + (descriptor ? ' — bank ref: ' + descriptor : '');
            const amount = document.getElementById('collect-amount');
            const submit = document.getElementById('collect-submit');
            document.getElementById('collect-event-available').textContent = '£' + eventMaximum.toFixed(2);
            document.getElementById('collect-already-paid').textContent = paid > 0 ? 'Already paid out: £' + paid.toFixed(2) : 'No earlier event payouts';
            document.getElementById('collect-stripe-balance').textContent = 'Checking…';
            amount.value = ''; amount.disabled = true; submit.disabled = true;
            document.getElementById('collect-notes').value = '';
            try {
                const response = await fetch('finance.php?stripe_balance=1', {headers:{'Accept':'application/json'}});
                const result = await response.json();
                if (!result.ok) throw new Error(result.error || 'Could not read Stripe balance.');
                const stripeAvailable = Number(result.available || 0);
                const maximum = Math.max(0, Math.min(eventMaximum, stripeAvailable));
                const stripeBalance = Number(result.balance || stripeAvailable);
                document.getElementById('collect-stripe-balance').textContent = '£' + stripeAvailable.toFixed(2) + ' available (balance £' + stripeBalance.toFixed(2) + ')';
                document.getElementById('collect-maximum').textContent = 'Maximum payout: £' + maximum.toFixed(2) + ' (the lower of event funds and Stripe available balance).';
                amount.max = maximum.toFixed(2); amount.value = maximum > 0 ? maximum.toFixed(2) : ''; amount.disabled = maximum <= 0; submit.disabled = maximum <= 0;
            } catch (error) {
                document.getElementById('collect-stripe-balance').textContent = 'Unavailable';
                document.getElementById('collect-maximum').textContent = error.message;
            }
        });

        fetch('finance.php?stripe_balance=1', {headers:{'Accept':'application/json'}})
            .then(response => response.json())
            .then(result => {
                if (!result.ok) throw new Error();
                const summary = document.getElementById('stripe-balance-summary');
                if (summary) summary.innerHTML = '<div class="text-muted">Stripe balance: £' + Number(result.balance || 0).toFixed(2) + '</div><div class="text-muted">Stripe available: £' + Number(result.available || 0).toFixed(2) + '</div>';
            })
            .catch(() => { const summary=document.getElementById('stripe-balance-summary'); if(summary)summary.textContent='Stripe balance unavailable'; });

        window.addEventListener('load', () => {
            const payoutCompleteModal = document.getElementById('payoutCompleteModal');
            if (payoutCompleteModal && window.bootstrap) {
                document.body.appendChild(payoutCompleteModal);
                new window.bootstrap.Modal(payoutCompleteModal).show();
            }
        });

    })();
</script>

<?php admin_layout_end(); ?>
