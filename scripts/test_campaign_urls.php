<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/helpers.php';
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

// Exercise the actual template-test recipient construction without loading admin/bootstrap.
$source = file_get_contents(dirname(__DIR__) . '/admin/campaigns.php');
$start = strpos($source, 'function campaign_admin_send_template_test(');
if (!preg_match('/    \$recipient = .*?;/', substr($source, $start), $match)) {
    throw new RuntimeException('Template-test recipient fixture not found.');
}
$merge = ['first_name' => 'Test'];
eval($match[0]);
$campaign = ['category' => 'ride_notice', 'subject_template' => 'Ride Notice', 'html_template' => '{{unsubscribe_url}}', 'text_template' => '{{unsubscribe_url}}'];
$settings = ['campaign_public_base_url' => 'https://ildra.witecanvas.com'];
$preview = emailCampaignRenderRecipient($campaign, $recipient, $settings);
$expected = 'https://ildra.witecanvas.com/email_unsubscribe.php?preview=1';
if ($preview['html'] !== $expected || $preview['text'] !== $expected || !str_contains($preview['after_footer_html'], $expected)) {
    throw new RuntimeException('Template test must use preview-only preferences in body and footer.');
}
$recipient['unsubscribe_token'] = str_repeat('a', 40);
$real = emailCampaignRenderRecipient($campaign, $recipient, $settings);
if ($real['text'] !== 'https://ildra.witecanvas.com/email_unsubscribe.php?t=' . str_repeat('a', 40)) {
    throw new RuntimeException('Real recipient unsubscribe URL changed.');
}
echo "PASS: template tests use preference previews; real recipients retain token links. No emails sent.\n";
