<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '/' ? '' : $basePath;
$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true) ?: defaultPages();
$navTree = buildNavTree($pages);
$navItemEventsUrl = $basePath . '/events';
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager', 'organiser'], true);
$basketCount = count($_SESSION['basket'] ?? []);
$sessionId=trim((string)($_GET['session_id']??'')); $ok=false;
if($sessionId!=='' && stripe_is_enabled(stripe_config($config))){$response=stripe_retrieve_checkout_session(stripe_config($config),$sessionId);if(!empty($response['ok']))$ok=miscPaymentComplete($pdo,(array)($response['data']??[]),$alerts);}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment confirmation | <?php echo h((string)($siteSettings['hero_title'] ?? 'ILDRA')); ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous"><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet"><?php include __DIR__ . '/views/header_styles.php'; ?><style>body{background:#f7f8f1;color:#0c2a12;font-family:Manrope,system-ui,sans-serif}.payment-card{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 12px 34px rgba(15,47,31,.07)}</style></head><body>
<?php include __DIR__ . '/views/header.php'; ?>
<main class="py-5"><div class="container"><div class="payment-card p-4 p-lg-5 mx-auto" style="max-width:635px"><div class="text-uppercase small text-success fw-bold mb-2">Payment confirmation</div><h1 class="h3 fw-bold mb-3"><?php echo $ok ? 'Payment received' : 'Payment status'; ?></h1><?php if($ok): ?><p class="mb-0">Thank you. Your payment has been received and recorded.</p><?php else: ?><p class="mb-0">We could not yet confirm this payment. If you have paid, please contact us quoting your payment email.</p><?php endif; ?></div></div></main>
<?php include __DIR__ . '/views/footer.php'; ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script></body></html>
