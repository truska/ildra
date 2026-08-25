<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$eventsReturnUrl = (string)($_SESSION['admin_list_returns']['events'] ?? 'events.php');
if (!preg_match('/^events\.php(?:\?[^#]*)?$/', $eventsReturnUrl)) $eventsReturnUrl = 'events.php';
$eventsReturnWithRow = $eventsReturnUrl . ($eventId > 0 ? '#event-' . $eventId : '');
$event = $eventId ? fetchEventById($pdo, $eventId) : null;
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$eventTypes = fetchEventTypes($pdo);
$venues = fetchVenues($pdo);
$eligibleOrganisers = fetchEligibleEventOrganisers($pdo);
$eventSettings = getSiteSettings($pdo);
$existingTypeId = is_array($event) ? (int)($event['event_type_id'] ?? 0) : 0;
$existingTypeName = is_array($event) ? (string)($event['event_type_name'] ?? '') : '';
$defaultEventType = findEventType($eventTypes, $existingTypeId, $existingTypeName);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage events.'];
    } else {
        // Pricing rows (per-event copy of a reusable pricing scheme)
        ensurePricingSchemeTables($pdo);
        ensureEventPricingTables($pdo);
        ensureDefaultPricingSchemes($pdo);

        $originalEventTypeId = is_array($event) ? (int)($event['event_type_id'] ?? 0) : 0;
        $newEventTypeId = (int)(findEventType($eventTypes, (int)($_POST['event_type_id'] ?? 0))['id'] ?? 0);
        $typeChanged = $originalEventTypeId > 0 && $newEventTypeId > 0 && $originalEventTypeId !== $newEventTypeId;

        // Validate pricing rows unless the event type is changing (type change always resets to defaults).
        $pricingRows = [];
        if (!$typeChanged) {
            $pricingRows = parseEventPricingRowsFromPost($_POST, $alerts);
            if (!$pricingRows) {
                // Don't save the event if the pricing rows are invalid/empty.
                // This keeps "Classes" required for public entry forms.
                $eventId = $eventId > 0 ? $eventId : 0;
            }
        }

        if ($typeChanged || $pricingRows) {
            $savedEventId = saveEvent($pdo, $_POST, $alerts);
            if ($savedEventId) {
                $savedEventId = (int)$savedEventId;

                if ($typeChanged) {
                    $schemeId = fetchDefaultPricingSchemeIdForEventType($pdo, $newEventTypeId);
                    if ($schemeId) {
                        copyPricingSchemeToEvent($pdo, $schemeId, $savedEventId);
                    } else {
                        $alerts[] = ['type' => 'danger', 'message' => 'No default pricing scheme is set for this event type.'];
                    }
                } else {
                    replaceEventPricingRows($pdo, $savedEventId, $pricingRows);
                }

                // Keep legacy classes_offered synced for the existing public entry form.
                syncEventClassesOfferedFromPricingRows($pdo, $savedEventId);

                $entryFormPosted = json_decode($_POST['entry_form_json'] ?? '[]', true);
                $componentEnabled = [];
                if (is_array($entryFormPosted)) {
                    foreach ($entryFormPosted as $block) {
                        if (!is_array($block)) {
                            continue;
                        }
                        if (($block['type'] ?? '') === 'component' && !empty($block['enabled'])) {
                            $cid = (int)($block['component_id'] ?? 0);
                            if ($cid > 0) {
                                $componentEnabled[$cid] = 1;
                            }
                        }
                    }
                }
                $_POST['component_enabled'] = $componentEnabled;
                $componentAlerts = [];
                saveEventEntryComponents($pdo, (int)$savedEventId, $_POST, $componentAlerts);
                $alerts = array_merge($alerts, $componentAlerts);

                // Only show success if there are no blocking errors from the pricing logic.
                $hasDanger = false;
                foreach ($alerts as $a) {
                    if (($a['type'] ?? '') === 'danger') { $hasDanger = true; break; }
                }
                if (!$hasDanger) {
                    $_SESSION['flash_success'] = 'Event saved.';
                    header('Location: event_edit.php?id=' . (int)$savedEventId);
                    exit;
                }
            }
        }
    }
}

