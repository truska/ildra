<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Location: /pages/ride-calendar', true, 302);
exit;

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';
$basket = $_SESSION['basket'] ?? [];
$basketEventCounts = [];
foreach ($basket as $item) {
    $eid = (int)($item['event_id'] ?? 0);
    if ($eid > 0) {
        $basketEventCounts[$eid] = ($basketEventCounts[$eid] ?? 0) + 1;
    }
}

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager', 'organiser'], true);
$basketCount = count($basket);

$eventsUpcoming = fetchEvents($pdo, true);
$eventsAll = fetchEvents($pdo, false);
$entryCounts = [];
if ($pdo) {
    try {
        // Ensure withdrawn flag exists before counting (older DBs may not have this column yet).
        ensure_bookings_tables($pdo);
        $stmt = $pdo->query("SELECT event_id, COUNT(*) AS total FROM booking_items WHERE COALESCE(is_withdrawn, 0) = 0 GROUP BY event_id");
        foreach ($stmt->fetchAll() as $row) {
            $eid = (int)($row['event_id'] ?? 0);
            if ($eid > 0) {
                $entryCounts[$eid] = (int)($row['total'] ?? 0);
            }
        }
    } catch (PDOException $e) {
        // ignore
    }
}
$isPublished = function ($row): bool {
    $status = strtolower(trim((string)($row['status'] ?? '')));
    return $status === 'published';
};
$publishedUpcoming = array_values(array_filter($eventsUpcoming, $isPublished));
$publishedAll = array_values(array_filter($eventsAll, $isPublished));
$eventsToShow = $publishedUpcoming ?: $publishedAll;
// Past published events (event_date < today)
$publishedPast = array_values(array_filter($eventsAll, function ($row) use ($isPublished) {
    if (!$isPublished($row)) {
        return false;
    }
    $eventDate = $row['event_date'] ?? '';
    if ($eventDate === '') {
        return false;
    }
    return strtotime($eventDate) < strtotime(date('Y-m-d'));
}));
usort($publishedPast, function ($a, $b) {
    return strcmp((string)($a['event_date'] ?? ''), (string)($b['event_date'] ?? ''));
});
usort($eventsToShow, function ($a, $b) {
    return strcmp((string)($a['event_date'] ?? ''), (string)($b['event_date'] ?? ''));
});

function event_url(array $event, string $basePath): string
{
    $id = (int)($event['id'] ?? 0);
    if ($id <= 0) {
        return '#';
    }
    $slug = slugify((string)($event['title'] ?? 'event'));
    return $basePath . '/events/' . $id . '-' . $slug;
}

function event_class_names(?PDO $pdo, array $event): array
{
    $eventId = (int)($event['id'] ?? 0);
    $rows = ($pdo && $eventId > 0) ? fetchEventPricingRows($pdo, $eventId) : [];
    $names = class_names_from_pricing_rows($rows);
    if (!$names) {
        $names = class_names_from_classes_offered($event['classes_offered'] ?? '');
    }
    return $names;
}

