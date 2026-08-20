<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$siteBase = $siteBase ?? '';
$eventsReturnUrl = (string)($_SESSION['admin_list_returns']['events'] ?? 'events.php');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventsQuery = http_build_query($_GET);
    $eventsReturnUrl = 'events.php' . ($eventsQuery !== '' ? '?' . $eventsQuery : '');
    $_SESSION['admin_list_returns']['events'] = $eventsReturnUrl;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage events.'];
    } elseif ($action === 'delete_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $eventToDelete = $eventId > 0 ? fetchEventById($pdo, $eventId) : null;
        $eventLastDate = $eventToDelete
            ? (string)($eventToDelete['end_date'] ?: ($eventToDelete['event_date'] ?? ''))
            : '';
        if ($eventToDelete && $eventLastDate !== '' && $eventLastDate < date('Y-m-d')) {
            $alerts[] = ['type' => 'danger', 'message' => 'Past events cannot be deleted from this page.'];
        } elseif ($eventId > 0 && deleteEvent($pdo, $eventId, $alerts)) {
            $successMessage = 'Event deleted.';
        }
    }
    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: ' . $eventsReturnUrl);
    exit;
}

$events = fetchEvents($pdo, false);
$rideReportsByEvent = [];
foreach (fetchNewsArticles($pdo, false) as $newsItem) {
    if (($newsItem['article_type'] ?? 'news') === 'ride_report' && !empty($newsItem['event_id'])) {
        $rideReportsByEvent[(int)$newsItem['event_id']] = $newsItem;
    }
}
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
$eventFilterOptions = static function (array $rows, callable $value): array {
    $options=[]; foreach($rows as$row){$v=trim((string)$value($row));if($v!=='')$options[$v]=$v;} natcasesort($options); return $options;
};
$filterValues = [
    'start_filter'=>trim((string)($_GET['start_filter']??'')), 'end_filter'=>trim((string)($_GET['end_filter']??'')),
    'title_filter'=>trim((string)($_GET['title_filter']??'')), 'venue_filter'=>trim((string)($_GET['venue_filter']??'')),
    'status_filter'=>trim((string)($_GET['status_filter']??'')), 'type_filter'=>trim((string)($_GET['type_filter']??'')),
    'classes_filter'=>trim((string)($_GET['classes_filter']??'')), 'entries_filter'=>trim((string)($_GET['entries_filter']??'')),
    'organiser_filter'=>trim((string)($_GET['organiser_filter']??'')), 'date_from'=>trim((string)($_GET['date_from']??'')), 'date_to'=>trim((string)($_GET['date_to']??'')),
];
$filterOptions = [
    'start_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['event_date']??'')), 'end_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['end_date']??'')),
    'title_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['title']??'')), 'venue_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['venue']??'')),
    'status_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['status']??'')), 'type_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['event_type_name']??'')),
    'organiser_filter'=>$eventFilterOptions($events,static fn(array $e)=>(string)($e['organiser']??'')),
];
$events=array_values(array_filter($events,static function(array $event)use($filterValues,$entryCounts,$basketCounts):bool{
    foreach(['start_filter'=>'event_date','end_filter'=>'end_date','title_filter'=>'title','venue_filter'=>'venue','status_filter'=>'status','type_filter'=>'event_type_name','organiser_filter'=>'organiser']as$filter=>$field)if($filterValues[$filter]!==''&&(string)($event[$field]??'')!==$filterValues[$filter])return false;
    $date=(string)($event['event_date']??'');if($filterValues['date_from']!==''&&$date<$filterValues['date_from'])return false;if($filterValues['date_to']!==''&&$date>$filterValues['date_to'])return false;
    $classes=implode(', ',event_class_names_admin($event));if($filterValues['classes_filter']!==''&&stripos($classes,$filterValues['classes_filter'])===false)return false;
    $id=(int)($event['id']??0);$entries=(string)((int)($entryCounts[$id]??($event['entry_count']??0))+(int)($basketCounts[$id]??0));if($filterValues['entries_filter']!==''&&stripos($entries,$filterValues['entries_filter'])===false)return false;
    return true;
}));
$today = date('Y-m-d');
$activeView = (($_GET['view'] ?? 'future') === 'past') ? 'past' : 'future';
$sortKey = $_GET['sort'] ?? 'start';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$allowedSortKeys = ['start', 'end', 'title', 'venue', 'status', 'type', 'entries', 'organiser'];
$hasExplicitSort = array_key_exists('sort', $_GET) || array_key_exists('dir', $_GET);
$upcoming = [];
$past = [];
foreach ($events as $ev) {
    // Multi-day rides remain current until their final day has passed.
    $date = (string)($ev['end_date'] ?: ($ev['event_date'] ?? ''));
    if ($date === '' || $date >= $today) {
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

function event_filter_select(string $name, array $options, string $selected): string
{
    $html='<select class="form-select form-select-sm" form="event-filter-form" name="'.h($name).'"><option value="">All</option>';
    foreach($options as$value=>$label)$html.='<option value="'.h((string)$value).'"'.($selected===(string)$value?' selected':'').'>'.h((string)$label).'</option>';
    return $html.'</select>';
}

function event_filter_row(array $filterValues, array $filterOptions): string
{
    ob_start(); ?>
    <tr class="admin-table-filter-row">
        <th><?php echo event_filter_select('start_filter',$filterOptions['start_filter'],$filterValues['start_filter']); ?></th>
        <th class="col-end"><?php echo event_filter_select('end_filter',$filterOptions['end_filter'],$filterValues['end_filter']); ?></th>
        <th><?php echo event_filter_select('title_filter',$filterOptions['title_filter'],$filterValues['title_filter']); ?></th>
        <th class="col-venue"><?php echo event_filter_select('venue_filter',$filterOptions['venue_filter'],$filterValues['venue_filter']); ?></th>
        <th class="col-status"><?php echo event_filter_select('status_filter',$filterOptions['status_filter'],$filterValues['status_filter']); ?></th>
        <th class="col-type"><?php echo event_filter_select('type_filter',$filterOptions['type_filter'],$filterValues['type_filter']); ?></th>
        <th class="col-classes"><input class="form-control form-control-sm" form="event-filter-form" name="classes_filter" value="<?php echo h($filterValues['classes_filter']); ?>" placeholder="Search"></th>
        <th><input class="form-control form-control-sm" form="event-filter-form" name="entries_filter" value="<?php echo h($filterValues['entries_filter']); ?>" placeholder="Search"></th>
        <th class="col-organiser"><?php echo event_filter_select('organiser_filter',$filterOptions['organiser_filter'],$filterValues['organiser_filter']); ?></th>
    </tr><?php return (string)ob_get_clean();
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
    $reportId = (int)($GLOBALS['rideReportsByEvent'][(int)($event['id'] ?? 0)]['id'] ?? 0);
    $reportUrl = $reportId ? 'news_edit.php?id=' . $reportId : 'news_edit.php?type=ride_report&event_id=' . (int)$event['id'];
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
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo h($reportUrl); ?>"><?php echo $reportId ? 'Edit Report' : 'Add Report'; ?></a>
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
    .event-summary-row td {
        border-bottom: 0;
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
    .event-view-tabs { border-bottom: 2px solid #d7dfd5; gap: .35rem; }
    .event-view-tabs .nav-link { border: 1px solid #cbd5c9; border-bottom: 0; background: #eef2ed; color: #405443; font-weight: 700; }
    .event-view-tabs .nav-link:hover { background: #e1e9df; color: #183d20; }
    .event-view-tabs .nav-link.future-tab.active { background: #198754; border-color: #198754; color: #fff; }
    .event-view-tabs .nav-link.past-tab.active { background: #495057; border-color: #495057; color: #fff; }
    .event-view-tabs .nav-link.active .badge { background: #fff !important; color: #26352a !important; }
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

<ul class="nav nav-tabs event-view-tabs mb-3" aria-label="Event date range">
    <li class="nav-item">
        <a class="nav-link future-tab <?php echo $activeView === 'future' ? 'active' : ''; ?>" href="?<?php echo h(http_build_query(array_merge($_GET, ['view' => 'future']))); ?>">
            Current &amp; Future <span class="badge text-bg-light ms-1"><?php echo count($upcoming); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link past-tab <?php echo $activeView === 'past' ? 'active' : ''; ?>" href="?<?php echo h(http_build_query(array_merge($_GET, ['view' => 'past']))); ?>">
            Past <span class="badge text-bg-light ms-1"><?php echo count($past); ?></span>
        </a>
    </li>
</ul>

<form method="get" id="event-filter-form" class="card-soft px-3 py-2 mb-3">
    <input type="hidden" name="view" value="<?php echo h($activeView); ?>">
    <div class="d-flex flex-wrap align-items-end gap-2">
        <div><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="date_from" value="<?php echo h($filterValues['date_from']); ?>"></div>
        <div><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="date_to" value="<?php echo h($filterValues['date_to']); ?>"></div>
        <button class="btn btn-sm btn-outline-secondary">Filter dates</button><a class="btn btn-sm btn-secondary" href="events.php?view=<?php echo h($activeView); ?>">Clear all</a>
    </div>
</form>

<div class="card-soft p-3 <?php echo $activeView === 'future' ? '' : 'd-none'; ?>">
    <div class="mb-2">
        <h6 class="mb-1">Current and Future Rides</h6>
        <div class="small text-muted">Ascending by start date.</div>
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
	                <?php echo event_filter_row($filterValues,$filterOptions); ?>
	            </thead>
            <tbody>
                <?php foreach ($upcoming as $event): ?>
                    <tr id="event-<?php echo (int)$event['id']; ?>" class="event-summary-row">
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
                                <a class="btn btn-sm btn-outline-success has-icon" href="event_edit.php?id=<?php echo (int)$event['id']; ?>">
                                    <i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span>
                                </a>
                                <a class="btn btn-sm btn-outline-primary has-icon" href="event_entries.php?event_id=<?php echo (int)$event['id']; ?>">
                                    <i class="fa-solid fa-list-check btn-icon"></i><span class="btn-label">Entry list</span>
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
                                <a class="btn btn-sm btn-outline-secondary has-icon" href="ride_notes.php?event_id=<?php echo (int)$event['id']; ?>">
                                    <i class="fa-solid fa-note-sticky btn-icon"></i><span class="btn-label">Ride Notes</span>
                                </a>
                            <?php if ($isAdmin): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this event?');">
                                    <input type="hidden" name="action" value="delete_event">
                                    <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                    <input type="hidden" name="view" value="future">
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
</div><div class="mt-2 text-end"><button class="btn btn-sm btn-outline-secondary" type="submit" form="event-filter-form">Apply table filters</button> <a class="btn btn-sm btn-secondary" href="events.php?view=<?php echo h($activeView); ?>">Clear all</a></div>

    <div class="card-soft p-3 <?php echo $activeView === 'past' ? '' : 'd-none'; ?>" id="past-rides">
        <div class="mb-2">
            <h6 class="mb-1">Past Rides</h6>
            <div class="small text-muted">Past rides, most recent first. Add or edit each ride report from the action row.</div>
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
                        <tr id="event-<?php echo (int)$event['id']; ?>" class="event-summary-row">
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
                                    <a class="btn btn-sm btn-outline-success has-icon" href="event_edit.php?id=<?php echo (int)$event['id']; ?>">
                                        <i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span>
                                    </a>
                                    <a class="btn btn-sm btn-outline-primary has-icon" href="event_entries.php?event_id=<?php echo (int)$event['id']; ?>">
                                        <i class="fa-solid fa-list-check btn-icon"></i><span class="btn-label">Entry list</span>
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
                                    <?php $reportId=(int)($rideReportsByEvent[(int)$event['id']]['id']??0); ?><a class="btn btn-sm btn-outline-primary has-icon" href="<?php echo $reportId?'news_edit.php?id='.$reportId:'news_edit.php?type=ride_report&amp;event_id='.(int)$event['id']; ?>"><i class="fa-solid fa-newspaper btn-icon"></i><span class="btn-label"><?php echo $reportId?'Edit Report':'Add Report'; ?></span></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$past): ?>
                        <tr><td colspan="9" class="text-muted">No past rides match the current filters. <a class="btn btn-sm btn-secondary ms-2" href="events.php?view=past">Clear filters</a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
admin_layout_end();
