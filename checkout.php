<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';

$basket = $_SESSION['basket'] ?? [];
$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basketCount = count($basket);

// Option A policy: checkout requires login.
if (!$isLoggedIn) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please sign in to checkout.']];
    header('Location: ' . $basePath . '/account');
    exit;
}

if (!$basket) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Add an entry before checking out.']];
    header('Location: ' . $basePath . '/basket');
    exit;
}

$navItemEventsUrl = $basePath . '/events';

$totalAmount = 0.0;
foreach ($basket as $item) {
    $totalAmount += price_to_number($item['price'] ?? 0);
}
$userBalance = $currentUser ? fetch_user_credit_balance($pdo, (int)($currentUser['id'] ?? 0)) : 0.0;
$paymentDue = max(0.0, $totalAmount - $userBalance);
$paymentDue = round($paymentDue, 2);
$insufficientCredit = $paymentDue > 0 && $totalAmount > 0;
$stripeConfig = stripe_config($config);
$stripeEnabled = stripe_is_enabled($stripeConfig);
$stripeTestMode = str_starts_with((string)($stripeConfig['publishable_key'] ?? ''), 'pk_test_');
$bookingRef = 'BK-' . strtoupper(bin2hex(random_bytes(4)));
$eventIdsForPayment = [];

$defaultContactName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
if ($defaultContactName === '') {
    $defaultContactName = (string)($currentUser['first_name'] ?? ($currentUser['email'] ?? ''));
}
$contactNamePrefill = trim((string)($_POST['contact_name'] ?? $defaultContactName));
$contactEmailPrefill = trim((string)($_POST['contact_email'] ?? ($currentUser['email'] ?? '')));
$contactPhonePrefill = trim((string)($_POST['contact_phone'] ?? ''));
$showCreditScreen = false;
$showPaymentScreen = false;
$pendingPaymentData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_checkout') {
    $contactName = trim((string)($_POST['contact_name'] ?? ''));
    $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
    $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
    $userId = $currentUser['id'] ?? null;

    $userFullName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
    if ($userFullName !== '' && ($contactName === '' || $contactName === (string)($currentUser['first_name'] ?? ''))) {
        $contactName = $userFullName;
    }

    $contactNamePrefill = $contactName;
    $contactEmailPrefill = $contactEmail;
    $contactPhonePrefill = $contactPhone;

    if ($contactName === '' || $contactEmail === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Contact name and email are required.'];
    }

    $paymentDue = max(0.0, $totalAmount - $userBalance);
    $paymentDue = round($paymentDue, 2);
    $creditToUse = round(min(max(0.0, $userBalance), max(0.0, $totalAmount)), 2);
    $needsCreditConfirmation = $creditToUse > 0 && (($_POST['confirm_credit'] ?? '') !== '1');
    $needsSimulatedPayment = !$stripeEnabled && $paymentDue > 0 && $totalAmount > 0 && (($_POST['confirm_payment'] ?? '') !== '1');

    if (!$alerts && $needsCreditConfirmation) {
        $showCreditScreen = true;
        $pendingPaymentData = [
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'credit_to_use' => $creditToUse,
            'payment_due' => $paymentDue,
        ];
    } elseif (!$alerts && $paymentDue > 0 && $stripeEnabled) {
        // Build Stripe Checkout Session
        $scheme = auth_cookie_secure() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $successUrl = $scheme . '://' . $host . ($basePath ?: '') . '/checkout/complete?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $scheme . '://' . $host . ($basePath ?: '') . '/checkout?canceled=1';
        $lineItems = [];
        foreach ($basket as $idx => $item) {
            $priceNumber = price_to_number($item['price'] ?? 0);
            if ($priceNumber < 0) {
                $priceNumber = 0;
            }
            $unitAmount = (int)round($priceNumber * 100);
            $name = trim((string)($item['event_title'] ?? $item['membership_name'] ?? 'Entry'));
            if ($name === '') {
                $name = 'Entry';
            }
            $descriptionParts = [];
            $meta = $item['metadata'] ?? [];
            if (!empty($meta['class_label'])) {
                $descriptionParts[] = (string)$meta['class_label'];
            }
            if (!empty($meta['rider_name']) || !empty($meta['horse_name'])) {
                $descriptionParts[] = 'Rider: ' . ($meta['rider_name'] ?? '—') . ' · Horse: ' . ($meta['horse_name'] ?? '—');
            }
            if (($item['booking_type'] ?? '') === 'membership') {
                $memberName = trim((string)($item['member_name'] ?? ''));
                if ($memberName !== '') {
                    $descriptionParts[] = 'Member: ' . $memberName;
                }
                if (!empty($item['membership_year'])) {
                    $descriptionParts[] = 'Year: ' . (int)$item['membership_year'];
                }
            } elseif (($item['booking_type'] ?? '') === 'horse_logbook') {
                $horseName = trim((string)($item['horse_name'] ?? ''));
                if ($horseName !== '') {
                    $descriptionParts[] = 'Horse: ' . $horseName;
                }
                if (!empty($item['logbook_year'])) {
                    $descriptionParts[] = 'Year: ' . (int)$item['logbook_year'];
                }
            }
            if (!empty($item['event_id'])) {
                $eventIdsForPayment[] = (int)$item['event_id'];
            }
            $description = trim(implode(' • ', array_filter($descriptionParts)));
            $productData = [
                'name' => $name,
            ];
            if ($description !== '') {
                $productData['description'] = $description;
            }
            $lineItems[] = [
                'price_data' => [
                    'currency' => $stripeConfig['currency'],
                    'unit_amount' => $unitAmount,
                    'product_data' => $productData,
                ],
                'quantity' => 1,
            ];
        }
        if ($creditToUse > 0 && $paymentDue > 0) {
            $lineItems = [[
                'price_data' => [
                    'currency' => $stripeConfig['currency'],
                    'unit_amount' => (int)round($paymentDue * 100),
                    'product_data' => [
                        'name' => 'Booking balance after account credit',
                        'description' => 'Order total ' . format_price($totalAmount) . ' less account credit ' . format_price($creditToUse),
                    ],
                ],
                'quantity' => 1,
            ]];
        }
        if (empty($lineItems)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Unable to prepare payment items.'];
        } else {
            $membershipYears = [];
            foreach ($basket as $item) {
                if (($item['booking_type'] ?? '') === 'membership' && !empty($item['membership_year'])) {
                    $membershipYears[] = (int)$item['membership_year'];
                }
                if (($item['booking_type'] ?? '') === 'horse_logbook' && !empty($item['logbook_year'])) {
                    $membershipYears[] = (int)$item['logbook_year'];
                }
            }
            $params = [
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $contactEmail,
                'line_items' => $lineItems,
                'metadata' => [
                    'booking_ref' => $bookingRef,
                    'user_id' => (int)($currentUser['id'] ?? 0),
                    'site' => (string)($_SERVER['HTTP_HOST'] ?? ''),
                    'event_ids' => $eventIdsForPayment ? implode(',', array_unique($eventIdsForPayment)) : '',
                    'membership_years' => $membershipYears ? implode(',', array_unique($membershipYears)) : '',
                ],
            ];
            if ($contactEmail !== '') {
                $params['customer_email'] = $contactEmail;
            }
            $resp = stripe_create_checkout_session($stripeConfig, $params);
            if (!($resp['ok'] ?? false)) {
                $alerts[] = ['type' => 'danger', 'message' => 'Could not start payment. ' . ($resp['error'] ?? 'Please try again.')];
            } else {
                $sessionData = $resp['data'] ?? [];
                $_SESSION['pending_checkout'] = [
                    'booking_ref' => $bookingRef,
                    'contact_name' => $contactName,
                    'contact_email' => $contactEmail,
                    'contact_phone' => $contactPhone,
                    'basket' => $basket,
                    'total' => $totalAmount,
                    'payment_due' => $paymentDue,
                    'user_balance' => $userBalance,
                    'session_id' => $sessionData['id'] ?? '',
                    'created_at' => time(),
                ];
                header('Location: ' . (string)($sessionData['url'] ?? $cancelUrl));
                exit;
            }
        }
    } elseif (!$alerts && $needsSimulatedPayment) {
        $showPaymentScreen = true;
        $pendingPaymentData = [
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
        ];
    } elseif (!$alerts) {
        $order = [
            'booking_ref' => $bookingRef,
            'user_id' => $userId ? (int)$userId : null,
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'items' => $basket,
            'total' => $totalAmount,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        append_booking_record($order, $alerts, $pdo);
        // Membership and horse logbook purchases are tracked separately.
        if (!$alerts && $userId) {
            foreach ($basket as $basketItem) {
                $bookingType = $basketItem['booking_type'] ?? '';
                if ($bookingType === 'membership') {
                    $typeId = (int)($basketItem['membership_type_id'] ?? 0);
                    if ($typeId <= 0) {
                        continue;
                    }
                    $memberId = (int)($basketItem['member_id'] ?? 0);
                    if ($memberId <= 0) {
                        $alerts[] = ['type' => 'danger', 'message' => 'Membership is missing a member. Please remove it from your basket and add again.'];
                        break;
                    }
                    $membershipType = fetchMembershipTypeById($pdo, $typeId);
                    if (!$membershipType) {
                        continue;
                    }
                    saveMembershipPurchase($pdo, [
                        'purchased_by_user_id' => (int)$userId,
                        'member_id' => $memberId,
                        'membership_type_id' => $typeId,
                        'membership_year' => (int)($basketItem['membership_year'] ?? $membershipType['membership_year'] ?? 0),
                        'amount' => $basketItem['price'] ?? '0',
                        'status' => 'active',
                    ], $alerts);
                } elseif ($bookingType === 'horse_logbook') {
                    $typeId = (int)($basketItem['logbook_type_id'] ?? 0);
                    $horseId = (int)($basketItem['horse_id'] ?? 0);
                    $year = (int)($basketItem['logbook_year'] ?? 0);
                    if ($typeId <= 0 || $horseId <= 0 || $year <= 0) {
                        continue;
                    }
                    $logbookType = fetchHorseLogbookTypeById($pdo, $typeId);
                    if (!$logbookType) {
                        continue;
                    }
                    saveHorseLogbookPurchase($pdo, [
                        'purchased_by_user_id' => (int)$userId,
                        'horse_id' => $horseId,
                        'logbook_type_id' => $typeId,
                        'valid_year' => $year,
                        'amount' => $basketItem['price'] ?? '0',
                        'status' => 'active',
                    ], $alerts);
                }
            }
        }
        // Finance logging: if payment required, first record payment (credit), then checkout debit
        $financeAlerts = [];
        if ($paymentDue > 0) {
            record_finance_transaction($pdo, [
                'user_id' => $userId ?: null,
                'type' => 'payment_simulated',
                'amount' => $paymentDue,
                'reference' => $order['booking_ref'],
                'notes' => 'Simulated card payment before checkout',
                'metadata' => [
                    'contact_email' => $contactEmail,
                    'contact_name' => $contactName,
                    'items' => count($basket),
                    'payment_method' => 'simulated_card',
                ],
            ], $financeAlerts);
        }
        record_finance_transaction($pdo, [
            'user_id' => $userId ?: null,
            'type' => 'checkout',
            'amount' => -1 * $totalAmount,
            'reference' => $order['booking_ref'],
            'notes' => $paymentDue > 0 ? 'Checkout completed (simulated card + credit)' : 'Checkout completed (credits)',
            'metadata' => [
                'contact_email' => $contactEmail,
                'contact_name' => $contactName,
                'items' => count($basket),
                'payment_method' => $paymentDue > 0 ? 'simulated_card' : 'account_credit',
                'payment_due' => $paymentDue,
                'credit_before' => $userBalance,
            ],
        ], $financeAlerts);

        // Booking confirmation email (never blocks checkout; failures are logged).
        $emailSettings = getEmailSettings($pdo);
        $emailPayload = render_booking_confirmation_email($order, $siteSettings, $emailSettings);
        send_logged_email(
            $pdo,
            $contactEmail,
            (string)($emailPayload['subject'] ?? ('Booking confirmation ' . (string)($order['booking_ref'] ?? ''))),
            (string)($emailPayload['html'] ?? ''),
            (string)($emailPayload['text'] ?? ''),
            [
                'type' => 'booking_confirmation',
                'booking_ref' => (string)($order['booking_ref'] ?? ''),
                'user_id' => $userId ? (int)$userId : null,
            ]
        );

        $_SESSION['basket'] = [];
        unset($_SESSION['basket_last_added']);
        saveBasketForSession($pdo, session_id(), [], $userId ?: null, null);
        $_SESSION['flash_success'] = 'Booking placed. You can review it any time.';
        header('Location: ' . $basePath . '/checkout/complete?id=' . rawurlencode($order['booking_ref']));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | <?php echo h($siteSettings['hero_title']); ?></title>
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
        .page-hero { display: none; }
        body.show-page-hero .page-hero { display: block; }
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
        .payment-hold {
            border: 1px dashed rgba(20, 97, 24, 0.25);
            background: rgba(20, 97, 24, 0.05);
        }
        .pill-muted {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(0,0,0,0.04);
            font-weight: 700;
            color: var(--text-main);
        }
        .stripe-test-note {
            border-left: 3px solid #635bff;
            background: rgba(99, 91, 255, 0.06);
            color: var(--text-main);
        }
        .stripe-test-note code {
            color: inherit;
            font-size: inherit;
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-white-50">Checkout</p>
            <h1 class="fw-bold mb-1">Confirm your entries</h1>
            <div class="text-white-50">No payment yet — we will email you.</div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card-soft p-4">
                        <div class="section-title mb-3">Entries</div>
                        <ul class="list-group list-group-flush mb-0">
                            <?php foreach ($basket as $item): ?>
                                <li class="list-group-item">
                                    <?php
                                        $bookingType = $item['booking_type'] ?? 'ride';
                                        $bookingTypeLabel = ucfirst($bookingType);
                                    ?>
                                    <?php
                                        $meta = $item['metadata'] ?? [];
                                        $chips = [];
                                        if (isset($meta['class_label'])) {
                                            $chips[] = $meta['class_label'];
                                        }
                                        if (isset($meta['rider_name']) || isset($meta['horse_name'])) {
                                            $chips[] = 'Rider: ' . ($meta['rider_name'] ?? '—') . ' · Horse: ' . ($meta['horse_name'] ?? '—');
                                        }
                                        if (isset($meta['contact_email']) || isset($meta['contact_phone'])) {
                                            $chips[] = 'Contact: ' . ($meta['contact_email'] ?? '') . (empty($meta['contact_phone']) ? '' : ' · ' . $meta['contact_phone']);
                                        }
                                        $componentsSummary = entry_components_summary($meta);
                                        if ($componentsSummary !== '') {
                                            $chips[] = 'Extras: ' . $componentsSummary;
                                        }
                                    ?>
                                    <div class="fw-semibold"><?php echo h($item['event_title']); ?><?php echo $chips ? ' — ' . h(implode(' • ', $chips)) : ''; ?></div>
                                    <div class="text-muted small">Price: <?php echo h(format_price($item['price'] ?? 0)); ?></div>
                                    <div class="text-muted small">Type: <?php echo h($bookingTypeLabel); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a class="btn btn-outline-success mt-3" href="<?php echo h($basePath); ?>/basket">Edit basket</a>
                        <?php if ($stripeTestMode): ?>
                            <div class="stripe-test-note rounded p-3 mt-3 small">
                                <div class="fw-semibold mb-1">Stripe test payment reminder</div>
                                <div>Successful payment: <code>4242 4242 4242 4242</code></div>
                                <div class="text-muted small mt-1">
                                    Generic decline: <code>4000 0000 0000 0002</code><br>
                                    Insufficient funds: <code>4000 0000 0000 9995</code><br>
                                    Authentication required: <code>4000 0025 0000 3155</code>
                                </div>
                                <div class="text-muted small mt-1">Use any future expiry date and any three-digit CVC.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <?php if ($showCreditScreen): ?>
                        <div class="card-soft p-4 payment-hold">
                            <div class="fw-bold fs-5 mb-2">Account credit available</div>
                            <p class="mb-3">
                                You have <strong>£<?php echo number_format($userBalance, 2); ?></strong> account credit.
                                This order will use <strong>£<?php echo number_format((float)($pendingPaymentData['credit_to_use'] ?? 0), 2); ?></strong> of it.
                            </p>
                            <div class="border rounded p-3 bg-white mb-3">
                                <div class="d-flex justify-content-between"><span>Order total</span><strong>£<?php echo number_format($totalAmount, 2); ?></strong></div>
                                <div class="d-flex justify-content-between"><span>Credit used</span><strong>−£<?php echo number_format((float)($pendingPaymentData['credit_to_use'] ?? 0), 2); ?></strong></div>
                                <div class="d-flex justify-content-between border-top mt-2 pt-2"><span>Remaining payment</span><strong>£<?php echo number_format((float)($pendingPaymentData['payment_due'] ?? 0), 2); ?></strong></div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="confirm_checkout">
                                <input type="hidden" name="confirm_credit" value="1">
                                <input type="hidden" name="contact_name" value="<?php echo h($pendingPaymentData['contact_name'] ?? $contactNamePrefill); ?>">
                                <input type="hidden" name="contact_email" value="<?php echo h($pendingPaymentData['contact_email'] ?? $contactEmailPrefill); ?>">
                                <input type="hidden" name="contact_phone" value="<?php echo h($pendingPaymentData['contact_phone'] ?? $contactPhonePrefill); ?>">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2">
                                    <a class="btn btn-outline-secondary" href="<?php echo h($basePath); ?>/basket">Edit basket</a>
                                    <button class="btn btn-success" type="submit">
                                        <?php echo ((float)($pendingPaymentData['payment_due'] ?? 0)) > 0 ? 'Use credit and continue' : 'Use credit and place order'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php elseif ($showPaymentScreen): ?>
                        <div class="card-soft p-4 payment-hold">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-bold">Simulated payment (Stripe preview)</div>
                                    <div class="text-muted small">No real charge. Confirm to complete checkout.</div>
                                </div>
                                <span class="pill-muted">Payment due: £<?php echo number_format($paymentDue, 2); ?></span>
                            </div>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="action" value="confirm_checkout">
                                <input type="hidden" name="confirm_credit" value="1">
                                <input type="hidden" name="confirm_payment" value="1">
                                <input type="hidden" name="contact_name" value="<?php echo h($pendingPaymentData['contact_name'] ?? $contactNamePrefill); ?>">
                                <input type="hidden" name="contact_email" value="<?php echo h($pendingPaymentData['contact_email'] ?? $contactEmailPrefill); ?>">
                                <input type="hidden" name="contact_phone" value="<?php echo h($pendingPaymentData['contact_phone'] ?? $contactPhonePrefill); ?>">
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Card number (simulated)</label>
                                    <input type="text" class="form-control" placeholder="4242 4242 4242 4242">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Expiry</label>
                                    <input type="text" class="form-control" placeholder="12/34">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold">CVC</label>
                                    <input type="text" class="form-control" placeholder="123">
                                </div>
                                <div class="col-12 d-flex justify-content-between align-items-center">
                                    <div class="text-muted small">Simulated payment only. No funds taken.</div>
                                    <button class="btn btn-success">Confirm payment</button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="card-soft p-4">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-bold mb-1">Contact details</div>
                                    <div class="text-muted small">We’ll use this for your booking confirmation.</div>
                                </div>
                                <div class="pill-muted">
                                    Credit: £<?php echo number_format($userBalance, 2); ?>
                                </div>
                            </div>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="action" value="confirm_checkout">
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Name</label>
                                    <input type="text" name="contact_name" class="form-control" value="<?php echo h($contactNamePrefill); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Email</label>
                                    <input type="email" name="contact_email" class="form-control" value="<?php echo h($contactEmailPrefill); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Phone</label>
                                    <input type="text" name="contact_phone" class="form-control" placeholder="+44..." value="<?php echo h($contactPhonePrefill); ?>">
                                </div>
                                <div class="col-12 d-flex justify-content-between align-items-center">
                                    <div>
                                    <div class="fw-semibold mb-1">Total (approx): £<?php echo number_format($totalAmount, 2); ?></div>
                                    <?php if ($insufficientCredit && $totalAmount > 0): ?>
                                        <div class="text-danger small">Not enough credit. You’ll pay £<?php echo number_format($paymentDue, 2); ?> now.</div>
                                    <?php else: ?>
                                        <div class="text-muted small">This will use your account credit.</div>
                                    <?php endif; ?>
                                </div>
                                <button class="btn btn-success"><?php echo $insufficientCredit && $totalAmount > 0 ? 'Continue to payment' : 'Place order'; ?></button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
