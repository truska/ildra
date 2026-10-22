<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages(); // ensure nav and anchors exist even if DB empty
}
$events = fetchEvents($pdo, true);
$faqs = fetchFaqs($pdo);
$advertising = fetchAdvertising($pdo, true);
$navTree = buildNavTree($pages);
$homePage = null;
foreach ($pages as $candidatePage) {
    if ((string)($candidatePage['slug'] ?? '') === 'home') { $homePage = $candidatePage; break; }
}
$counts = contentCounts($pages, $events, $faqs);
$contactEmail = trim((string)($siteSettings['company_contact_email'] ?? ''));

$eventAnnouncement = !empty($siteSettings['event_announcement_enabled']) && $pdo ? event_announcement($events) : null;
$siteSettings = announcement_public_settings($pdo, $siteSettings);
$manualAnnouncementVisible = manual_announcement_visible($siteSettings);
$announcementAsset = $manualAnnouncementVisible ? fetchAssetLibraryById($pdo, (int)$siteSettings['announcement_asset_id']) : null;
$announcementImage = $announcementAsset && ($announcementAsset['asset_type'] ?? '') === 'image' && empty($announcementAsset['archived']) ? assetLibraryPublicUrl($announcementAsset, 'md') : '';
$announcementImageRight = $siteSettings['announcement_image_side'] === 'right';

$aboutIldra = array_values(array_filter($pages, fn($p) => ($p['nav_group'] ?? '') === 'about-ildra'));
$aboutEndurance = array_values(array_filter($pages, fn($p) => ($p['nav_group'] ?? '') === 'about-endurance'));
$eventsByDate = array_slice($events, 0, 5);
$canViewAdmin = roleCanAccessAdmin((string)($currentUser['role'] ?? ''));
$isLoggedIn = !empty($currentUser);

function page_url(array $page): string
{
    $slug = page_destination_slug($page);
    global $basePath;
    return $basePath . '/pages/' . rawurlencode($slug);
}

