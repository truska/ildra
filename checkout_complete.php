<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';

$stripeConfig = stripe_config($config);
$stripeEnabled = stripe_is_enabled($stripeConfig);
$sessionId = isset($_GET['session_id']) ? (string)$_GET['session_id'] : '';
$pendingCheckout = $_SESSION['pending_checkout'] ?? null;

$orderId = isset($_GET['id']) ? (string)$_GET['id'] : '';
$order = $orderId ? find_booking_by_id($orderId, $pdo, $alerts) : null;

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

if (!$order && $sessionId !== '' && $pendingCheckout && ($pendingCheckout['session_id'] ?? '') === $sessionId && $stripeEnabled) {
    $resp = stripe_retrieve_checkout_session($stripeConfig, $sessionId);
    if (($resp['ok'] ?? false) && isset($resp['data']) && is_array($resp['data'])) {
        $sessionData = $resp['data'];
        $paymentStatus = strtolower((string)($sessionData['payment_status'] ?? ''));
        if ($paymentStatus === 'paid' || strtolower((string)($sessionData['status'] ?? '')) === 'complete') {
            $bookingRef = (string)($pendingCheckout['booking_ref'] ?? '');
            $contactName = (string)($pendingCheckout['contact_name'] ?? '');
            $contactEmail = (string)($pendingCheckout['contact_email'] ?? '');
            $contactPhone = (string)($pendingCheckout['contact_phone'] ?? '');
            $basket = $pendingCheckout['basket'] ?? [];
            $totalAmount = (float)($pendingCheckout['total'] ?? 0);
            $paymentDue = (float)($pendingCheckout['payment_due'] ?? 0);
            $userBalance = (float)($pendingCheckout['user_balance'] ?? 0);
            $paymentIntentId = (string)($sessionData['payment_intent'] ?? '');
            $stripeChargeId = '';
            $stripeFee = null;
            $stripeNet = null;
            if ($paymentIntentId !== '') {
                $piResp = stripe_retrieve_payment_intent($stripeConfig, $paymentIntentId, ['latest_charge.balance_transaction']);
                if (($piResp['ok'] ?? false) && isset($piResp['data']) && is_array($piResp['data'])) {
                    $pi = $piResp['data'];
                    $stripeChargeId = (string)($pi['latest_charge'] ?? '');
                    if (is_array($pi['latest_charge'] ?? null)) {
                        $stripeChargeId = (string)($pi['latest_charge']['id'] ?? $stripeChargeId);
                        $balTx = $pi['latest_charge']['balance_transaction'] ?? null;
                        if (is_array($balTx)) {
                            $stripeFee = isset($balTx['fee']) ? ((int)$balTx['fee']) / 100 : null;
                            $stripeNet = isset($balTx['net']) ? ((int)$balTx['net']) / 100 : null;
                        }
                    }
                }
            }
            if ($bookingRef !== '') {
                $existing = find_booking_by_id($bookingRef, $pdo, $alerts);
                if ($existing) {
                    $order = $existing;
                    unset($_SESSION['pending_checkout']);
                }
            }
            if (!$order) {
                $order = [
                    'booking_ref' => $bookingRef !== '' ? $bookingRef : 'BK-' . strtoupper(bin2hex(random_bytes(4))),
                    'user_id' => (int)($currentUser['id'] ?? 0) ?: null,
                    'contact_name' => $contactName,
                    'contact_email' => $contactEmail,
                    'contact_phone' => $contactPhone,
                    'items' => $basket,
                    'total' => $totalAmount,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                append_booking_record($order, $alerts, $pdo);
                if (!$alerts && $currentUser) {
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
                                'purchased_by_user_id' => (int)($currentUser['id'] ?? 0),
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
                                'purchased_by_user_id' => (int)($currentUser['id'] ?? 0),
                                'horse_id' => $horseId,
                                'logbook_type_id' => $typeId,
                                'valid_year' => $year,
                                'amount' => $basketItem['price'] ?? '0',
                                'status' => 'active',
                            ], $alerts);
                        }
                    }
                }
                $financeAlerts = [];
                if ($paymentDue > 0) {
                    record_finance_transaction($pdo, [
                        'user_id' => $currentUser['id'] ?? null,
                        'type' => 'payment_stripe',
                        'amount' => $paymentDue,
                        'reference' => $order['booking_ref'],
                        'notes' => 'Stripe payment',
                        'metadata' => [
                            'contact_email' => $contactEmail,
                            'contact_name' => $contactName,
                        'items' => count($basket),
                        'stripe_session_id' => $sessionId,
                        'stripe_payment_intent' => $paymentIntentId,
                        'stripe_charge_id' => $stripeChargeId,
                        'stripe_fee' => $stripeFee,
                        'stripe_net' => $stripeNet,
                        'event_ids' => isset($sessionData['metadata']['event_ids']) ? $sessionData['metadata']['event_ids'] : '',
                        'membership_years' => isset($sessionData['metadata']['membership_years']) ? $sessionData['metadata']['membership_years'] : '',
                    ],
                ], $financeAlerts);
            }
            record_finance_transaction($pdo, [
                'user_id' => $currentUser['id'] ?? null,
                    'type' => 'checkout',
                    'amount' => -1 * $totalAmount,
                    'reference' => $order['booking_ref'],
                    'notes' => $paymentDue > 0 ? 'Checkout completed (Stripe + credit)' : 'Checkout completed (credits)',
                    'metadata' => [
                        'contact_email' => $contactEmail,
                        'contact_name' => $contactName,
                        'items' => count($basket),
                        'payment_method' => $paymentDue > 0 ? 'stripe' : 'account_credit',
                        'payment_due' => $paymentDue,
                        'credit_before' => $userBalance,
                        'stripe_session_id' => $sessionId,
                        'stripe_payment_intent' => $paymentIntentId,
                        'stripe_charge_id' => $stripeChargeId,
                        'stripe_fee' => $stripeFee,
                        'stripe_net' => $stripeNet,
                        'event_ids' => isset($sessionData['metadata']['event_ids']) ? $sessionData['metadata']['event_ids'] : '',
                        'membership_years' => isset($sessionData['metadata']['membership_years']) ? $sessionData['metadata']['membership_years'] : '',
                    ],
                ], $financeAlerts);
                // Booking confirmation email (never blocks checkout; failures are logged).
                $emailSettings = getEmailSettings($pdo);
                $emailPayload = render_booking_confirmation_email($order, $siteSettings, $emailSettings);
                send_logged_email(
                    $pdo,
                    $contactEmail,
                    (string)($emailPayload['subject'] ?? ('Purchase Confirmation ' . (string)($order['booking_ref'] ?? ''))),
                    (string)($emailPayload['html'] ?? ''),
                    (string)($emailPayload['text'] ?? ''),
                    [
                        'type' => 'booking_confirmation',
                        'booking_ref' => (string)($order['booking_ref'] ?? ''),
                        'user_id' => (int)($currentUser['id'] ?? 0),
                        'source' => 'stripe_checkout_complete',
                    ]
                );
                $_SESSION['basket'] = [];
                unset($_SESSION['basket_last_added']);
                saveBasketForSession($pdo, session_id(), [], $currentUser['id'] ?? null, null);
                unset($_SESSION['pending_checkout']);
            }
        }
    }
}

