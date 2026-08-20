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

function booking_description(array $booking): string
{
    $descriptions = [];
    foreach ($booking['items'] ?? [] as $item) {
        $meta = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $details = [];
        $type = booking_type_slug($item);
        if ($type === 'horse_logbook') {
            $horseName = trim((string)($meta['horse_name'] ?? $item['horse_name'] ?? ''));
            if ($horseName !== '') $details[] = 'Horse: ' . $horseName;
        } elseif ($type === 'membership') {
            $memberName = trim((string)($meta['member_name'] ?? $item['member_name'] ?? ''));
            if ($memberName !== '') $details[] = 'Member: ' . $memberName;
        } else {
            foreach (['rider_name' => 'Rider', 'horse_name' => 'Horse', 'class_label' => 'Class'] as $key => $label) {
                $value = trim((string)($meta[$key] ?? $item[$key] ?? ''));
                if ($value !== '') $details[] = $label . ': ' . $value;
            }
        }
        $details[] = 'Value: ' . format_price($item['price'] ?? 0);
        if ($details) $descriptions[] = implode("\n", $details);
    }
    return implode("\n", $descriptions);
}

function booking_event_names(array $booking): string
{
    $names = [];
    foreach ($booking['items'] ?? [] as $item) {
        $type = booking_type_slug($item);
        if ($type === 'horse_logbook') {
            $name = 'Horse Logbook';
        } elseif ($type === 'membership') {
            $name = 'Membership';
        } else {
            $name = trim((string)($item['event_title'] ?? $item['event_name'] ?? ''));
        }
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    return implode(' | ', $names);
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

$userOptions = [];
$transactionTypeOptions = [];
foreach ($bookings as $booking) {
    $email = trim((string)($booking['contact_email'] ?? ''));
    if ($email !== '') $userOptions[$email] = $email;
    $transactionType = booking_event_names($booking);
    if ($transactionType !== '') $transactionTypeOptions[$transactionType] = $transactionType;
}
natcasesort($userOptions);
natcasesort($transactionTypeOptions);
$filterForm = 'booking-filter-form';
$tableColumns = [
    'id' => ['label'=>'Ref','sortable'=>true,'filter'=>'text','placeholder'=>'Search ref','form'=>$filterForm,'value'=>static fn(array $r):string=>(string)($r['booking_ref']??$r['id']??'')],
    'placed' => ['label'=>'Ordered','sortable'=>true,'filter'=>'text','placeholder'=>'Search ordered','form'=>$filterForm,'value'=>static fn(array $r):string=>format_display_datetime($r['created_at']??null,''),'sort_value'=>static fn(array $r):int=>!empty($r['created_at'])?(int)strtotime((string)$r['created_at']):0,'compare'=>'number'],
    'contact' => ['label'=>'Contact','sortable'=>true,'filter'=>'text','placeholder'=>'Search contact','form'=>$filterForm,'value'=>static fn(array $r):string=>(string)($r['contact_name']??'')],
    'event_name' => ['label'=>'Type','sortable'=>false,'filter'=>'select','form'=>$filterForm,'options'=>$transactionTypeOptions,'value'=>static fn(array $r):string=>booking_event_names($r)],
    'description' => ['label'=>'Description','sortable'=>false,'filter'=>'text','placeholder'=>'Search description','form'=>$filterForm,'value'=>static fn(array $r):string=>booking_description($r)],
    'entries' => ['label'=>'Trans #','sortable'=>true,'filter'=>'text','placeholder'=>'Search count','form'=>$filterForm,'value'=>static fn(array $r):string=>(string)count($r['items']??[]),'sort_value'=>static fn(array $r):int=>count($r['items']??[]),'compare'=>'number'],
    'total' => ['label'=>'Total','sortable'=>true,'filter'=>'text','placeholder'=>'Search total','form'=>$filterForm,'value'=>static fn(array $r):string=>format_price($r['total']??0),'sort_value'=>static fn(array $r):float=>(float)($r['total']??0),'compare'=>'number'],
    'user' => ['label'=>'User','sortable'=>true,'filter'=>'select','form'=>$filterForm,'options'=>$userOptions,'value'=>static fn(array $r):string=>(string)($r['contact_email']??'')],
    'actions' => ['label'=>'Actions'],
];
$table = admin_table_prepare($bookings, $tableColumns, 'placed', 'desc');
$bookings = $table['rows'];
$filters = $table['filters'];
$sortKey = $table['sort_key'];
$sortDir = $table['sort_dir'];
$showingCount = (int)$table['pagination']['total'];

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
            padding-top: 0.3rem;
            padding-bottom: 0.3rem;
        }
        #bookingsTable .description-lines {
            font-size: 0.78rem;
            line-height: 1.25;
            min-width: 18rem;
            width: 30%;
        }
        #bookingsTable .col-contact {
            width: 7rem;
            min-width: 7rem;
            line-height: 1.2;
        }
        #bookingsTable .col-ref {
            white-space: nowrap;
        }
        #bookingsTable .booking-actions {
            vertical-align: top;
            text-align: left;
            white-space: nowrap;
            padding-top: 0.45rem;
        }
        #bookingsTable .booking-actions .btn-group-mobile {
            justify-content: flex-start;
            margin-bottom: 0.3rem;
        }
        @media (max-width: 767.98px) {
            #bookingsTable th.col-contact,
            #bookingsTable td.col-contact,
            #bookingsTable th.col-event-name,
            #bookingsTable td.col-event-name,
            #bookingsTable th.col-description,
            #bookingsTable td.col-description,
            #bookingsTable th.col-user,
            #bookingsTable td.col-user {
                display: none;
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

<form method="get" id="<?php echo h($filterForm); ?>" class="mb-2 text-end">
    <?php if ($filterType !== 'all'): ?><input type="hidden" name="type" value="<?php echo h($filterType); ?>"><?php endif; ?>
    <?php if ($filterEventId > 0): ?><input type="hidden" name="event_id" value="<?php echo $filterEventId; ?>"><?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary">Filter</button>
    <a class="btn btn-sm btn-link" href="bookings.php<?php echo $filterType !== 'all' || $filterEventId > 0 ? '?' . h(http_build_query(array_filter(['type'=>$filterType !== 'all' ? $filterType : null, 'event_id'=>$filterEventId > 0 ? $filterEventId : null]))) : ''; ?>">Clear</a>
</form>
    <?php echo admin_table_pagination($table); ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle" id="bookingsTable">
            <thead class="table-light">
                <tr>
                    <th class="col-ref"><?php echo admin_table_heading('id', $tableColumns['id'], $sortKey, $sortDir); ?></th>
                    <th><?php echo admin_table_heading('placed', $tableColumns['placed'], $sortKey, $sortDir); ?></th>
                    <th class="col-contact"><?php echo admin_table_heading('contact', $tableColumns['contact'], $sortKey, $sortDir); ?></th>
                    <th class="col-event-name"><?php echo admin_table_heading('event_name', $tableColumns['event_name'], $sortKey, $sortDir); ?></th>
                    <th class="col-description"><?php echo admin_table_heading('description', $tableColumns['description'], $sortKey, $sortDir); ?></th>
                    <th><?php echo admin_table_heading('entries', $tableColumns['entries'], $sortKey, $sortDir); ?></th>
                    <th><?php echo admin_table_heading('total', $tableColumns['total'], $sortKey, $sortDir); ?></th>
                    <th class="col-user"><?php echo admin_table_heading('user', $tableColumns['user'], $sortKey, $sortDir); ?></th>
                    <th class="text-end">Actions</th>
                </tr>
                <tr class="admin-table-filter-row">
                    <th class="col-ref"><?php echo admin_table_filter('id', $tableColumns['id'], $filters); ?></th>
                    <th><?php echo admin_table_filter('placed', $tableColumns['placed'], $filters); ?></th>
                    <th class="col-contact"><?php echo admin_table_filter('contact', $tableColumns['contact'], $filters); ?></th>
                    <th class="col-event-name"><?php echo admin_table_filter('event_name', $tableColumns['event_name'], $filters); ?></th>
                    <th class="col-description"><?php echo admin_table_filter('description', $tableColumns['description'], $filters); ?></th>
                    <th><?php echo admin_table_filter('entries', $tableColumns['entries'], $filters); ?></th>
                    <th><?php echo admin_table_filter('total', $tableColumns['total'], $filters); ?></th>
                    <th class="col-user"><?php echo admin_table_filter('user', $tableColumns['user'], $filters); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                    <?php
                        $items = $booking['items'] ?? [];
                        $itemCount = count($items);
                        $total = isset($booking['total']) ? format_price($booking['total']) : '£0.00';
                        $bookingRef = $booking['booking_ref'] ?? $booking['id'] ?? '';
                        $eventNames = booking_event_names($booking);
                        $description = booking_description($booking);
                        $contactName = trim((string)($booking['contact_name'] ?? ''));
                        $contactParts = preg_split('/\s+/', $contactName, 2) ?: [];
                    ?>
                    <tr class="booking-row">
                        <td class="small fw-semibold text-muted text-dark col-ref"><?php echo h($bookingRef); ?></td>
                        <td class="small"><?php echo h(format_display_datetime($booking['created_at'] ?? null, '')); ?></td>
                        <td class="small col-contact">
                            <div><?php echo h((string)($contactParts[0] ?? '')); ?><?php if (!empty($contactParts[1])): ?><br><?php echo h((string)$contactParts[1]); ?><?php endif; ?></div>
                        </td>
                        <td class="small col-event-name fw-semibold text-dark"><?php echo h($eventNames !== '' ? $eventNames : '—'); ?></td>
                        <td class="col-description text-muted description-lines"><?php echo $description !== '' ? nl2br(h($description)) : '—'; ?></td>
                        <td class="small"><div class="fw-semibold text-dark"><?php echo $itemCount; ?></div></td>
                        <td class="small"><?php echo h($total); ?></td>
                        <td class="small col-user">
                            <?php echo h($booking['contact_email'] ?? ''); ?>
                        </td>
                        <td class="booking-actions">
                            <?php foreach ($items as $actionItem): ?>
                                <?php
                                    $actionItemId = (int)($actionItem['id'] ?? 0);
                                    $actionEventId = (int)($actionItem['event_id'] ?? 0);
                                    $entryUrl = $actionItemId > 0 ? ('entry_item.php?item_id=' . $actionItemId . ($actionEventId > 0 ? '&event_id=' . $actionEventId : '')) : '';
                                    $entryEditUrl = $actionItemId > 0 ? ('entry_item.php?item_id=' . $actionItemId . '&mode=edit' . ($actionEventId > 0 ? '&event_id=' . $actionEventId : '')) : '';
                                ?>
                                <?php if ($entryUrl !== ''): ?>
                                    <div class="btn-group-mobile">
                                        <a class="btn btn-sm btn-outline-secondary has-icon" href="<?php echo h($entryUrl); ?>"><i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View</span></a>
                                        <a class="btn btn-sm btn-outline-success has-icon" href="<?php echo h($entryEditUrl); ?>"><i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span></a>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$bookings): ?>
                    <tr><td colspan="9" class="text-muted">No bookings match the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
admin_layout_end();
