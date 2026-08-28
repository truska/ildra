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

ensure_finance_tables($pdo, $alerts);
$stripeConfig = stripe_config($config);
$stripeIsTest = str_starts_with((string)($stripeConfig['secret_key'] ?? ''), 'sk_test_');
$payoutSummary=$_SESSION['finance_payout_summary']??null;unset($_SESSION['finance_payout_summary']);
if(empty($_SESSION['finance_payout_csrf']))$_SESSION['finance_payout_csrf']=bin2hex(random_bytes(24));
if(empty($_SESSION['finance_payout_key']))$_SESSION['finance_payout_key']=bin2hex(random_bytes(16));
$financePayoutCsrf=(string)$_SESSION['finance_payout_csrf'];$financePayoutKey=(string)$_SESSION['finance_payout_key'];

function finance_event_payout_capacity(PDO $pdo,int $eventId):array{
    $payments=0.0;$fees=0.0;
    $stmt=$pdo->prepare("SELECT ft.amount,ft.metadata,SUM(CASE WHEN bi.event_id=:event_id THEN bi.price ELSE 0 END) event_amount,SUM(bi.price) booking_amount FROM finance_transactions ft JOIN bookings b ON b.booking_ref=ft.reference JOIN booking_items bi ON bi.booking_id=b.new_id WHERE ft.type='payment_stripe' GROUP BY ft.id,ft.amount,ft.metadata HAVING event_amount>0");$stmt->execute([':event_id'=>$eventId]);
    foreach($stmt->fetchAll()?:[] as$row){$total=(float)($row['booking_amount']??0);if($total<=0)continue;$share=min(1,max(0,(float)$row['event_amount']/$total));$payments+=(float)$row['amount']*$share;$meta=json_decode((string)($row['metadata']??''),true);$fees+=(is_array($meta)&&isset($meta['stripe_fee'])?(float)$meta['stripe_fee']:0)*$share;}
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(ft.amount),0) FROM finance_transactions ft JOIN booking_items bi ON bi.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata,'$.booking_item_id')) AS UNSIGNED) WHERE ft.type='entry_refund' AND bi.event_id=:event_id");$stmt->execute([':event_id'=>$eventId]);$refunds=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM finance_transactions WHERE type='stripe_payout' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.event_id')) AS UNSIGNED)=:event_id");$stmt->execute([':event_id'=>$eventId]);$paid=(float)$stmt->fetchColumn();
    $net=$payments-$refunds-$fees;return['payments'=>$payments,'refunds'=>$refunds,'stripe_fee'=>$fees,'net'=>$net,'paid'=>$paid,'remaining'=>max(0,$net-$paid)];
}

