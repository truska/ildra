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
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basketCount = count($_SESSION['basket'] ?? []);
$navItemEventsUrl = $basePath . '/events';

$code = trim((string)($_GET['code'] ?? ''));
$item = null;
if ($pdo && $code !== '' && ensure_bookings_tables($pdo)) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                bi.*,
                b.booking_ref,
                b.user_id,
                b.contact_name,
                b.contact_email,
                b.contact_phone,
                b.created_at AS booking_created_at,
                e.title AS event_title_live,
                e.event_date,
                e.venue,
                e.organiser,
                et.name AS event_type_name
            FROM booking_items bi
            LEFT JOIN bookings b ON bi.booking_id = b.new_id
            LEFT JOIN events e ON bi.event_id = e.id
            LEFT JOIN event_types et ON e.event_type_id = et.id
            WHERE bi.guid = :code
            LIMIT 1
        ");
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        if ($row) {
            $item = hydrate_booking_item($row);
        }
    } catch (PDOException $e) {
        $item = null;
    }
}

$meta = $item && is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
$eventTitle = $item ? (string)($item['event_title_live'] ?? $item['event_title'] ?? 'Entry') : 'Entry';
$bookingRef = $item ? (string)($item['booking_ref'] ?? $item['booking_id'] ?? '') : '';
$bookingPlaced = $item['booking_created_at'] ?? null;
$entryPrice = $item ? price_to_number($item['price'] ?? 0) : 0.0;
$price = $item ? format_price($entryPrice) : '£0.00';
$eventType = $item ? (string)($item['event_type_name'] ?? $item['booking_type_label'] ?? ucfirst((string)($item['booking_type'] ?? 'entry'))) : 'Entry';
$componentsSummary = entry_components_summary($meta);
$eventDateText = $item && !empty($item['event_date']) ? format_display_date($item['event_date'], 'Date TBC') : 'Date TBC';
$eventVenue = $item ? (string)($item['venue'] ?? '') : '';
$organiser = $item ? (string)($item['organiser'] ?? '') : '';
$backUrl = $basePath . '/bookings';

$refunded = false;
if ($item && $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM finance_transactions
            WHERE type = 'entry_refund'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => (string)($item['id'] ?? 0)]);
        $refunded = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $refunded = false;
    }
}
$statusLabel = $item ? (!empty($item['is_withdrawn']) ? ($refunded ? 'Withdrawn & Refunded' : 'Withdrawn') : 'Entry Accepted') : 'Entry';