$event = $event ?? [
    'id' => 0,
    'title' => '',
    'event_date' => '',
    'end_date' => '',
    'start_time' => (string)$eventSettings['event_default_start_time'],
    'end_time' => (string)$eventSettings['event_default_end_time'],
    'venue' => '',
    'venue_id' => 0,
    'organiser' => '',
    'organiser_user_id' => 0,
    'classes_offered' => '',
    'entry_open_at' => null,
    'non_member_entry_open_at' => null,
    'entry_close_at' => null,
    'status' => 'draft',
    'description' => '',
    'event_type' => $defaultEventType['name'] ?? 'Ride',
    'event_type_id' => $defaultEventType['id'] ?? null,
    'capacity_enabled' => 0,
    'capacity_limit' => 50,
];
$event['venue'] = $event['venue'] ?: ($event['venue_name'] ?? '');
$selectedEventType = findEventType($eventTypes, (int)($event['event_type_id'] ?? 0), (string)($event['event_type_name'] ?? $event['event_type'] ?? 'ride'));
$event['event_type'] = $selectedEventType['name'] ?? ($event['event_type'] ?? 'ride');
$event['event_type_id'] = $selectedEventType['id'] ?? ($event['event_type_id'] ?? null);
$event['event_type_label'] = $selectedEventType['name'] ?? ucfirst((string)($event['event_type'] ?? 'ride'));
$eventTypeSelectedId = (int)($event['event_type_id'] ?? 0);
$selectedVenueId = (int)($event['venue_id'] ?? 0);
$entryComponentsAll = fetchEntryComponents($pdo, $eventTypeSelectedId, true);
$eventComponents = $eventId ? fetchEventEntryComponents($pdo, $eventId, $eventTypeSelectedId) : [];
$eventComponentsById = [];
foreach ($eventComponents as $comp) {
    $eventComponentsById[(int)($comp['id'] ?? 0)] = $comp;
}
$entryFormConfig = event_entry_form($event, $eventComponents);
$entryOpenAt = $event['entry_open_at'] ?? null;
$nonMemberEntryOpenAt = $event['non_member_entry_open_at'] ?? null;
$entryCloseAt = $event['entry_close_at'] ?? null;
$entryOpenDate = $entryOpenAt ? date('Y-m-d', strtotime((string)$entryOpenAt)) : '';
$entryOpenTime = $entryOpenAt ? date('H:i', strtotime((string)$entryOpenAt)) : '';
$nonMemberEntryOpenDate = $nonMemberEntryOpenAt ? date('Y-m-d', strtotime((string)$nonMemberEntryOpenAt)) : '';
$nonMemberEntryOpenTime = $nonMemberEntryOpenAt ? date('H:i', strtotime((string)$nonMemberEntryOpenAt)) : '';
$entryCloseDate = $entryCloseAt ? date('Y-m-d', strtotime((string)$entryCloseAt)) : '';
$entryCloseTime = $entryCloseAt ? date('H:i', strtotime((string)$entryCloseAt)) : '';
$scheduleDefaults = $event['event_date'] ? event_date_defaults((string)$event['event_date'], $eventSettings) : null;
$entryOpenDefaultDate = (string)($scheduleDefaults['entry_open_date'] ?? '');
$nonMemberEntryOpenDefaultDate = (string)($scheduleDefaults['non_member_entry_open_date'] ?? '');
$entryCloseDefaultDate = (string)($scheduleDefaults['entry_close_date'] ?? '');
$displayOpenDate = $entryOpenDate ?: $entryOpenDefaultDate;
$displayNonMemberOpenDate = $nonMemberEntryOpenDate ?: $nonMemberEntryOpenDefaultDate;
$displayCloseDate = $entryCloseDate ?: $entryCloseDefaultDate;
$displayOpenTime = $entryOpenTime ?: (string)$eventSettings['event_member_open_time'];
$displayNonMemberOpenTime = $nonMemberEntryOpenTime ?: (string)$eventSettings['event_non_member_open_time'];
$displayCloseTime = $entryCloseTime ?: (string)$eventSettings['event_entry_close_time'];
$weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Pricing schemes + per-event pricing rows (Option A: classes live inside schemes, events get a copy)
ensurePricingSchemeTables($pdo);
ensureEventPricingTables($pdo);
ensureDefaultPricingSchemes($pdo);

// Default scheme rows per event type (used for client-side reset when event type changes).
$defaultPricingRowsByType = [];
foreach ($eventTypes as $t) {
    $tid = (int)($t['id'] ?? 0);
    if ($tid <= 0) {
        continue;
    }
    $sid = fetchDefaultPricingSchemeIdForEventType($pdo, $tid);
    if (!$sid) {
        continue;
    }
    $rows = fetchPricingSchemeRows($pdo, (int)$sid);
        $defaultPricingRowsByType[$tid] = array_values(array_map(static function (array $r): array {
            return [
                'sort_order' => (int)($r['sort_order'] ?? 10),
                'class_code' => (string)($r['class_code'] ?? ''),
                'class_group' => (string)($r['class_group'] ?? ''),
                'class_name' => (string)($r['class_name'] ?? ''),
                'price' => (string)($r['price'] ?? '0'),
                'foreign_recognition_price' => $r['foreign_recognition_price'] !== null ? (string)$r['foreign_recognition_price'] : '',
                'is_member_price' => !empty($r['is_member_price']) ? 1 : 0,
                'is_junior_ride' => !empty($r['is_junior_ride']) ? 1 : 0,
                'enabled' => 1,
            ];
        }, $rows));
}

// Pricing rows for this event (persisted copy), or defaults for new events.
$eventPricingRows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $names = (array)($_POST['event_row_class_name'] ?? []);
    $codes = (array)($_POST['event_row_class_code'] ?? []);
    $groups = (array)($_POST['event_row_class_group'] ?? []);
    $prices = (array)($_POST['event_row_price'] ?? []);
    $foreignPrices = (array)($_POST['event_row_foreign_recognition_price'] ?? []);
    $sort = (array)($_POST['event_row_sort'] ?? []);
    $member = (array)($_POST['event_row_is_member_price'] ?? []);
    $junior = (array)($_POST['event_row_is_junior_ride'] ?? []);
    $enabled = (array)($_POST['event_row_enabled'] ?? []);
    foreach (array_keys($names) as $key) {
        $eventPricingRows[] = [
            'id' => 0,
            'sort_order' => (int)($sort[$key] ?? 0),
            'class_code' => (string)($codes[$key] ?? ''),
            'class_group' => (string)($groups[$key] ?? ''),
            'class_name' => (string)($names[$key] ?? ''),
            'price' => (string)($prices[$key] ?? '0'),
            'foreign_recognition_price' => (string)($foreignPrices[$key] ?? ''),
            'is_member_price' => !empty($member[$key]) ? 1 : 0,
            'is_junior_ride' => !empty($junior[$key]) ? 1 : 0,
            'enabled' => !empty($enabled[$key]) ? 1 : 0,
        ];
    }
} elseif ($eventId > 0) {
    $eventPricingRows = fetchEventPricingRows($pdo, $eventId);
    if (!$eventPricingRows) {
        migrateEventClassesOfferedToPricingRows($pdo, $eventId);
        $eventPricingRows = fetchEventPricingRows($pdo, $eventId);
    }
    if (!$eventPricingRows) {
        $sid = fetchDefaultPricingSchemeIdForEventType($pdo, $eventTypeSelectedId);
        if ($sid) {
            copyPricingSchemeToEvent($pdo, (int)$sid, $eventId);
            syncEventClassesOfferedFromPricingRows($pdo, $eventId);
            $eventPricingRows = fetchEventPricingRows($pdo, $eventId);
        }
    }
} else {
    $sid = fetchDefaultPricingSchemeIdForEventType($pdo, $eventTypeSelectedId);
    if ($sid) {
        $rows = fetchPricingSchemeRows($pdo, (int)$sid);
        foreach ($rows as $r) {
            $eventPricingRows[] = [
                'id' => 0,
                'sort_order' => (int)($r['sort_order'] ?? 10),
                'class_code' => (string)($r['class_code'] ?? ''),
                'class_group' => (string)($r['class_group'] ?? ''),
                'class_name' => (string)($r['class_name'] ?? ''),
                'price' => (string)($r['price'] ?? '0'),
                'foreign_recognition_price' => $r['foreign_recognition_price'] !== null ? (string)$r['foreign_recognition_price'] : '',
                'is_member_price' => !empty($r['is_member_price']) ? 1 : 0,
                'is_junior_ride' => !empty($r['is_junior_ride']) ? 1 : 0,
                'enabled' => 1,
            ];
        }
    }
}

