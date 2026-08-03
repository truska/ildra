<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$siteBase = $siteBase ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage events.'];
    } elseif ($action === 'delete_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId > 0 && deleteEvent($pdo, $eventId, $alerts)) {
            $successMessage = 'Event deleted.';
        }
    }
    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: events.php');
    exit;
}

$events = fetchEvents($pdo, false);
$entryCounts = [];
$basketCounts = [];
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
        // ignore; fallback to zeros
    }
}
// include items currently in the session basket so admins see held slots
foreach ($_SESSION['basket'] ?? [] as $item) {
    $eid = (int)($item['event_id'] ?? 0);
    if ($eid > 0) {
        $basketCounts[$eid] = ($basketCounts[$eid] ?? 0) + 1;
    }
}
$today = date('Y-m-d');
$sortKey = $_GET['sort'] ?? 'start';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$allowedSortKeys = ['start', 'end', 'title', 'venue', 'status', 'type', 'entries', 'organiser'];
$hasExplicitSort = array_key_exists('sort', $_GET) || array_key_exists('dir', $_GET);
$upcoming = [];
$past = [];
foreach ($events as $ev) {
    $date = $ev['event_date'] ?? '';
    if ($date && $date >= $today) {
        $upcoming[] = $ev;
    } else {
        $past[] = $ev;
    }
}
if (!in_array($sortKey, $allowedSortKeys, true)) {
    $sortKey = 'start';
}

$defaultUpcomingSort = fn($a, $b) => strcmp((string)($a['event_date'] ?? ''), (string)($b['event_date'] ?? ''));
$defaultPastSort = fn($a, $b) => strcmp((string)($b['event_date'] ?? ''), (string)($a['event_date'] ?? ''));

$cmp = function (array $a, array $b) use ($sortKey, $sortDir, $entryCounts, $basketCounts): int {
    $dir = $sortDir === 'asc' ? 1 : -1;

    $va = '';
    $vb = '';
    if ($sortKey === 'start') {
        $va = (string)($a['event_date'] ?? '');
        $vb = (string)($b['event_date'] ?? '');
    } elseif ($sortKey === 'end') {
        $va = (string)($a['end_date'] ?? '');
        $vb = (string)($b['end_date'] ?? '');
    } elseif ($sortKey === 'title') {
        $va = mb_strtolower((string)($a['title'] ?? ''));
        $vb = mb_strtolower((string)($b['title'] ?? ''));
    } elseif ($sortKey === 'venue') {
        $va = mb_strtolower((string)($a['venue'] ?? ''));
        $vb = mb_strtolower((string)($b['venue'] ?? ''));
    } elseif ($sortKey === 'status') {
        $va = mb_strtolower((string)($a['status'] ?? ''));
        $vb = mb_strtolower((string)($b['status'] ?? ''));
    } elseif ($sortKey === 'type') {
        $va = mb_strtolower((string)($a['event_type_name'] ?? ''));
        $vb = mb_strtolower((string)($b['event_type_name'] ?? ''));
    } elseif ($sortKey === 'organiser') {
        $va = mb_strtolower((string)($a['organiser'] ?? ''));
        $vb = mb_strtolower((string)($b['organiser'] ?? ''));
    } elseif ($sortKey === 'entries') {
        $ida = (int)($a['id'] ?? 0);
        $idb = (int)($b['id'] ?? 0);
        $va = (string)((int)($entryCounts[$ida] ?? (int)($a['entry_count'] ?? 0)) + (int)($basketCounts[$ida] ?? 0));
        $vb = (string)((int)($entryCounts[$idb] ?? (int)($b['entry_count'] ?? 0)) + (int)($basketCounts[$idb] ?? 0));
        $na = (int)$va;
        $nb = (int)$vb;
        if ($na === $nb) {
            return 0;
        }
        return ($na < $nb ? -1 : 1) * $dir;
    }

    if ($va === $vb) {
        return 0;
    }
    return ($va < $vb ? -1 : 1) * $dir;
};