function event_url(array $event): string
{
    $id = (int)($event['id'] ?? 0);
    global $basePath;
    if ($id <= 0) {
        return '#';
    }
    $slug = slugify((string)($event['title'] ?? 'event'));
    return $basePath . '/events/' . $id . '-' . $slug;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($siteSettings['hero_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --green: #0f5d2d;
            --green-strong: #0c4522;
            --green-soft: #e8f3ea;
            --yellow: #dce705;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
            --radius-lg: 8px;
            --radius-md: 6px;
            --shadow-soft: 0 18px 48px rgba(0,0,0,0.08);
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .hero { color: #fff; }
        .logo-badge {
            width: 110px;
            height: 110px;
            background: #fffbef;
            border-radius: 50%;
            display: grid;
            place-items: center;
            border: 4px solid var(--yellow);
            box-shadow: 0 14px 40px rgba(0,0,0,0.18);
            color: var(--green);
            font-weight: 800;
        }
        .nav-link, .dropdown-item {
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .navbar {
            box-shadow: 0 10px 36px rgba(0,0,0,0.12);
            position: relative;
            z-index: 3;
        }
        .nav-caret {
            color: #fff;
            padding: 0 0.25rem;
        }
        .navbar .dropdown-menu {
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 18px 50px rgba(0,0,0,0.12);
        }
        .card-soft {
            border-radius: var(--radius-lg);
            border: 1px solid rgba(0,0,0,0.04);
            box-shadow: var(--shadow-soft);
            background: #fff;
        }
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
        .badge-pill {
            background: rgba(20, 97, 24, 0.12);
            color: var(--green);
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 700;
        }
        .event-row {
            border-left: 4px solid var(--green);
            background: #fff;
            display: block;
            color: inherit;
            text-decoration: none;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .event-row:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        .page-advertising { display: grid; gap: 0.8rem; }
        .page-advertising-item { display: block; border-radius: 12px; overflow: hidden; background: #fff; }
        .page-advertising-item img { display: block; width: 100%; height: auto; max-height: 110px; object-fit: contain; }
        .announcement-bar {
            background: var(--announcement-bg, var(--green-strong));
            color: var(--announcement-text, #fff);
        }
        .event-announcement--opening { background: #dce705; color: #0c4522; }
        .event-announcement--closing { background: #8b1e24; color: #fff; }
        .event-announcement a { color: inherit; text-decoration: none; }
        .event-announcement a:hover { text-decoration: underline; }
        .announcement-bar h2, .announcement-bar h3, .announcement-copy, .announcement-copy a { color: inherit; }
        .announcement-toggle {
            position: relative;
            width: 100%;
            min-height: 54px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .7rem 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 800;
            text-align: center;
        }
        .announcement-toggle > span {
            width: 100%;
            padding: 0 2.5rem;
            font-size: clamp(1.35rem, 1.1rem + 1vw, 1.75rem);
            line-height: 1.25;
        }
        .announcement-toggle .collapse-icon { position: absolute; right: 0; }
        .announcement-toggle:hover,
        .announcement-toggle:focus-visible { color: inherit; text-decoration: underline; }
        .announcement-toggle .collapse-icon { transition: transform .2s ease; }
        .announcement-toggle[aria-expanded="true"] .collapse-icon { transform: rotate(180deg); }
        .announcement-detail {
            border-top: 1px solid rgba(255,255,255,.2);
            padding: 1.25rem 0 1.5rem;
        }
        .announcement-image {
            width: 100%;
            max-height: 230px;
            object-fit: contain;
            background: rgba(255,255,255,.08);
            border-radius: var(--radius-md);
        }

        .announcement-collapse {
            color: inherit;
            border-color: currentColor;
        }
        .announcement-collapse:hover { background: var(--announcement-text); color: var(--announcement-bg); }
        .entry-status {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 800;
            line-height: 1.25;
            white-space: nowrap;
        }
        .entry-status-open { background: rgba(25, 135, 84, 0.16); color: #146c43; }
        .entry-status-not-open, .entry-status-closed { background: rgba(220, 53, 69, 0.14); color: #b02a37; }
        .entry-status-closing { background: rgba(253, 126, 20, 0.18); color: #a94f00; }
        .footer {
            background: var(--green);
            color: #fff;
        }
        .letter-spacing-1 {
            letter-spacing: 0.08em;
        }
        .cta-btn {
            background: var(--yellow);
            color: var(--green);
            font-weight: 800;
            border: none;
            padding: 0.85rem 1.6rem;
            border-radius: 14px;
        }
        .cta-btn:hover {
            background: #c3cf00;
            color: var(--green);
        }
        @media (max-width: 991px) {
            .brand-block { min-width: 0; }
            .nav-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
        @media (max-width: 575.98px) {
            .page-advertising { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .home-promotions-column { order: 1; }
            .home-next-ride-column { order: 2; }
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <header class="hero">
        <?php $headerIsHome = true; $headerPage = $homePage; include __DIR__ . '/views/header.php'; ?>
    </header>

    <?php if ($eventAnnouncement): ?>
        <div class="event-announcement event-announcement--<?php echo h($eventAnnouncement['kind']); ?>"><a class="container d-block py-2 text-center fw-bold" href="<?php echo h(event_url($eventAnnouncement['event'])); ?>"><?php echo h($eventAnnouncement['text']); ?></a></div>
    <?php endif; ?>
    <?php if ($manualAnnouncementVisible): ?>
    <section class="announcement-bar" aria-labelledby="home-announcement-title" style="--announcement-bg: <?php echo h(announcement_colour((string)$siteSettings['announcement_bg_colour'], '#0c4522')); ?>; --announcement-text: <?php echo h(announcement_colour((string)$siteSettings['announcement_text_colour'], '#ffffff')); ?>;">
        <div class="container">
            <button class="announcement-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#homeAnnouncement" aria-expanded="false" aria-controls="homeAnnouncement">
                <span id="home-announcement-title"><?php echo h((string)$siteSettings['announcement_title']); ?></span>
                <i class="fa-solid fa-chevron-down collapse-icon" aria-hidden="true"></i>
            </button>
            <div class="collapse" id="homeAnnouncement">
                <div class="announcement-detail"><div class="row g-4 align-items-center">
                    <?php if (trim((string)$siteSettings['announcement_heading']) !== ''): ?><div class="col-12<?php echo $announcementImage !== '' ? ' text-center' : ''; ?>"><h2 class="h4 mb-0"><?php echo h((string)$siteSettings['announcement_heading']); ?></h2></div><?php endif; ?>
                    <?php if ($announcementImage !== ''): ?><div class="col-md-6 <?php echo $announcementImageRight ? 'order-md-2' : ''; ?>"><img class="announcement-image" src="<?php echo h($announcementImage); ?>" alt="<?php echo h((string)(($announcementAsset['alt_text'] ?? '') ?: ($announcementAsset['title'] ?? '') ?: $siteSettings['announcement_title'])); ?>"></div><?php endif; ?>
                    <div class="<?php echo $announcementImage !== '' ? 'col-md-6 ' . ($announcementImageRight ? 'order-md-1' : '') : 'col-12'; ?> announcement-copy">
                        <?php if (trim((string)$siteSettings['announcement_subheading']) !== ''): ?><h3 class="h5"><?php echo h((string)$siteSettings['announcement_subheading']); ?></h3><?php endif; ?>
                        <?php echo render_wysiwyg((string)$siteSettings['announcement_body_html']); ?>
                        <div class="d-flex justify-content-end mt-3">
                            <button class="btn announcement-collapse" type="button" data-bs-toggle="collapse" data-bs-target="#homeAnnouncement" aria-expanded="true" aria-controls="homeAnnouncement">Collapse <i class="fa-solid fa-chevron-up ms-1" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <main class="py-5">
        <div class="container">
            <div class="row g-4 align-items-start mb-5">
                <div class="col-lg-6">
                    <section class="mb-4" id="about-us">
                        <div class="card-soft p-4">
                            <div class="section-title mb-3">About us</div>
                            <h3 class="fw-bold mb-3"><?php echo h((string)($homePage['title'] ?? $siteSettings['welcome_title'])); ?></h3>
                            <div class="page-body"><?php echo (string)($homePage['body_html'] ?? nl2br(h($siteSettings['welcome_body']))); ?></div>
                            <?php
                            $homeButtonUrl = trim((string)($homePage['button_url'] ?? ''));
                            if ($homeButtonUrl === '' && !empty($homePage['button_asset_id'])) {
                                $homeButtonAsset = fetchAssetLibraryById($pdo, (int)$homePage['button_asset_id']);
                                if ($homeButtonAsset && empty($homeButtonAsset['archived'])) $homeButtonUrl = assetLibraryPublicUrl($homeButtonAsset);
                            }
                            ?>
                            <?php if (!empty($homePage['button_name']) && $homeButtonUrl !== ''): ?>
                                <a class="btn button2 mt-3" href="<?php echo h($homeButtonUrl); ?>" title="<?php echo h((string)($homePage['button_title'] ?? '')); ?>" target="<?php echo ($homePage['button_target'] ?? '_self') === '_blank' ? '_blank' : '_self'; ?>"<?php echo ($homePage['button_target'] ?? '_self') === '_blank' ? ' rel="noopener"' : ''; ?>><?php echo h((string)$homePage['button_name']); ?></a>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section id="contact">
                        <div class="card-soft p-4">
                            <div class="section-title mb-3">Contact us</div>
                            <p class="mb-3">Have a question about rides, membership or volunteering? Drop us a line and we will connect you with the right committee.</p>
                            <?php if ($contactEmail !== ''): ?>
                                <a href="mailto:<?php echo h($contactEmail); ?>" class="btn cta-btn">Email ILDRA</a>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div class="col-lg-6">
                    <div class="row g-4 align-items-start">
                <div class="col-md-8 home-next-ride-column">
                    <section class="card-soft p-4 bg-white" aria-labelledby="next-events-heading">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <div class="section-title mb-0" id="next-events-heading">Next Ride</div>
                            <a title="ILDRA Events Calendar" class="btn button2 btn-sm" href="<?php echo h($basePath); ?>/events">All Events</a>
                        </div>
                        <?php foreach (array_slice($eventsByDate, 0, 1) as $event): ?>
                            <?php
                            $eventDate = $event['event_date'] ?? '';
                            $endDate = $event['end_date'] ?? '';
                            $dateRange = h(format_display_date($eventDate, 'Date TBC'));
                            if ($eventDate && $endDate && $endDate !== $eventDate) {
                                $dateRange .= ' to ' . h(format_display_date($endDate, 'Date TBC'));
                            }

                            $entryOpenAt = $event['entry_open_at'] ?? null;
                            $entryCloseAt = $event['entry_close_at'] ?? null;
                            if (!$entryOpenAt && $eventDate) {
                                $entryOpenAt = date('Y-m-d 00:00:00', strtotime($eventDate . ' -1 month'));
                            }
                            if (!$entryCloseAt && $eventDate) {
                                $entryCloseAt = date('Y-m-d 23:59:59', strtotime($eventDate . ' -1 week'));
                            }
                            $now = app_local_datetime();
                            $entryOpenDt = $entryOpenAt ? app_local_datetime((string)$entryOpenAt) : null;
                            $entryCloseDt = $entryCloseAt ? app_local_datetime((string)$entryCloseAt) : null;
                            if ($entryOpenDt && $now < $entryOpenDt) {
                                $entryStatusClass = 'not-open';
                                $entryStatusLabel = 'Entries Not Open';
                                $entryStatusDate = $entryOpenDt->format('d M Y');
                            } elseif ($entryCloseDt && $now > $entryCloseDt) {
                                $entryStatusClass = 'closed';
                                $entryStatusLabel = 'Entries Closed';
                                $entryStatusDate = '';
                            } elseif ($entryCloseDt && $entryCloseDt->getTimestamp() - $now->getTimestamp() < 3 * 86400) {
                                $entryStatusClass = 'closing';
                                $entryStatusLabel = 'Entries Closing Soon';
                                $entryStatusDate = $entryCloseDt->format('d M Y');
                            } else {
                                $entryStatusClass = 'open';
                                $entryStatusLabel = 'Entries Close';
                                $entryStatusDate = $entryCloseDt ? $entryCloseDt->format('d M Y') : '';
                            }

                            if (isThirdPartyEvent($event)) {
                                $entryStatusClass = 'not-open'; $entryStatusLabel = '3rd Party Event'; $entryStatusDate = '';
                            }
                            $classNames = [];
                            if ($pdo) {
                                $classNames = class_names_from_pricing_rows(fetchEventPricingRows($pdo, (int)($event['id'] ?? 0)));
                            }
                            if (!$classNames) {
                                $classNames = class_names_from_classes_offered($event['classes_offered'] ?? '');
                            }
                            ?>
                            <a class="event-row rounded-3 p-3 mb-3" href="<?php echo h(event_url($event)); ?>">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="small text-muted"><?php echo $dateRange; ?></div>
                                        <div class="fw-bold"><?php echo h($event['title']); ?></div>
                                        <?php if (!empty($event['venue'])): ?>
                                            <div class="text-muted small"><?php echo h($event['venue']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($classNames): ?>
                                            <div class="text-muted small">Classes: <?php echo h(implode(', ', $classNames)); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="entry-status entry-status-<?php echo h($entryStatusClass); ?>">
                                        <span><?php echo h($entryStatusLabel); ?></span>
                                        <?php if ($entryStatusDate !== ''): ?><span><?php echo h($entryStatusDate); ?></span><?php endif; ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (!$eventsByDate): ?>
                            <div class="text-muted small">No events have been published yet.</div>
                        <?php endif; ?>
                    </section>
                </div>

                <aside class="col-md-4 home-promotions-column" aria-label="Promotions">
                    <?php if ($advertising): ?>
                        <div class="page-advertising">
                            <?php foreach ($advertising as $advert): ?>
                                <?php if (empty($advert['image'])) continue; ?>
                                <?php $advertImage = image_upload_public_path('advertising', 'sm', (string)$advert['image']); ?>
                                <?php $advertTarget = ($advert['link_target'] ?? '_blank') === '_self' ? '_self' : '_blank'; ?>
                                <?php if (!empty($advert['url'])): ?><a class="page-advertising-item card-soft" href="<?php echo h($advert['url']); ?>" target="<?php echo h($advertTarget); ?>"<?php echo $advertTarget === '_blank' ? ' rel="noopener sponsored"' : ''; ?>><?php else: ?><div class="page-advertising-item card-soft"><?php endif; ?>
                                    <img src="<?php echo h($advertImage); ?>" alt="<?php echo h($advert['name']); ?>" title="<?php echo h($advert['title'] ?: $advert['name']); ?>" loading="lazy">
                                <?php if (!empty($advert['url'])): ?></a><?php else: ?></div><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </aside>
                    </div>

            <?php $followingEvents = array_slice($eventsByDate, 1, 4); ?>
            <?php if ($followingEvents): ?>
                <section class="card-soft p-4 bg-white mt-4" aria-labelledby="following-rides-heading">
                    <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                        <div class="section-title mb-0" id="following-rides-heading">Following Rides</div>
                        <a title="ILDRA Events Calendar" class="btn button2 btn-sm" href="<?php echo h($basePath); ?>/events">All Events</a>
                    </div>
                    <div class="row row-cols-1 g-3">
                        <?php foreach ($followingEvents as $event): ?>
                            <?php
                            $eventDate = $event['event_date'] ?? '';
                            $endDate = $event['end_date'] ?? '';
                            $dateRange = h(format_display_date($eventDate, 'Date TBC'));
                            if ($eventDate && $endDate && $endDate !== $eventDate) {
                                $dateRange .= ' to ' . h(format_display_date($endDate, 'Date TBC'));
                            }
                            $entryOpenAt = $event['entry_open_at'] ?? null;
                            $entryCloseAt = $event['entry_close_at'] ?? null;
                            if (!$entryOpenAt && $eventDate) $entryOpenAt = date('Y-m-d 00:00:00', strtotime($eventDate . ' -1 month'));
                            if (!$entryCloseAt && $eventDate) $entryCloseAt = date('Y-m-d 23:59:59', strtotime($eventDate . ' -1 week'));
                            $now = app_local_datetime();
                            $entryOpenDt = $entryOpenAt ? app_local_datetime((string)$entryOpenAt) : null;
                            $entryCloseDt = $entryCloseAt ? app_local_datetime((string)$entryCloseAt) : null;
                            if ($entryOpenDt && $now < $entryOpenDt) {
                                $entryStatusClass = 'not-open'; $entryStatusLabel = 'Entries Not Open'; $entryStatusDate = $entryOpenDt->format('d M Y');
                            } elseif ($entryCloseDt && $now > $entryCloseDt) {
                                $entryStatusClass = 'closed'; $entryStatusLabel = 'Entries Closed'; $entryStatusDate = '';
                            } elseif ($entryCloseDt && $entryCloseDt->getTimestamp() - $now->getTimestamp() < 3 * 86400) {
                                $entryStatusClass = 'closing'; $entryStatusLabel = 'Entries Closing Soon'; $entryStatusDate = $entryCloseDt->format('d M Y');
                            } else {
                                $entryStatusClass = 'open'; $entryStatusLabel = 'Entries Close'; $entryStatusDate = $entryCloseDt ? $entryCloseDt->format('d M Y') : '';
                            }
                            if (isThirdPartyEvent($event)) {
                                $entryStatusClass = 'not-open'; $entryStatusLabel = '3rd Party Event'; $entryStatusDate = '';
                            }
                            $classNames = $pdo ? class_names_from_pricing_rows(fetchEventPricingRows($pdo, (int)($event['id'] ?? 0))) : [];
                            if (!$classNames) $classNames = class_names_from_classes_offered($event['classes_offered'] ?? '');
                            ?>
                            <div class="col">
                                <a class="event-row rounded-3 p-3 h-100" href="<?php echo h(event_url($event)); ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div><div class="small text-muted"><?php echo $dateRange; ?></div><div class="fw-bold"><?php echo h($event['title']); ?></div><?php if (!empty($event['venue'])): ?><div class="text-muted small"><?php echo h($event['venue']); ?></div><?php endif; ?><?php if ($classNames): ?><div class="text-muted small">Classes: <?php echo h(implode(', ', $classNames)); ?></div><?php endif; ?></div>
                                        <span class="entry-status entry-status-<?php echo h($entryStatusClass); ?>"><span><?php echo h($entryStatusLabel); ?></span><?php if ($entryStatusDate !== ''): ?><span><?php echo h($entryStatusDate); ?></span><?php endif; ?></span>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php render_password_reveal_assets(); ?>
</body>
</html>
