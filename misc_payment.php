<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store');
$settings = getSiteSettings($pdo);
$token = (string)($_GET['token'] ?? '');
$request = null;
if ($pdo && preg_match('/^[a-f0-9]{48}$/D', $token)) {
    $stmt = $pdo->prepare('SELECT status,stripe_checkout_url FROM misc_payment_requests WHERE request_token=:token LIMIT 1');
    $stmt->execute([':token'=>$token]);
    $request = $stmt->fetch() ?: null;
}
$message = 'This payment request is unavailable. Please contact the administrator.';
if ($request && $request['status'] === 'paid') {
    $message = 'This payment request has already been paid.';
} elseif ($request && $request['status'] === 'sent') {
    if (purchasingBlocked($settings, $currentUser)) {
        $message = purchasingMessage($settings);
    } else {
        $url = (string)$request['stripe_checkout_url'];
        if (preg_match('~^https://checkout\.stripe\.com/[^\s]*$~D', $url)) {
            header('Location: '.$url, true, 303); exit;
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment Request</title></head><body><main style="max-width:42rem;margin:4rem auto;padding:1rem;font-family:system-ui"><h1>Payment Request</h1><p><?php echo h($message); ?></p><a href="account">My Account</a> · <a href="./">Home</a></main></body></html>
