<?php
declare(strict_types=1);

if (!function_exists('footer_page_url')) {
    function footer_page_url(array $page, string $basePath = ''): string
    {
        $slug = page_destination_slug($page);
        return $slug === '' ? '#' : $basePath . '/pages/' . rawurlencode($slug);
    }
}

if (!function_exists('footer_event_url')) {
    function footer_event_url(array $event, string $basePath = ''): string
    {
        $id = (int)($event['id'] ?? 0);
        if ($id <= 0) {
            return '#';
        }
        return $basePath . '/events/' . $id . '-' . slugify((string)($event['title'] ?? 'event'));
    }
}

if (!function_exists('footer_short_date')) {
    function footer_short_date($date): string
    {
        $timestamp = $date ? strtotime((string)$date) : false;
        return $timestamp ? date('d M Y', $timestamp) : 'Date TBC';
    }
}

if (!function_exists('footer_entry_status')) {
    function footer_entry_status(array $event, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? app_local_datetime();
        $eventDate = trim((string)($event['event_date'] ?? ''));
        $openAt = $event['entry_open_at'] ?? null;
        $closeAt = $event['entry_close_at'] ?? null;

        if (!$openAt && $eventDate !== '') {
            $openAt = date('Y-m-d 00:00:00', strtotime($eventDate . ' -1 month'));
        }
        if (!$closeAt && $eventDate !== '') {
            $closeAt = date('Y-m-d 23:59:59', strtotime($eventDate . ' -1 week'));
        }

        $openDate = $openAt ? app_local_datetime((string)$openAt) : null;
        $closeDate = $closeAt ? app_local_datetime((string)$closeAt) : null;
        if ($openDate && $now < $openDate) {
            return ['label' => 'Entries Not Open', 'date' => footer_short_date($openDate->format('Y-m-d')), 'class' => 'not-open'];
        }
        if ($closeDate && $now > $closeDate) {
            return ['label' => 'Entries Closed', 'date' => '', 'class' => 'closed'];
        }
        if ($closeDate && $closeDate->getTimestamp() - $now->getTimestamp() < 3 * 86400) {
            return ['label' => 'Entries Closing Soon', 'date' => footer_short_date($closeDate->format('Y-m-d')), 'class' => 'closing'];
        }
        return [
            'label' => 'Entries Close',
            'date' => $closeDate ? footer_short_date($closeDate->format('Y-m-d')) : '',
            'class' => 'open',
        ];
    }
}

