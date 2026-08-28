<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager', 'organiser'], true);
$basketCount = count($_SESSION['basket'] ?? []);
$navItemEventsUrl = $basePath . '/events';

if (!$isLoggedIn) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please sign in to view your bookings.']];
    header('Location: ' . $basePath . '/account');
    exit;
}
$userId=(int)($currentUser['id']??0);$userEmail=strtolower((string)($currentUser['email']??''));
if(empty($_SESSION['booking_cancel_csrf']))$_SESSION['booking_cancel_csrf']=bin2hex(random_bytes(24));
$bookingCancelCsrf=(string)$_SESSION['booking_cancel_csrf'];

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && in_array((string)($_POST['action']??''),['cancel_entry_credit','cancel_entry_refund'],true)){
    $action=(string)$_POST['action'];$itemId=(int)($_POST['item_id']??0);$reason=trim((string)($_POST['reason']??''));$cancelAlerts=[];
    if(!hash_equals($bookingCancelCsrf,(string)($_POST['csrf']??'')))$cancelAlerts[]=['type'=>'danger','message'=>'Your cancellation form has expired. Please try again.'];
    ensure_bookings_tables($pdo,$cancelAlerts);ensure_finance_tables($pdo,$cancelAlerts);
    $stmt=$pdo->prepare("SELECT bi.*,b.booking_ref,b.user_id,b.contact_name,b.contact_email,e.title AS live_title,e.event_date,e.entry_close_at FROM booking_items bi JOIN bookings b ON b.new_id=bi.booking_id JOIN events e ON e.id=bi.event_id WHERE bi.id=:id LIMIT 1");$stmt->execute([':id'=>$itemId]);$entry=$stmt->fetch();
    $owns=$entry&&(((int)($entry['user_id']??0)>0&&(int)$entry['user_id']===$userId)||($userEmail!==''&&strtolower((string)($entry['contact_email']??''))===$userEmail));
    $closeAt=$entry?strtotime((string)($entry['entry_close_at']??'')):false;
    if(empty($cancelAlerts) && !$owns)$cancelAlerts[]=['type'=>'danger','message'=>'That entry is not available for cancellation.'];
    elseif(empty($cancelAlerts) && !empty($entry['is_withdrawn']))$cancelAlerts[]=['type'=>'warning','message'=>'This entry has already been cancelled.'];
    elseif(empty($cancelAlerts) && ($closeAt===false||$closeAt<=time()))$cancelAlerts[]=['type'=>'warning','message'=>'The entry close date/time has passed. Please contact an administrator.'];
    elseif(empty($cancelAlerts)){
        $entryPrice=price_to_number($entry['price']??0);$title=(string)($entry['live_title']??$entry['event_title']??'Entry');$date=format_display_date($entry['event_date']??null,'Date TBC');
        $cancelMethod=$action==='cancel_entry_credit'?'credit':'refund';
        $amount=$cancelMethod==='credit'?$entryPrice:max(0,$entryPrice-price_to_number($siteSettings['event_stripe_refund_fee']??'5.00'));
        $result=cancel_event_entry($pdo,$config,$entry,$cancelMethod,$amount,$reason,$userId,'Customer',$cancelAlerts,'customer',$userId);
        if($result)$_SESSION['entry_cancellation_summary']=['title'=>$title,'date'=>$date,'method'=>$result['method']==='credit'?'Credited':'Refunded','amount'=>$result['amount']];
    }
    if($cancelAlerts)$_SESSION['flash_alerts']=$cancelAlerts;header('Location: '.$basePath.'/bookings');exit;
}

$orders = [];
$cancellationSummary=$_SESSION['entry_cancellation_summary']??null;unset($_SESSION['entry_cancellation_summary']);
$allOrders = load_all_bookings($pdo);
$userId = (int)($currentUser['id'] ?? 0);
$userEmail = strtolower((string)($currentUser['email'] ?? ''));
foreach ($allOrders as $order) {
    $matchesUser = ($userId > 0 && (int)($order['user_id'] ?? 0) === $userId)
        || ($userEmail !== '' && strtolower((string)($order['contact_email'] ?? '')) === $userEmail);
    if ($matchesUser) {
        $orders[] = $order;
    }
}