// Option A policy: booking details require login and must belong to the current user.
if (!$isLoggedIn) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please sign in to view booking details.']];
    header('Location: ' . $basePath . '/account');
    exit;
}

if ($order) {
    $userId = (int)($currentUser['id'] ?? 0);
    $userEmail = strtolower((string)($currentUser['email'] ?? ''));
    $matchesUser = ($userId > 0 && (int)($order['user_id'] ?? 0) === $userId)
        || ($userEmail !== '' && strtolower((string)($order['contact_email'] ?? '')) === $userEmail);
    if (!$matchesUser && !$canViewAdmin) {
        // Avoid confirming whether a booking id exists.
        $order = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $order ? 'Booking complete' : 'Booking not found'; ?> | <?php echo h($siteSettings['hero_title']); ?></title>
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
            #confettiCanvas {
                position: fixed;
                inset: 0;
                width: 100%;
                height: 100%;
                pointer-events: none;
                z-index: 9999;
            }
            /* Hide hero everywhere unless explicitly opted in */
            .page-hero { display: none; }
            body.show-page-hero .page-hero { display: block; }
        </style>
        <?php include __DIR__ . '/views/header_styles.php'; ?>
    </head>
    <body>
        <?php if ($order): ?>
            <canvas id="confettiCanvas" aria-hidden="true"></canvas>
        <?php endif; ?>
        <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-white-50">Checkout</p>
            <h1 class="fw-bold mb-1"><?php echo $order ? 'Thank you' : 'Booking not found'; ?></h1>
            <div class="text-white-50"><?php echo $order ? 'We have recorded your entries.' : 'Please return to the bookings list.'; ?></div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php if ($order): ?>
                <div class="card-soft p-4 mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold">Booking reference</div>
                            <div class="text-muted small"><?php echo h($order['booking_ref'] ?? $order['id']); ?></div>
                        </div>
                        <a class="btn btn-outline-success btn-sm" href="<?php echo h($basePath); ?>/bookings">View all bookings</a>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="fw-semibold">Placed</div>
                            <div class="text-muted"><?php echo h(format_display_datetime($order['created_at'] ?? null, '')); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="fw-semibold">Contact</div>
                            <div class="text-muted"><?php echo h($order['contact_name']); ?> · <?php echo h($order['contact_email']); ?></div>
                            <?php if (!empty($order['contact_phone'])): ?>
                                <div class="text-muted small"><?php echo h($order['contact_phone']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="fw-semibold">Total (approx)</div>
                            <div class="text-muted"><?php echo isset($order['total']) ? '£' . number_format((float)$order['total'], 2) : '—'; ?></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="fw-semibold mb-1">Items</div>
                        <ul class="list-group list-group-flush">
                            <?php foreach (($order['items'] ?? []) as $item): ?>
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
                                        $componentsSummary = entry_components_summary($meta);
                                        if ($componentsSummary !== '') {
                                            $chips[] = 'Extras: ' . $componentsSummary;
                                        }
                                    ?>
                                    <div class="fw-semibold"><?php echo h($item['event_title'] ?? ''); ?><?php echo $chips ? ' — ' . h(implode(' • ', $chips)) : ''; ?></div>
                                    <div class="text-muted small">Price: <?php echo h(format_price($item['price'] ?? 0)); ?></div>
                                    <div class="text-muted small">Type: <?php echo h($bookingTypeLabel); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php else: ?>
                <div class="card-soft p-4">
                    <div class="fw-bold mb-1">Booking not found</div>
                    <div class="text-muted mb-3">Try selecting an order from the list.</div>
                    <a class="btn btn-outline-success" href="<?php echo h($basePath); ?>/bookings">Back to bookings</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php if ($order): ?>
        <script>
            (function () {
                if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

                var canvas = document.getElementById("confettiCanvas");
                if (!canvas) return;
                var ctx = canvas.getContext("2d");
                if (!ctx) return;

                var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
                function resize() {
                    canvas.width = Math.floor(window.innerWidth * DPR);
                    canvas.height = Math.floor(window.innerHeight * DPR);
                }
                resize();
                window.addEventListener("resize", resize, { passive: true });

                function rand(min, max) { return min + Math.random() * (max - min); }

                var colors = ["#FF3B30", "#FF9500", "#FFCC00", "#34C759", "#007AFF", "#AF52DE", "#FF2D55", "#00C7BE"];
                var pieces = [];

                var originX = canvas.width * 0.5;
                var originY = canvas.height * 0.62;

                var count = 180;
                for (var i = 0; i < count; i++) {
                    var angle = rand(-Math.PI * 0.95, -Math.PI * 0.05); // upward "mushroom cloud"
                    var speed = rand(8, 18) * DPR;
                    pieces.push({
                        x: originX + rand(-6, 6) * DPR,
                        y: originY + rand(-6, 6) * DPR,
                        vx: Math.cos(angle) * speed,
                        vy: Math.sin(angle) * speed,
                        size: rand(6, 12) * DPR,
                        rot: rand(0, Math.PI * 2),
                        vr: rand(-0.25, 0.25),
                        color: colors[(Math.random() * colors.length) | 0],
                        shape: Math.random() < 0.65 ? "rect" : "circle",
                        life: rand(0.8, 1.6), // seconds (fades)
                        age: 0
                    });
                }

                var start = performance.now();
                var last = start;
                var duration = 2000;
                var gravity = 26 * DPR; // px/s^2
                var drag = 0.985;

                function frame(now) {
                    var dt = Math.min(0.05, (now - last) / 1000);
                    last = now;

                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    var elapsed = now - start;
                    var t = Math.min(1, elapsed / duration);

                    for (var j = 0; j < pieces.length; j++) {
                        var p = pieces[j];
                        p.age += dt;
                        if (p.age > p.life) continue;

                        p.vx *= drag;
                        p.vy = p.vy * drag + gravity * dt;
                        p.x += p.vx;
                        p.y += p.vy;
                        p.rot += p.vr;

                        var alpha = 1;
                        var fadeStart = p.life * 0.65;
                        if (p.age > fadeStart) {
                            alpha = Math.max(0, 1 - (p.age - fadeStart) / (p.life - fadeStart));
                        }
                        // also fade out as the overall effect ends
                        alpha *= (1 - Math.max(0, t - 0.85) / 0.15);

                        ctx.save();
                        ctx.globalAlpha = alpha;
                        ctx.translate(p.x, p.y);
                        ctx.rotate(p.rot);
                        ctx.fillStyle = p.color;
                        if (p.shape === "circle") {
                            ctx.beginPath();
                            ctx.arc(0, 0, p.size * 0.35, 0, Math.PI * 2);
                            ctx.fill();
                        } else {
                            ctx.fillRect(-p.size * 0.4, -p.size * 0.15, p.size * 0.8, p.size * 0.3);
                        }
                        ctx.restore();
                    }

                    if (elapsed < duration) {
                        requestAnimationFrame(frame);
                        return;
                    }

                    canvas.parentNode && canvas.parentNode.removeChild(canvas);
                    window.removeEventListener("resize", resize);
                }

                requestAnimationFrame(frame);
            })();
        </script>
    <?php endif; ?>
</body>
</html>
