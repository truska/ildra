<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../finance.php';
require_once __DIR__ . '/../bookings_store.php';
require_once __DIR__ . '/table_sort.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageFinance = in_array($currentRole, ['superadmin', 'admin', 'manager'], true);
if (!$canManageFinance) {
    header('Location: index.php');
    exit;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$event = $eventId > 0 ? fetchEventById($pdo, $eventId) : null;

ensure_finance_tables($pdo, $alerts);
ensure_bookings_tables($pdo);

$entries = [];
$refundByItemId = [];
$transactions = [];
$grossFees = 0.0;
$refundTotal = 0.0;
$withdrawnCount = 0;
$refundedCount = 0;
$entrySortKey = $_GET['entry_sort'] ?? 'placed';
$entrySortDir = strtolower($_GET['entry_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$txSortKey = $_GET['tx_sort'] ?? 'date';
$txSortDir = strtolower($_GET['tx_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

function finance_detail_sort_link(string $prefix, string $key, string $label, string $currentKey, string $currentDir): string
{
    $dir = ($currentKey === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '↕';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? '↑' : '↓';
    }
    $query = $_GET;
    $query[$prefix . '_sort'] = $key;
    $query[$prefix . '_dir'] = $dir;
    $url = '?' . http_build_query($query);
    return '<a class="text-decoration-none text-dark sort-link" href="' . h($url) . '">'
        . h($label)
        . '<span class="sort-arrow" aria-hidden="true">' . h($arrow) . '</span>'
        . '</a>';
}

if ($event && $pdo) {
    $stmt = $pdo->prepare("
        SELECT
            bi.*,
            b.booking_ref,
            b.contact_name,
            b.created_at AS booking_created_at,
            JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.class_label')) AS class_label,
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.rider_name')), ''),
                NULLIF(b.contact_name, ''),
                b.contact_email
            ) AS person_name
        FROM booking_items bi
        LEFT JOIN bookings b ON bi.booking_id = b.new_id
        WHERE bi.event_id = :event_id
        ORDER BY b.created_at DESC, bi.id DESC
    ");
    $stmt->execute([':event_id' => $eventId]);
    $entries = $stmt->fetchAll() ?: [];

    foreach ($entries as $entry) {
        $grossFees += (float)($entry['price'] ?? 0);
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_count,
               SUM(CASE WHEN COALESCE(is_withdrawn, 0) = 1 THEN 1 ELSE 0 END) AS withdrawn_count
        FROM booking_items
        WHERE event_id = :event_id
    ");
    $stmt->execute([':event_id' => $eventId]);
    $countRow = $stmt->fetch() ?: [];
    $withdrawnCount = (int)($countRow['withdrawn_count'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT
            CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) AS UNSIGNED) AS booking_item_id,
            SUM(ABS(amount)) AS refund_total
        FROM finance_transactions
        WHERE type IN ('entry_refund','entry_stripe_refund','entry_credit')
        GROUP BY booking_item_id
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $itemId = (int)($row['booking_item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $refundByItemId[$itemId] = (float)($row['refund_total'] ?? 0);
    }

    $refs = [];
    $eventAmountByRef = [];
    $eventEntryIdsByRef = [];
    foreach ($entries as $entry) {
        $ref = trim((string)($entry['booking_ref'] ?? ''));
        if ($ref !== '') {
            $refs[$ref] = true;
            $eventAmountByRef[$ref] = ($eventAmountByRef[$ref] ?? 0) + (float)($entry['price'] ?? 0);
            $eventEntryIdsByRef[$ref][] = (int)($entry['id'] ?? 0);
        }
        $itemId = (int)($entry['id'] ?? 0);
        $refundTotal += $refundByItemId[$itemId] ?? 0.0;
    }

    $types = ['payment_stripe'];
    if ($refs) {
        $refValues = array_keys($refs);
        $placeholders = implode(',', array_fill(0, count($refValues), '?'));
        $typePlaceholders = implode(',', array_fill(0, count($types), '?'));
        $bookingAmountByRef = [];
        $totalStmt = $pdo->prepare("SELECT b.booking_ref, SUM(bi.price) AS booking_amount FROM bookings b JOIN booking_items bi ON bi.booking_id=b.new_id WHERE b.booking_ref IN ($placeholders) GROUP BY b.booking_ref");
        $totalStmt->execute($refValues);
        foreach($totalStmt->fetchAll()?:[] as $totalRow)$bookingAmountByRef[(string)$totalRow['booking_ref']]=(float)$totalRow['booking_amount'];

        $stmt = $pdo->prepare("
            SELECT ft.*,
                   COALESCE(
                       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.contact_name')), ''),
                       NULLIF(b.contact_name, ''),
                       NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                       u.email
                   ) AS person_name
            FROM finance_transactions ft
            LEFT JOIN bookings b ON b.booking_ref = ft.reference
            LEFT JOIN users u ON u.id = ft.user_id
            WHERE ft.reference IN ($placeholders)
              AND ft.type IN ($typePlaceholders)
            ORDER BY ft.created_at DESC, ft.id DESC
        ");
        $stmt->execute(array_merge($refValues, $types));
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $row['is_booking_level'] = true;
            $meta = json_decode((string)($row['metadata'] ?? ''), true);
            $ref=(string)($row['reference']??'');$bookingAmount=(float)($bookingAmountByRef[$ref]??0);$share=$bookingAmount>0?min(1,max(0,(float)($eventAmountByRef[$ref]??0)/$bookingAmount)):0;
            $row['amount']=(float)($row['amount']??0)*$share;
            $row['stripe_fee'] = (is_array($meta) && isset($meta['stripe_fee']) ? (float)$meta['stripe_fee'] : 0.0)*$share;
            $row['entry_ids'] = array_values(array_filter($eventEntryIdsByRef[$ref] ?? []));
            $transactions[] = $row;
        }
    }

    $stmt = $pdo->prepare("
        SELECT ft.*,
               COALESCE(
                   NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.rider_name')), ''),
                   NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.contact_name')), ''),
                   NULLIF(b.contact_name, ''),
                   NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                   u.email
               ) AS person_name
        FROM finance_transactions ft
        JOIN booking_items bi
            ON bi.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.booking_item_id')) AS UNSIGNED)
        LEFT JOIN bookings b ON b.new_id = bi.booking_id
        LEFT JOIN users u ON u.id = ft.user_id
        WHERE ft.type IN ('entry_refund','entry_stripe_refund','entry_credit')
          AND bi.event_id = :event_id
        ORDER BY ft.created_at DESC, ft.id DESC
    ");
    $stmt->execute([':event_id' => $eventId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $meta=json_decode((string)($row['metadata']??''),true);
        $row['entry_ids']=[(int)($meta['booking_item_id']??0)];
        $row['amount'] = -abs((float)($row['amount'] ?? 0));
        $row['stripe_fee'] = 0.0;
        $transactions[] = $row;
    }

    $refundedIds = [];
    $stmt = $pdo->prepare("
        SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) AS booking_item_id
        FROM finance_transactions
        WHERE type IN ('entry_refund','entry_stripe_refund','entry_credit')
          AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) IN (
            SELECT id FROM booking_items WHERE event_id = :event_id AND COALESCE(is_withdrawn, 0) = 1
          )
    ");
    $stmt->execute([':event_id' => $eventId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = (int)($row['booking_item_id'] ?? 0);
        if ($id > 0) {
            $refundedIds[$id] = true;
        }
    }
    $refundedCount = count($refundedIds);

    $stmt=$pdo->prepare("SELECT ft.*,NULL person_name FROM finance_transactions ft WHERE ft.type='stripe_payout' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata,'$.event_id')) AS UNSIGNED)=:event_id ORDER BY ft.created_at DESC,ft.id DESC");
    $stmt->execute([':event_id'=>$eventId]);
    foreach($stmt->fetchAll()?:[] as$row){$row['amount']=-abs((float)($row['amount']??0));$row['stripe_fee']=0.0;$row['entry_ids']=[];$transactions[]=$row;}
}

$incomeTransactions=array_values(array_filter($transactions,static fn(array$tx):bool=>(string)($tx['type']??'')!=='stripe_payout'));
$transactionTotal = array_sum(array_map(static fn(array $tx): float => (float)($tx['amount'] ?? 0), $incomeTransactions));
$stripeFeeTotal = array_sum(array_map(static fn(array $tx): float => (float)($tx['stripe_fee'] ?? 0), $incomeTransactions));
$netRevenue = $transactionTotal - $stripeFeeTotal;
$txSearch = trim((string)($_GET['tx_search'] ?? ''));
$txTypeFilter = trim((string)($_GET['tx_type'] ?? ''));
$txFilters=[];foreach(['id','date','reference','entry','person','amount','fee','net'] as $key)$txFilters[$key]=trim((string)($_GET['tx_'.$key]??''));
$transactionTypeOptions = [];$transactionIdOptions=[];$transactionEntryOptions=[];
foreach ($transactions as $tx){$transactionTypeOptions[(string)($tx['type'] ?? '')] = ucwords(str_replace('_', ' ', (string)($tx['type'] ?? '')));$transactionIdOptions['T-'.(int)($tx['id']??0)]='T-'.(int)($tx['id']??0);foreach($tx['entry_ids']??[] as$entryId)$transactionEntryOptions['E-'.(int)$entryId]='E-'.(int)$entryId;}
natcasesort($transactionIdOptions);natcasesort($transactionEntryOptions);
if ($txSearch !== '' || $txTypeFilter !== '' || array_filter($txFilters,static fn(string $value):bool=>$value!=='')) {
    $transactions = array_values(array_filter($transactions, static function(array $tx) use ($txSearch, $txTypeFilter, $txFilters): bool {
        if ($txTypeFilter !== '' && (string)($tx['type'] ?? '') !== $txTypeFilter) return false;
        $entryIds=implode(' ',array_map(static fn($id):string=>'E-'.(int)$id,$tx['entry_ids']??[]));
        $fee=(float)($tx['stripe_fee']??0);$amount=(float)($tx['amount']??0);
        $values=['id'=>'T-'.(int)($tx['id']??0),'date'=>format_display_datetime($tx['created_at']??null,''),'reference'=>(string)($tx['reference']??''),'entry'=>$entryIds,'person'=>(string)($tx['person_name']??''),'amount'=>number_format($amount,2,'.',''),'fee'=>number_format($fee,2,'.',''),'net'=>number_format($amount-$fee,2,'.','')];
        foreach($txFilters as $key=>$filter)if($filter!==''&&stripos($values[$key]??'',$filter)===false)return false;
        if ($txSearch === '') return true;
        $haystack = implode(' ', ['T-'.(int)($tx['id']??0),(string)($tx['created_at'] ?? ''),(string)($tx['type'] ?? ''),(string)($tx['reference'] ?? ''),$entryIds,(string)($tx['person_name'] ?? ''),(string)($tx['notes'] ?? ''),(string)($tx['amount'] ?? '')]);
        return stripos($haystack, $txSearch) !== false;
    }));
}
$totalEntryCount=count($entries);
$entryFilters=[];foreach(['id','booking_ref','placed','person','fee','refund','net'] as $key)$entryFilters[$key]=trim((string)($_GET['entry_'.$key]??''));
$linkedEntryIds=array_values(array_filter(array_map('intval',explode(',',(string)($_GET['entry_ids']??'')))));
$entryClassFilter=trim((string)($_GET['entry_class']??''));$entryClassOptions=[];foreach($entries as $entry){$class=(string)($entry['class_label']??'');if($class!=='')$entryClassOptions[$class]=$class;}natcasesort($entryClassOptions);
if($linkedEntryIds||$entryClassFilter!==''||array_filter($entryFilters,static fn(string $value):bool=>$value!==''))$entries=array_values(array_filter($entries,static function(array $entry)use($linkedEntryIds,$entryClassFilter,$entryFilters,$refundByItemId):bool{$id=(int)($entry['id']??0);$fee=(float)($entry['price']??0);$refund=(float)($refundByItemId[$id]??0);if($linkedEntryIds&&!in_array($id,$linkedEntryIds,true))return false;if($entryClassFilter!==''&&(string)($entry['class_label']??'')!==$entryClassFilter)return false;$values=['id'=>'E-'.$id,'booking_ref'=>(string)($entry['booking_ref']??''),'placed'=>format_display_datetime($entry['booking_created_at']??null,''),'person'=>(string)($entry['person_name']??''),'fee'=>number_format($fee,2,'.',''),'refund'=>number_format($refund,2,'.',''),'net'=>number_format($fee-$refund,2,'.','')];foreach($entryFilters as$key=>$filter)if($filter!==''&&stripos($values[$key]??'',$filter)===false)return false;return true;}));
$acceptedCount = max(0, $totalEntryCount - $withdrawnCount);
$withdrawnOnlyCount = max(0, $withdrawnCount - $refundedCount);
$pageTitle = $event ? 'Finance · ' . ($event['title'] ?? 'Event') : 'Event finance';
admin_layout_start($pageTitle, 'finance');
?>
<style>
    .finance-detail-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
    .finance-detail-header { display: flex; gap: 1rem; flex-wrap: wrap; }
    .finance-detail-header .meta { min-width: 180px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Event finance</div>
        <h5 class="mb-0"><?php echo h($event['title'] ?? 'Event'); ?></h5>
        <?php if ($event): ?>
            <div class="text-muted small"><?php echo h($event['event_date'] ?: 'Date TBC'); ?> · <?php echo h($event['event_type_name'] ?? ''); ?></div>
        <?php endif; ?>
    </div>
    <a class="btn btn-outline-secondary" href="finance.php?tab=events">Back to finance</a>
</div>

<?php if (!$event): ?>
    <section class="card-soft p-3">
        <div class="text-muted">Event not found.</div>
    </section>
<?php else: ?>
    <section class="card-soft p-3 mb-3">
        <div class="finance-detail-header">
            <div class="meta">
                <div class="small text-muted">Total transactions</div>
                <div class="fw-semibold"><?php echo format_price($transactionTotal); ?></div>
            </div>
            <div class="meta">
                <div class="small text-muted">Stripe fee</div>
                <div class="fw-semibold"><?php echo format_price($stripeFeeTotal); ?></div>
            </div>
            <div class="meta">
                <div class="small text-muted">Net income</div>
                <div class="fw-semibold"><?php echo format_price($netRevenue); ?></div>
            </div>
            <div class="meta">
                <div class="small text-muted">Event entries</div>
                <div class="fw-semibold"><?php echo $acceptedCount; ?> Accepted</div>
                <?php if (!empty($event['capacity_enabled']) && (int)($event['capacity_limit'] ?? 0) > 0): ?>
                    <div class="text-muted small">Event limited to <?php echo (int)($event['capacity_limit'] ?? 0); ?></div>
                <?php endif; ?>
                <div class="text-muted small"><?php echo $withdrawnCount; ?> Withdrawn</div>
                <div class="text-muted small">
                    Of which <?php echo $withdrawnOnlyCount; ?> Withdrawn Only, <?php echo $refundedCount; ?> Withdrawn &amp; Refunded
                </div>
            </div>
        </div>
    </section>

    <div class="finance-detail-grid">
        <section class="card-soft p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Transactions</div>
                <div class="text-muted small">Stripe payments and refunds/credits only</div>
            </div>
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><div class="small text-muted">Use an Entry ID such as E-123 to show its payment and any matching refund, or a Transaction ID such as T-456.</div><div class="text-nowrap"><button class="btn btn-sm btn-outline-success" form="tx-column-filter-form">Filter</button> <a class="btn btn-sm btn-link" href="finance_event.php?event_id=<?php echo $eventId; ?>">Clear</a></div></div>
            <form method="get" id="tx-column-filter-form"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"></form>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo finance_detail_sort_link('tx', 'id', 'Trans ID', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'date', 'Date', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'type', 'Type', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'reference', 'Reference', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'entry', 'ID', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'person', 'Person', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('tx', 'amount', '£ Amount', (string)$txSortKey, (string)$txSortDir); ?></th><th class="text-end"><?php echo finance_detail_sort_link('tx', 'fee', '£ Stripe', (string)$txSortKey, (string)$txSortDir); ?></th><th class="text-end"><?php echo finance_detail_sort_link('tx', 'net', 'Net', (string)$txSortKey, (string)$txSortDir); ?></th>
                        </tr>
                        <tr class="admin-table-filter-row"><th><select class="form-select form-select-sm" form="tx-column-filter-form" name="tx_id" data-auto-select><option value="">All IDs</option><?php foreach($transactionIdOptions as$value=>$label):?><option value="<?php echo h($value); ?>" <?php echo $txFilters['id']===$value?'selected':''; ?>><?php echo h($label); ?></option><?php endforeach;?></select></th><th><input class="form-control form-control-sm" form="tx-column-filter-form" name="tx_date" value="<?php echo h($txFilters['date']); ?>" placeholder="Date" data-auto-search></th><th><select class="form-select form-select-sm" form="tx-column-filter-form" name="tx_type" data-auto-select><option value="">All</option><?php foreach($transactionTypeOptions as$value=>$label):?><option value="<?php echo h($value); ?>" <?php echo $txTypeFilter===$value?'selected':''; ?>><?php echo h($label); ?></option><?php endforeach;?></select></th><th><input class="form-control form-control-sm" form="tx-column-filter-form" name="tx_reference" value="<?php echo h($txFilters['reference']); ?>" placeholder="Reference" data-auto-search></th><th><select class="form-select form-select-sm" form="tx-column-filter-form" name="tx_entry" data-auto-select><option value="">All IDs</option><?php foreach($transactionEntryOptions as$value=>$label):?><option value="<?php echo h($value); ?>" <?php echo $txFilters['entry']===$value?'selected':''; ?>><?php echo h($label); ?></option><?php endforeach;?></select></th><th><input class="form-control form-control-sm" form="tx-column-filter-form" name="tx_person" value="<?php echo h($txFilters['person']); ?>" placeholder="Person" data-auto-search></th><th><input class="form-control form-control-sm" form="tx-column-filter-form" name="tx_amount" value="<?php echo h($txFilters['amount']); ?>" placeholder="Amount" data-auto-search></th><th><input class="form-control form-control-sm" form="tx-column-filter-form" name="tx_fee" value="<?php echo h($txFilters['fee']); ?>" placeholder="Fee" data-auto-search></th><th><input class="form-control form-control-sm" form="tx-column-filter-form" name="tx_net" value="<?php echo h($txFilters['net']); ?>" placeholder="Net" data-auto-search></th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $allowedTxSort = ['id', 'date', 'type', 'reference', 'entry', 'person', 'amount', 'fee', 'net'];
                        if (!in_array($txSortKey, $allowedTxSort, true)) {
                            $txSortKey = 'date';
                        }
                        usort($transactions, function (array $a, array $b) use ($txSortKey, $txSortDir): int {
                            $dir = $txSortDir === 'asc' ? 1 : -1;
                            if ($txSortKey === 'id') return ((int)($a['id']??0) <=> (int)($b['id']??0))*$dir;
                            if ($txSortKey === 'date') {
                                return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')) * $dir;
                            }
                            if ($txSortKey === 'type') {
                                return strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? '')) * $dir;
                            }
                            if ($txSortKey === 'reference') {
                                return strcmp((string)($a['reference'] ?? ''), (string)($b['reference'] ?? '')) * $dir;
                            }
                            if ($txSortKey === 'person') {
                                return strcasecmp((string)($a['person_name'] ?? ''), (string)($b['person_name'] ?? '')) * $dir;
                            }
                            if($txSortKey==='entry'){$na=(int)(($a['entry_ids'][0]??0));$nb=(int)(($b['entry_ids'][0]??0));}
                            elseif($txSortKey==='fee'){$na=(float)($a['stripe_fee']??0);$nb=(float)($b['stripe_fee']??0);}
                            elseif($txSortKey==='net'){$na=(float)($a['amount']??0)-(float)($a['stripe_fee']??0);$nb=(float)($b['amount']??0)-(float)($b['stripe_fee']??0);}
                            else{$na = (float)($a['amount'] ?? 0);$nb = (float)($b['amount'] ?? 0);}
                            if ($na === $nb) {
                                return 0;
                            }
                            return ($na < $nb ? -1 : 1) * $dir;
                        });
                        ?>
                        <?php foreach ($transactions as $tx): ?>
                            <?php
                            $amountVal = (float)($tx['amount'] ?? 0);
                            $type = (string)($tx['type'] ?? '');
                            $typeLabel = str_replace('_', ' ', $type);
                            if (!empty($tx['is_booking_level'])) {
                                $typeLabel .= ' (booking)';
                            }
                            ?>
                            <tr>
                                <td class="fw-semibold"><a class="text-decoration-none" href="?event_id=<?php echo $eventId; ?>&amp;tx_id=T-<?php echo (int)($tx['id']??0); ?>&amp;entry_ids=<?php echo h(implode(',',array_map('intval',$tx['entry_ids']??[]))); ?>#entries-table" title="Show entries for this transaction">T-<?php echo (int)($tx['id']??0); ?></a></td>
                                <td class="text-muted small"><?php echo h(format_display_datetime($tx['created_at'] ?? null, '')); ?></td>
                                <td class="text-capitalize"><?php echo h($typeLabel); ?></td>
                                <td><?php echo h($tx['reference'] ?? ''); ?></td>
                                <td><?php $rowEntryIds=array_values(array_filter($tx['entry_ids']??[]));if($rowEntryIds):foreach($rowEntryIds as $rowEntryId):?><a class="badge text-bg-light text-decoration-none me-1" href="?event_id=<?php echo $eventId; ?>&amp;tx_entry=E-<?php echo (int)$rowEntryId; ?>&amp;entry_id=E-<?php echo (int)$rowEntryId; ?>">E-<?php echo (int)$rowEntryId; ?></a><?php endforeach;else:?><span class="text-muted">—</span><?php endif;?></td>
                                <td><?php echo h($tx['person_name'] ?? '—'); ?></td>
                                <?php $feeVal=(float)($tx['stripe_fee']??0); ?><td class="text-end"><?php echo ($amountVal < 0 ? '-' : '') . format_price(abs($amountVal)); ?></td><td class="text-end"><?php echo format_price($feeVal); ?></td><td class="text-end fw-semibold"><?php echo format_price($amountVal-$feeVal); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$transactions): ?>
                            <tr><td colspan="9" class="text-muted">No matching money movements.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card-soft p-3" id="entries-table">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Entries</div>
                <div class="text-muted small">Fees + refunds per entry</div>
            </div>
            <form method="get" id="entry-column-filter-form" class="mb-2 text-end"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><button class="btn btn-sm btn-outline-success">Filter entries</button> <a class="btn btn-sm btn-link" href="finance_event.php?event_id=<?php echo $eventId; ?>">Clear</a></form>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo finance_detail_sort_link('entry', 'id', 'ID', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'booking_ref', 'Booking ref', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'placed', 'Date', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'person', 'Person', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'class', 'Class', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('entry', 'fee', '£ Stripe', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('entry', 'refund', 'Refund', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('entry', 'net', 'Net', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                        </tr>
                        <tr class="admin-table-filter-row"><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_id" value="<?php echo h($entryFilters['id']); ?>" placeholder="Entry ID"></th><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_booking_ref" value="<?php echo h($entryFilters['booking_ref']); ?>" placeholder="Booking ref"></th><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_placed" value="<?php echo h($entryFilters['placed']); ?>" placeholder="Date"></th><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_person" value="<?php echo h($entryFilters['person']); ?>" placeholder="Person"></th><th><select class="form-select form-select-sm" form="entry-column-filter-form" name="entry_class"><option value="">All classes</option><?php foreach($entryClassOptions as$value=>$label):?><option value="<?php echo h($value); ?>" <?php echo $entryClassFilter===$value?'selected':''; ?>><?php echo h($label); ?></option><?php endforeach;?></select></th><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_fee" value="<?php echo h($entryFilters['fee']); ?>" placeholder="Fee"></th><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_refund" value="<?php echo h($entryFilters['refund']); ?>" placeholder="Refund"></th><th><input class="form-control form-control-sm" form="entry-column-filter-form" name="entry_net" value="<?php echo h($entryFilters['net']); ?>" placeholder="Net"></th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $allowedEntrySort = ['id', 'booking_ref', 'placed', 'person', 'class', 'fee', 'refund', 'net'];
                        if (!in_array($entrySortKey, $allowedEntrySort, true)) {
                            $entrySortKey = 'placed';
                        }
                        usort($entries, function (array $a, array $b) use ($entrySortKey, $entrySortDir, $refundByItemId): int {
                            $dir = $entrySortDir === 'asc' ? 1 : -1;
                            if ($entrySortKey === 'id') return ((int)($a['id']??0) <=> (int)($b['id']??0))*$dir;
                            if ($entrySortKey === 'booking_ref') {
                                return strcmp((string)($a['booking_ref'] ?? ''), (string)($b['booking_ref'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'placed') {
                                return strcmp((string)($a['booking_created_at'] ?? ''), (string)($b['booking_created_at'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'class') {
                                return strcmp((string)($a['class_label'] ?? ''), (string)($b['class_label'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'person') {
                                return strcasecmp((string)($a['person_name'] ?? ''), (string)($b['person_name'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'fee') {
                                $na = (float)($a['price'] ?? 0);
                                $nb = (float)($b['price'] ?? 0);
                            } elseif ($entrySortKey === 'refund') {
                                $na = (float)($refundByItemId[(int)($a['id'] ?? 0)] ?? 0);
                                $nb = (float)($refundByItemId[(int)($b['id'] ?? 0)] ?? 0);
                            } else {
                                $na = (float)($a['price'] ?? 0) - (float)($refundByItemId[(int)($a['id'] ?? 0)] ?? 0);
                                $nb = (float)($b['price'] ?? 0) - (float)($refundByItemId[(int)($b['id'] ?? 0)] ?? 0);
                            }
                            if ($na === $nb) {
                                return 0;
                            }
                            return ($na < $nb ? -1 : 1) * $dir;
                        });
                        ?>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $fee = (float)($entry['price'] ?? 0);
                            $itemId = (int)($entry['id'] ?? 0);
                            $refundAmount = $itemId > 0 ? (float)($refundByItemId[$itemId] ?? 0) : 0.0;
                            $netAmount = $fee - $refundAmount;
                            ?>
                            <tr>
                                <td class="fw-semibold"><a class="text-decoration-none" href="?event_id=<?php echo $eventId; ?>&amp;tx_entry=E-<?php echo $itemId; ?>&amp;entry_id=E-<?php echo $itemId; ?>" title="Show this entry and its transactions">E-<?php echo $itemId; ?></a></td>
                                <td><?php echo h($entry['booking_ref'] ?? ''); ?></td>
                                <td class="text-muted small"><?php echo h(format_display_datetime($entry['booking_created_at'] ?? null, '')); ?></td>
                                <td><?php echo h($entry['person_name'] ?? '—'); ?></td>
                                <td><?php echo h($entry['class_label'] ?? ''); ?></td>
                                <td class="text-end"><?php echo format_price($fee); ?></td>
                                <td class="text-end"><?php echo format_price($refundAmount); ?></td>
                                <td class="text-end fw-semibold"><?php echo format_price($netAmount); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$entries): ?>
                            <tr><td colspan="8" class="text-muted">No entries yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php endif; ?>

<script>
(() => {
    const submitForm = control => document.getElementById(control.getAttribute('form'))?.requestSubmit();
    document.querySelectorAll('select[form$="-column-filter-form"]').forEach(select => {
        select.addEventListener('change', () => submitForm(select));
    });
    document.querySelectorAll('input[form$="-column-filter-form"]:not([type="hidden"])').forEach(input => {
        const initialValue = input.value.trim();
        let timer;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const value = input.value.trim();
            if (value.length >= 3 || (value === '' && initialValue !== '')) {
                timer = setTimeout(() => submitForm(input), 450);
            }
        });
    });
})();
</script>
<?php admin_layout_end(); ?>