$eventCloseMap = [];
if ($pdo) {
    $eventIds = [];
    foreach ($orders as $order) {
        foreach (($order['items'] ?? []) as $item) {
            $eventId = (int)($item['event_id'] ?? 0);
            if ($eventId > 0) {
                $eventIds[$eventId] = true;
            }
        }
    }
    if ($eventIds) {
        $ids = array_keys($eventIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, title, entry_close_at FROM events WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $eventCloseMap[(int)($row['id'] ?? 0)] = [
                'title' => (string)($row['title'] ?? ''),
                'entry_close_at' => (string)($row['entry_close_at'] ?? ''),
            ];
        }
    }

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings | <?php echo h($siteSettings['hero_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --green: #146118;
            --green-alt: #1f7c24;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .page-hero {
            background: linear-gradient(120deg, rgba(20, 97, 24, 0.9), rgba(20, 97, 24, 0.75)), url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            padding: 2.5rem 0;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.12), transparent 32%);
            z-index: 0;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .section-title::before {
            content: '';
            width: 40px;
            height: 4px;
            border-radius: 999px;
            background: var(--green);
            display: inline-block;
        }
        .booking-card {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 14px 38px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .meta-label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            color: var(--muted);
            margin-bottom: 4px;
            font-weight: 700;
        }
        .booking-item + .booking-item { border-top: 1px solid rgba(0,0,0,0.04); }
        .badge-chip {
            background: rgba(20, 97, 24, 0.1);
            color: var(--green);
            border: 1px solid rgba(20, 97, 24, 0.2);
            border-radius: 999px;
            padding: 4px 10px;
            font-weight: 700;
        }
        .booking-cancelled-pill {
            background: #f8d7da;
            border: 1px solid #e6aeb5;
            color: #842029;
            opacity: 1;
            font-weight: 700;
        }
        .small-link {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--green-alt);
        }
        .cancel-choice {
            border-radius: 14px;
            padding: 0.95rem 1rem;
            text-align: left;
            font-weight: 700;
        }
        .cancel-choice strong {
            display: block;
            margin-bottom: 0.15rem;
        }
        .cancel-choice span {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            opacity: 0.9;
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-white-50">Bookings</p>
            <h1 class="fw-bold mb-1">Completed bookings</h1>
            <div class="text-white-50">Review past checkouts.</div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="section-title mb-0">Bookings</div>
                    <a class="btn btn-outline-success btn-sm" href="<?php echo h($navItemEventsUrl); ?>">Back to events</a>
                </div>
            <?php if (!$orders): ?>
                <div class="text-muted small">No bookings yet. Add entries and checkout.</div>
                <?php else: ?>
                    <?php
                        $pageSize = 3;
                        $page = max(1, (int)($_GET['page'] ?? 1));
                        $totalOrders = count($orders);
                        $totalPages = (int)max(1, ceil($totalOrders / $pageSize));
                        if ($page > $totalPages) { $page = $totalPages; }
                        $offset = ($page - 1) * $pageSize;
                        $ordersPage = array_slice($orders, $offset, $pageSize);
                    ?>
                    <?php foreach ($ordersPage as $order): ?>
                        <?php
                        $items = $order['items'] ?? [];
                        $total = isset($order['total']) ? '£' . number_format((float)$order['total'], 2) : '—';
                        $detailsId = 'booking-items-' . h($order['id'] ?? $order['booking_ref'] ?? uniqid('bk'));
                        $nowTs = time();
                        $eventNames = [];
                        $cancelledItemCount = 0;
                        foreach ($items as $item) {
                            $eventName = trim((string)($item['event_title'] ?? $item['event_name'] ?? ''));
                            if ($eventName !== '' && !in_array($eventName, $eventNames, true)) {
                                $eventNames[] = $eventName;
                            }
                            if (!empty($item['is_withdrawn'])) {
                                $cancelledItemCount++;
                            }
                        }
                        $bookingCancellationLabel = $cancelledItemCount >= count($items) ? 'Cancelled' : 'Partially cancelled';
                        ?>
                        <div class="booking-card p-4 mb-3">
                            <div class="d-flex flex-wrap justify-content-between gap-3">
                                <div class="d-flex flex-wrap gap-4">
                                    <div>
                                        <div class="meta-label">Order placed</div>
                                        <div class="fw-semibold"><?php echo h(format_display_datetime($order['created_at'] ?? null, '')); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-label">Total</div>
                                        <div class="fw-semibold"><?php echo h($total); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-label">Items</div>
                                        <div class="fw-semibold"><?php echo count($items); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-label">Contact</div>
                                        <div class="fw-semibold"><?php echo h($order['contact_name'] ?? ''); ?></div>
                                        <div class="text-muted small"><?php echo h($order['contact_email'] ?? ''); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-label">Event Name</div>
                                        <div class="fw-semibold"><?php echo h($eventNames ? implode(' | ', $eventNames) : '—'); ?></div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="text-muted small mb-1">Booking #<?php echo h($order['booking_ref'] ?? $order['id']); ?></div>
                                    <button class="btn btn-success btn-sm collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $detailsId; ?>" aria-expanded="false" aria-controls="<?php echo $detailsId; ?>">
                                        View items
                                    </button>
                                    <?php if ($cancelledItemCount > 0): ?>
                                        <div class="mt-2"><span class="btn btn-sm booking-cancelled-pill disabled" aria-disabled="true"><?php echo h($bookingCancellationLabel); ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-3 collapse" id="<?php echo $detailsId; ?>">
                                <div class="fw-bold mb-2">Items purchased</div>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($items as $idx => $item): ?>
                                        <?php
                                        $hubGuid = ($pdo && !empty($item['id'])) ? ensure_booking_item_guid($pdo, (int)$item['id']) : '';
                                        $entryLink = $hubGuid !== '' ? ($basePath . '/entry_hub.php?code=' . urlencode((string)$hubGuid)) : '#';
                                        $bookingType = $item['booking_type'] ?? 'ride';
                                        $bookingTypeLabel = ucfirst($bookingType);
                                        $meta = $item['metadata'] ?? [];
                                        $chips = [];
                                        foreach (['class_label', 'rider_name', 'horse_name'] as $k) {
                                            if (isset($meta[$k])) {
                                                $chips[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $meta[$k];
                                            }
                                        }
                                        $componentsSummary = entry_components_summary($meta);
                                        if ($componentsSummary !== '') {
                                            $chips[] = 'Extras: ' . $componentsSummary;
                                        }
                                        $itemCancelModalId = 'cancel-item-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($item['id'] ?? ($order['booking_ref'] ?? 'bk') . '-' . $idx));
                                        $eventId = (int)($item['event_id'] ?? 0);
                                        $isCancelableItem = empty($item['is_withdrawn']) && !in_array($bookingType, ['membership', 'horse_logbook'], true)
                                            && $eventId > 0
                                            && isset($eventCloseMap[$eventId]);
                                        $cancelBlocked = false;
                                        $closeLabel = '';
                                        $itemPrice=price_to_number($item['price']??0);$refundFee=price_to_number($siteSettings['event_stripe_refund_fee']??'5.00');$refundPreview=max(0,$itemPrice-$refundFee);
                                        $cancelEventTitle = trim((string)($eventCloseMap[$eventId]['title'] ?? ($item['event_title'] ?? 'this event')));
                                        if ($isCancelableItem) {
                                            $entryCloseAt = trim((string)($eventCloseMap[$eventId]['entry_close_at'] ?? ''));
                                            if ($entryCloseAt !== '') {
                                                $closeLabel = format_display_datetime($entryCloseAt, '');
                                                $closeTs = strtotime($entryCloseAt);
                                                $cancelBlocked = $closeTs === false || $closeTs <= $nowTs;
                                            } else $cancelBlocked = true;
                                        }
                                        ?>
                                        <div class="list-group-item booking-item">
                                            <div class="d-flex justify-content-between flex-wrap gap-2">
                                                <div>
                                                    <div class="fw-semibold mb-1"><?php echo h($item['event_title'] ?? ''); ?></div>
                                                    <?php if ($chips): ?>
                                                        <div class="text-muted small"><?php echo h(implode(' • ', $chips)); ?> · Type: <?php echo h($bookingTypeLabel); ?></div>
                                                    <?php else: ?>
                                                        <div class="text-muted small">Type: <?php echo h($bookingTypeLabel); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if ($entryLink !== '#'): ?>
                                                        <a class="btn btn-outline-success btn-sm" href="<?php echo h($entryLink); ?>">View details</a>
                                                    <?php endif; ?>
                                                    <?php if ($isCancelableItem && !$cancelBlocked): ?>
                                                        <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#<?php echo h($itemCancelModalId); ?>">
                                                            Cancel
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($isCancelableItem && !$cancelBlocked): ?>
                                            <div class="modal fade" id="<?php echo h($itemCancelModalId); ?>" tabindex="-1" aria-labelledby="<?php echo h($itemCancelModalId); ?>Label" aria-hidden="true">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content border-0" style="border-radius: 18px;">
                                                        <div class="modal-header border-0 pb-0">
                                                            <div>
                                                                <div class="text-uppercase small fw-bold text-muted">Cancel entry</div>
                                                                <h5 class="modal-title mb-0" id="<?php echo h($itemCancelModalId); ?>Label"><?php echo h($item['event_title'] ?? 'Entry'); ?></h5>
                                                            </div>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body pt-3">
                                                            <?php if (!$cancelBlocked): ?>
                                                                <p class="mb-2">This entry is still within the cancellation window.</p>
                                                                <?php if ($cancelEventTitle !== ''): ?>
                                                                    <div class="text-muted small mb-3">Event: <?php echo h($cancelEventTitle); ?></div>
                                                                <?php endif; ?>
                                                                <?php if ($closeLabel !== ''): ?>
                                                                    <div class="text-muted small mb-3">Entries close at: <?php echo h($closeLabel); ?></div>
                                                                <?php endif; ?>
                                                                <p class="small text-muted mb-3">Credit returns the full entry amount to your ILDRA account for future purchases. A refund is sent to the original Stripe payment, less the <?php echo format_price($refundFee); ?> refund fee.</p>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold" for="<?php echo h($itemCancelModalId); ?>-reason">Reason (Optional)</label>
                                                                    <textarea class="form-control" id="<?php echo h($itemCancelModalId); ?>-reason" form="<?php echo h($itemCancelModalId); ?>-form" name="reason" rows="3" placeholder="Add a short note about why you are cancelling"></textarea>
                                                                </div>
                                                                <form method="post" id="<?php echo h($itemCancelModalId); ?>-form"><input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>"><input type="hidden" name="csrf" value="<?php echo h($bookingCancelCsrf); ?>"><div class="d-grid gap-2" id="<?php echo h($itemCancelModalId); ?>-choices">
                                                                    <button type="button" class="btn btn-success cancel-choice" data-credit-choice="<?php echo h($itemCancelModalId); ?>">
                                                                        <strong>Get Credit</strong>
                                                                        <span>Full entry amount applied to your account for future ILDRA purchases.</span>
                                                                    </button>
                                                                    <button type="button" class="btn btn-outline-secondary cancel-choice" data-refund-choice="<?php echo h($itemCancelModalId); ?>">
                                                                        <strong>Get Refund</strong>
                                                                        <span>Refund <?php echo format_price($refundPreview); ?> after the <?php echo format_price($refundFee); ?> refund fee.</span>
                                                                    </button>
                                                                </div><div class="d-none" id="<?php echo h($itemCancelModalId); ?>-credit-confirm"><p class="mb-2 fw-semibold">Confirm credit of <?php echo format_price($itemPrice); ?></p><p class="small text-muted">This will cancel the entry and apply the full amount to your ILDRA account for a future purchase.</p><div class="d-flex gap-2"><button type="submit" name="action" value="cancel_entry_credit" class="btn btn-success">Confirm credit</button><button type="button" class="btn btn-outline-secondary" data-credit-back="<?php echo h($itemCancelModalId); ?>">Back</button></div></div><div class="d-none" id="<?php echo h($itemCancelModalId); ?>-refund-confirm"><p class="mb-2 fw-semibold">Confirm refund of <?php echo format_price($refundPreview); ?></p><p class="small text-muted">This will cancel the entry and ask Stripe to return the funds to the original payment method. It can take up to five days to show on your statement.</p><div class="d-flex gap-2"><button type="submit" name="action" value="cancel_entry_refund" class="btn btn-danger">Confirm refund</button><button type="button" class="btn btn-outline-secondary" data-refund-back="<?php echo h($itemCancelModalId); ?>">Back</button></div></div></form><p class="small text-muted border-top pt-3 mt-3 mb-0">If you want to change your entry (for example, rider, horse, class, or rosette selection), please cancel it by selecting <strong>Credit</strong>, then re-enter with the new details and use your new credit balance to pay.</p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="modal-footer border-0 pt-0">
                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Bookings pagination">
                            <ul class="pagination justify-content-center mt-3">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo h($basePath); ?>/bookings?page=<?php echo max(1, $page - 1); ?>" aria-label="Previous">Previous</a>
                                </li>
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo h($basePath); ?>/bookings?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo h($basePath); ?>/bookings?page=<?php echo min($totalPages, $page + 1); ?>" aria-label="Next">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if(is_array($cancellationSummary)): ?>
        <div class="modal fade" id="entry-cancellation-complete" tabindex="-1" aria-labelledby="entry-cancellation-complete-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0" style="border-radius:18px;">
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <div class="text-uppercase small fw-bold text-success">Cancellation complete</div>
                            <h2 class="modal-title h5 mb-0" id="entry-cancellation-complete-label">Entry cancelled</h2>
                        </div>
                    </div>
                    <div class="modal-body">
                        <p>ENTRY for <strong><?php echo h($cancellationSummary['title']??''); ?></strong> on <strong><?php echo h($cancellationSummary['date']??''); ?></strong> has been cancelled.</p>
                        <?php if(($cancellationSummary['method']??'')==='Credited'): ?>
                            <p class="mb-0">You have been credited <strong><?php echo format_price((float)($cancellationSummary['amount']??0)); ?></strong>. Your credit has been applied to your account for future ILDRA purchases.</p>
                        <?php else: ?>
                            <p class="mb-0">Your refund of <strong><?php echo format_price((float)($cancellationSummary['amount']??0)); ?></strong> has been initiated. It may take up to 5 days to show on your statement.</p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-success" data-bs-dismiss="modal">Close</button></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.querySelectorAll('[data-refund-choice]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.refundChoice;
                document.getElementById(id + '-choices').classList.add('d-none');
                document.getElementById(id + '-refund-confirm').classList.remove('d-none');
            });
        });
        document.querySelectorAll('[data-credit-choice]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.creditChoice;
                document.getElementById(id + '-choices').classList.add('d-none');
                document.getElementById(id + '-credit-confirm').classList.remove('d-none');
            });
        });
        document.querySelectorAll('[data-credit-back]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.creditBack;
                document.getElementById(id + '-credit-confirm').classList.add('d-none');
                document.getElementById(id + '-choices').classList.remove('d-none');
            });
        });
        document.querySelectorAll('[data-refund-back]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.refundBack;
                document.getElementById(id + '-refund-confirm').classList.add('d-none');
                document.getElementById(id + '-choices').classList.remove('d-none');
            });
        });
        const completionModal = document.getElementById('entry-cancellation-complete');
        if (completionModal) new bootstrap.Modal(completionModal).show();
    </script>
</body>
</html>
