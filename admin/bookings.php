<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$bookings = load_all_bookings($pdo);
$eventTypes = fetchEventTypes($pdo);
$allEvents = fetchEvents($pdo, false);
$eventTypeLabels = [];
foreach ($eventTypes as $type) {
    $key = strtolower((string)($type['name'] ?? ''));
    if ($key !== '') {
        $eventTypeLabels[$key] = $type['name'];
    }
}
$eventsByType = [];
foreach ($allEvents as $ev) {
    $slug = strtolower((string)($ev['event_type_name'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $eventsByType[$slug][] = $ev;
}

$sortKey = $_GET['sort'] ?? 'placed';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$filterType = strtolower(trim((string)($_GET['type'] ?? 'all')));
$filterEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($filterType === '') {
    $filterType = 'all';
}
$activeFilterLabel = $filterType === 'all' ? '' : ($eventTypeLabels[$filterType] ?? ucfirst($filterType));

$eventsAll = fetchEvents($pdo, false);
$eventTitleMap = [];
foreach ($eventsAll as $ev) {
    $eventTitleMap[(int)($ev['id'] ?? 0)] = $ev['title'] ?? '';
}

function booking_type_slug(array $item): string
{
    $slug = strtolower((string)($item['booking_type'] ?? $item['event_type_name'] ?? ''));
    return $slug !== '' ? $slug : 'unknown';
}

function booking_has_membership_item(array $booking): bool
{
    foreach ($booking['items'] ?? [] as $item) {
        if (booking_type_slug($item) === 'membership') {
            return true;
        }
    }
    return false;
}

function booking_matches_type(array $booking, string $filterType): bool
{
    if ($filterType === 'all') {
        return true;
    }
    foreach ($booking['items'] ?? [] as $item) {
        if (booking_type_slug($item) === $filterType) {
            return true;
        }
    }
    return false;
}

function booking_matches_event(array $booking, int $eventId): bool
{
    if ($eventId <= 0) {
        return true;
    }
    foreach ($booking['items'] ?? [] as $item) {
        if ((int)($item['event_id'] ?? 0) === $eventId) {
            return true;
        }
    }
    return false;
}

function booking_type_label(array $item, array $labelMap): string
{
    $slug = booking_type_slug($item);
    if (isset($labelMap[$slug])) {
        return $labelMap[$slug];
    }
    if (!empty($item['booking_type_label'])) {
        return (string)$item['booking_type_label'];
    }
    $raw = $item['booking_type'] ?? ($item['event_type_name'] ?? $slug);
    return ucfirst((string)$raw);
}

function booking_sort_value(array $booking, string $key)
{
    switch ($key) {
        case 'id':
            return (string)($booking['booking_ref'] ?? $booking['id'] ?? '');
        case 'placed':
            return $booking['created_at'] ? strtotime((string)$booking['created_at']) : 0;
        case 'contact':
            return strtolower(trim((string)($booking['contact_name'] ?? $booking['contact_email'] ?? '')));
        case 'entries':
            return count($booking['items'] ?? []);
        case 'total':
            return (float)($booking['total'] ?? 0);
        case 'user':
            $userId = $booking['user_id'] ?? '';
            $email = $booking['contact_email'] ?? '';
            return strtolower(trim(($userId !== '' ? (string)$userId . ' ' : '') . $email));
        default:
            return (string)($booking['created_at'] ?? '');
    }
}

$typeCounts = [];
foreach ($eventTypeLabels as $slug => $_label) {
    $typeCounts[$slug] = count($eventsByType[$slug] ?? []);
}

// Bookings view is for event bookings only; membership purchases are managed in Members.
$bookings = array_values(array_filter($bookings, fn($booking) => !booking_has_membership_item($booking)));

$typeFilters = [['slug' => 'all', 'label' => 'All bookings', 'count' => count($bookings)]];
foreach ($eventTypeLabels as $slug => $label) {
    $typeFilters[] = [
        'slug' => $slug,
        'label' => $label,
        'count' => $typeCounts[$slug] ?? 0,
    ];
}

$bookings = array_values(array_filter($bookings, fn($booking) => booking_matches_type($booking, $filterType) && booking_matches_event($booking, $filterEventId)));

usort($bookings, function ($a, $b) use ($sortKey, $sortDir) {
    $va = booking_sort_value($a, $sortKey);
    $vb = booking_sort_value($b, $sortKey);
    if ($va == $vb) {
        return 0;
    }
    return $sortDir === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
});

$showingCount = $filterType === 'all' ? count($bookings) : count($eventsByType[$filterType] ?? []);

admin_layout_start('Bookings', 'bookings');
?>
<div class="card-soft p-4">
    <style>
        #bookingsTable tr.booking-row + tr.booking-row td {
            border-top: 2px solid #e0e5dd;
        }
        #bookingsTable tr.booking-row:first-of-type td {
            border-top: none;
        }
        #bookingsTable tr.booking-row {
            border-top: 2px solid #e0e5dd;
        }
        #bookingsTable tr.booking-row td {
            padding-top: 0.9rem;
            padding-bottom: 0.9rem;
            border-bottom: 0 !important; /* no line between booking header and entries */
        }
        #bookingsTable tr.event-row td {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
            border-top: none;
        }
        #bookingsTable tr.event-row td,
        #bookingsTable tr.event-row:first-of-type td {
            border-top: 0 !important;
            border-bottom: 0 !important;
        }
    </style>
    <style>
        /* Make child event rows feel subordinate to the booking summary */
        #bookingsTable .event-row td {
            font-size: 0.95rem;
            color: #444;
            background-color: transparent;
            border-left: 0;
            padding-left: 0;
        }
        #bookingsTable .event-row .event-cell {
            padding-left: 1.5rem;
        }
        #bookingsTable .event-row .event-title {
            font-weight: 600;
            color: #212529;
            margin-right: 0.35rem;
        }
        #bookingsTable .event-row .event-details {
            color: #6c757d;
        }
        @media (max-width: 767.98px) {
            #bookingsTable th.col-contact,
            #bookingsTable td.col-contact,
            #bookingsTable th.col-user,
            #bookingsTable td.col-user {
                display: none;
            }
            #bookingsTable .event-row .event-cell {
                padding-left: 0.75rem;
            }
        }
    </style>
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <div class="fw-bold mb-1">Bookings</div>
            <div class="text-muted small">
                Showing <?php echo $showingCount; ?><?php echo $filterType !== 'all' ? ' filtered by ' . h($activeFilterLabel) : ''; ?><?php if ($filterEventId > 0): ?> for event "<?php echo h($eventTitleMap[$filterEventId] ?? ('ID ' . $filterEventId)); ?>"<?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter by type">
                <?php foreach ($typeFilters as $filter): ?>
                    <?php
                        $isActive = $filterType === strtolower((string)$filter['slug']);
                        $query = ['sort' => $sortKey, 'dir' => $sortDir];
                        if ($filter['slug'] !== 'all') {
                            $query['type'] = $filter['slug'];
                        }
                        $countBadge = $filter['count'] ? ' (' . (int)$filter['count'] . ')' : '';
                    ?>
                    <a class="btn btn-outline-success<?php echo $isActive ? ' active' : ''; ?>" href="?<?php echo h(http_build_query($query)); ?>">
                        <?php echo h($filter['label']); ?><?php echo $countBadge; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