$navItemEventsUrl = $basePath . '/events';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events | <?php echo h($siteSettings['hero_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --green: #146118;
            --green-alt: #1f7c24;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .page-hero {
            background: linear-gradient(120deg, rgba(20, 97, 24, 0.9), rgba(20, 97, 24, 0.75)), url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            padding: 2.5rem 0;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.12), transparent 32%);
            z-index: 0;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .event-card {
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 8px 24px rgba(0,0,0,0.05);
            background: #fff;
            padding: 14px 16px;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .event-card.full {
            opacity: 0.65;
            pointer-events: none;
        }
        .badge-full {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #f8d7da;
            color: #842029;
            font-weight: 700;
            font-size: 0.85rem;
        }
        .event-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(0,0,0,0.08);
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
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(20,97,24,0.1);
            color: var(--green);
            font-weight: 700;
            font-size: 0.85rem;
        }
        .pill-status { font-weight: 800; }
        .pill-open { background: rgba(25,135,84,0.16); color: #198754; }
        .pill-opening { background: rgba(13,110,253,0.14); color: #0d6efd; }
        .pill-closed { background: rgba(220,53,69,0.14); color: #dc3545; }
        .pill-muted { background: rgba(0,0,0,0.04); color: #555; }
        .event-meta {
            color: var(--muted);
            font-size: 0.9rem;
        }
        .month-header {
            font-weight: 800;
            letter-spacing: 0.08em;
            font-size: 0.85rem;
            color: var(--muted);
            margin: 1rem 0 0.5rem;
        }
        .event-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 2px;
        }
        .event-date {
            font-weight: 700;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .event-notes {
            color: var(--muted);
            font-size: 0.9rem;
            font-style: italic;
        }
        @media (max-width: 767px) {
            .d-flex.gap-2.wrap-mobile { flex-wrap: wrap; }
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <main class="py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-12">
                    <div class="card-soft p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 wrap-mobile gap-2">
                            <div>
                                <p class="mb-1 text-uppercase small fw-bold text-muted">Events &amp; rides</p>
                                <h1 class="fw-bold mb-1">Event calendar</h1>
                                <div class="small text-muted">&nbsp;</div>
                            </div>
                            <div class="d-flex gap-2">
                                <a class="btn btn-sm btn-outline-success" href="<?php echo h($basePath); ?>/basket">Basket<?php echo $basketCount ? ' (' . $basketCount . ')' : ''; ?></a>
                                <?php if ($canViewAdmin): ?>
                                    <a class="btn btn-sm btn-outline-success" href="<?php echo h($siteBase); ?>/admin/events.php">Manage events</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                            $currentMonth = '';
                        ?>
	                        <?php foreach ($eventsToShow as $event): ?>
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
                                $dateRange = 'Date TBC';
                                if ($eventDate) {
                                    $eventDateDt = new DateTimeImmutable((string)$eventDate);
                                    $dateRange = $eventDateDt->format('jS M Y');
                                }
                                if ($eventDate && $endDate && $endDate !== $eventDate) {
                                    $endDateDt = new DateTimeImmutable((string)$endDate);
                                    $dateRange .= ' to ' . $endDateDt->format('jS M Y');
                                }
                                $dateRange = h($dateRange);
                                $classNames = event_class_names($pdo, $event);
                                $classesText = $classNames ? implode(', ', $classNames) : '';
                                $metaParts = [];
                                if (!empty($event['venue'])) { $metaParts[] = $event['venue']; }
                                if ($timeRange) { $metaParts[] = $timeRange; }
                                if (!empty($event['event_type_name'] ?? '')) { $metaParts[] = $event['event_type_name']; }
                                $typeLabel = $event['event_type_name'] ?? '';
                            $monthLabel = $eventDate ? strtoupper(date('F Y', strtotime($eventDate))) : '';
                            if ($monthLabel && $monthLabel !== $currentMonth) {
                                $currentMonth = $monthLabel;
                                echo '<div class="month-header">' . h($currentMonth) . '</div>';
                            }
	                                $count = ($entryCounts[$event['id']] ?? (int)($event['entry_count'] ?? 0)) + ($basketEventCounts[$event['id']] ?? 0);
	                                $limitEnabled = !empty($event['capacity_enabled']);
	                                $limit = (int)($event['capacity_limit'] ?? 0);
	                                $isLimited = $limitEnabled && $limit > 0;
	                                $isFull = $isLimited && $count >= $limit;

	                                // Entry window status badge (matches single-event page logic).
	                                $entryOpenAt = $event['entry_open_at'] ?? null;
	                                $nonMemberEntryOpenAt = $event['non_member_entry_open_at'] ?? null;
	                                $entryCloseAt = $event['entry_close_at'] ?? null;
	                                if (!$entryOpenAt && $eventDate) {
	                                    $entryOpenAt = date('Y-m-d 00:00:00', strtotime($eventDate . ' -1 month'));
	                                }
	                                if (!$nonMemberEntryOpenAt && $entryOpenAt) {
	                                    $nonMemberEntryOpenAt = date('Y-m-d H:i:s', strtotime((string)$entryOpenAt . ' +1 week'));
	                                }
	                                if (!$entryCloseAt && $eventDate) {
	                                    $entryCloseAt = date('Y-m-d 23:59:59', strtotime($eventDate . ' -1 week'));
	                                }
	                                $now = new DateTimeImmutable('now');
	                                $entryOpenDt = $entryOpenAt ? new DateTimeImmutable((string)$entryOpenAt) : null;
	                                $nonMemberEntryOpenDt = $nonMemberEntryOpenAt ? new DateTimeImmutable((string)$nonMemberEntryOpenAt) : null;
	                                $entryCloseDt = $entryCloseAt ? new DateTimeImmutable((string)$entryCloseAt) : null;
	                                $entryPills = [];
	                                if ($entryOpenDt && $now < $entryOpenDt) {
	                                    $entryPills[] = [
	                                        'class' => 'pill-opening',
	                                        'label' => 'Members ' . $entryOpenDt->format('jS M H:i'),
	                                        'title' => 'Member entries will open on ' . $entryOpenDt->format('jS M Y \a\t H:i'),
	                                    ];
	                                    if ($nonMemberEntryOpenDt) {
	                                        $entryPills[] = [
	                                            'class' => 'pill-opening',
	                                            'label' => 'Non-members ' . $nonMemberEntryOpenDt->format('jS M H:i'),
	                                            'title' => 'Non-member entries will open on ' . $nonMemberEntryOpenDt->format('jS M Y \a\t H:i'),
	                                        ];
	                                    }
	                                } elseif ($nonMemberEntryOpenDt && $now < $nonMemberEntryOpenDt) {
	                                    $entryPills[] = [
	                                        'class' => 'pill-open',
	                                        'label' => 'Members open',
	                                        'title' => 'Member entries are open now',
	                                    ];
	                                    $entryPills[] = [
	                                        'class' => 'pill-opening',
	                                        'label' => 'Non-members ' . $nonMemberEntryOpenDt->format('jS M H:i'),
	                                        'title' => 'Non-member entries will open on ' . $nonMemberEntryOpenDt->format('jS M Y \a\t H:i'),
	                                    ];
	                                } elseif ($entryCloseDt && $now > $entryCloseDt) {
	                                    $entryPills[] = [
	                                        'class' => 'pill-closed',
	                                        'label' => 'Closed ' . $entryCloseDt->format('jS M H:i'),
	                                        'title' => 'Entries closed on ' . $entryCloseDt->format('jS M Y \a\t H:i'),
	                                    ];
	                                } else {
	                                    $entryPills[] = [
	                                        'class' => 'pill-open',
	                                        'label' => 'Open' . ($entryOpenDt ? ' (from ' . $entryOpenDt->format('jS M H:i') . ')' : ''),
	                                        'title' => $entryCloseDt ? ('Entries are open until ' . $entryCloseDt->format('jS M Y \a\t H:i')) : 'Entries are open',
	                                    ];
	                                }
	                                if ($entryCloseDt) {
	                                    $isClosed = $now > $entryCloseDt;
	                                    $entryPills[] = [
	                                        'class' => $isClosed ? 'pill-closed' : 'pill',
	                                        'label' => ($isClosed ? 'Closed ' : 'Closes ') . $entryCloseDt->format('jS M H:i'),
	                                        'title' => ($isClosed ? 'Closed on ' : 'Closes on ') . $entryCloseDt->format('jS M Y \a\t H:i'),
	                                    ];
	                                }
	                            ?>
	                            <a class="event-card mb-2<?php echo $isFull ? ' full' : ''; ?>" href="<?php echo h(event_url($event, $basePath)); ?>">
	                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
	                                    <div>
                                        <div class="event-date"><?php echo $dateRange; ?></div>
                                        <div class="event-title"><?php echo h($event['title']); ?></div>
                                        <?php if ($metaParts): ?>
                                            <div class="event-meta"><?php echo h(implode(' • ', $metaParts)); ?></div>
                                        <?php endif; ?>
                                        <?php if ($classesText): ?>
                                            <div class="text-muted small mt-1">
                                                Classes: <?php echo h($classesText); ?>
                                                <?php if ($isLimited): ?>
                                                    · <?php echo h($count); ?>/<?php echo h($limit); ?> slots
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($event['description'])): ?>
                                            <div class="event-notes"><?php echo h($event['description']); ?></div>
                                        <?php endif; ?>
	                                        <?php if ($isFull): ?>
	                                            <div class="badge-full mt-2">Event full</div>
	                                        <?php endif; ?>
	                                    </div>
	                                    <div class="d-flex flex-column align-items-end gap-2">
	                                        <?php if ($typeLabel): ?>
	                                            <span class="pill"><?php echo h($typeLabel); ?></span>
	                                        <?php endif; ?>
	                                        <?php foreach ($entryPills as $pill): ?>
	                                            <span class="pill pill-status <?php echo h($pill['class']); ?>" title="<?php echo h($pill['title']); ?>"><?php echo h($pill['label']); ?></span>
	                                        <?php endforeach; ?>
	                                    </div>
	                                </div>
	                            </a>
	                        <?php endforeach; ?>
                        <?php if (!$eventsToShow): ?>
                            <div class="text-muted small">No events available.</div>
                        <?php endif; ?>
                </div>
            </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