usort($upcoming, $cmp);
usort($past, $cmp);

// Preserve the historical default ordering when no explicit sort is requested:
// upcoming ASC by start date, previous events DESC by start date.
if (!$hasExplicitSort) {
    usort($upcoming, $defaultUpcomingSort);
    usort($past, $defaultPastSort);
    // Don't highlight any sort column by default (prevents implying the same sort
    // is applied to both tables while defaults differ between sections).
    $sortKey = '__none__';
    $sortDir = 'asc';
}

function event_class_names_admin(array $event): array
{
    $eventId = (int)($event['id'] ?? 0);
    if ($eventId <= 0) {
        return [];
    }
    $pdo = $GLOBALS['pdo'] ?? null;
    $rows = ($pdo instanceof PDO) ? fetchEventPricingRows($pdo, $eventId) : [];
    $names = class_names_from_pricing_rows($rows);
    if (!$names) {
        $names = class_names_from_classes_offered($event['classes_offered'] ?? '');
    }
    return $names;
}

function render_event_card(array $event, string $siteBase, bool $isAdmin): string
{
    $start = $event['event_date'] ?? '';
    $end = $event['end_date'] ?? '';
    $endDisplay = ($end && $end !== $start) ? ' to ' . h($end) : '';
    $classNames = event_class_names_admin($event);
    $classes = $classNames ? implode(', ', $classNames) : '';
    $slugTitle = slugify((string)($event['title'] ?? 'event'));
    $viewUrl = $siteBase . '/events/' . (int)($event['id'] ?? 0) . '-' . $slugTitle;
    ob_start();
    ?>
    <div class="py-2 border-bottom">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-md-2">
                <div class="small text-muted">Start</div>
                <div class="fw-semibold"><?php echo h($start ?: 'Date TBC'); ?></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="small text-muted">End</div>
                <div class="fw-semibold"><?php echo h($end ?: ''); ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="small text-muted">Title</div>
                <div class="fw-semibold"><?php echo h($event['title'] ?? 'Untitled'); ?></div>
                <div class="text-muted small"><?php echo h($event['venue'] ?: 'Venue TBC'); ?></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="small text-muted">Status / Type</div>
                <div class="fw-semibold"><?php echo h($event['status'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo h($event['event_type_name'] ?? ''); ?></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="small text-muted">Classes</div>
                <div class="text-muted small"><?php echo h($classes ?: '—'); ?></div>
            </div>
            <div class="col-12 col-md-1 text-md-end">
                <div class="d-flex flex-wrap flex-md-column gap-1 justify-content-end">
                    <a class="btn btn-sm btn-outline-success" href="event_edit.php?id=<?php echo (int)$event['id']; ?>">Edit</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($viewUrl); ?>" target="_blank" rel="noopener">View</a>
                    <?php if ($isAdmin): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this event?');">
                            <input type="hidden" name="action" value="delete_event">
                            <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

admin_layout_start('Events', 'events');
?>
<style>
    .event-action-row td {
        padding-top: 0;
        border-top: 0;
    }
    .action-buttons {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: .35rem;
    }
    .action-buttons .btn { white-space: nowrap; }
    .action-buttons form { margin: 0; }
    @media (max-width: 767.98px) {
        th.col-end,
        td.col-end,
        th.col-venue,
        td.col-venue,
        th.col-status,
        td.col-status,
        th.col-type,
        td.col-type,
        th.col-classes,
        td.col-classes,
        th.col-organiser,
        td.col-organiser {
            display: none;
        }
    }
</style>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <div class="small text-muted">Manage events</div>
        <h5 class="mb-0">Events</h5>
    </div>
    <div class="admin-page-actions">
        <a class="btn btn-success has-icon" href="event_edit.php">
            <i class="fa-solid fa-calendar-plus btn-icon"></i>
            <span class="btn-label">Add New Event</span>
        </a>
    </div>
</div>

<div class="card-soft p-3">
    <div class="mb-2">
        <div class="small text-muted">Upcoming events (ascending by start date)</div>
    </div>
    <div class="table-responsive">
	        <table class="table table-sm align-middle">
	            <thead class="table-light">
	                <tr>
	                    <th><?php echo admin_sort_link('start', 'Start', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-end"><?php echo admin_sort_link('end', 'End', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('title', 'Title', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-venue"><?php echo admin_sort_link('venue', 'Venue', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-status"><?php echo admin_sort_link('status', 'Status', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-type"><?php echo admin_sort_link('type', 'Type', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-classes">Classes</th>
	                    <th><?php echo admin_sort_link('entries', 'Entries', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-organiser"><?php echo admin_sort_link('organiser', 'Organiser', (string)$sortKey, (string)$sortDir); ?></th>
	                </tr>
	            </thead>
            <tbody>
                <?php foreach ($upcoming as $event): ?>
                    <tr>
                        <td><?php echo h(format_display_date($event['event_date'] ?? null, '')); ?></td>
                        <td class="col-end"><?php echo h(format_display_date($event['end_date'] ?? null, '')); ?></td>
                        <td><?php echo h($event['title']); ?></td>
                        <td class="text-muted small col-venue"><?php echo h($event['venue'] ?: '—'); ?></td>
                        <td class="col-status"><?php echo h($event['status']); ?></td>
                        <td class="text-muted small col-type"><?php echo h($event['event_type_name'] ?? ''); ?></td>
                        <td class="text-muted small col-classes">
                            <?php
                            $classNames = event_class_names_admin($event);
                            echo h($classNames ? implode(', ', $classNames) : '—');
                            ?>
                        </td>
                        <?php
                            $baseCount = array_key_exists($event['id'], $entryCounts)
                                ? $entryCounts[$event['id']]
                                : (int)($event['entry_count'] ?? 0);
                            $count = $baseCount + ($basketCounts[$event['id']] ?? 0);
                            $limitEnabled = !empty($event['capacity_enabled']);
                            $limit = (int)($event['capacity_limit'] ?? 0);
                            $isLimited = $limitEnabled && $limit > 0;
                        ?>
                        <td class="text-muted small">
                            <?php if ($isLimited): ?>
                                <?php echo h($count); ?>/<?php echo h($limit); ?>
                            <?php else: ?>
                                <?php echo h($count); ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small col-organiser"><?php echo h($event['organiser'] ?? '—'); ?></td>
                    </tr>
                    <tr class="event-action-row">
                        <td colspan="9">
                            <div class="action-buttons">
                                <a class="btn btn-sm btn-outline-primary has-icon" href="event_entries.php?event_id=<?php echo (int)$event['id']; ?>">
                                    <i class="fa-solid fa-list-check btn-icon"></i><span class="btn-label">Entry list</span>
                                </a>
                                <a class="btn btn-sm btn-outline-success has-icon" href="event_edit.php?id=<?php echo (int)$event['id']; ?>">
                                    <i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span>
                                </a>
                                <a class="btn btn-sm btn-outline-dark has-icon" href="event_duplicate.php?source_id=<?php echo (int)$event['id']; ?>">
                                    <i class="fa-solid fa-copy btn-icon"></i><span class="btn-label">Copy</span>
                                </a>
                            <?php
                                $slugTitle = slugify((string)($event['title'] ?? 'event'));
                                $viewUrl = $siteBase . '/events/' . (int)$event['id'] . '-' . $slugTitle;
                            ?>
                                <a class="btn btn-sm btn-outline-secondary has-icon" href="<?php echo h($viewUrl); ?>" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View</span>
                                </a>
                            <?php if ($isAdmin): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this event?');">
                                    <input type="hidden" name="action" value="delete_event">
                                    <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger has-icon"><i class="fa-solid fa-trash btn-icon"></i><span class="btn-label">Delete</span></button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?>
                    <tr><td colspan="9" class="text-muted">No upcoming events.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($past): ?>
    <div class="card-soft p-3 mt-3">
        <div class="mb-2">
            <div class="small text-muted">Previous events (most recent first)</div>
        </div>
        <div class="table-responsive">
	        <table class="table table-sm align-middle">
	            <thead class="table-light">
	                <tr>
	                    <th><?php echo admin_sort_link('start', 'Start', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-end"><?php echo admin_sort_link('end', 'End', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('title', 'Title', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-venue"><?php echo admin_sort_link('venue', 'Venue', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-status"><?php echo admin_sort_link('status', 'Status', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-type"><?php echo admin_sort_link('type', 'Type', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-classes">Classes</th>
	                    <th><?php echo admin_sort_link('entries', 'Entries', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th class="col-organiser"><?php echo admin_sort_link('organiser', 'Organiser', (string)$sortKey, (string)$sortDir); ?></th>
	                </tr>
	            </thead>
                <tbody>
                    <?php foreach ($past as $event): ?>
                        <tr>
                            <td><?php echo h(format_display_date($event['event_date'] ?? null, '')); ?></td>
                            <td class="col-end"><?php echo h(format_display_date($event['end_date'] ?? null, '')); ?></td>
                            <td><?php echo h($event['title']); ?></td>
                            <td class="text-muted small col-venue"><?php echo h($event['venue'] ?: '—'); ?></td>
                            <td class="col-status"><?php echo h($event['status']); ?></td>
                            <td class="text-muted small col-type"><?php echo h($event['event_type_name'] ?? ''); ?></td>
                            <td class="text-muted small col-classes">
                                <?php
                                $classNames = event_class_names_admin($event);
                                echo h($classNames ? implode(', ', $classNames) : '—');
                                ?>
                            </td>
                            <?php
                                $baseCount = array_key_exists($event['id'], $entryCounts)
                                    ? $entryCounts[$event['id']]
                                    : (int)($event['entry_count'] ?? 0);
                                $count = $baseCount + ($basketCounts[$event['id']] ?? 0);
                                $limitEnabled = !empty($event['capacity_enabled']);
                                $limit = (int)($event['capacity_limit'] ?? 0);
                                $isLimited = $limitEnabled && $limit > 0;
                            ?>
                            <td class="text-muted small">
                                <?php if ($isLimited): ?>
                                    <?php echo h($count); ?>/<?php echo h($limit); ?>
                                <?php else: ?>
                                    <?php echo h($count); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small col-organiser"><?php echo h($event['organiser'] ?? '—'); ?></td>
                        </tr>
                        <tr class="event-action-row">
                            <td colspan="9">
                                <div class="action-buttons">
                                    <a class="btn btn-sm btn-outline-primary has-icon" href="event_entries.php?event_id=<?php echo (int)$event['id']; ?>">
                                        <i class="fa-solid fa-list-check btn-icon"></i><span class="btn-label">Entry list</span>
                                    </a>
                                    <a class="btn btn-sm btn-outline-success has-icon" href="event_edit.php?id=<?php echo (int)$event['id']; ?>">
                                        <i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span>
                                    </a>
                                    <a class="btn btn-sm btn-outline-dark has-icon" href="event_duplicate.php?source_id=<?php echo (int)$event['id']; ?>">
                                        <i class="fa-solid fa-copy btn-icon"></i><span class="btn-label">Copy</span>
                                    </a>
                                <?php
                                    $slugTitle = slugify((string)($event['title'] ?? 'event'));
                                    $viewUrl = $siteBase . '/events/' . (int)$event['id'] . '-' . $slugTitle;
                                ?>
                                    <a class="btn btn-sm btn-outline-secondary has-icon" href="<?php echo h($viewUrl); ?>" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View</span>
                                    </a>
                                <?php if ($isAdmin): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this event?');">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger has-icon"><i class="fa-solid fa-trash btn-icon"></i><span class="btn-label">Delete</span></button>
                                    </form>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php
admin_layout_end();
