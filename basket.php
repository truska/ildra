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
$canViewAdmin = roleCanAccessAdmin((string)($currentUser['role'] ?? ''));
$basketCount = count($basket);
$configuredTimeout = isset($siteSettings['basket_timeout_seconds']) ? (int)$siteSettings['basket_timeout_seconds'] : (15 * 60);
$basketExpirySeconds = max(300, $configuredTimeout);
$expiresAt = null;
$basketTimerSeconds = null;
$now = time();

// Option A policy: basket requires login (and is always scoped to the current user).
if (!$isLoggedIn) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please sign in to view your basket.']];
    header('Location: ' . $basePath . '/account');
    exit;
}

// Coupon emails can be opened before the recipient has chosen an entry. Keep a
// currently-valid code in their basket session, then check its eligible items
// when an entry is added or checkout begins.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['coupon'])) {
    $coupon = coupon_find($pdo, (string)$_GET['coupon']);
    $nowCoupon = date('Y-m-d H:i:s');
    if ($coupon && ($coupon['status'] ?? '') === 'active' && $nowCoupon >= (string)$coupon['valid_from'] && $nowCoupon <= (string)$coupon['valid_until'] && (int)$coupon['use_count'] < (int)$coupon['usage_limit']) {
        $_SESSION['basket_coupon_code'] = (string)$coupon['code'];
        $_SESSION['flash_success'] = 'Coupon ' . (string)$coupon['code'] . ' saved. Add an eligible entry to use it.';
        header('Location: ' . $basePath . '/basket');
        exit;
    }
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'This coupon is not currently valid.']];
    header('Location: ' . $basePath . '/basket');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'apply_coupon') {
        $result = coupon_validate_for_basket($pdo, (string)($_POST['coupon_code'] ?? ''), $basket, (int)($currentUser['id'] ?? 0));
        if (!empty($result['ok'])) {
            $_SESSION['basket_coupon_code'] = (string)$result['coupon']['code'];
            $_SESSION['flash_success'] = 'Coupon applied: ' . format_price($result['discount']) . ' will be deducted at checkout.';
            header('Location: ' . $basePath . '/basket'); exit;
        }
        $alerts[] = ['type'=>'danger','message'=>(string)($result['message'] ?? 'Coupon could not be applied.')];
    }
    if ($action === 'remove_coupon') {
        unset($_SESSION['basket_coupon_code']);
        $_SESSION['flash_success'] = 'Coupon removed.';
        header('Location: ' . $basePath . '/basket'); exit;
    }
    if ($action === 'expire_basket') {
        $_SESSION['basket'] = [];
        unset($_SESSION['basket_last_added']);
        saveBasketForSession($pdo, session_id(), [], $currentUser['id'] ?? null, null);
        $_SESSION['flash_info'] = 'Your basket expired. Items have been released.';
        header('Location: ' . $basePath . '/basket');
        exit;
    }
    if ($action === 'remove_item') {
        $removeId = $_POST['entry_id'] ?? '';
        $basket = array_values(array_filter($basket, fn($item) => ($item['id'] ?? '') !== $removeId));
        $_SESSION['basket'] = $basket;
        if (!$basket) {
            unset($_SESSION['basket_last_added']);
        }
        saveBasketForSession($pdo, session_id(), $basket, $currentUser['id'] ?? null, $_SESSION['basket_last_added'] ?? null);
        $_SESSION['flash_success'] = 'Entry removed from your basket.';
        header('Location: ' . $basePath . '/basket');
        exit;
    }
}

$lastAdded = (int)($_SESSION['basket_last_added'] ?? 0);
if ($basket && $lastAdded > 0) {
    $expiresAt = $lastAdded + $basketExpirySeconds;
    if ($now >= $expiresAt) {
        $_SESSION['basket'] = [];
        unset($_SESSION['basket_last_added']);
        saveBasketForSession($pdo, session_id(), [], $currentUser['id'] ?? null, null);
        $_SESSION['flash_info'] = 'Your basket expired. Please add items again.';
        header('Location: ' . $basePath . '/basket');
        exit;
    }
    $basketTimerSeconds = max(0, $expiresAt - $now);
}

