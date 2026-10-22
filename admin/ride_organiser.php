<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_once __DIR__ . '/table_sort.php';

// Preserve header filters, sorting and pagination through the quick-access views.
$listState = array_filter(array_intersect_key($_GET, array_flip(['q', 'period', 'venue_filter', 'organiser_filter', 'sort', 'dir', 'p', 'per_page'])), 'is_scalar');
$listQuery = http_build_query($listState);
$listUrl = 'ride_organiser.php?' . $listQuery;
$rides = array_values(array_filter(fetchEvents($pdo), static fn(array $ride): bool => canAccessRideOrganiserEvent($currentUser, $ride)));
$venueOptions = $organiserOptions = [];
foreach ($rides as $ride) {
    $venueName = trim((string)($ride['venue'] ?? ''));
    $organiserName = trim((string)($ride['organiser'] ?? ''));
    if ($venueName !== '') $venueOptions[$venueName] = $venueName;
    if ($organiserName !== '') $organiserOptions[$organiserName] = $organiserName;
}
natcasesort($venueOptions);
natcasesort($organiserOptions);
$formId = 'ride-organiser-filter-form';
$tableColumns = [
    'period' => ['label'=>'Date', 'field'=>'event_date', 'sortable'=>true, 'filter'=>'select', 'options'=>['upcoming'=>'Upcoming', 'past'=>'Past'], 'form'=>$formId,
        'filter_match'=>static function(array $ride, string $period): bool {
            $date = substr((string)(($ride['end_date'] ?? '') ?: ($ride['event_date'] ?? '')), 0, 10);
            return $period === 'past' ? $date < date('Y-m-d') : $date >= date('Y-m-d');
        }],
    'q' => ['label'=>'Event', 'field'=>'title', 'sortable'=>true, 'filter'=>'text', 'placeholder'=>'Search events', 'form'=>$formId],
    'venue_filter' => ['label'=>'Venue', 'field'=>'venue', 'sortable'=>true, 'filter'=>'select', 'options'=>$venueOptions, 'form'=>$formId],
    'organiser_filter' => ['label'=>'Organiser', 'field'=>'organiser', 'sortable'=>true, 'filter'=>'select', 'options'=>$organiserOptions, 'form'=>$formId],
    'actions' => ['label'=>'Quick access'],
];
$table = admin_table_prepare($rides, $tableColumns, 'period', 'asc');
$eventId = max(0, (int)($_GET['event_id'] ?? 0));
$showVenue = ($_GET['view'] ?? '') === 'venue';
$event = $showVenue && $eventId ? fetchEventById($pdo, $eventId) : null;
if ($event && !canAccessRideOrganiserEvent($currentUser, $event)) {
    http_response_code(403);
    exit('You do not have access to this event.');
}
admin_layout_start('Ride Organiser', 'ride_organiser');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">Ride Organiser</h1><?php if ($showVenue): ?><a class="btn btn-outline-secondary" href="<?php echo h($listUrl); ?>">Back to events</a><?php endif; ?></div>
<?php if ($showVenue): ?>
    <?php $venue = $event && !empty($event['venue_id']) ? fetchVenueById($pdo, (int)$event['venue_id']) : null; ?>
    <?php if (!$venue): ?><div class="alert alert-info">No venue details are available for this event.</div>
    <?php else: ?>
        <div class="card-soft p-4"><div class="d-flex justify-content-between gap-3 mb-3"><div><h2 class="h5"><?php echo h($venue['name']); ?></h2><?php if (trim((string)$event['title']) !== trim((string)$venue['name'])): ?><div class="text-muted"><?php echo h($event['title']); ?></div><?php endif; ?></div><button type="button" class="btn btn-outline-secondary d-print-none" onclick="window.print()">Print</button></div>
        <dl class="row mb-0">
        <?php foreach ($venue as $field => $value): if (in_array($field, ['created_at', 'name'], true)) continue; ?>
            <?php $label = ['id'=>'Venue ID', 'name'=>'Venue name', 'google_url'=>'Google Maps URL', 'internal_notes_html'=>'Private Venue Information', 'notes'=>'Notes', 'created_at'=>'Created', 'updated_at'=>'Last updated'][$field] ?? ucwords(str_replace('_', ' ', $field)); ?>
            <dt class="col-md-3 mb-3"><?php echo h($label); ?></dt><dd class="col-md-9 mb-3">
            <?php if (in_array($field, ['directions', 'notes', 'internal_notes_html'], true)): ?>
                <?php echo trim((string)$value) !== '' ? render_wysiwyg((string)$value) : '—'; ?>
            <?php else: ?><div style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo h((string)$value ?: '—'); ?></div><?php endif; ?>
            </dd>
        <?php endforeach; ?>
        </dl></div>
    <?php endif; ?>
<?php else: ?>
    <form method="get" id="ride-organiser-filter-form">
        <input type="hidden" name="sort" value="<?php echo h($table['sort_key']); ?>">
        <input type="hidden" name="dir" value="<?php echo h($table['sort_dir']); ?>">
        <input type="hidden" name="per_page" value="<?php echo (int)$table['pagination']['per_page']; ?>">
    </form>
    <div class="card-soft p-3 table-responsive">
    <?php echo admin_table_record_count($table, 'event', 'events'); ?>
    <table class="table align-middle"><thead class="table-light"><tr>
        <?php foreach ($tableColumns as $key=>$column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
    </tr><tr class="admin-table-filter-row">
        <?php foreach ($tableColumns as $key=>$column): ?><th>
            <?php echo admin_table_filter($key, $column, $table['filters']); ?>
            <?php if ($key === 'actions'): ?><button class="btn btn-sm btn-outline-secondary" form="ride-organiser-filter-form">Filter</button> <a class="btn btn-sm btn-link" href="ride_organiser.php">Clear</a><?php endif; ?>
        </th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($table['rows'] as $ride):
        $id = (int)$ride['id'];
        $context = http_build_query(['organiser' => 1, 'return_query' => $listQuery]);
    ?>
    <tr><td><?php echo h(format_display_date($ride['event_date'] ?? null, '—')); ?></td><td class="fw-semibold"><?php echo h($ride['title']); ?></td><td><?php echo h($ride['venue'] ?? '—'); ?></td><td><?php echo h($ride['organiser'] ?? '—'); ?></td><td><div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-success" href="event_entries.php?event_id=<?php echo $id; ?>&amp;<?php echo h($context); ?>">Entry List</a>
        <a class="btn btn-sm btn-outline-primary" href="../ride_notes.php?event_id=<?php echo $id; ?>&amp;preview=1" target="_blank" rel="noopener">Rider Notes</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($listUrl . '&view=venue&event_id=' . $id); ?>">Venue details</a>
    </div></td></tr>
    <?php endforeach; ?>
    <?php if (!$table['rows']): ?><tr><td colspan="5" class="text-muted">No events match these filters.</td></tr><?php endif; ?>
    </tbody></table>
    <?php echo admin_table_pagination($table); ?>
    </div>
<?php endif; ?>
<?php admin_layout_end(); ?>
