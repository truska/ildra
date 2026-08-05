<?php
declare(strict_types=1);

if (!function_exists('footer_page_url')) {
    function footer_page_url(array $page, string $basePath = ''): string
    {
        $slug = trim((string)($page['slug'] ?? ''));
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
        $now = $now ?? new DateTimeImmutable('now');
        $eventDate = trim((string)($event['event_date'] ?? ''));
        $openAt = $event['entry_open_at'] ?? null;
        $closeAt = $event['entry_close_at'] ?? null;

        if (!$openAt && $eventDate !== '') {
            $openAt = date('Y-m-d 00:00:00', strtotime($eventDate . ' -1 month'));
        }
        if (!$closeAt && $eventDate !== '') {
            $closeAt = date('Y-m-d 23:59:59', strtotime($eventDate . ' -1 week'));
        }

        $openDate = $openAt ? new DateTimeImmutable((string)$openAt) : null;
        $closeDate = $closeAt ? new DateTimeImmutable((string)$closeAt) : null;
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
$footerNavTree = isset($navTree) && is_array($navTree) ? $navTree : buildNavTree($footerPages);
$footerEvents = isset($eventsByDate) && is_array($eventsByDate) ? $eventsByDate : fetchEvents($pdo ?? null, true);
$footerEvents = array_slice(array_values($footerEvents), 0, 3);
$footerIsLoggedIn = isset($isLoggedIn) ? (bool)$isLoggedIn : !empty($currentUser);
$footerCanViewAdmin = isset($canViewAdmin) ? (bool)$canViewAdmin : false;
?>
<style>
    .site-footer { background: var(--green, #0f5d2d); color: #fff; }
    .site-footer a { color: inherit; }
    .site-footer-title { color: var(--yellow, #dce705); font-size: 0.82rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
    .site-footer-links { display: grid; gap: 0.45rem; margin: 0; padding: 0; list-style: none; }
    .site-footer-links a { text-decoration: none; }
    .site-footer-links a:hover { text-decoration: underline; }
    .site-footer-muted { color: rgba(255,255,255,0.72); }
    .site-footer-events { display: grid; gap: 0.65rem; margin: 0; padding: 0; list-style: none; }
    .site-footer-event { display: inline-block; text-decoration: none; line-height: 1.35; }
    .site-footer-event:hover .site-footer-event-name { text-decoration: underline; }
    .site-footer-event-date { display: block; color: rgba(255,255,255,0.72); font-size: 0.78rem; }
    .site-footer-bottom { border-top: 1px solid rgba(255,255,255,0.14); }
</style>
<footer class="site-footer pt-5 pb-3">
    <div class="container">
        <div class="row g-4">
            <div class="col-6 col-lg-2">
                <div class="site-footer-title mb-3">Main menu</div>
                <ul class="site-footer-links small">
                    <li><a href="<?php echo h($footerBasePath); ?>/">Home</a></li>
                    <?php foreach ($footerNavTree as $groupKey => $group): ?>
                        <?php if ($groupKey === 'home' || empty($group['pages'])) { continue; } ?>
                        <?php $firstPage = reset($group['pages']); ?>
                        <li><a href="<?php echo h(footer_page_url($firstPage, $footerBasePath)); ?>"><?php echo h((string)($group['label'] ?? '')); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <div class="site-footer-title mb-3">Contact</div>
                <div class="site-footer-muted small">Contact details coming soon.</div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div class="site-footer-title mb-0">Events</div>
                    <a class="btn button3 btn-sm fw-bold" href="<?php echo h($footerBasePath); ?>/events">All Events</a>
                </div>
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
            </div>

            <div class="col-12 col-lg-2">
                <div class="site-footer-title mb-3">Social</div>
                <div class="site-footer-muted small">Social links coming soon.</div>
            </div>
        </div>

        <div class="site-footer-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-4 pt-3 small">
            <div><?php echo h((string)($footerSettings['hero_title'] ?? 'ILDRA')); ?> &middot; <?php echo date('Y'); ?></div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($footerCanViewAdmin): ?>
                    <a class="btn button3 btn-sm fw-bold" href="<?php echo h($footerBasePath); ?>/admin/index.php">View admin area</a>
                <?php elseif (!$footerIsLoggedIn): ?>
                    <a class="btn button3 btn-sm fw-bold" href="<?php echo h($footerBasePath); ?>/account">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
