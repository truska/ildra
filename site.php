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
        <?php include __DIR__ . '/views/header.php'; ?>
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
                        <a class="btn cta-btn btn-lg" href="<?php echo h($siteSettings['hero_cta_url']); ?>"><?php echo h($siteSettings['hero_cta_label']); ?></a>
                        <a class="action-chip" href="<?php echo h($basePath); ?>/events">View events</a>
                    </div>
                </div>
                <div class="text-end d-none d-lg-block"></div>
            </div>
        </div>
    </header>

    <main class="py-5">
        <div class="container mb-5">
            <div class="section-title mb-3">Season highlights</div>
            <div class="row g-3">
                <?php $nextEvent = $eventsByDate[0] ?? null; ?>
                <div class="col-md-4">
                    <div class="card-soft p-3 h-100">
                        <div class="small text-uppercase text-muted fw-bold mb-2">Next ride</div>
                        <div class="fw-bold mb-1"><?php echo h($nextEvent['title'] ?? 'See the calendar'); ?></div>
                        <div class="text-muted mb-2"><?php echo h(format_display_date($nextEvent['event_date'] ?? null, 'View dates')); ?></div>
                        <a class="action-chip text-success" href="<?php echo h($basePath); ?>/events">View details</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-soft p-3 h-100">
                        <div class="small text-uppercase text-muted fw-bold mb-2">Member perks</div>
                        <ul class="mb-0 ps-3">
                            <li>Organised rides &amp; training days</li>
                            <li>Discounts with partners</li>
                            <li>Guidance for new endurance riders</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-soft p-3 h-100">
                        <div class="small text-uppercase text-muted fw-bold mb-2">Stay connected</div>
                        <p class="mb-3">Sign up for ride updates, training days, and club news.</p>
                        <a class="action-chip text-success" href="<?php echo h($basePath); ?>/index.php">Manage membership</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="row g-4 align-items-start mb-5">
                <div class="col-lg-6">
                    <div class="section-title mb-3">About Endurance Riding</div>
                    <div class="card-soft p-4">
                        <h3 class="fw-bold mb-3"><?php echo h($siteSettings['welcome_title']); ?></h3>
                        <p class="mb-0"><?php echo nl2br(h($siteSettings['welcome_body'])); ?></p>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card-soft p-4 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="section-title mb-0">Next events</div>
                            <span class="badge-pill">Upcoming</span>
                        </div>
                        <?php foreach ($eventsByDate as $event): ?>
                            <?php
                            $eventDate = $event['event_date'] ?? '';
                            $endDate = $event['end_date'] ?? '';
                            $timeRange = '';
                            if (!empty($event['start_time'])) {
                                $timeRange = date('H:i', strtotime($event['start_time']));
                                if (!empty($event['end_time'])) {
                                    $timeRange .= ' - ' . date('H:i', strtotime($event['end_time']));
                                }
                            } elseif (!empty($event['end_time'])) {
                                $timeRange = 'Until ' . date('H:i', strtotime($event['end_time']));
                            }
                            $dateRange = h(format_display_date($eventDate, 'Date TBC'));
                            if ($eventDate && $endDate && $endDate !== $eventDate) {
                                $dateRange .= ' to ' . h(format_display_date($endDate, 'Date TBC'));
                            }
                            // Build a compact meta line
                            $metaParts = [];
                            if (!empty($event['venue'])) {
                                $metaParts[] = $event['venue'];
                            }
                            if ($timeRange) {
                                $metaParts[] = $timeRange;
                            }
                            if (!empty($event['event_type_name'] ?? '')) {
                                $metaParts[] = $event['event_type_name'];
                            }
                            ?>
                            <a class="event-row rounded-3 p-3 mb-2" href="<?php echo h(event_url($event)); ?>">
                                <div class="small text-muted"><?php echo $dateRange; ?></div>
                                <div class="fw-bold mb-1"><?php echo h($event['title']); ?></div>
                                <?php if ($metaParts): ?>
                                    <div class="text-muted small"><?php echo h(implode(' • ', $metaParts)); ?></div>
                                <?php endif; ?>
                                <?php
                                $classNames = [];
                                if ($pdo) {
                                    $classNames = class_names_from_pricing_rows(fetchEventPricingRows($pdo, (int)($event['id'] ?? 0)));
                                }
                                if (!$classNames) {
                                    $classNames = class_names_from_classes_offered($event['classes_offered'] ?? '');
                                }
                                if ($classNames) {
                                    echo '<div class="text-muted small">Classes: ' . h(implode(', ', $classNames)) . '</div>';
                                }
                                ?>
                                <?php if (!empty($event['organiser'])): ?>
                                    <div class="text-muted small">Organiser: <?php echo h($event['organiser']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($event['description'])): ?>
                                    <div class="text-muted small fst-italic"><?php echo h($event['description']); ?></div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (!$eventsByDate): ?>
                            <div class="text-muted small">No events have been published yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="row g-4 mb-5">
                <div class="col-12">
                    <div class="card-soft p-4">
                        <div class="section-title mb-2">Event access</div>
                        <?php if ($isLoggedIn): ?>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div>
                                    <div class="fw-semibold">You are signed in as <?php echo h($currentUser['email']); ?></div>
                                    <?php if ($canViewAdmin): ?>
                                        <div class="text-muted small mb-1">Role: <?php echo h($currentUser['role']); ?></div>
                                        <a class="btn btn-success btn-sm" href="<?php echo h($basePath); ?>/admin/index.php">View admin area</a>
                                    <?php else: ?>
                                        <div class="text-muted small mb-0">Role: <?php echo h($currentUser['role'] ?? 'user'); ?>. You can browse events and bookings (coming soon).</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="fw-semibold mb-1">Login</div>
                                    <form method="POST" action="<?php echo h($basePath); ?>/index.php">
                                        <input type="hidden" name="action" value="login">
                                        <div class="mb-2">
                                            <input type="email" name="email" class="form-control" placeholder="Email" required>
                                        </div>
                                        <div class="mb-2">
                                            <input type="password" name="password" class="form-control" placeholder="Password" required>
                                        </div>
                                        <button class="btn btn-success btn-sm">Login</button>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <div class="fw-semibold mb-1">Create account</div>
                                    <form method="POST" action="<?php echo h($basePath); ?>/index.php">
                                        <input type="hidden" name="action" value="register">
                                        <div class="mb-2">
                                            <input type="text" name="first_name" class="form-control" placeholder="First name">
                                        </div>
                                        <div class="mb-2">
                                            <input type="text" name="last_name" class="form-control" placeholder="Last name">
                                        </div>
                                        <div class="mb-2">
                                            <input type="email" name="email" class="form-control" placeholder="Email" required>
                                        </div>
                                        <div class="mb-2">
                                            <input type="password" name="password" class="form-control" placeholder="Password (min 8 chars)" required>
                                        </div>
                                        <div class="mb-2">
                                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm password" required>
                                        </div>
                                        <button class="btn btn-outline-success btn-sm">Create account</button>
                                    </form>
                                    <div class="text-muted small mt-2">New accounts are standard users. Admin access is granted by an admin.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <div class="col-lg-6">
                    <div class="section-title mb-3" id="about-ildra">About ILDRA</div>
                    <div class="row g-3">
                        <?php foreach ($aboutIldra as $page): ?>
                            <div class="col-12" id="<?php echo h($page['slug']); ?>">
                                <a class="card-soft p-3 h-100 d-block text-decoration-none text-reset" href="<?php echo h(page_url($page)); ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-1 fw-bold"><?php echo h($page['title']); ?></h5>
                                        <span class="badge-pill">ILDRA</span>
                                    </div>
                                    <p class="mb-1 text-muted"><?php echo h($page['excerpt']); ?></p>
                                    <div class="small text-secondary"><?php echo nl2br(h($page['body_html'])); ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="section-title mb-3" id="about-endurance">About Endurance</div>
                    <div class="row g-3">
                        <?php foreach ($aboutEndurance as $page): ?>
                            <div class="col-12" id="<?php echo h($page['slug']); ?>">
                                <a class="card-soft p-3 h-100 d-block text-decoration-none text-reset" href="<?php echo h(page_url($page)); ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-1 fw-bold"><?php echo h($page['title']); ?></h5>
                                        <span class="badge-pill">Endurance</span>
                                    </div>
                                    <p class="mb-1 text-muted"><?php echo h($page['excerpt']); ?></p>
                                    <div class="small text-secondary"><?php echo nl2br(h($page['body_html'])); ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php
                $otherPages = array_filter($pages, fn($p) => !in_array($p['nav_group'], ['about-ildra', 'about-endurance'], true));
                $otherGroups = [];
                foreach ($otherPages as $p) {
                    $otherGroups[$p['nav_group']][] = $p;
                }
            ?>
            <?php if ($otherPages): ?>
                <div class="row g-4 mb-5">
                    <div class="col-12">
                        <div class="section-title mb-3">More from ILDRA</div>
                    </div>
                    <?php foreach ($otherGroups as $groupKey => $groupPages): ?>
                        <div class="col-lg-6">
                            <div class="card-soft p-3 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="mb-0 fw-bold"><?php echo h(NAV_GROUPS[$groupKey] ?? ucfirst(str_replace('-', ' ', $groupKey))); ?></h5>
                                    <span class="badge-pill"><?php echo h((string)count($groupPages)); ?> pages</span>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($groupPages as $page): ?>
                                        <div class="col-12" id="<?php echo h($page['slug']); ?>">
                                            <a class="border rounded-3 p-3 bg-white d-block text-decoration-none text-reset" href="<?php echo h(page_url($page)); ?>">
                                                <div class="fw-bold mb-1"><?php echo h($page['title']); ?></div>
                                                <div class="text-muted small mb-1"><?php echo h($page['excerpt']); ?></div>
                                                <div class="small text-secondary"><?php echo nl2br(h($page['body_html'])); ?></div>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-5">
                <div class="col-lg-7">
                    <div class="section-title mb-3" id="faqs">FAQs</div>
                    <div class="accordion" id="faqAccordion">
                        <?php foreach ($faqs as $index => $faq): ?>
                            <div class="accordion-item card-soft mb-2">
                                <h2 class="accordion-header" id="faq-<?php echo (int)$faq['id']; ?>">
                                    <button class="accordion-button <?php echo $index === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo (int)$faq['id']; ?>" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                                        <?php echo h($faq['question']); ?>
                                    </button>
                                </h2>
                                <div id="collapse-<?php echo (int)$faq['id']; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        <?php echo nl2br(h($faq['answer'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$faqs): ?>
                            <div class="text-muted small">No FAQs published yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="section-title mb-3" id="contact">Contact us</div>
                    <div class="card-soft p-4">
                        <p class="mb-3">Have a question about rides, membership or volunteering? Drop us a line and we will connect you with the right committee.</p>
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><strong>Email:</strong> info@ildra.example</li>
                            <li class="mb-2"><strong>Phone:</strong> +353 (0)1 800 0000</li>
                            <li class="mb-2"><strong>Social:</strong> @ildraendurance</li>
                        </ul>
                        <a href="mailto:info@ildra.example" class="btn cta-btn w-100">Email ILDRA</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

	    <footer class="footer py-4">
	        <div class="container d-flex flex-column flex-lg-row justify-content-between align-items-center gap-2">
	            <div><?php echo h($siteSettings['hero_title']); ?> · <?php echo date('Y'); ?></div>
	            <div class="small d-flex align-items-center gap-3">
	                <?php if ($canViewAdmin): ?>
	                    <a class="btn btn-light btn-sm fw-bold" href="<?php echo h($basePath); ?>/admin/index.php">View admin area</a>
	                <?php elseif (!$isLoggedIn): ?>
	                    <a class="btn btn-light btn-sm fw-bold" href="<?php echo h($basePath); ?>/account">Login / Register</a>
	                <?php endif; ?>
	            </div>
	        </div>
	    </footer>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