$bookingTotals = [
    'all' => 0.0,
    'active' => 0.0,
    'withdrawn' => 0.0,
    'refund' => 0.0,
];
$payments = [];
$paidForEntry = 0.0;
$balanceDue = 0.0;
$stripeFeeTotal = 0.0;
$stripeFeeForEntry = 0.0;
$bookingItemCount = 0;
$event = null;
$entryComponents = [];
$entryForm = [];
$entryComponentsById = [];
$classOptions = [];
$selectedClassValue = '';
$selectedComponentValues = [];
$selectedComponentFlags = [];
if ($item && $pdo) {
    $ref = (string)($item['booking_ref'] ?? '');
    if ($ref !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(bi.price) AS total_all,
                    SUM(CASE WHEN COALESCE(bi.is_withdrawn, 0) = 0 THEN bi.price ELSE 0 END) AS total_active,
                    SUM(CASE WHEN COALESCE(bi.is_withdrawn, 0) = 1 THEN bi.price ELSE 0 END) AS total_withdrawn
                FROM booking_items bi
                LEFT JOIN bookings b ON bi.booking_id = b.new_id
                WHERE b.booking_ref = :ref
            ");
            $stmt->execute([':ref' => $ref]);
            $row = $stmt->fetch() ?: [];
            $bookingTotals['all'] = (float)($row['total_all'] ?? 0);
            $bookingTotals['active'] = (float)($row['total_active'] ?? 0);
            $bookingTotals['withdrawn'] = (float)($row['total_withdrawn'] ?? 0);

            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM booking_items bi
                LEFT JOIN bookings b ON bi.booking_id = b.new_id
                WHERE b.booking_ref = :ref
            ");
            $countStmt->execute([':ref' => $ref]);
            $bookingItemCount = (int)($countStmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            // ignore
        }
        if (ensure_finance_tables($pdo)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT type, amount, created_at, metadata
                    FROM finance_transactions
                    WHERE reference = :ref
                      AND type IN ('payment_simulated', 'payment_stripe', 'checkout', 'entry_refund')
                    ORDER BY created_at ASC
                ");
                $stmt->execute([':ref' => $ref]);
                $paymentSimulated = 0.0;
                $paymentStripe = 0.0;
                $checkoutDebit = 0.0;
                $checkoutDate = null;
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    $type = (string)($row['type'] ?? '');
                    $amount = (float)($row['amount'] ?? 0);
                    $metaRow = [];
                    $decodedMeta = json_decode((string)($row['metadata'] ?? ''), true);
                    if (is_array($decodedMeta)) {
                        $metaRow = $decodedMeta;
                    }
                    if ($type === 'entry_refund') {
                        $bookingTotals['refund'] += $amount;
                        $payments[] = [
                            'date' => $row['created_at'] ?? null,
                            'method' => 'Refund',
                            'amount' => -1 * $amount,
                        ];
                        continue;
                    }
                    if ($type === 'payment_simulated') {
                        $paymentSimulated += $amount;
                        $payments[] = [
                            'date' => $row['created_at'] ?? null,
                            'method' => 'Online',
                            'amount' => $amount,
                        ];
                        continue;
                    }
                    if ($type === 'payment_stripe') {
                        $paymentStripe += $amount;
                        $stripeFeeTotal += max(0.0, (float)($metaRow['stripe_fee'] ?? 0));
                        $payments[] = [
                            'date' => $row['created_at'] ?? null,
                            'method' => 'Stripe',
                            'amount' => $amount,
                        ];
                        continue;
                    }
                    if ($type === 'checkout') {
                        $checkoutDebit += abs($amount);
                        $checkoutDate = $row['created_at'] ?? $checkoutDate;
                    }
                }
                $creditApplied = max(0.0, $checkoutDebit - $paymentSimulated - $paymentStripe);
                if ($creditApplied > 0) {
                    $payments[] = [
                        'date' => $checkoutDate,
                        'method' => 'Credit',
                        'amount' => $creditApplied,
                    ];
                }
            } catch (PDOException $e) {
                $payments = [];
            }
        }
    }
    if (!empty($item['is_withdrawn'])) {
        $withdrawnTotal = $bookingTotals['withdrawn'] > 0 ? $bookingTotals['withdrawn'] : 0.0;
        $refundShare = $withdrawnTotal > 0 ? ($bookingTotals['refund'] * ($entryPrice / $withdrawnTotal)) : 0.0;
        $paidForEntry = max(0.0, $entryPrice - $refundShare);
        $balanceDue = 0.0;
    } else {
        $paidPool = $bookingTotals['all'] - $bookingTotals['refund'];
        $activeTotal = $bookingTotals['active'] > 0 ? $bookingTotals['active'] : 0.0;
        $paidForEntry = $activeTotal > 0 ? ($paidPool * ($entryPrice / $activeTotal)) : 0.0;
        $balanceDue = max(0.0, $entryPrice - $paidForEntry);
    }

    if ($stripeFeeTotal > 0) {
        $allocationBase = $bookingTotals['all'] > 0 ? $bookingTotals['all'] : $entryPrice;
        $stripeFeeForEntry = $allocationBase > 0 ? round($stripeFeeTotal * ($entryPrice / $allocationBase), 2) : 0.0;
    }
}