$navItemEventsUrl = $basePath . '/events';

function event_url_local(array $event, string $basePath): string
{
    $id = (int)($event['event_id'] ?? 0);
    if ($id <= 0) {
        return '#';
    }
    $slug = slugify((string)($event['event_title'] ?? 'event'));
    return $basePath . '/events/' . $id . '-' . $slug;
}

$totalAmount = 0.0;
foreach ($basket as $item) {
    $totalAmount += price_to_number($item['price'] ?? 0);
}
$appliedCoupon = null; $couponDiscount = 0.0;
$savedCouponCode = strtoupper(trim((string)($_SESSION['basket_coupon_code'] ?? '')));
$savedCoupon = $savedCouponCode !== '' ? coupon_find($pdo, $savedCouponCode) : null;
$nowCoupon = date('Y-m-d H:i:s');
if ($savedCoupon && (($savedCoupon['status'] ?? '') !== 'active' || $nowCoupon < (string)$savedCoupon['valid_from'] || $nowCoupon > (string)$savedCoupon['valid_until'] || (int)$savedCoupon['use_count'] >= (int)$savedCoupon['usage_limit'])) {
    unset($_SESSION['basket_coupon_code']);
    $savedCoupon = null;
    $savedCouponCode = '';
}
if ($basket && $savedCoupon) {
    $couponResult = coupon_validate_for_basket($pdo, (string)$_SESSION['basket_coupon_code'], $basket, (int)($currentUser['id'] ?? 0));
    if (!empty($couponResult['ok'])) { $appliedCoupon=$couponResult['coupon']; $couponDiscount=(float)$couponResult['discount']; }
}
$userBalance = fetch_user_credit_balance($pdo, (int)($currentUser['id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basket | <?php echo h($siteSettings['hero_title']); ?></title>
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
        .navbar { box-shadow: 0 10px 36px rgba(0,0,0,0.12); position: relative; z-index: 3; }
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
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-white-50">Event basket</p>
            <h1 class="fw-bold mb-1">Your entries</h1>
            <div class="text-white-50">Review entries, adjust, and checkout.</div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>
            <?php if ($basket && $basketTimerSeconds !== null): ?>
                <div class="alert d-flex justify-content-between align-items-center flex-wrap gap-2" role="status" id="basket-timer-banner" data-remaining="<?php echo (int)$basketTimerSeconds; ?>" style="background: #fff5e6; border: 1px solid #f7d8b4; color: #4b2c11;">
                    <div class="d-flex align-items-center gap-2">
                        <span aria-hidden="true" style="font-size: 1.4rem;">⏱️</span>
                        <div>
                            <div class="fw-semibold">Your items are held for <span id="basket-countdown">--:--</span></div>
                            <div class="small" style="color:#6c4a2b;">If time runs out, your items will be released.</div>
                        </div>
                    </div>
                </div>
                <form id="basket-expire-form" method="POST" class="d-none">
                    <input type="hidden" name="action" value="expire_basket">
                </form>
            <?php endif; ?>
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card-soft p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="section-title mb-0">Basket</div>
                            <a class="btn btn-sm btn-outline-success" href="<?php echo h($navItemEventsUrl); ?>">Back to Events</a>
                        </div>
                        <?php if (!$basket): ?>
                            <div class="text-muted small">No entries yet. Start from an event page.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($basket as $item): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div>
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
                                                <div class="fw-semibold"><?php echo h($item['event_title']); ?><?php echo $chips ? ' (' . h(implode(' • ', $chips)) . ')' : ''; ?></div>
                                                <?php if ($bookingType !== 'membership'): ?>
                                                    <a class="small" href="<?php echo h(event_url_local($item, $basePath)); ?>">View Event</a>
                                                <?php endif; ?>
                                                <div class="text-muted small">Price: <?php echo h(format_price($item['price'] ?? 0)); ?></div>
                                                <div class="text-muted small">Type: <?php echo h($bookingTypeLabel); ?></div>
                                            </div>
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="action" value="remove_item">
                                                <input type="hidden" name="entry_id" value="<?php echo h($item['id']); ?>">
                                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card-soft p-3">
                        <div class="fw-bold mb-1">Basket summary</div>
                        <div class="text-muted small mb-3">Items: <?php echo $basketCount; ?></div>
                        <div class="small mb-2 <?php echo $userBalance < 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo $userBalance < 0 ? 'Debit balance: ' : 'Account credit available: '; ?><strong><?php echo h(format_price(abs($userBalance))); ?></strong></div>
                        <?php if($savedCoupon): ?><div class="small text-success mb-3">Saved coupon: <strong><?php echo h(format_price($savedCoupon['amount'])); ?></strong><?php if($appliedCoupon): ?> <span class="text-muted">(−<?php echo h(format_price($couponDiscount)); ?> on this basket)</span><?php endif; ?> <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#savedCouponDetails">Details</button></div><?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="fw-semibold">Total (approx)</div>
                            <div class="fs-5"><?php echo $totalAmount > 0 ? '£' . number_format($totalAmount, 2) : '—'; ?></div>
                        </div>
                        <?php if($savedCoupon): ?>
                            <form method="post" class="mb-3"><input type="hidden" name="action" value="apply_coupon"><label class="form-label small fw-semibold">Use a different coupon</label><div class="input-group"><input class="form-control" name="coupon_code" placeholder="AB12-CD34"><button class="btn btn-outline-success">Replace</button></div><div class="form-text">One coupon may be used per purchase.</div></form>
                            <form method="post" class="mb-3"><input type="hidden" name="action" value="remove_coupon"><button class="btn btn-sm btn-outline-secondary">Remove saved coupon</button></form>
                        <?php else: ?>
                            <form method="post" class="mb-3"><input type="hidden" name="action" value="apply_coupon"><label class="form-label small fw-semibold">Coupon code</label><div class="input-group"><input class="form-control" name="coupon_code" placeholder="AB12-CD34"><button class="btn btn-outline-success">Apply</button></div><div class="form-text">One coupon may be used per purchase.</div></form>
                        <?php endif; ?>
                        <a class="btn btn-success w-100<?php echo !$basket ? ' disabled' : ''; ?>" href="<?php echo h($basePath); ?>/checkout">Go to Checkout</a>
                        <a class="btn btn-outline-success w-100 mt-2" href="<?php echo h($navItemEventsUrl); ?>">Add more Entries</a>
                    </div>
                    <?php if($savedCoupon): ?><div class="modal fade" id="savedCouponDetails" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Saved coupon</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><dl class="row mb-0"><dt class="col-sm-4">Code</dt><dd class="col-sm-8"><?php echo h($savedCoupon['code']); ?></dd><dt class="col-sm-4">Value</dt><dd class="col-sm-8"><?php echo h(format_price($savedCoupon['amount'])); ?></dd><dt class="col-sm-4">Details</dt><dd class="col-sm-8"><?php echo h((string)$savedCoupon['title']); ?></dd><dt class="col-sm-4">Valid until</dt><dd class="col-sm-8"><?php echo h(format_display_datetime($savedCoupon['valid_until'])); ?></dd></dl></div></div></div></div><?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        (() => {
            const banner = document.getElementById('basket-timer-banner');
            const countdownEl = document.getElementById('basket-countdown');
            const expireForm = document.getElementById('basket-expire-form');
            if (!banner || !countdownEl) return;

            let remaining = parseInt(banner.getAttribute('data-remaining') || '0', 10);
            if (Number.isNaN(remaining) || remaining <= 0) {
                if (expireForm) expireForm.submit();
                return;
            }

            const render = () => {
                const mins = Math.floor(remaining / 60);
                const secs = remaining % 60;
                countdownEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
            };

            render();
            const timer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(timer);
                    if (expireForm) expireForm.submit();
                    return;
                }
                render();
            }, 1000);
        })();
    </script>
</body>
</html>