admin_layout_start($eventId ? 'Edit Event' : 'Add Event', 'events');
?>
<style>
    .section-card { background: #fff; border: 1px solid rgba(0,0,0,0.06); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1rem; box-shadow: 0 8px 20px rgba(0,0,0,0.04);}
    .section-title { font-weight: 700; margin-bottom: 0.25rem; }
    .section-sub { color: #6c757d; margin-bottom: 0.75rem; font-size: 0.95rem;}
    .fixed-actions { position: sticky; bottom: 0; background: rgba(245,247,243,0.92); border-top: 1px solid rgba(0,0,0,0.06); padding: 0.75rem 0; backdrop-filter: blur(4px); }
    .pricing-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .pricing-table th { font-size: 0.85rem; letter-spacing: .02em; text-transform: uppercase; color: rgba(0,0,0,.55); background: rgba(0,0,0,.02); border-bottom: 1px solid rgba(0,0,0,.08); padding: 0.55rem; }
    .pricing-table td { border-bottom: 1px solid rgba(0,0,0,.06); padding: 0.5rem; vertical-align: middle; }
    .pricing-table .compact { width: 1%; white-space: nowrap; }
    .pricing-table .price-input { width: 120px; }
    .pricing-table .code-input { width: 160px; }
    .pricing-table .name-input { min-width: 240px; }
    .pricing-table .row-muted { color: rgba(0,0,0,.5); }
    .pricing-table .remove-btn { white-space: nowrap; }
    .component-row { border: 1px solid rgba(0,0,0,0.06); border-radius: 8px; padding: 0.75rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 1rem; align-items: center; }
    .component-row .meta { font-size: 0.9rem; color: #6c757d; }
    .component-row .controls { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .component-grid { display: grid; gap: 0.75rem; }
    @media (max-width: 767.98px) {
        .section-card { padding: 1rem; }
        .fixed-actions { flex-direction: column; align-items: stretch; }
        .fixed-actions .btn { width: 100%; }
    }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <div class="small text-muted"><?php echo $eventId ? 'Edit event' : 'Add new event'; ?></div>
        <h5 class="mb-0"><?php echo $eventId ? h($event['title']) : 'New Event'; ?></h5>
    </div>
    <div class="admin-page-actions">
        <?php if ($eventId): ?>
            <?php $viewUrl = $siteBase . '/events/' . $eventId . '-' . slugify((string)($event['title'] ?? 'event')); ?>
            <a class="btn btn-outline-success has-icon" href="<?php echo h($viewUrl); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View event</span></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary has-icon" href="<?php echo h($eventsReturnWithRow); ?>"><i class="fa-solid fa-arrow-left btn-icon"></i><span class="btn-label">Back to list</span></a>
    </div>
</div>

<div class="card-soft p-4">
    <form method="POST">
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="event_id" value="<?php echo h((string)$event['id']); ?>">
        <input type="hidden" name="venue" id="venue_name_input" value="<?php echo h($event['venue']); ?>">

        <div class="section-card">
            <div class="section-title">Event Details</div>
            <div class="section-sub">Type, venue, title, and organiser</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Event type</label>
                    <select name="event_type_id" class="form-select">
                        <?php foreach ($eventTypes as $type): ?>
                            <?php $isSelected = $eventTypeSelectedId === (int)($type['id'] ?? 0); ?>
                            <option value="<?php echo (int)($type['id'] ?? 0); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                <?php echo h($type['name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Venue</label>
                    <select name="venue_id" id="venue_id" class="form-select">
                        <option value="0"<?php echo $selectedVenueId === 0 ? ' selected' : ''; ?>>Choose venue...</option>
                        <?php foreach ($venues as $v): ?>
                            <?php
                            $vid = (int)($v['id'] ?? 0);
                            $vName = (string)($v['name'] ?? '');
                            $vPostcode = (string)($v['postcode'] ?? '');
                            $selected = $selectedVenueId === $vid ? ' selected' : '';
                            $label = $vName . ($vPostcode ? " ({$vPostcode})" : '');
                            ?>
                            <option value="<?php echo $vid; ?>" <?php echo $selected; ?> data-name="<?php echo h($vName); ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small text-muted mt-1">Choose the venue first. New events will use this to prefill the title.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" id="event_title_input" class="form-control" required value="<?php echo h($event['title']); ?>">
                    <div class="small text-muted mt-1">Prefilled from the venue on new events, but you can edit it.</div>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Organiser</label>
                    <?php
                        $selectedOrganiserUserId = (int)($event['organiser_user_id'] ?? 0);
                        $legacyOrganiser = trim((string)($event['organiser'] ?? ''));
                        $hasSelectedOrganiser = false;
                    ?>
                    <select name="organiser_user_id" class="form-select">
                        <option value="0">Choose organiser...</option>
                        <?php foreach ($eligibleOrganisers as $userRow): ?>
                            <?php
                                $userId = (int)($userRow['id'] ?? 0);
                                $fullName = trim((string)(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? '')));
                                $label = $fullName !== '' ? $fullName : (string)($userRow['email'] ?? ('User #' . $userId));
                                $roleLabel = ucfirst((string)($userRow['role'] ?? 'user'));
                                $selected = $selectedOrganiserUserId > 0 && $selectedOrganiserUserId === $userId;
                                if ($selected) {
                                    $hasSelectedOrganiser = true;
                                }
                            ?>
                            <option value="<?php echo $userId; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                <?php echo h($label . ' (' . $roleLabel . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($legacyOrganiser !== '' && !$hasSelectedOrganiser): ?>
                        <div class="small text-muted mt-1">Current saved organiser: <?php echo h($legacyOrganiser); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-2">
                <a class="small" href="venues.php">Manage venues</a>
            </div>
        </div>

        <div class="section-card">
            <div class="section-title">Date &amp; Time</div>
            <div class="section-sub">Schedule for the event</div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Start date</label>
                    <input type="date" name="event_date" class="form-control" required value="<?php echo h($event['event_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start time</label>
                    <input type="time" name="start_time" class="form-control" value="<?php echo h($event['start_time']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo h($event['end_date'] ?? $event['event_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End time</label>
                    <input type="time" name="end_time" class="form-control" value="<?php echo h($event['end_time']); ?>">
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Members open date</label>
                    <input type="date" name="entry_open_date" class="form-control" value="<?php echo h($displayOpenDate); ?>">
                    <div class="small text-muted mt-1" id="openDateHint">Defaults to <?php echo (int)$eventSettings['event_member_open_weeks']; ?> weeks / previous <?php echo h($weekdayNames[(int)$eventSettings['event_member_open_weekday']]); ?>.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Members open time</label>
                    <input type="time" name="entry_open_time" class="form-control" value="<?php echo h($displayOpenTime); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Non-members open date</label>
                    <input type="date" name="non_member_entry_open_date" class="form-control" value="<?php echo h($displayNonMemberOpenDate); ?>">
                    <div class="small text-muted mt-1" id="nonMemberOpenDateHint">Defaults to <?php echo (int)$eventSettings['event_non_member_open_weeks']; ?> weeks / previous <?php echo h($weekdayNames[(int)$eventSettings['event_non_member_open_weekday']]); ?>.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Non-members open time</label>
                    <input type="time" name="non_member_entry_open_time" class="form-control" value="<?php echo h($displayNonMemberOpenTime); ?>">
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Entries close date</label>
                    <input type="date" name="entry_close_date" class="form-control" value="<?php echo h($displayCloseDate); ?>">
                    <div class="small text-muted mt-1" id="closeDateHint">Defaults to the previous <?php echo h($weekdayNames[(int)$eventSettings['event_entry_close_weekday']]); ?>.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Entries close time</label>
                    <input type="time" name="entry_close_time" class="form-control" value="<?php echo h($displayCloseTime); ?>">
                </div>
            </div>
        </div>

        <div class="section-card">
            <div class="section-title">Classes &amp; Pricing</div>
            <div class="section-sub">Prices are copied from the default pricing scheme for this event type. Editing here affects this event only.</div>

            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div class="small text-muted">
                    <span class="fw-bold">Tip:</span> Foreign Recognition is optional on member-price rows; blank uses the member rate. Changing the event type will reset pricing to that type’s default scheme.
                </div>
                <button class="btn btn-sm btn-outline-secondary has-icon" type="button" id="addPricingRowBtn"><i class="fa-solid fa-plus btn-icon"></i><span class="btn-label">Add row</span></button>
            </div>

            <div class="border rounded overflow-hidden">
                <div class="table-responsive">
                    <table class="pricing-table" id="pricingRowsTable">
                        <thead>
                        <tr>
                            <th class="compact">On</th>
                            <th class="compact">Code</th>
                            <th class="compact">Ride type</th>
                            <th>Class name</th>
                            <th class="compact">£ Price</th>
                            <th class="compact">Member</th>
                            <th class="compact">£ Foreign</th>
                            <th class="compact">Junior</th>
                            <th class="compact"></th>
                        </tr>
                        </thead>
                        <tbody id="pricingRowsTbody">
                        <?php if (!$eventPricingRows): ?>
                            <tr><td colspan="9" class="row-muted small p-3">No pricing rows yet. Use “Add row” or select an event type with a default pricing scheme.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_values($eventPricingRows) as $i => $row): ?>
                                <?php
                                $rowKey = ($row['id'] ?? 0) ? ('id' . (int)$row['id']) : ('r' . $i);
                                $enabledChecked = !empty($row['enabled']);
                                $memberChecked = !empty($row['is_member_price']);
                                $juniorChecked = !empty($row['is_junior_ride']);
                                $sortOrder = (int)($row['sort_order'] ?? (($i + 1) * 10));
                                ?>
                                <tr data-row-key="<?php echo h($rowKey); ?>">
                                    <td class="compact">
                                        <input type="hidden" name="event_row_enabled[<?php echo h($rowKey); ?>]" value="0">
                                        <input class="form-check-input" type="checkbox" value="1" name="event_row_enabled[<?php echo h($rowKey); ?>]" <?php echo $enabledChecked ? 'checked' : ''; ?> aria-label="Enabled">
                                    </td>
                                    <td class="compact">
                                        <input type="hidden" name="event_row_sort[<?php echo h($rowKey); ?>]" value="<?php echo h((string)$sortOrder); ?>">
                                        <input type="text" class="form-control form-control-sm code-input" name="event_row_class_code[<?php echo h($rowKey); ?>]" value="<?php echo h((string)($row['class_code'] ?? '')); ?>" placeholder="PR">
                                    </td>
                                    <td class="compact"><input type="text" class="form-control form-control-sm text-uppercase" name="event_row_class_group[<?php echo h($rowKey); ?>]" value="<?php echo h((string)($row['class_group'] ?? '')); ?>" placeholder="PR"></td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm name-input" name="event_row_class_name[<?php echo h($rowKey); ?>]" value="<?php echo h((string)($row['class_name'] ?? '')); ?>" placeholder="Pleasure Ride">
                                    </td>
                                    <td class="compact">
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm price-input" name="event_row_price[<?php echo h($rowKey); ?>]" value="<?php echo h((string)($row['price'] ?? '0')); ?>">
                                    </td>
                                    <td class="compact">
                                        <input type="hidden" name="event_row_is_member_price[<?php echo h($rowKey); ?>]" value="0">
                                        <input class="form-check-input" type="checkbox" value="1" name="event_row_is_member_price[<?php echo h($rowKey); ?>]" <?php echo $memberChecked ? 'checked' : ''; ?> aria-label="Member price">
                                    </td>
                                    <td class="compact"><input type="number" step="0.01" min="0" class="form-control form-control-sm price-input" name="event_row_foreign_recognition_price[<?php echo h($rowKey); ?>]" value="<?php echo h((string)($row['foreign_recognition_price'] ?? '')); ?>" placeholder="Member"></td>
                                    <td class="compact">
                                        <input type="hidden" name="event_row_is_junior_ride[<?php echo h($rowKey); ?>]" value="0">
                                        <input class="form-check-input" type="checkbox" value="1" name="event_row_is_junior_ride[<?php echo h($rowKey); ?>]" <?php echo $juniorChecked ? 'checked' : ''; ?> aria-label="Junior ride">
                                    </td>
                                    <td class="compact">
                                        <button class="btn btn-sm btn-outline-danger remove-btn" type="button" data-remove-pricing-row>Remove</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div class="section-title">Event limits</div>
            <div class="section-sub">Limit entries for this event</div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="capacity_enabled" name="capacity_enabled" value="1" <?php echo !empty($event['capacity_enabled']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="capacity_enabled">Limit total entries</label>
                </div>
                <input type="number" min="1" class="form-control" style="max-width: 120px;" id="capacity_limit" name="capacity_limit" value="<?php echo h((int)($event['capacity_limit'] ?? 50)); ?>" <?php echo empty($event['capacity_enabled']) ? 'disabled' : ''; ?>>
                <span class="text-muted small">spaces</span>
            </div>
            <div class="text-muted small mt-1">Leave limit off for unlimited capacity.</div>
        </div>

        <div class="section-card">
            <div class="section-title">Entry form builder</div>
            <div class="section-sub">Order controls the public entry form. Classes stay required.</div>
            <input type="hidden" name="entry_form_json" id="entry_form_json">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <div class="input-group input-group-sm" style="max-width: 420px;">
                    <select class="form-select" id="addFormItemSelect">
                        <option value="" data-label="Add form item...">Add form item...</option>
                        <optgroup label="Form sections">
                            <option value="block:rider_details" data-label="Rider details">Rider details</option>
                            <option value="block:horse_details" data-label="Horse details">Horse details</option>
                            <option value="block:contact" data-label="Contact info">Contact info</option>
                        </optgroup>
                        <optgroup label="Components">
                            <?php foreach ($entryComponentsAll as $comp): ?>
                                <option value="component:<?php echo (int)($comp['id'] ?? 0); ?>" data-label="<?php echo h($comp['name'] ?? 'Component'); ?> (<?php echo h($comp['type'] ?? ''); ?>)"><?php echo h($comp['name'] ?? 'Component'); ?> (<?php echo h($comp['type'] ?? ''); ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <button class="btn btn-outline-secondary has-icon" type="button" id="addFormItemBtn"><i class="fa-solid fa-plus btn-icon"></i><span class="btn-label">Add</span></button>
                </div>
            </div>
            <div id="entryFormList" class="list-group"></div>
        </div>

        <div class="section-card">
            <div class="section-title">Description</div>
            <textarea name="description" class="form-control" rows="4"><?php echo h($event['description'] ?? ''); ?></textarea>
        </div>

        <div class="section-card">
            <div class="section-title">Status</div>
            <select name="status" class="form-select" style="max-width: 240px;">
                <option value="draft" <?php echo ($event['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                <option value="published" <?php echo ($event['status'] === 'published') ? 'selected' : ''; ?>>Published</option>
            </select>
        </div>

        <div class="fixed-actions d-flex gap-2">
            <button class="btn btn-success has-icon"><i class="fa-solid fa-floppy-disk btn-icon"></i><span class="btn-label">Save</span></button>
            <a class="btn btn-outline-secondary has-icon" href="events.php"><i class="fa-solid fa-xmark btn-icon"></i><span class="btn-label">Cancel</span></a>
        </div>
    </form>
</div>

<div class="modal fade" id="eventTypeChangeModal" tabindex="-1" aria-labelledby="eventTypeChangeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventTypeChangeModalLabel">Change event type?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Changing to <strong id="eventTypeChangeName"></strong> will replace the current classes and prices with that event type’s default pricing scheme.</p>
                <p>Any unsaved changes in the Classes &amp; Pricing section will be lost.</p>
                <div class="alert alert-warning mb-0" role="alert">
                    <strong>Remember to save:</strong> after applying this change, click <strong>Save</strong> at the bottom of the event record to make it permanent.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep current type</button>
                <button type="button" class="btn btn-success" id="confirmEventTypeChange">Apply event type</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const startDate = document.querySelector('input[name="event_date"]');
        const openDate = document.querySelector('input[name="entry_open_date"]');
        const nonMemberOpenDate = document.querySelector('input[name="non_member_entry_open_date"]');
        const closeDate = document.querySelector('input[name="entry_close_date"]');
        const openHint = document.getElementById('openDateHint');
        const nonMemberOpenHint = document.getElementById('nonMemberOpenDateHint');
        const closeHint = document.getElementById('closeDateHint');
        const capacityToggle = document.getElementById('capacity_enabled');
        const capacityInput = document.getElementById('capacity_limit');
        const venueSelect = document.getElementById('venue_id');
        const venueNameInput = document.getElementById('venue_name_input');
        const titleInput = document.getElementById('event_title_input');
        const isNewEvent = <?php echo $eventId > 0 ? 'false' : 'true'; ?>;
        const endDate = document.querySelector('input[name="end_date"]');
        const startTime = document.querySelector('input[name="start_time"]');
        const endTime = document.querySelector('input[name="end_time"]');
        const openTime = document.querySelector('input[name="entry_open_time"]');
        const nonMemberOpenTime = document.querySelector('input[name="non_member_entry_open_time"]');
        const closeTime = document.querySelector('input[name="entry_close_time"]');
        const eventDefaults = <?php echo json_encode([
            'startTime' => (string)$eventSettings['event_default_start_time'],
            'endDays' => (int)$eventSettings['event_default_end_days'],
            'endTime' => (string)$eventSettings['event_default_end_time'],
            'memberOpenWeeks' => (int)$eventSettings['event_member_open_weeks'],
            'memberOpenWeekday' => (int)$eventSettings['event_member_open_weekday'],
            'memberOpenTime' => (string)$eventSettings['event_member_open_time'],
            'nonMemberOpenWeeks' => (int)$eventSettings['event_non_member_open_weeks'],
            'nonMemberOpenWeekday' => (int)$eventSettings['event_non_member_open_weekday'],
            'nonMemberOpenTime' => (string)$eventSettings['event_non_member_open_time'],
            'closeWeeks' => (int)$eventSettings['event_entry_close_weeks'],
            'closeWeekday' => (int)$eventSettings['event_entry_close_weekday'],
            'closeTime' => (string)$eventSettings['event_entry_close_time'],
        ], JSON_UNESCAPED_SLASHES); ?>;
        if (venueSelect && venueNameInput) {
            let lastAutoTitle = '';
            const syncVenueName = () => {
                const opt = venueSelect.options[venueSelect.selectedIndex];
                const name = opt ? opt.getAttribute('data-name') : '';
                venueNameInput.value = (venueSelect.value !== '0' && name) ? name : '';
                if (isNewEvent && titleInput && name) {
                    const currentTitle = titleInput.value.trim();
                    if (currentTitle === '' || currentTitle === lastAutoTitle) {
                        titleInput.value = name;
                        lastAutoTitle = name;
                    }
                }
            };
            venueSelect.addEventListener('change', syncVenueName);
            syncVenueName();
        }

        const pad = (n) => String(n).padStart(2, '0');
        const formatDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const plusDays = (dateStr, days) => {
            if (!dateStr) return '';
            const dt = new Date(dateStr + 'T12:00:00');
            if (Number.isNaN(dt.getTime())) return '';
            dt.setDate(dt.getDate() + days);
            return formatDate(dt);
        };
        const previousWeekday = (dateStr, weekday, weeks) => {
            if (!dateStr) return '';
            const dt = new Date(dateStr + 'T12:00:00');
            if (Number.isNaN(dt.getTime())) return '';
            let daysBack = (dt.getDay() - weekday + 7) % 7;
            if (daysBack === 0) daysBack = 7;
            daysBack += Math.max(0, weeks - 1) * 7;
            dt.setDate(dt.getDate() - daysBack);
            return formatDate(dt);
        };
        const applyNewEventDefaults = () => {
            if (!isNewEvent || !startDate || !startDate.value) {
                return;
            }
            const start = new Date(startDate.value + 'T12:00:00');
            if (Number.isNaN(start.getTime())) {
                return;
            }
            if (endDate && !endDate.value) {
                endDate.value = plusDays(startDate.value, eventDefaults.endDays);
            }
            if (startTime && !startTime.value) {
                startTime.value = eventDefaults.startTime;
            }
            if (endTime && !endTime.value) {
                endTime.value = eventDefaults.endTime;
            }
            if (openDate && !openDate.value) {
                openDate.value = previousWeekday(startDate.value, eventDefaults.memberOpenWeekday, eventDefaults.memberOpenWeeks);
            }
            if (openTime && (!openTime.value || openTime.value === '00:00')) {
                openTime.value = eventDefaults.memberOpenTime;
            }
            if (nonMemberOpenDate && !nonMemberOpenDate.value) {
                nonMemberOpenDate.value = previousWeekday(startDate.value, eventDefaults.nonMemberOpenWeekday, eventDefaults.nonMemberOpenWeeks);
            }
            if (nonMemberOpenTime && (!nonMemberOpenTime.value || nonMemberOpenTime.value === '00:00')) {
                nonMemberOpenTime.value = eventDefaults.nonMemberOpenTime;
            }
            if (closeDate && !closeDate.value) {
                closeDate.value = previousWeekday(startDate.value, eventDefaults.closeWeekday, eventDefaults.closeWeeks);
            }
            if (closeTime && !closeTime.value) {
                closeTime.value = eventDefaults.closeTime;
            }
        };
        if (startDate) {
            startDate.addEventListener('change', applyNewEventDefaults);
        }

        const describeDiff = (targetDateStr, refDateStr, defaultLabel) => {
            if (!targetDateStr || !refDateStr) return defaultLabel;
            const target = new Date(targetDateStr);
            const ref = new Date(refDateStr);
            if (Number.isNaN(target.getTime()) || Number.isNaN(ref.getTime())) return defaultLabel;
            const diffMs = target.getTime() - ref.getTime();
            const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));
            const dayLabel = (n) => (Math.abs(n) === 1 ? 'day' : 'days');
            if (diffDays === 0) return 'Same day as start';
            if (diffDays < 0) return `${Math.abs(diffDays)} ${dayLabel(diffDays)} before start`;
            return `${diffDays} ${dayLabel(diffDays)} after start`;
        };

        const updateHints = () => {
            const startVal = startDate?.value;
            if (openHint && openDate) {
                openHint.textContent = describeDiff(openDate.value, startVal, 'Calculated from the managed member opening rule.');
            }
            if (nonMemberOpenHint && nonMemberOpenDate) {
                nonMemberOpenHint.textContent = describeDiff(nonMemberOpenDate.value, startVal, 'Calculated from the managed non-member opening rule.');
            }
            if (closeHint && closeDate) {
                closeHint.textContent = describeDiff(closeDate.value, startVal, 'Calculated from the managed closing rule.');
            }
        };

        if (openDate) {
            const syncNonMemberOpen = () => {
                if (nonMemberOpenDate && openDate.value && !nonMemberOpenDate.value) {
                    nonMemberOpenDate.value = startDate?.value ? previousWeekday(startDate.value, eventDefaults.nonMemberOpenWeekday, eventDefaults.nonMemberOpenWeeks) : '';
                }
                if (nonMemberOpenTime && (!nonMemberOpenTime.value || nonMemberOpenTime.value === '00:00')) {
                    nonMemberOpenTime.value = eventDefaults.nonMemberOpenTime;
                }
            };
            openDate.addEventListener('change', syncNonMemberOpen);
            openTime?.addEventListener('change', syncNonMemberOpen);
        }

        [startDate, openDate, nonMemberOpenDate, closeDate].forEach((el) => {
            el?.addEventListener('input', updateHints);
            el?.addEventListener('change', updateHints);
        });
        updateHints();

        // Capacity toggle
        if (capacityToggle && capacityInput) {
            const syncCapacity = () => {
                capacityInput.disabled = !capacityToggle.checked;
            };
            capacityToggle.addEventListener('change', syncCapacity);
            syncCapacity();
        }

        // Pricing rows editor (per-event copy of a pricing scheme)
        const defaultPricingRowsByType = <?php echo json_encode($defaultPricingRowsByType, JSON_UNESCAPED_UNICODE); ?>;
        const eventTypeSelect = document.querySelector('select[name="event_type_id"]');
        const pricingTbody = document.getElementById('pricingRowsTbody');
        const addPricingRowBtn = document.getElementById('addPricingRowBtn');
        let pricingRowCounter = 0;

        const normalizePrice = (value) => {
            const raw = String(value ?? '').trim().replace(/[^0-9.]/g, '');
            const num = Number.parseFloat(raw);
            if (!Number.isFinite(num) || num < 0) return '0';
            return num.toFixed(2);
        };

        const createPricingRow = (rowKey, row) => {
            const tr = document.createElement('tr');
            tr.dataset.rowKey = rowKey;
            const sortOrder = Number((row && row.sort_order) ?? 0) || 0;
            const classCode = String((row && row.class_code) ?? '');
            const classGroup = String((row && row.class_group) ?? '');
            const className = String((row && row.class_name) ?? '');
            const price = normalizePrice((row && row.price) ?? '0');
            const foreignPrice = (row && row.foreign_recognition_price) == null || String(row.foreign_recognition_price).trim() === '' ? '' : normalizePrice(row.foreign_recognition_price);
            const enabled = (row && row.enabled) !== 0 && (row && row.enabled) !== '0';
            const isMemberPrice = (row && row.is_member_price) === 1 || (row && row.is_member_price) === '1' || (row && row.is_member_price) === true;
            const isJuniorRide = (row && row.is_junior_ride) === 1 || (row && row.is_junior_ride) === '1' || (row && row.is_junior_ride) === true;

            tr.innerHTML = `
                <td class="compact">
                    <input type="hidden" name="event_row_enabled[${rowKey}]" value="0">
                    <input class="form-check-input" type="checkbox" value="1" name="event_row_enabled[${rowKey}]" ${enabled ? 'checked' : ''} aria-label="Enabled">
                </td>
                <td class="compact">
                    <input type="hidden" name="event_row_sort[${rowKey}]" value="${sortOrder}">
                    <input type="text" class="form-control form-control-sm code-input" name="event_row_class_code[${rowKey}]" value="${classCode.replace(/\"/g, '&quot;')}" placeholder="PR">
                </td>
                <td class="compact"><input type="text" class="form-control form-control-sm text-uppercase" name="event_row_class_group[${rowKey}]" value="${classGroup.replace(/"/g, '&quot;')}" placeholder="PR"></td>
                <td>
                    <input type="text" class="form-control form-control-sm name-input" name="event_row_class_name[${rowKey}]" value="${className.replace(/\"/g, '&quot;')}" placeholder="Pleasure Ride">
                </td>
                <td class="compact">
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm price-input" name="event_row_price[${rowKey}]" value="${price}">
                </td>
                <td class="compact">
                    <input type="hidden" name="event_row_is_member_price[${rowKey}]" value="0">
                    <input class="form-check-input" type="checkbox" value="1" name="event_row_is_member_price[${rowKey}]" ${isMemberPrice ? 'checked' : ''} aria-label="Member price">
                </td>
                <td class="compact"><input type="number" step="0.01" min="0" class="form-control form-control-sm price-input" name="event_row_foreign_recognition_price[${rowKey}]" value="${foreignPrice}" placeholder="Member"></td>
                <td class="compact">
                    <input type="hidden" name="event_row_is_junior_ride[${rowKey}]" value="0">
                    <input class="form-check-input" type="checkbox" value="1" name="event_row_is_junior_ride[${rowKey}]" ${isJuniorRide ? 'checked' : ''} aria-label="Junior ride">
                </td>
                <td class="compact">
                    <button class="btn btn-sm btn-outline-danger remove-btn" type="button" data-remove-pricing-row>Remove</button>
                </td>
            `;
            return tr;
        };

        const renderPricingRows = (rows) => {
            if (!pricingTbody) return;
            pricingTbody.innerHTML = '';
            if (!rows || rows.length === 0) {
                const empty = document.createElement('tr');
                empty.innerHTML = `<td colspan="9" class="row-muted small p-3">No pricing rows yet. Use “Add row” or select an event type with a default pricing scheme.</td>`;
                pricingTbody.appendChild(empty);
                return;
            }
            rows.forEach((row, idx) => {
                const rowKey = `n${Date.now()}_${pricingRowCounter++}_${idx}`;
                const sortOrder = Number(row.sort_order ?? (idx + 1) * 10) || ((idx + 1) * 10);
                pricingTbody.appendChild(createPricingRow(rowKey, { ...row, sort_order: sortOrder }));
            });
        };

        if (pricingTbody) {
            pricingTbody.addEventListener('click', (e) => {
                const target = e.target;
                const btn = target && target.closest ? target.closest('[data-remove-pricing-row]') : null;
                if (!btn) return;
                const row = btn.closest('tr');
                if (row) {
                    row.remove();
                }
                if (pricingTbody.querySelectorAll('tr').length === 0) {
                    renderPricingRows([]);
                }
            });
        }

        if (addPricingRowBtn && pricingTbody) {
            addPricingRowBtn.addEventListener('click', () => {
                if (pricingTbody.querySelector('td[colspan="9"]')) {
                    pricingTbody.innerHTML = '';
                }
                const rowKey = `n${Date.now()}_${pricingRowCounter++}`;
                const existing = pricingTbody.querySelectorAll('tr').length;
                const row = createPricingRow(rowKey, {
                    sort_order: (existing + 1) * 10,
                    class_code: '',
                    class_group: '',
                    class_name: '',
                    price: '0.00',
                    foreign_recognition_price: '',
                    is_member_price: 0,
                    is_junior_ride: 0,
                    enabled: 1,
                });
                pricingTbody.appendChild(row);
                const nameInput = row.querySelector('input[name^="event_row_class_name"]');
                if (nameInput && nameInput.focus) {
                    nameInput.focus();
                }
            });
        }

        // UX: event type change resets pricing to default scheme for that type (matches server-side behaviour on save).
        if (eventTypeSelect && pricingTbody) {
            let lastEventTypeId = String(eventTypeSelect.value || '');
            let pendingEventTypeId = '';
            const eventTypeChangeModalEl = document.getElementById('eventTypeChangeModal');
            const eventTypeChangeName = document.getElementById('eventTypeChangeName');
            const confirmEventTypeChange = document.getElementById('confirmEventTypeChange');
            const eventTypeChangeModal = eventTypeChangeModalEl && window.bootstrap
                ? new bootstrap.Modal(eventTypeChangeModalEl)
                : null;

            eventTypeSelect.addEventListener('change', () => {
                const nextTypeId = String(eventTypeSelect.value || '');
                if (nextTypeId === lastEventTypeId) return;
                const nextTypeName = eventTypeSelect.selectedOptions[0]?.textContent?.trim() || 'the selected event type';

                pendingEventTypeId = nextTypeId;
                eventTypeSelect.value = lastEventTypeId;
                if (eventTypeChangeName) eventTypeChangeName.textContent = nextTypeName;
                eventTypeChangeModal?.show();
            });

            confirmEventTypeChange?.addEventListener('click', () => {
                if (!pendingEventTypeId) return;
                lastEventTypeId = pendingEventTypeId;
                eventTypeSelect.value = lastEventTypeId;
                const rows = defaultPricingRowsByType[lastEventTypeId] || defaultPricingRowsByType[Number(lastEventTypeId)] || [];
                renderPricingRows(rows);
                pendingEventTypeId = '';
                eventTypeChangeModal?.hide();
            });

            eventTypeChangeModalEl?.addEventListener('hidden.bs.modal', () => {
                pendingEventTypeId = '';
                eventTypeSelect.value = lastEventTypeId;
            });
        }

        // Entry form builder
        const entryFormList = document.getElementById('entryFormList');
        const addFormItemSelect = document.getElementById('addFormItemSelect');
        const addFormItemBtn = document.getElementById('addFormItemBtn');
        const entryFormHidden = document.getElementById('entry_form_json');
        const formEl = document.querySelector('form');
        const components = <?php echo json_encode(array_values($entryComponentsAll), JSON_UNESCAPED_UNICODE); ?>;
        const componentMap = new Map(components.map((c) => [String(c.id), c]));
        let entryForm = <?php echo json_encode($entryFormConfig, JSON_UNESCAPED_UNICODE); ?>;

        const ensureClassesBlock = () => {
            const hasClasses = entryForm.some((b) => b.type === 'classes');
            if (!hasClasses) {
                entryForm.unshift({ type: 'classes', label: 'Classes', enabled: true });
            }
        };

        const updateOptionStates = () => {
            if (!addFormItemSelect) return;
            Array.from(addFormItemSelect.options).forEach((opt) => {
                const baseLabel = opt.dataset.label || opt.textContent;
                opt.textContent = baseLabel;
                opt.disabled = false;
                if (!opt.value) return;
                const [kind, id] = opt.value.split(':');
                if (kind === 'block') {
                    const exists = entryForm.some((b) => b.type === id);
                    if (exists) {
                        opt.textContent = `${baseLabel} (added)`;
                        opt.disabled = true;
                    }
                } else if (kind === 'component') {
                    const exists = entryForm.some((b) => b.type === 'component' && String(b.component_id) === id);
                    if (exists) {
                        opt.textContent = `${baseLabel} (added)`;
                        opt.disabled = true;
                    }
                }
            });
            addFormItemSelect.value = '';
        };

        const syncHidden = () => {
            ensureClassesBlock();
            entryFormHidden.value = JSON.stringify(entryForm);
        };

        const render = () => {
            ensureClassesBlock();
            entryFormList.innerHTML = '';
            entryForm.forEach((block, idx) => {
                const item = document.createElement('div');
                item.className = 'list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2';
                item.dataset.index = idx;
                const labelText = block.type === 'component'
                    ? (componentMap.get(String(block.component_id))?.name || block.label || 'Component')
                    : (block.label || block.type);
                const typeText = block.type === 'component'
                    ? `Component #${block.component_id}`
                    : block.type.replace('_', ' ');
                item.innerHTML = `
                    <div>
                        <div class="fw-semibold">${labelText}</div>
                        <div class="text-muted small">${typeText}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="form-check mb-0">
                            <input class="form-check-input toggle-enabled" type="checkbox" ${block.type === 'classes' ? 'checked disabled' : (block.enabled !== false ? 'checked' : '')}>
                            <label class="form-check-label">Enabled</label>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary move-up"${idx === 0 ? ' disabled' : ''}>↑</button>
                            <button type="button" class="btn btn-outline-secondary move-down"${idx === entryForm.length - 1 ? ' disabled' : ''}>↓</button>
                        </div>
                        ${block.type === 'classes' ? '' : '<button type="button" class="btn btn-outline-danger btn-sm remove-block">Remove</button>'}
                    </div>
                `;
                entryFormList.appendChild(item);
            });
            syncHidden();
            updateOptionStates();
        };

        const addBlock = (type, data = {}) => {
            entryForm.push({ type, enabled: true, ...data });
            render();
        };

        entryFormList?.addEventListener('click', (e) => {
            const item = e.target.closest('.list-group-item');
            if (!item) return;
            const idx = parseInt(item.dataset.index, 10);
            if (e.target.classList.contains('move-up') && idx > 0) {
                [entryForm[idx - 1], entryForm[idx]] = [entryForm[idx], entryForm[idx - 1]];
                render();
            }
            if (e.target.classList.contains('move-down') && idx < entryForm.length - 1) {
                [entryForm[idx + 1], entryForm[idx]] = [entryForm[idx], entryForm[idx + 1]];
                render();
            }
            if (e.target.classList.contains('remove-block')) {
                entryForm.splice(idx, 1);
                render();
            }
        });

        entryFormList?.addEventListener('change', (e) => {
            const item = e.target.closest('.list-group-item');
            if (!item) return;
            const idx = parseInt(item.dataset.index, 10);
            if (e.target.classList.contains('toggle-enabled')) {
                entryForm[idx].enabled = e.target.checked;
                syncHidden();
            }
        });

        const handleAddSelection = () => {
            const val = addFormItemSelect?.value || '';
            if (!val) return;
            if (val.startsWith('block:')) {
                addBlock(val.replace('block:', ''));
            } else if (val.startsWith('component:')) {
                const id = val.replace('component:', '');
                const comp = componentMap.get(id);
                if (!comp) return;
                addBlock('component', { component_id: parseInt(id, 10), label: comp.name });
            }
            addFormItemSelect.value = '';
        };

        addFormItemBtn?.addEventListener('click', handleAddSelection);
        addFormItemSelect?.addEventListener('change', handleAddSelection);

        formEl?.addEventListener('submit', () => {
            syncHidden();
        });

        render();
    })();
</script>
<?php
admin_layout_end();
