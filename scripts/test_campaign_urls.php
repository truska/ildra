<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/email_campaigns.php';
// No bootstrap, private configuration, database or mail transport is loaded.
$_SERVER = [];
foreach (['https://ildra.witecanvas.com/' => 'https://ildra.witecanvas.com', 'https://enduranceridingireland.com' => 'https://enduranceridingireland.com'] as $input => $expected) {
    if (emailCampaignBaseUrl(['campaign_public_base_url' => $input]) !== $expected) {
        throw new RuntimeException('Configured campaign origin changed.');
    }
}
foreach (['', '/events', 'ftp://example.com'] as $input) {
    $rejected = false;
    try { emailCampaignBaseUrl(['campaign_public_base_url' => $input]); }
    catch (RuntimeException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Missing or invalid campaign origin accepted.');
}
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
if (emailCampaignBaseUrl([]) !== 'https://example.com') throw new RuntimeException('Request origin fallback failed.');
echo "PASS: staging/live campaign origins, trailing slashes, missing cron hostname and invalid schemes. No emails sent.\n";