if(isset($_GET['stripe_balance'])){
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
        if(!in_array($currentRole,['superadmin','admin'],true))$alerts[]=['type'=>'danger','message'=>'Only Admin and SuperAdmin users can create payouts.'];
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
    } elseif ($action === 'adjust_balance') {
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
$sortKey = $_GET['sort'] ?? 'when';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$transactionsDisplayed = fetch_finance_transactions($pdo, 500, (string)$sortKey, strtoupper($sortDir));
$transactionUserOptions=[];$transactionTypeOptions=[];
foreach($transactionsDisplayed as$tx){
    $uid=(string)($tx['user_id']??'');$name=trim((string)($tx['first_name']??'').' '.(string)($tx['last_name']??''));$email=trim((string)($tx['email']??''));
    if($uid!=='')$transactionUserOptions[$uid]=$name!==''?$name.($email!==''?' ('.$email.')':''):($email?:'User #'.$uid);
    $type=(string)($tx['type']??'');if($type!=='')$transactionTypeOptions[$type]=ucwords(str_replace('_',' ',$type));
}
natcasesort($transactionUserOptions);natcasesort($transactionTypeOptions);
$transactionFilterForm='transaction-filter-form';
$transactionColumns=[
    'when'=>['label'=>'When','sortable'=>true,'filter'=>'text','placeholder'=>'Search when','form'=>$transactionFilterForm,'value'=>static fn(array $r):string=>format_display_datetime($r['created_at']??null,''),'sort_value'=>static fn(array $r):string=>(string)($r['created_at']??'')],
    'user'=>['label'=>'User','sortable'=>true,'filter'=>'select','form'=>$transactionFilterForm,'options'=>$transactionUserOptions,'value'=>static fn(array $r):string=>(string)($r['user_id']??'')],
    'type'=>['label'=>'Type','sortable'=>true,'filter'=>'select','form'=>$transactionFilterForm,'options'=>$transactionTypeOptions],
    'amount'=>['label'=>'Amount','sortable'=>true,'filter'=>'text','placeholder'=>'Search amount','form'=>$transactionFilterForm,'compare'=>'number'],
    'balance'=>['label'=>'Balance after','field'=>'balance_after','sortable'=>true,'filter'=>'text','placeholder'=>'Search balance','form'=>$transactionFilterForm,'compare'=>'number'],
    'reference'=>['label'=>'Reference','sortable'=>true,'filter'=>'text','placeholder'=>'Search reference','form'=>$transactionFilterForm],
    'notes'=>['label'=>'Notes','sortable'=>true,'filter'=>'text','placeholder'=>'Search notes','form'=>$transactionFilterForm,'value'=>static function(array $r):string{$notes=(string)($r['notes']??'');$meta=$r['metadata']??[];if(is_array($meta)&&($r['type']??'')==='entry_refund'&&!empty($meta['actor_name']))$notes='Entry refunded and withdrawn by admin ('.(string)$meta['actor_name'].')';return $notes;}],
];
$transactionTable=admin_table_prepare($transactionsDisplayed,$transactionColumns,'when','desc');$transactionsDisplayed=$transactionTable['rows'];$transactionFilters=$transactionTable['filters'];$sortKey=$transactionTable['sort_key'];$sortDir=$transactionTable['sort_dir'];
$events = fetchEvents($pdo, false);
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
                        SUM(ft.amount) AS refunds_total
                    FROM finance_transactions ft
                    JOIN booking_items bi
                        ON bi.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.booking_item_id')) AS UNSIGNED)
                    WHERE ft.type = 'entry_refund'
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
    </div>
</div>

<div class="finance-grid finance-section" data-finance-section="credits">
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
                                <button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#collectStripeModal" data-event-id="<?php echo $eventId; ?>" data-event-title="<?php echo h($event['title'] ?? 'Untitled'); ?>" data-descriptor="<?php echo h($statementDescriptor); ?>" data-event-max="<?php echo h(number_format($remaining,2,'.','')); ?>" data-paid="<?php echo h(number_format($paidOut,2,'.','')); ?>" <?php echo !$stripeIsTest||!in_array($currentRole,['superadmin','admin'],true)?'disabled':''; ?>>Collect from Stripe</button>
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
                    $notesText = (string)($tx['notes'] ?? '');
                    $meta = $tx['metadata'] ?? [];
                    if (is_array($meta) && ($tx['type'] ?? '') === 'entry_refund' && !empty($meta['actor_name'])) {
                        $notesText = 'Entry refunded and withdrawn by admin (' . (string)$meta['actor_name'] . ')';
                    }
                    ?>
                    <tr>
                        <td class="text-muted small"><?php echo h(format_display_datetime($tx['created_at'] ?? null, '')); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($label); ?></div>
                            <?php if ($email): ?><div class="text-muted small"><?php echo admin_table_value($email, 'email'); ?></div><?php endif; ?>
                        </td>
                        <td class="text-capitalize"><?php echo h(str_replace('_', ' ', $tx['type'])); ?></td>
                        <td>
                            <span class="pill <?php echo $amountPill; ?>">
                                <?php echo $isPositive ? '+' : ($isNegative ? '-' : ''); ?>£<?php echo number_format(abs($amountVal), 2); ?>
                            </span>
                        </td>
                        <td><?php echo $tx['balance_after'] !== null ? '£' . number_format((float)$tx['balance_after'], 2) : '—'; ?></td>
                        <td><?php echo h($tx['reference'] ?? ''); ?></td>
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
