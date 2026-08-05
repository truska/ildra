<?php
declare(strict_types=1);

$developmentBannerHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
$developmentBannerHost = preg_replace('/:\d+$/', '', $developmentBannerHost) ?: '';
$showDevelopmentBanner = str_starts_with($developmentBannerHost, 'dev-')
    || str_starts_with($developmentBannerHost, 'dev.')
    || in_array($developmentBannerHost, ['localhost', '127.0.0.1', '::1'], true);
?>
<?php if ($showDevelopmentBanner): ?>
    <div class="development-site-banner" role="status" aria-label="Development site">
        DEVELOPMENT SITE — NOT LIVE
    </div>
    <style>
        .development-site-banner {
            position: relative;
            z-index: 5000;
            width: 100%;
            padding: 0.42rem 1rem;
            background: #c1121f;
            color: #fff;
            border-bottom: 2px solid #7f0000;
            font: 800 0.8rem/1.2 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0.12em;
            text-align: center;
            text-transform: uppercase;
        }
    </style>
<?php endif; ?>