$footerBasePath = isset($basePath) ? (string)$basePath : '';
$footerSettings = isset($siteSettings) && is_array($siteSettings) ? $siteSettings : defaultSiteSettings();
$footerPages = isset($pages) && is_array($pages) ? $pages : [];
$footerPolicyPages = array_values(array_filter($footerPages, static function (array $page): bool {
    return !empty($page['is_published']) && !empty($page['show_in_footer']);
}));
$footerNavTree = isset($navTree) && is_array($navTree) ? $navTree : buildNavTree($footerPages);
$footerEvents = isset($eventsByDate) && is_array($eventsByDate) ? $eventsByDate : fetchEvents($pdo ?? null, true);
$footerEvents = array_slice(array_values($footerEvents), 0, 3);
$footerIsLoggedIn = isset($isLoggedIn) ? (bool)$isLoggedIn : !empty($currentUser);
$footerCanViewAdmin = isset($canViewAdmin) ? (bool)$canViewAdmin : false;
$footerCompanyName = trim((string)($footerSettings['company_name'] ?? ''));
$footerContactEmail = trim((string)($footerSettings['company_contact_email'] ?? ''));
$footerSocials = fetchCompanySocials($pdo ?? null, true);
$footerAffiliates = fetchCompanyAffiliates($pdo ?? null, true);
$footerSocialIcons = [
    'facebook' => 'fa-brands fa-facebook-f',
    'instagram' => 'fa-brands fa-instagram',
    'youtube' => 'fa-brands fa-youtube',
    'x-twitter' => 'fa-brands fa-x-twitter',
    'linkedin' => 'fa-brands fa-linkedin-in',
    'tiktok' => 'fa-brands fa-tiktok',
    'website' => 'fa-solid fa-link',
];
?>
<style>
    .site-footer { background: var(--green, #0f5d2d); color: #fff; }
    .site-footer a { color: inherit; }
    .site-footer a:hover,
    .site-footer a:focus-visible,
    .site-footer .btn:hover,
    .site-footer .btn:focus-visible { color: var(--yellow, #dce705); text-decoration: none; }
    .site-footer-title { color: var(--yellow, #dce705); font-size: 0.82rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
    .site-footer-links { display: grid; gap: 0.45rem; margin: 0; padding: 0; list-style: none; }
    .site-footer-links a { text-decoration: none; }
    .site-footer-muted { color: rgba(255,255,255,0.72); }
    .site-footer-contact { display: grid; gap: 0.75rem; }
    .site-footer-contact-name { max-width: 25rem; line-height: 1.45; }
    .site-footer-contact-link { display: flex; align-items: flex-start; gap: 0.65rem; width: fit-content; text-decoration: none; overflow-wrap: anywhere; }
    .site-footer-contact-link i { flex: 0 0 1.1rem; margin-top: 0.15rem; color: var(--yellow, #dce705); text-align: center; }
    .site-footer-events { display: grid; gap: 0.65rem; margin: 0; padding: 0; list-style: none; }
    .site-footer-event { display: inline-block; text-decoration: none; line-height: 1.35; }
    .site-footer-event-date { display: block; color: rgba(255,255,255,0.72); font-size: 0.78rem; }
    .site-footer-affiliates { display: grid; gap: 1rem; align-items: start; }
    .site-footer-affiliate { display: block; width: fit-content; padding: 0.45rem; border-radius: 8px; background: rgba(255,255,255,0.94); }
    .site-footer-affiliate:hover,
    .site-footer-affiliate:focus-visible { background: var(--yellow, #dce705); }
    .site-footer-affiliate img { display: block; width: 100%; max-width: 150px; max-height: 90px; object-fit: contain; }
    .site-footer-bottom { border-top: 1px solid rgba(255,255,255,0.14); }
    .site-footer-copyright { display: flex; flex-wrap: wrap; align-items: center; }
    .site-footer-policy-links { display: inline-flex; flex-wrap: wrap; align-items: center; }
    .site-footer-policy-links::before,
    .site-footer-policy-links a + a::before { content: '|'; padding: 0 0.65rem; color: rgba(255,255,255,0.55); }
    @media (max-width: 767.98px) {
        .site-footer { padding-top: 2rem !important; }
        .site-footer > .container > .row { --bs-gutter-y: 1.75rem; }
        .site-footer-title { margin-bottom: 0.75rem !important; }
        .site-footer-links--mobile-columns,
        .site-footer-events,
        .site-footer-affiliates { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1rem; }
        .site-footer-affiliate { width: 100%; }
        .site-footer-copyright { display: block; }
        .site-footer-policy-links { display: flex; margin-top: 0.25rem; }
        .site-footer-policy-links::before { display: none; }
        .site-footer-policy-links a:first-child::before { display: none; }
        .site-footer-account { width: 100%; justify-content: flex-end; text-align: right; }
    }
</style>
<footer class="site-footer pt-5 pb-3">
    <div class="container">
        <div class="row g-4">
            <div class="col-12 col-lg-2">
                <div class="site-footer-title mb-3">Main menu</div>
                <ul class="site-footer-links site-footer-links--mobile-columns small">
                    <li><a href="<?php echo h($footerBasePath); ?>/">Home</a></li>
                    <?php foreach ($footerNavTree as $groupKey => $group): ?>
                        <?php if ($groupKey === 'home' || empty($group['pages'])) { continue; } ?>
                        <?php $firstPage = reset($group['pages']); ?>
                        <li><a href="<?php echo h(footer_page_url($firstPage, $footerBasePath)); ?>"><?php echo h((string)($group['label'] ?? '')); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <div class="site-footer-title mb-3">Contact</div>
                <div class="site-footer-contact small">
                    <?php if ($footerCompanyName !== ''): ?><div class="site-footer-contact-name"><?php echo h($footerCompanyName); ?></div><?php endif; ?>
                    <?php if ($footerContactEmail !== ''): ?><a class="site-footer-contact-link" href="mailto:<?php echo h($footerContactEmail); ?>">
                        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                        <span><?php echo h($footerContactEmail); ?></span>
                    </a><?php endif; ?>
                    <?php foreach ($footerSocials as $footerSocial): ?>
                        <?php
                        $footerSocialPlatform = strtolower((string)($footerSocial['platform'] ?? 'website'));
                        $footerSocialLabel = trim((string)($footerSocial['label'] ?? ''));
                        if ($footerSocialLabel === '') {
                            $footerSocialLabel = $footerSocialPlatform === 'x-twitter' ? 'X / Twitter' : ucfirst($footerSocialPlatform);
                        }
                        $footerSocialIcon = $footerSocialIcons[$footerSocialPlatform] ?? $footerSocialIcons['website'];
                        ?>
                        <a class="site-footer-contact-link" href="<?php echo h((string)$footerSocial['url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo h($footerSocialLabel); ?>">
                            <i class="<?php echo h($footerSocialIcon); ?>" aria-hidden="true"></i>
                            <span><?php echo h($footerSocialLabel); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 <?php echo $footerAffiliates ? 'col-md-4 col-lg-2' : 'col-lg-4'; ?>">
                <div class="site-footer-title mb-3">Events</div>
                <ul class="site-footer-events">
                    <?php foreach ($footerEvents as $footerEvent): ?>
                        <li>
                            <a class="site-footer-event" href="<?php echo h(footer_event_url($footerEvent, $footerBasePath)); ?>">
                                <span class="site-footer-event-name fw-bold small"><?php echo h((string)($footerEvent['title'] ?? 'Event')); ?></span><br>
                                <span class="site-footer-event-date"><?php echo h(footer_short_date($footerEvent['event_date'] ?? null)); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$footerEvents): ?><li class="site-footer-muted small">No upcoming events published.</li><?php endif; ?>
                </ul>
                <a class="btn button3 btn-sm fw-bold mt-3" href="<?php echo h($footerBasePath); ?>/events">All Events</a>
            </div>

            <div class="col-12 col-md-4 col-lg-2">
                <div class="site-footer-title mb-3">Member services</div>
                <ul class="site-footer-links site-footer-links--mobile-columns small">
                    <li><a href="<?php echo h($footerBasePath); ?>/memberships">Memberships</a></li>
                    <li><a href="<?php echo h($footerBasePath); ?>/logbooks">Horse logbooks</a></li>
                    <li><a href="<?php echo h($footerBasePath); ?>/bookings">Bookings</a></li>
                    <li><a href="<?php echo h($footerBasePath); ?>/basket">Basket</a></li>
                </ul>
            </div>

            <?php if ($footerAffiliates): ?>
                <div class="col-12 col-md-4 col-lg-2">
                    <div class="site-footer-title mb-3">Partners</div>
                    <div class="site-footer-affiliates">
                        <?php foreach ($footerAffiliates as $footerAffiliate): ?>
                            <?php
                            $footerAffiliateName = trim((string)($footerAffiliate['name'] ?? 'Affiliate'));
                            $footerAffiliateAsset = fetchAssetLibraryById($pdo ?? null, (int)($footerAffiliate['asset_id'] ?? 0));
                            $footerAffiliateLogo = $footerAffiliateAsset && ($footerAffiliateAsset['asset_type'] ?? '') === 'image' && empty($footerAffiliateAsset['archived'])
                                ? assetLibraryPublicUrl($footerAffiliateAsset, 'sm')
                                : '/filestore/images/affiliate-placeholder.svg';
                            ?>
                            <a class="site-footer-affiliate" href="<?php echo h((string)$footerAffiliate['website_url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Visit <?php echo h($footerAffiliateName); ?>">
                                <img src="<?php echo h($footerAffiliateLogo); ?>" alt="<?php echo h($footerAffiliateName); ?>" loading="lazy">
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="site-footer-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-4 pt-3 small">
            <div class="site-footer-copyright">
                <span><?php echo h((string)($footerSettings['company_short_name'] ?? $footerSettings['hero_title'] ?? 'ILDRA')); ?> &middot; <?php echo date('Y'); ?></span>
                <?php if ($footerPolicyPages): ?>
                    <span class="site-footer-policy-links">
                        <?php foreach ($footerPolicyPages as $footerPolicyPage): ?>
                            <a href="<?php echo h(footer_page_url($footerPolicyPage, $footerBasePath)); ?>"><?php echo h((string)$footerPolicyPage['title']); ?></a>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="site-footer-account d-flex align-items-center gap-2">
                <?php if ($footerCanViewAdmin): ?>
                    <a class="btn button3 btn-sm fw-bold" href="<?php echo h($footerBasePath); ?>/admin/index.php"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> View admin area</a>
                <?php elseif (!$footerIsLoggedIn): ?>
                    <a class="btn button3 btn-sm fw-bold text-center" href="<?php echo h($footerBasePath); ?>/account">Login / Register<br>Membership</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
