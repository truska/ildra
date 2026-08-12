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
$navTree = buildNavTree($pages);
$counts = contentCounts($pages, $events, $faqs);

$aboutIldra = array_values(array_filter($pages, fn($p) => ($p['nav_group'] ?? '') === 'about-ildra'));
$aboutEndurance = array_values(array_filter($pages, fn($p) => ($p['nav_group'] ?? '') === 'about-endurance'));
$eventsByDate = array_slice($events, 0, 3);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$isLoggedIn = !empty($currentUser);

function page_url(array $page): string
{
    $slug = $page['slug'] ?? '';
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
        .hero {
            background: url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(160deg, rgba(12,69,34,0.9) 0%, rgba(12,69,34,0.78) 40%, rgba(12,69,34,0.72) 100%);
            z-index: 0;
        }
        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.1), transparent 36%), radial-gradient(circle at 70% 10%, rgba(255,255,255,0.08), transparent 32%);
            z-index: 0;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            padding: 3.5rem 0 4.5rem;
        }
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
        .announcement-placeholder {
            min-height: 92px;
            border: 2px dashed rgba(15, 93, 45, 0.28);
            background: var(--green-soft);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
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
        /* Hero layout */
        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr);
            gap: 2rem;
        }
        .stat-pill {
            background: rgba(255,255,255,0.14);
            color: #fff;
            border-radius: 8px;
            padding: 0.3rem 0.7rem;
            font-weight: 700;
            display: inline-flex;
            gap: 0.3rem;
            align-items: center;
            font-size: 0.9rem;
        }
        .subdued { color: rgba(255,255,255,0.8); }
        @media (max-width: 991px) {
            .hero-grid { grid-template-columns: 1fr; }
            .brand-block { min-width: 0; }
            .nav-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <header class="hero">
        <?php $headerIsHome = true; include __DIR__ . '/views/header.php'; ?>
        <div class="container hero-content">
            <div class="hero-grid align-items-center">
                <div>
                    <p class="mb-2 text-uppercase small fw-bold letter-spacing-1"><?php echo h($siteSettings['hero_subtitle']); ?></p>
                    <h1 class="display-4 fw-bold mb-3"><?php echo h($siteSettings['hero_title']); ?></h1>
                    <p class="lead mb-4 subdued"><?php echo h($siteSettings['hero_tagline']); ?></p>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="stat-pill">Recognised by Horse Sport Ireland</span>
                        <span class="stat-pill">Events: <?php echo h((string)$counts['events']); ?></span>
                    </div>
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <a class="btn button1 btn-lg" href="<?php echo h($siteSettings['hero_cta_url']); ?>"><?php echo h($siteSettings['hero_cta_label']); ?></a>
                        <a class="button2 btn-lg" href="<?php echo h($basePath); ?>/events">View events</a>
                    </div>
                </div>
                <div class="text-end d-none d-lg-block"></div>
            </div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <div class="announcement-placeholder card-soft rounded-3 p-4 mb-5" aria-label="Announcement placeholder">
                <div>
                    <div class="small text-uppercase fw-bold letter-spacing-1">Announcement</div>
                    <div class="text-muted">Latest ILDRA news and notices will appear here.</div>
                </div>
            </div>

            <div class="row g-4 align-items-start mb-5">
                <div class="col-lg-5">
                    <section class="mb-4" id="about-us">
                        <div class="section-title mb-3">About us</div>
                        <div class="card-soft p-4">
                            <h3 class="fw-bold mb-3"><?php echo h($siteSettings['welcome_title']); ?></h3>
                            <p class="mb-0"><?php echo nl2br(h($siteSettings['welcome_body'])); ?></p>
                        </div>
                    </section>

                    <section id="contact">
                        <div class="section-title mb-3">Contact us</div>
                        <div class="card-soft p-4">
                            <p class="mb-3">Have a question about rides, membership or volunteering? Drop us a line and we will connect you with the right committee.</p>
                            <a href="mailto:info@ildra.example" class="btn cta-btn">Email ILDRA</a>
                        </div>
                    </section>
                </div>

                <div class="col-lg-7">
                    <section class="card-soft p-4 bg-white" aria-labelledby="next-events-heading">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <div class="section-title mb-0" id="next-events-heading">Next events</div>
                            <a class="btn button2 btn-sm" href="<?php echo h($basePath); ?>/events">All Events</a>
                        </div>
                        <?php foreach ($eventsByDate as $event): ?>
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
                            $now = new DateTimeImmutable('now');
                            $entryOpenDt = $entryOpenAt ? new DateTimeImmutable((string)$entryOpenAt) : null;
                            $entryCloseDt = $entryCloseAt ? new DateTimeImmutable((string)$entryCloseAt) : null;
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