if ($item && $pdo) {
    $eventId = (int)($item['event_id'] ?? 0);
    if ($eventId > 0) {
        $event = fetchEventById($pdo, $eventId);
        if ($event) {
            $eventTypeId = (int)($event['event_type_id'] ?? 0);
            $entryComponents = fetchEventEntryComponents($pdo, $eventId, $eventTypeId);
            $entryForm = event_entry_form($event, $entryComponents);
            foreach ($entryComponents as $c) {
                $entryComponentsById[(int)($c['id'] ?? 0)] = $c;
            }
            $pricingRows = fetchEventPricingRows($pdo, $eventId);
            foreach ($pricingRows as $row) {
                if (empty($row['enabled'])) {
                    continue;
                }
                $label = trim((string)($row['class_name'] ?? ''));
                $code = trim((string)($row['class_code'] ?? ''));
                if ($label === '' && $code === '') {
                    continue;
                }
                $rowId = (int)($row['id'] ?? 0);
                $classOptions[] = [
                    'value' => $rowId > 0 ? (string)$rowId : ($code !== '' ? $code : $label),
                    'code' => $code !== '' ? $code : $label,
                    'label' => $label !== '' ? $label : $code,
                    'price' => format_price((float)($row['price'] ?? 0)),
                    'is_member_price' => !empty($row['is_member_price']),
                    'row_id' => $rowId,
                ];
            }
        }
    }
    $selectedPricingRowId = (int)($meta['pricing_row_id'] ?? 0);
    $selectedClassCode = (string)($meta['class_code'] ?? '');
    $selectedIsMember = !empty($meta['is_member_price']);
    foreach ($classOptions as $cls) {
        if ($selectedPricingRowId > 0 && (int)($cls['row_id'] ?? 0) === $selectedPricingRowId) {
            $selectedClassValue = (string)$cls['value'];
            break;
        }
        if ($selectedPricingRowId <= 0 && $selectedClassCode !== '' && (string)$cls['code'] === $selectedClassCode) {
            if ($selectedIsMember === !empty($cls['is_member_price'])) {
                $selectedClassValue = (string)$cls['value'];
            }
        }
    }
    foreach (($meta['components'] ?? []) as $comp) {
        $compId = (int)($comp['id'] ?? 0);
        if ($compId <= 0) {
            continue;
        }
        $selectedComponentFlags[$compId] = true;
        $selectedComponentValues[$compId] = $comp['value'] ?? '';
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($eventTitle); ?> | Event Hub</title>
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
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .meta-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            font-weight: 700;
        }
        .status-banner {
            background: #f6faf2;
            border: 1px solid rgba(31, 124, 36, 0.25);
            border-radius: 14px;
            padding: 1.5rem;
        }
        .status-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1f7c24;
        }
        .detail-grid {
            display: grid;
            gap: 1rem 2rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .fee-table th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
        .balance-bar {
            background: #1f7c24;
            color: #fff;
            font-weight: 700;
            text-align: center;
            border-radius: 12px;
            padding: 0.75rem;
        }
        .public-note {
            font-size: 0.85rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
    <?php include __DIR__ . '/views/header.php'; ?>

    <main class="py-5">
        <div class="container">
            <?php if (!$item): ?>
                <div class="card-soft p-4">
                    <div class="fw-bold mb-1">Entry not found</div>
                    <div class="text-muted mb-3">We could not find that entry.</div>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="fw-bold mb-1">Event Hub <span class="text-muted fw-normal"><?php echo h($eventTitle); ?></span></div>
                            <div class="public-note">Keep a note of this URL — it is personal to you.</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div></div>
                    <a class="btn btn-outline-success btn-sm" href="<?php echo h($backUrl); ?>">Back to bookings</a>
                </div>

                <div class="card-soft p-4 mb-4 status-banner">
                    <div class="status-title"><?php echo h($statusLabel); ?></div>
                    <div class="text-muted small mt-2">
                        <?php echo h($meta['rider_name'] ?? 'Entrant'); ?>, your entry has been recorded for <?php echo h($eventTitle); ?>.
                    </div>
                    <?php if ($organiser !== ''): ?>
                        <div class="text-muted small mt-2">Organiser: <?php echo h($organiser); ?></div>
                    <?php endif; ?>
                </div>

                <div class="card-soft p-4 mb-4">
                    <div class="detail-grid">
                        <div>
                            <div class="meta-label">Booking ref</div>
                            <div class="fw-semibold"><?php echo h($bookingRef !== '' ? $bookingRef : '—'); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Placed</div>
                            <div class="fw-semibold"><?php echo h(format_display_datetime($bookingPlaced, '—')); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Price</div>
                            <div class="fw-semibold"><?php echo h($price); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Type</div>
                            <div class="fw-semibold"><?php echo h($eventType); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Class</div>
                            <div class="fw-semibold"><?php echo h((string)($meta['class_label'] ?? '—')); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Rider</div>
                            <div class="fw-semibold"><?php echo h((string)($meta['rider_name'] ?? '—')); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Horse</div>
                            <div class="fw-semibold"><?php echo h((string)($meta['horse_name'] ?? '—')); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Contact</div>
                            <div class="fw-semibold"><?php echo h((string)($item['contact_name'] ?? '—')); ?></div>
                            <div class="text-muted small"><?php echo h((string)($item['contact_email'] ?? '')); ?></div>
                            <?php if (!empty($item['contact_phone'])): ?>
                                <div class="text-muted small"><?php echo h((string)$item['contact_phone']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card-soft p-4 h-100">
                            <div class="fw-bold mb-3">Fees</div>
                            <table class="table fee-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-end">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo h((string)($meta['class_label'] ?? $eventTitle)); ?></td>
                                        <td class="text-end"><?php echo h($price); ?></td>
                                    </tr>
                                    <?php if ($componentsSummary !== ''): ?>
                                        <tr>
                                            <td>Extras: <?php echo h($componentsSummary); ?></td>
                                            <td class="text-end">Included</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td class="fw-semibold">Total fees</td>
                                        <td class="text-end fw-semibold"><?php echo h($price); ?></td>
                                    </tr>
                                    <?php if ($stripeFeeForEntry > 0): ?>
                                        <tr>
                                            <td class="text-muted small">
                                                Stripe fee<?php echo $bookingItemCount > 1 ? ' (this entry’s share)' : ''; ?>
                                            </td>
                                            <td class="text-end text-muted small"><?php echo h(format_price($stripeFeeForEntry)); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="card-soft p-4 h-100">
                            <div class="fw-bold mb-3">Payments</div>
                            <table class="table fee-table mb-3">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$payments): ?>
                                        <tr><td colspan="3" class="text-muted">No payments recorded.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($payments as $p): ?>
                                            <tr>
                                                <td><?php echo h(format_display_datetime($p['date'] ?? null, '—')); ?></td>
                                                <td><?php echo h($p['method']); ?></td>
                                                <td class="text-end"><?php echo h(format_price($p['amount'] ?? 0)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <?php
                                    $totalPayments = 0.0;
                                    foreach ($payments as $p) {
                                        $totalPayments += (float)($p['amount'] ?? 0);
                                    }
                                    ?>
                                    <tr>
                                        <td colspan="2" class="fw-semibold">Total payments</td>
                                        <td class="text-end fw-semibold"><?php echo h(format_price($totalPayments)); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div class="balance-bar">Balance due: <?php echo h(format_price($balanceDue)); ?></div>
                        </div>
                    </div>
                </div>
                <?php if ($event && $entryForm): ?>
                    <div class="mt-4">
                        <button class="btn btn-outline-success btn-sm" type="button" id="toggleEntryForm" aria-expanded="false">
                            View entry form
                        </button>
                    </div>
                    <div class="mt-3 d-none" id="entryFormPanel">
                        <div class="card-soft p-4">
                            <?php foreach ($entryForm as $block): ?>
                                <?php
                                $type = $block['type'] ?? '';
                                $enabled = isset($block['enabled']) ? (bool)$block['enabled'] : true;
                                if (!$enabled) {
                                    continue;
                                }
                                ?>
                                <?php if ($type === 'classes'): ?>
                                    <div class="mb-4">
                                        <div class="fw-bold mb-2">Class selection</div>
                                        <label class="form-label" for="classSelectView">Class</label>
                                        <select class="form-select" id="classSelectView" disabled>
                                            <option value="">Choose...</option>
                                            <?php foreach ($classOptions as $cls): ?>
                                                <?php
                                                $memberLabel = '';
                                                if (array_key_exists('is_member_price', $cls)) {
                                                    $memberLabel = $cls['is_member_price'] ? ', member' : ', non-member';
                                                }
                                                $selected = $selectedClassValue !== '' && (string)$cls['value'] === $selectedClassValue;
                                                ?>
                                                <option value="<?php echo h((string)$cls['value']); ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                                    <?php echo h($cls['label']); ?><?php echo $cls['price'] !== '' ? ' (' . h((string)$cls['price']) . $memberLabel . ')' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php elseif ($type === 'rider_details'): ?>
                                    <div class="mb-4">
                                        <div class="fw-bold mb-2">Rider details</div>
                                        <label class="form-label" for="riderNameView">Rider name</label>
                                        <input type="text" id="riderNameView" class="form-control" value="<?php echo h((string)($meta['rider_name'] ?? '')); ?>" disabled>
                                    </div>
                                <?php elseif ($type === 'horse_details'): ?>
                                    <div class="mb-4">
                                        <div class="fw-bold mb-2">Horse details</div>
                                        <label class="form-label" for="horseNameView">Horse name</label>
                                        <input type="text" id="horseNameView" class="form-control" value="<?php echo h((string)($meta['horse_name'] ?? '')); ?>" disabled>
                                    </div>
                                <?php elseif ($type === 'contact'): ?>
                                    <div class="mb-4">
                                        <div class="fw-bold mb-2">Contact information</div>
                                        <div class="mb-3">
                                            <label class="form-label" for="contactEmailView">Email</label>
                                            <input type="email" id="contactEmailView" class="form-control" value="<?php echo h((string)($meta['contact_email'] ?? ($item['contact_email'] ?? ''))); ?>" disabled>
                                        </div>
                                        <div>
                                            <label class="form-label" for="contactPhoneView">Phone</label>
                                            <input type="text" id="contactPhoneView" class="form-control" value="<?php echo h((string)($meta['contact_phone'] ?? ($item['contact_phone'] ?? ''))); ?>" disabled>
                                        </div>
                                    </div>
                                <?php elseif ($type === 'component'): ?>
                                    <?php
                                    $cid = (int)($block['component_id'] ?? 0);
                                    if ($cid <= 0 || !isset($entryComponentsById[$cid])) {
                                        continue;
                                    }
                                    $component = $entryComponentsById[$cid];
                                    $label = $component['label_override'] ?? ($component['name'] ?? 'Extra');
                                    $ctype = $component['type'] ?? 'product';
                                    $inputKind = $component['input_kind'] ?? 'checkbox';
                                    $price = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
                                    $isSelected = !empty($selectedComponentFlags[$cid]);
                                    $inputValue = $selectedComponentValues[$cid] ?? '';
                                    $hasCost = $ctype === 'product' && $price !== 0.0;
                                    $isRequiredProduct = $ctype === 'product' && !empty($component['is_required']);
                                    $description = trim((string)($component['description'] ?? ''));
                                    $showSelector = $ctype === 'product' && !$isRequiredProduct;
                                    ?>
                                    <div class="mb-4">
                                        <div class="fw-bold mb-2"><?php echo h($label); ?></div>
                                        <?php if ($description !== ''): ?>
                                            <div class="text-muted small mb-2"><?php echo h($description); ?></div>
                                        <?php endif; ?>
                                        <div class="border rounded p-3">
                                            <?php if ($inputKind === 'checkbox'): ?>
                                                <?php if ($isRequiredProduct): ?>
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <div class="fw-semibold mb-0"><?php echo h($label); ?></div>
                                                        <span class="badge bg-light text-dark border">Included £<?php echo h(number_format((float)$price, 2)); ?></span>
                                                    </div>
                                                    <div class="text-muted small mb-1">Required add-on, included in your entry.</div>
                                                <?php else: ?>
                                                    <div class="form-check d-flex align-items-center gap-2 mb-1">
                                                        <input class="form-check-input" type="checkbox" <?php echo $isSelected ? 'checked' : ''; ?> disabled>
                                                        <label class="form-check-label fw-semibold"><?php echo h($label); ?></label>
                                                        <?php if ($hasCost): ?>
                                                            <span class="badge bg-light text-dark border">+£<?php echo h(number_format((float)$price, 2)); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-muted small">Optional add-on.</div>
                                                <?php endif; ?>
                                            <?php elseif ($inputKind === 'textarea'): ?>
                                                <label class="form-label mb-1"><?php echo h($label); ?></label>
                                                <textarea class="form-control" rows="3" disabled><?php echo h((string)$inputValue); ?></textarea>
                                                <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($price)); ?></div><?php endif; ?>
                                            <?php else: ?>
                                                <label class="form-label mb-1"><?php echo h($label); ?></label>
                                                <input type="text" class="form-control" value="<?php echo h((string)$inputValue); ?>" disabled>
                                                <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($price)); ?></div><?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer py-4" style="background: var(--green); color: #fff;">
        <div class="container d-flex flex-column flex-lg-row justify-content-between align-items-center gap-2">
            <div><?php echo h($siteSettings['hero_title']); ?> · <?php echo date('Y'); ?></div>
            <div class="small d-flex align-items-center gap-3">
                <?php if ($canViewAdmin): ?>
                    <a class="btn btn-light btn-sm fw-bold" href="<?php echo h($basePath); ?>/admin/index.php">View admin area</a>
                <?php endif; ?>
            </div>
        </div>
    </footer>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        const toggleEntryForm = document.getElementById('toggleEntryForm');
        const entryFormPanel = document.getElementById('entryFormPanel');
        if (toggleEntryForm && entryFormPanel) {
            toggleEntryForm.addEventListener('click', () => {
                const isOpen = !entryFormPanel.classList.contains('d-none');
                entryFormPanel.classList.toggle('d-none', isOpen);
                toggleEntryForm.setAttribute('aria-expanded', String(!isOpen));
                toggleEntryForm.textContent = isOpen ? 'View entry form' : 'Hide entry form';
            });
        }
    </script>
</body>
</html>