<?php if (!$bookings): ?>
    <div class="alert alert-info mb-0">No bookings to show<?php echo $filterType !== 'all' ? ' for this type.' : '.'; ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="bookingsTable">
            <thead class="table-light">
                <tr>
                    <th><?php echo admin_sort_link('id', 'Booking ref', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('placed', 'Placed', (string)$sortKey, (string)$sortDir); ?></th>
                    <th class="col-contact"><?php echo admin_sort_link('contact', 'Contact', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('entries', 'Events', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('total', 'Total', (string)$sortKey, (string)$sortDir); ?></th>
                    <th class="col-user"><?php echo admin_sort_link('user', 'User', (string)$sortKey, (string)$sortDir); ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                    <?php
                        $items = $booking['items'] ?? [];
                        $itemCount = count($items);
                        $total = isset($booking['total']) ? format_price($booking['total']) : '£0.00';
                        $bookingRef = $booking['booking_ref'] ?? $booking['id'] ?? '';
                    ?>
                    <tr class="booking-row">
                        <td class="small fw-semibold text-muted text-dark"><?php echo h($bookingRef); ?></td>
                        <td class="small"><?php echo h(format_display_datetime($booking['created_at'] ?? null, '')); ?></td>
                        <td class="small col-contact">
                            <div><?php echo h($booking['contact_name'] ?? ''); ?></div>
                        </td>
                        <td class="small"><div class="fw-semibold text-dark mb-1"><?php echo $itemCount; ?> event<?php echo $itemCount === 1 ? '' : 's'; ?></div></td>
                        <td class="small"><?php echo h($total); ?></td>
                        <td class="small col-user">
                            <?php echo h($booking['contact_email'] ?? ''); ?>
                        </td>
                        <td></td>
                    </tr>
                    <?php foreach ($items as $item): ?>
                        <?php
                            $eventTitle = trim((string)($item['event_title'] ?? $item['event_name'] ?? 'Event'));
                            $eventTypeLabel = booking_type_label($item, $eventTypeLabels);
                            $eventPrice = format_price($item['price'] ?? 0);
                            $quickFields = $item['quick_view_fields'] ?? [];
                            $meta = $item['metadata'] ?? [];
                            $detailParts = [];
                            foreach ($quickFields as $fieldKey) {
                                $value = $meta[$fieldKey] ?? ($item[$fieldKey] ?? '');
                                if ($value === '' || $value === null) {
                                    continue;
                                }
                                $label = ucwords(str_replace('_', ' ', (string)$fieldKey));
                                $detailParts[] = $label . ': ' . $value;
                            }
                            $detailText = implode(' · ', $detailParts);
                            $itemId = (int)($item['id'] ?? 0);
                            $eventId = (int)($item['event_id'] ?? 0);
                            $entryUrl = $itemId > 0 ? ('entry_item.php?item_id=' . $itemId . ($eventId > 0 ? '&event_id=' . $eventId : '')) : '';
                            $entryEditUrl = $itemId > 0 ? ('entry_item.php?item_id=' . $itemId . '&mode=edit' . ($eventId > 0 ? '&event_id=' . $eventId : '')) : '';
                        ?>
                        <tr class="event-row">
                            <td></td>
                            <td class="event-cell" colspan="5">
                                <span class="event-title"><?php echo h($eventTitle); ?></span>
                                <span class="event-details">
                                    · <?php echo h($eventTypeLabel); ?> · <?php echo h($eventPrice); ?>
                                    <?php if ($detailText !== ''): ?>
                                        · <?php echo h($detailText); ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php if ($entryUrl !== ''): ?>
                                    <div class="btn-group-mobile justify-content-end">
                                        <a class="btn btn-sm btn-outline-secondary has-icon" href="<?php echo h($entryUrl); ?>"><i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View</span></a>
                                        <a class="btn btn-sm btn-outline-success has-icon" href="<?php echo h($entryEditUrl); ?>"><i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span></a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
<?php
admin_layout_end();
