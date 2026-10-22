<?php
declare(strict_types=1);
require dirname(__DIR__) . '/stripe.php';
foreach (['dev-ildra.witecanvas.com', 'ildra.witecanvas.com', 'enduranceridingireland.com'] as $host) {
    foreach ([$host, $host . ':28085'] as $authority) {
        $actual = stripe_misc_webhook_url('', ['HTTP_HOST' => $authority]);
        $expected = 'https://' . $host . '/stripe_misc_webhook.php';
        if ($actual !== $expected) throw new RuntimeException('Wrong public URL for ' . $authority);
    }
}
$expected = 'https://ildra.witecanvas.com/club/stripe_misc_webhook.php';
if (stripe_misc_webhook_url('/club/', ['SERVER_NAME' => 'ildra.witecanvas.com']) !== $expected) throw new RuntimeException('Subdirectory or server-name fallback failed.');
foreach (['', 'example.org/path', 'user@example.org', "example.org\r\nInjected: header"] as $invalid) {
    try { stripe_misc_webhook_url('', ['HTTP_HOST' => $invalid]); }
    catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid authority was accepted.');
}
echo "Webhook addresses follow dev, staging and live hostnames; public HTTPS, forwarded browser ports and invalid hosts: passed.\n";
