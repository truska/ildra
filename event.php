<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$event = $eventId > 0 ? fetchEventById($pdo, $eventId) : null;
$basket = $_SESSION['basket'] ?? [];

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basketCount = count($basket);
$people = $isLoggedIn ? fetchMembersForUser($pdo, (int)($currentUser['id'] ?? 0)) : [];
$horses = $isLoggedIn ? fetchHorsesForUser($pdo, (int)($currentUser['id'] ?? 0)) : [];
$notRegisteredHorseId = 1;
if ($isLoggedIn && $pdo) {
    $stmt = $pdo->prepare("SELECT id, owner_user_id, name, is_archived, 0 AS is_linked, 'global' AS link_permission FROM horses WHERE id = :id AND is_archived = 0 LIMIT 1");
    $stmt->execute([':id' => $notRegisteredHorseId]);
    $notRegisteredHorse = $stmt->fetch();
    if ($notRegisteredHorse) {
        $notRegisteredHorse['name'] = 'Not Registered';
        $notRegisteredHorse['is_global_placeholder'] = 1;
        $horses[] = $notRegisteredHorse;
    }
}
$peopleWithActiveMembership = [];
$memberPriceEligibleByPerson = [];
$memberPriceUsedByPerson = [];
if ($isLoggedIn && $pdo && $people) {
    $memberIdSet = [];
    foreach ($people as $p) {
        $memberIdSet[(int)($p['id'] ?? 0)] = true;
    }
    foreach (fetchMemberships($pdo) as $membership) {
        $memberId = (int)($membership['member_id'] ?? 0);
        if ($memberId <= 0 || empty($memberIdSet[$memberId])) {
            continue;
        }
        $status = strtolower((string)($membership['status'] ?? ''));
        if ($status === 'active') {
            $peopleWithActiveMembership[$memberId] = true;
        }
    }
    if ($eventId > 0 && ensure_bookings_tables($pdo)) {
        try {
            $stmt = $pdo->prepare("
                SELECT metadata
                FROM booking_items
                WHERE event_id = :eid
                  AND COALESCE(is_withdrawn, 0) = 0
                  AND booking_type <> 'membership'
            ");
            $stmt->execute([':eid' => $eventId]);
            foreach ($stmt->fetchAll() as $row) {
                $meta = json_decode((string)($row['metadata'] ?? ''), true);
                if (!is_array($meta)) {
                    continue;
                }
                $personId = (int)($meta['person_id'] ?? 0);
                if ($personId <= 0 || empty($memberIdSet[$personId])) {
                    continue;
                }
                if (!empty($meta['is_member_price'])) {
                    $memberPriceUsedByPerson[$personId] = true;
                }
            }
        } catch (PDOException $e) {
            // Ignore DB errors; fall back to basket-only checks.
        }
    }
    foreach ($basket as $item) {
        if ((int)($item['event_id'] ?? 0) !== $eventId) {
            continue;
        }
        $meta = $item['metadata'] ?? [];
        if (!is_array($meta)) {
            continue;
        }
        $personId = (int)($meta['person_id'] ?? 0);
        if ($personId <= 0 || empty($memberIdSet[$personId])) {
            continue;
        }
        if (!empty($meta['is_member_price'])) {
            $memberPriceUsedByPerson[$personId] = true;
        }
    }
    foreach ($memberIdSet as $personId => $_) {
        $memberPriceEligibleByPerson[$personId] = !empty($peopleWithActiveMembership[$personId])
            && empty($memberPriceUsedByPerson[$personId]);
    }
}
$dbEntryCount = (int)($event['entry_count'] ?? 0);
$sessionEntryCount = 0;
foreach ($basket as $item) {
    if ((int)($item['event_id'] ?? 0) === $eventId) {
        $sessionEntryCount++;
    }
}
$totalEntryCount = $dbEntryCount + $sessionEntryCount;
$capacityEnabled = !empty($event['capacity_enabled']);
$capacityLimit = (int)($event['capacity_limit'] ?? 0);
$hasLimit = $capacityEnabled && $capacityLimit > 0;
$isFull = $hasLimit && $totalEntryCount >= $capacityLimit;

$isPublished = function ($row): bool {
    $status = strtolower(trim((string)($row['status'] ?? '')));
    return $status === 'published';
};

if ($event && !$isPublished($event) && !$canViewAdmin) {
    $event = null;
}

$upcomingEvents = array_values(array_filter(fetchEvents($pdo, true), $isPublished));

if (!$event) {
    http_response_code(404);
}

function event_url(array $event, string $basePath): string
{
    $id = (int)($event['id'] ?? 0);
    if ($id <= 0) {
        return '#';
    }
    $slug = slugify((string)($event['title'] ?? 'event'));
    return $basePath . '/events/' . $id . '-' . $slug;
}

$navItemEventsUrl = $basePath . '/events';
$classOptions = [];
$eventTypeId = (int)($event['event_type_id'] ?? 0);
$entryComponents = $event ? fetchEventEntryComponents($pdo, (int)$event['id'], $eventTypeId) : [];
$entryForm = $event ? event_entry_form($event, $entryComponents) : [];
$entryComponentsById = [];
foreach ($entryComponents as $c) {
    $entryComponentsById[(int)($c['id'] ?? 0)] = $c;
}
$componentSelections = $_POST['component'] ?? [];
$componentValues = $_POST['component_value'] ?? [];
$componentSelectFlags = $_POST['component_select'] ?? [];
$hasAnyActiveMembership = !empty($peopleWithActiveMembership);
$entryOpenAt = $event['entry_open_at'] ?? null;
$nonMemberEntryOpenAt = $event['non_member_entry_open_at'] ?? null;
$entryCloseAt = $event['entry_close_at'] ?? null;
if (!$entryOpenAt && !empty($event['event_date'])) {
    $entryOpenAt = date('Y-m-d 00:00:00', strtotime($event['event_date'] . ' -1 month'));
}
if (!$nonMemberEntryOpenAt && $entryOpenAt) {
    $nonMemberEntryOpenAt = date('Y-m-d H:i:s', strtotime((string)$entryOpenAt . ' +1 week'));
}
if (!$entryCloseAt && !empty($event['event_date'])) {
    $entryCloseAt = date('Y-m-d 23:59:59', strtotime($event['event_date'] . ' -1 week'));
}
$now = new DateTimeImmutable('now');
$memberEntryOpenDt = $entryOpenAt ? new DateTimeImmutable((string)$entryOpenAt) : null;
$nonMemberEntryOpenDt = $nonMemberEntryOpenAt ? new DateTimeImmutable((string)$nonMemberEntryOpenAt) : null;
$entryCloseDt = $entryCloseAt ? new DateTimeImmutable((string)$entryCloseAt) : null;
$entryOpenDt = $hasAnyActiveMembership ? $memberEntryOpenDt : $nonMemberEntryOpenDt;
$entriesOpenNow = true;
$entryStateMessage = '';
if ($entryCloseDt && $now > $entryCloseDt) {
    $entriesOpenNow = false;
    $entryStateMessage = 'Entries closed on ' . $entryCloseDt->format('jS M Y \a\t H:i');
} elseif ($hasAnyActiveMembership) {
    if ($memberEntryOpenDt && $now < $memberEntryOpenDt) {
        $entriesOpenNow = false;
        $entryStateMessage = 'Member entries open on ' . $memberEntryOpenDt->format('jS M Y \a\t H:i');
        if ($nonMemberEntryOpenDt) {
            $entryStateMessage .= '. Non-member entries open on ' . $nonMemberEntryOpenDt->format('jS M Y \a\t H:i');
        }
    } elseif ($nonMemberEntryOpenDt && $now < $nonMemberEntryOpenDt) {
        $entryStateMessage = 'Member entries are open now. Non-member entries open on ' . $nonMemberEntryOpenDt->format('jS M Y \a\t H:i');
    } else {
        $entryStateMessage = $entryCloseDt ? 'Entries are open until ' . $entryCloseDt->format('jS M Y \a\t H:i') : 'Entries are open';
    }
} elseif ($nonMemberEntryOpenDt && $now < $nonMemberEntryOpenDt) {
    $entriesOpenNow = false;
    if ($memberEntryOpenDt && $now >= $memberEntryOpenDt) {
        $entryStateMessage = 'Member entries are open now. Non-member entries open on ' . $nonMemberEntryOpenDt->format('jS M Y \a\t H:i');
    } else {
        $entryStateMessage = 'Non-member entries open on ' . $nonMemberEntryOpenDt->format('jS M Y \a\t H:i');
        if ($memberEntryOpenDt) {
            $entryStateMessage .= '. Member entries open on ' . $memberEntryOpenDt->format('jS M Y \a\t H:i');
        }
    }
} else {
    $entryStateMessage = $entryCloseDt ? 'Entries are open until ' . $entryCloseDt->format('jS M Y \a\t H:i') : 'Entries are open';
}
$entriesAvailable = ($entriesOpenNow && !$isFull) || $canViewAdmin;
$eventPricingRows = $event ? fetchEventPricingRows($pdo, (int)($event['id'] ?? 0)) : [];
if ($eventPricingRows) {
    foreach ($eventPricingRows as $row) {
        if (empty($row['enabled'])) {
            continue;
        }
        $label = trim((string)($row['class_name'] ?? ''));
        $code = trim((string)($row['class_code'] ?? ''));
        if ($label === '' && $code === '') {
            continue;
        }
        $rowId = (int)($row['id'] ?? 0);
        $value = $rowId > 0 ? (string)$rowId : ($code !== '' ? $code : $label);
        $classOptions[] = [
            'value' => $value,
            'code' => $code !== '' ? $code : $label,
            'label' => $label !== '' ? $label : $code,
            'price' => format_price((float)($row['price'] ?? 0)),
            'is_member_price' => !empty($row['is_member_price']),
            'is_junior_ride' => !empty($row['is_junior_ride']),
            'pricing_row_id' => $rowId > 0 ? $rowId : null,
        ];
    }
}
if (!$classOptions) {
    $classesDecoded = json_decode((string)($event['classes_offered'] ?? ''), true);
    if (is_array($classesDecoded)) {
        foreach ($classesDecoded as $cls) {
            $label = $cls['label'] ?? ($cls['code'] ?? '');
            $code = $cls['code'] ?? $label;
            $price = $cls['price'] ?? '';
            if ($label === '' && $code === '') {
                continue;
            }
            $classOptions[] = [
                'value' => $code,
                'code' => $code,
                'label' => $label ?: $code,
                'price' => $price === '' ? '' : format_price($price),
                'is_member_price' => null,
                'is_junior_ride' => null,
                'pricing_row_id' => null,
            ];
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_booking') {
    if (!$event) {
        $alerts[] = ['type' => 'danger', 'message' => 'Event not found.'];
    } else {
        // Option A policy: adding entries to a basket requires login.
        if (!$isLoggedIn) {
            $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please sign in to enter events.']];
            header('Location: ' . $basePath . '/account');
            exit;
        }
        if ($isFull) {
            $alerts[] = ['type' => 'danger', 'message' => 'This event is now full. No more entries can be added.'];
        }
        if (!$canViewAdmin && !$entriesOpenNow) {
            $alerts[] = ['type' => 'danger', 'message' => $entryStateMessage];
        }
        $classCode = trim((string)($_POST['class_code'] ?? ''));
        $riderName = trim((string)($_POST['rider_name'] ?? ''));
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
        $accompanyingAdult = trim((string)($_POST['accompanying_adult'] ?? ''));
        $horseName = trim((string)($_POST['horse_name'] ?? ''));
        $personId = (int)($_POST['person_id'] ?? 0);
        $horseId = (int)($_POST['horse_id'] ?? 0);
        $componentSelections = $_POST['component'] ?? [];
        $selectedPersonIsJunior = false;

        // Optional: link this entry to a saved person/horse owned by the current account.
        if ($personId > 0) {
            $p = fetchPersonForUserById($pdo, (int)($currentUser['id'] ?? 0), $personId);
            if (!$p || !empty($p['is_archived'])) {
                $personId = 0;
            } else {
                $selectedPersonIsJunior = strcasecmp((string)($p['junior_or_senior'] ?? ''), 'Junior') === 0;
            }
        }
        if ($horseId > 0) {
            if ($horseId === $notRegisteredHorseId) {
                $stmt = $pdo->prepare("SELECT id FROM horses WHERE id = :id AND is_archived = 0 LIMIT 1");
                $stmt->execute([':id' => $notRegisteredHorseId]);
                if (!$stmt->fetchColumn()) {
                    $horseId = 0;
                } else {
                    $horseName = 'Not Registered';
                }
            } else {
                $h = fetchHorseForUserById($pdo, (int)($currentUser['id'] ?? 0), $horseId);
                if (!$h || !empty($h['is_archived'])) {
                    $horseId = 0;
                }
            }
        }

        $selectedClass = null;
        foreach ($classOptions as $cls) {
            if ((string)($cls['value'] ?? $cls['code']) === $classCode) {
                $selectedClass = $cls;
                break;
            }
        }
        $bookingTypeSlug = strtolower(trim((string)($event['event_type_name'] ?? '')));
        $bookingTypeId = (int)($event['event_type_id'] ?? 0);
        $bookingTypeLabel = $event['event_type_name'] ?? ucfirst($bookingTypeSlug !== '' ? $bookingTypeSlug : 'ride');

        if (!$selectedClass) {
            $alerts[] = ['type' => 'danger', 'message' => 'Please choose a class to book.'];
        }
        if ($riderName === '' || $horseName === '' || $contactEmail === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Rider name, horse name and contact email are required.'];
        }
        if ($selectedClass && !empty($selectedClass['is_member_price'])) {
            if ($personId <= 0) {
                $alerts[] = ['type' => 'danger', 'message' => 'Choose a member above to use member pricing.'];
            } elseif (empty($memberPriceEligibleByPerson[$personId])) {
                $alerts[] = ['type' => 'danger', 'message' => 'Member pricing is not available for the selected person.'];
            }
        }
        if ($selectedClass && (!empty($selectedClass['is_junior_ride']) || $selectedPersonIsJunior) && $accompanyingAdult === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Accompanying adult is required for junior rides.'];
        }

        if (!$alerts) {
            // Re-evaluate capacity before adding (includes current basket)
            $currentDbCount = (int)($event['entry_count'] ?? 0);
            $currentSessionCount = 0;
            foreach ($basket as $item) {
                if ((int)($item['event_id'] ?? 0) === $eventId) {
                    $currentSessionCount++;
                }
            }
            $currentTotal = $currentDbCount + $currentSessionCount;
            $limitEnabled = !empty($event['capacity_enabled']);
            $limit = (int)($event['capacity_limit'] ?? 0);
            $hasLimit = $limitEnabled && $limit > 0;
            if ($hasLimit && $currentTotal >= $limit) {
                $alerts[] = ['type' => 'danger', 'message' => 'This event is full. Your entry was not added.'];
            }
        }

        if (!$alerts) {
            $componentsSelected = [];
            $componentsTotal = 0.0;
            foreach ($entryComponents as $component) {
                $compId = (int)($component['id'] ?? 0);
                if ($compId <= 0) {
                    continue;
                }
                $inputKind = $component['input_kind'] ?? 'checkbox';
                $rawValue = $inputKind === 'checkbox' ? (isset($componentSelections[$compId]) ? 'on' : '') : trim((string)($componentValues[$compId] ?? ''));
                $quantity = $inputKind === 'quantity' ? max(0, (int)$rawValue) : 0;
                $type = $component['type'] ?? 'product';
                $isProduct = $type === 'product';
                $isRequiredFlag = !empty($component['is_required']);
                $price = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
                $hasCost = $isProduct && $price !== 0.0;
                $isRequiredProduct = $isProduct && $hasCost && $isRequiredFlag;
                $isRequiredConsent = !$hasCost && $isRequiredFlag && $inputKind === 'checkbox';
                $selectedFlag = $isRequiredProduct
                    ? true
                    : ($inputKind === 'quantity'
                        ? $quantity > 0
                        : ($isProduct ? isset($componentSelectFlags[$compId]) : ($inputKind === 'checkbox' ? $rawValue !== '' : $rawValue !== '')));
                if ($isRequiredConsent && !$selectedFlag) {
                    $alerts[] = ['type' => 'danger', 'message' => 'Please accept ' . h($component['name'] ?? 'the required option') . ' to continue.'];
                    break;
                }
                if (!$selectedFlag) {
                    continue;
                }
                $label = $component['label_override'] ?? ($component['name'] ?? 'Extra');
                $lineTotal = $isProduct && $price !== 0.0
                    ? ($inputKind === 'quantity' ? ($price * $quantity) : $price)
                    : 0.0;
                if ($lineTotal !== 0.0) {
                    $componentsTotal += $lineTotal;
                }
                $componentsSelected[] = [
                    'id' => $compId,
                    'label' => $label,
                    'name' => $component['name'] ?? $label,
                    'type' => $type,
                    'price' => $price,
                    'input_kind' => $inputKind,
                    'value' => $inputKind === 'checkbox' ? null : ($inputKind === 'quantity' ? (string)$quantity : $rawValue),
                    'quantity' => $inputKind === 'quantity' ? $quantity : null,
                    'line_total' => $lineTotal !== 0.0 ? round($lineTotal, 2) : null,
                ];
            }
            $basePrice = price_to_number($selectedClass['price'] ?? 0);
            $metadata = [
                'class_code' => $selectedClass['code'],
                'class_label' => $selectedClass['label'],
                'pricing_row_id' => $selectedClass['pricing_row_id'] ?? null,
                'is_member_price' => $selectedClass['is_member_price'] ?? null,
                'is_junior_ride' => $selectedClass['is_junior_ride'] ?? null,
                'rider_name' => $riderName,
                'contact_email' => $contactEmail,
                'contact_phone' => $contactPhone,
                'accompanying_adult' => $accompanyingAdult !== '' ? $accompanyingAdult : null,
                'horse_name' => $horseName,
                'person_id' => $personId ?: null,
                'horse_id' => $horseId ?: null,
                'base_price' => $basePrice,
                'components' => $componentsSelected,
                'components_total' => $componentsTotal,
            ];
            $entry = [
                'id' => uniqid('bk', true),
                'event_id' => $event['id'],
                'event_title' => $event['title'],
                'price' => $basePrice + $componentsTotal,
                'booking_type' => $bookingTypeSlug !== '' ? $bookingTypeSlug : 'ride',
                    'booking_type_id' => $bookingTypeId ?: null,
                    'booking_type_label' => $bookingTypeLabel,
                    'metadata' => $metadata,
                ];
                $basket[] = $entry;
                $_SESSION['basket'] = $basket;
                $_SESSION['basket_last_added'] = time();
                saveBasketForSession($pdo, session_id(), $basket, $currentUser['id'] ?? null, $_SESSION['basket_last_added']);
                $_SESSION['flash_success'] = 'Entry added to your basket.';
                header('Location: ' . $basePath . '/basket');
                exit;
            }
        }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $event ? h($event['title']) . ' | ' . h($siteSettings['hero_title']) : 'Event not found'; ?></title>
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
            background: radial-gradient(circle at 25% 20%, rgba(255, 255, 255, 0.12), transparent 32%);
            z-index: 0;
        }

        .page-hero .container {
            position: relative;
            z-index: 2;
        }

        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }

        .card-strong {
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12);
        }

        .logo-badge {
            width: 48px;
            height: 48px;
            background: #fffbef;
            border-radius: 50%;
            display: grid;
            place-items: center;
            border: 2px solid #dce705;
            color: var(--green);
            font-weight: 800;
        }

        .meta-chip {
            background: rgba(20, 97, 24, 0.1);
            color: var(--green);
            padding: 6px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: 0.01em;
        }

        .entry-state {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 800;
            letter-spacing: 0.01em;
            font-size: 1rem;
        }

        .entry-state-opening { background: rgba(13,110,253,0.14); color: #0d6efd; }
        .entry-state-open { background: rgba(25,135,84,0.16); color: #198754; }
        .entry-state-closed { background: rgba(220,53,69,0.14); color: #dc3545; }

        .section-block {
            margin-bottom: 1.25rem;
        }

        .form-label {
            font-weight: 600;
        }

        #entryForm .form-control,
        #entryForm .form-select {
            font-family: inherit;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
        }

        .form-section {
            padding: 1rem 0 0.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.035);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .helper-text {
            color: #6b7b6b;
        }

        .validation-message {
            color: #b02a37;
        }

        .cta-row {
            border-radius: 14px;
            background: rgba(20, 97, 24, 0.06);
            padding: 1rem;
        }

        .btn-enter {
            min-width: 180px;
            box-shadow: 0 10px 30px rgba(20, 97, 24, 0.22);
        }

        .btn-secondary-quiet {
            border-color: #d1ded1;
            color: var(--muted);
        }

        .btn-secondary-quiet:hover {
            border-color: var(--green);
            color: var(--green);
        }
        .is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 1px rgba(220,53,69,0.4);
        }

        @media (max-width: 767px) {
            .page-hero {
                padding: 1.75rem 0;
            }
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>

<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <p class="mb-1 text-uppercase small fw-bold text-white-50">Event detail</p>
                    <h1 class="fw-bold mb-1"><?php echo $event ? h($event['title']) : 'Event not found'; ?></h1>
                    <?php if ($event): ?>
                        <?php
                        $eventDate = $event['event_date'] ?? '';
                        $endDate = $event['end_date'] ?? '';
                        $dateRange = h(format_display_date($eventDate, 'Date TBC'));
                        if ($eventDate && $endDate && $endDate !== $eventDate) {
                            $dateRange .= ' to ' . h(format_display_date($endDate, 'Date TBC'));
                        }
                        ?>
                        <div class="text-white-50"><?php echo $dateRange; ?><?php echo !empty($event['venue']) ? ' • ' . h($event['venue']) : ''; ?></div>
                    <?php else: ?>
                        <div class="text-white-50">We could not find that event.</div>
                    <?php endif; ?>
                </div>
                <a class="btn btn-outline-light btn-sm" href="<?php echo h($navItemEventsUrl); ?>">Back to events</a>
            </div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>
            <?php if ($event): ?>
                <?php
                $startTime = $event['start_time'] ?? '';
                $endTime = $event['end_time'] ?? '';
                $timeRange = '';
                if (!empty($startTime)) {
                    $timeRange = date('H:i', strtotime($startTime));
                    if (!empty($endTime)) {
                        $timeRange .= ' - ' . date('H:i', strtotime($endTime));
                    }
                } elseif (!empty($endTime)) {
                    $timeRange = 'Until ' . date('H:i', strtotime($endTime));
                }
                $metaParts = [];
                if ($timeRange) {
                    $metaParts[] = $timeRange;
                }
                if (!empty($event['event_type_name'] ?? '')) {
                    $metaParts[] = $event['event_type_name'];
                }
                if (!empty($event['organiser'])) {
                    $metaParts[] = 'Organiser: ' . $event['organiser'];
                }
                $classesList = class_names_from_pricing_rows($eventPricingRows);
                if (!$classesList) {
                    $classesList = class_names_from_classes_offered($event['classes_offered'] ?? '');
                }
                ?>

                <div class="card-soft p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div class="meta-chip">
                            <span class="fw-bold"><?php echo h($event['title']); ?></span>
                        </div>
                        <a class="btn btn-outline-success btn-sm" href="<?php echo h($navItemEventsUrl); ?>">Back to events</a>
                    </div>
                    <?php if (!empty($event['description'])): ?>
                        <p class="mb-3"><?php echo h($event['description']); ?></p>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="fw-semibold mb-2">Event details</div>
                            <ul class="list-unstyled mb-0 text-muted small">
                                <?php
                                    $eventDateText = 'TBC';
                                    if (!empty($event['event_date'])) {
                                        $eventDateText = (new DateTimeImmutable((string)$event['event_date']))->format('jS M Y');
                                    }
                                    $endDateText = '';
                                    if (!empty($event['end_date']) && ($event['end_date'] !== $event['event_date'])) {
                                        $endDateText = (new DateTimeImmutable((string)$event['end_date']))->format('jS M Y');
                                    }
                                ?>
                                <li><strong class="text-dark">Dates:</strong> <?php echo h($eventDateText); ?><?php echo $endDateText !== '' ? ' to ' . h($endDateText) : ''; ?></li>
                                <li><strong class="text-dark">Venue:</strong> <?php echo h($event['venue'] ?: 'Venue TBC'); ?></li>
                                <?php if (!empty($event['organiser'])): ?>
                                    <li><strong class="text-dark">Organiser:</strong> <?php echo h($event['organiser']); ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <div class="fw-semibold mb-2">Classes offered</div>
                            <?php if ($classesList): ?>
                                <ul class="list-unstyled mb-0 text-muted small">
                                    <?php foreach ($classesList as $cls): ?>
                                        <li><?php echo h($cls); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="text-muted small">No classes available to book.</div>
                            <?php endif; ?>
                            <?php if ($hasLimit): ?>
                                <div class="text-muted small mt-2">Entries: <?php echo h($totalEntryCount); ?> / <?php echo h($capacityLimit); ?><?php if ($isFull): ?> · <span class="text-danger fw-semibold">Event full</span><?php endif; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$entriesAvailable): ?>
                    <div class="card-soft card-strong p-4 mb-4">
                        <?php
                            $entryCardClass = 'entry-state-open';
                            $entryCardHeading = 'Entries open';
                            $entryCardDetail = $entryStateMessage;
                            if ($isFull) {
                                $entryCardClass = 'entry-state-closed';
                                $entryCardHeading = 'Event full';
                                $entryCardDetail = 'No further entries can be accepted.';
                            } elseif ($entryOpenDt && $now < $entryOpenDt) {
                                $entryCardClass = 'entry-state-opening';
                                $entryCardHeading = ($hasAnyActiveMembership ? 'Member' : 'Non-member') . ' entries open on ' . $entryOpenDt->format('jS M Y');
                                $entryCardDetail = $entryStateMessage;
                            } elseif ($entryCloseDt && $now > $entryCloseDt) {
                                $entryCardClass = 'entry-state-closed';
                                $entryCardHeading = 'Entries have closed';
                                $entryCardDetail = 'Closed on ' . $entryCloseDt->format('jS M Y') . ' at ' . $entryCloseDt->format('H:i') . '.';
                            }
                        ?>
                        <div class="entry-state <?php echo h($entryCardClass); ?> mb-2"><?php echo h($entryCardHeading); ?></div>
                        <div class="text-muted mb-0"><?php echo h($entryCardDetail); ?></div>
                    </div>
                <?php else: ?>
                    <div class="card-soft card-strong p-4 mb-4">
                        <div class="mb-3">
                                <?php if ($isFull): ?>
                                    <div class="alert alert-danger mt-2 py-2 mb-0">Event full. No further entries can be accepted.</div>
                                    <br/>
                                <?php elseif ($canViewAdmin && !$entriesOpenNow): ?>
                                    <div class="alert alert-warning mt-2 py-2 mb-0">Admin preview: <?php echo h($entryStateMessage); ?> (visitors cannot enter)</div>
                                    <br/>
                                <?php elseif ($canViewAdmin): ?>
                                    <div class="alert alert-info mt-2 py-2 mb-0">Admin note: <?php echo h($entryStateMessage); ?></div>
                                    <br/>
                            <?php endif; ?>

                            <div class="section-title mb-1">Enter this event</div>
                            <?php if ($isLoggedIn): ?>
                                <div class="text-muted small">Enter your details to take part in this event. Fields marked <span class="text-danger">*</span> are required.</div>
                            <?php else: ?>
                                <div class="text-muted small">Please sign in to enter this event.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($isFull): ?>
                            <div class="alert alert-danger mb-3">Event full. No further entries can be accepted.</div>
                        <?php endif; ?>
                        <?php if (!$isLoggedIn): ?>
                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                <div class="text-muted small mb-0">You’ll need an account so we can attach this entry to you and manage your bookings.</div>
                                <a class="btn btn-success" href="<?php echo h($basePath); ?>/account">Login / Register</a>
                            </div>
                        <?php elseif (!$classOptions): ?>
                            <div class="text-muted small mb-0">No classes available to book.</div>
                        <?php else: ?>
                                <form method="POST" class="row g-4 px-2 px-md-3" id="entryForm" novalidate>
                                <input type="hidden" name="action" value="add_booking">
                                <input type="hidden" name="person_id" id="personId" value="">
                                <input type="hidden" name="horse_id" id="horseId" value="">

	                                <?php if ($isLoggedIn): ?>
	                                    <div class="col-12 form-section">
	                                        <div class="fw-bold mb-2">Save time</div>
	                                        <div class="row g-3">
	                                            <div class="col-12 col-md-6">
	                                                <label class="form-label" for="prefillPerson">Person <span class="text-muted">(optional)</span></label>
                                                <select class="form-select person-type-select" id="prefillPerson" <?php echo $people ? '' : 'disabled'; ?>>
	                                                    <option value="">Choose...</option>
	                                                    <?php foreach ($people as $p): ?>
	                                                        <?php
	                                                        $pId = (int)($p['id'] ?? 0);
	                                                        $pLabel = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
	                                                        if ($pLabel === '') {
	                                                            $pLabel = 'Person #' . $pId;
	                                                        }
                                                            $personType = personRecordType($p, $currentUser ?? []);
                                                            $pLabel = personRecordTypeMarker($personType) . ' ' . $pLabel;
	                                                        if (!empty($peopleWithActiveMembership[$pId])) {
	                                                            $pLabel .= ' (membership active)';
	                                                        }
                                                            $isJuniorPerson = strcasecmp((string)($p['junior_or_senior'] ?? ''), 'Junior') === 0;
	                                                        ?>
	                                                        <option value="<?php echo $pId; ?>" data-member-eligible="<?php echo !empty($memberPriceEligibleByPerson[$pId]) ? '1' : '0'; ?>" data-person-junior="<?php echo $isJuniorPerson ? '1' : '0'; ?>"><?php echo h($pLabel); ?></option>
	                                                    <?php endforeach; ?>
	                                                </select>
	                                                <div class="small text-muted mt-1">
	                                                    <?php if ($people): ?>
	                                                        Select a saved person to prefill matching fields.
	                                                    <?php else: ?>
	                                                        No saved people yet.
	                                                    <?php endif; ?>
	                                                    <a href="<?php echo h($basePath); ?>/account?view=people">Manage people</a>
	                                                </div>
	                                            </div>
	                                            <div class="col-12 col-md-6">
	                                                <label class="form-label" for="prefillHorse">Horse</label>
                                                <select class="form-select" id="prefillHorse">
	                                                    <option value="">Choose...</option>
	                                                    <?php foreach ($horses as $h): ?>
	                                                        <?php
                                                            $horseLabel = (string)($h['name'] ?? 'Horse #' . (int)($h['id'] ?? 0));
                                                            if (!empty($h['is_linked'])) {
                                                                $horseLabel .= ' [linked]';
                                                            }
                                                            ?>
	                                                        <option value="<?php echo (int)($h['id'] ?? 0); ?>" <?php echo count($horses) === 1 && !empty($h['is_global_placeholder']) ? 'selected' : ''; ?>><?php echo h($horseLabel); ?></option>
	                                                    <?php endforeach; ?>
	                                                </select>
	                                                <div class="small text-muted mt-1">
	                                                    <?php if ($horses): ?>
	                                                        Select a saved horse, or choose Not Registered if the horse is not on the system.
	                                                    <?php else: ?>
	                                                        No saved horses yet.
	                                                    <?php endif; ?>
	                                                    <a href="<?php echo h($basePath); ?>/account?view=horses">Manage horses</a>
	                                                </div>
	                                            </div>
	                                        </div>
	                                    </div>
	                                <?php endif; ?>

                                <?php foreach ($entryForm as $block): ?>
                                    <?php
                                    $type = $block['type'] ?? '';
                                    $enabled = isset($block['enabled']) ? (bool)$block['enabled'] : true;
                                    if (!$enabled) {
                                        continue;
                                    }
                                    ?>
                                    <?php if ($type === 'classes'): ?>
                                        <div class="col-12 form-section">
                                            <div class="fw-bold mb-2">Class selection</div>
                                            <label class="form-label" for="classSelect">Class <span class="text-danger">*</span></label>
                                            <select name="class_code" class="form-select" id="classSelect" data-required="true">
                                                <option value="">Choose...</option>
                                                <?php foreach ($classOptions as $cls): ?>
                                                    <?php
                                                    $memberLabel = '';
                                                    $isMemberPrice = !empty($cls['is_member_price']);
                                                    if (array_key_exists('is_member_price', $cls) && $cls['is_member_price'] !== null) {
                                                        $memberLabel = $isMemberPrice ? ', member' : ', non-member';
                                                    }
                                                    $lockIcon = $isMemberPrice ? ' 🔒' : '';
                                                    $baseLabel = $cls['label'] . ($cls['price'] !== '' ? ' (' . $cls['price'] . $memberLabel . ')' : '');
                                                    $lockedLabel = $baseLabel . $lockIcon;
                                                    ?>
                                                    <option value="<?php echo h((string)($cls['value'] ?? $cls['code'])); ?>"
                                                            data-price="<?php echo h((string)$cls['price']); ?>"
                                                            data-label="<?php echo h($baseLabel); ?>"
                                                            data-label-locked="<?php echo h($lockedLabel); ?>"
                                                            data-junior-ride="<?php echo !empty($cls['is_junior_ride']) ? '1' : '0'; ?>"
                                                            <?php echo $isMemberPrice ? 'data-member-price="1" disabled' : ''; ?>>
                                                        <?php echo h($lockedLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="small helper-text" id="classPriceHint">Price will show when you pick a class. To unlock member rates, choose a member above.</div>
                                            <div class="validation-message small d-none" data-validation-for="class_code">Please choose a class.</div>
                                        </div>
                                    <?php elseif ($type === 'rider_details'): ?>
                                        <div class="col-12 form-section">
                                            <div class="fw-bold mb-2">Rider details</div>
                                            <div class="mb-3">
                                                <label class="form-label" for="riderName">Rider name <span class="text-danger">*</span></label>
                                                <input type="text" name="rider_name" id="riderName" class="form-control" data-required="true" data-prefill="person.full_name">
                                                <div class="validation-message small d-none" data-validation-for="rider_name">Rider name is required.</div>
                                            </div>
                                            <div class="mb-0 d-none" id="juniorRideFields">
                                                <label class="form-label" for="accompanyingAdult">Accompanying adult <span class="text-danger">*</span></label>
                                                <input type="text" name="accompanying_adult" id="accompanyingAdult" class="form-control" value="<?php echo h((string)($_POST['accompanying_adult'] ?? '')); ?>">
                                                <div class="validation-message small d-none" data-validation-for="accompanying_adult">Accompanying adult is required for junior rides.</div>
                                                <div class="small text-muted mt-1">This field is only needed for junior ride categories.</div>
                                            </div>
                                        </div>
                                    <?php elseif ($type === 'horse_details'): ?>
                                        <div class="col-12 form-section">
                                            <div class="fw-bold mb-2">Horse details</div>
                                            <div class="mb-0">
                                                <label class="form-label" for="horseName">Horse name <span class="text-danger">*</span></label>
                                                <input type="text" name="horse_name" id="horseName" class="form-control" data-required="true" data-prefill="horse.name">
                                                <div class="validation-message small d-none" data-validation-for="horse_name">Horse name is required.</div>
                                            </div>
                                        </div>
                                    <?php elseif ($type === 'contact'): ?>
                                        <div class="col-12 form-section">
                                            <div class="fw-bold mb-2">Contact information</div>
                                            <div class="mb-3">
                                                <label class="form-label" for="contactEmail">Email <span class="text-danger">*</span></label>
                                                <input type="email" name="contact_email" id="contactEmail" class="form-control" value="<?php echo h($currentUser['email'] ?? ''); ?>" data-required="true" data-prefill="person.email">
                                                <div class="validation-message small d-none" data-validation-for="contact_email">Please enter a valid email.</div>
                                            </div>
                                            <div class="mb-0">
                                                <label class="form-label" for="contactPhone">Phone <span class="text-muted">(optional)</span></label>
                                                <input type="text" name="contact_phone" id="contactPhone" class="form-control" placeholder="+44..." data-prefill="person.phone">
                                            </div>
                                        </div>
                                    <?php elseif ($type === 'component'): ?>
                                        <?php
                                        $cid = (int)($block['component_id'] ?? 0);
                                        if ($cid <= 0 || !isset($entryComponentsById[$cid])) {
                                            continue;
                                        }
                                        $component = $entryComponentsById[$cid];
                                        $compId = $cid;
                                        $label = $component['label_override'] ?? ($component['name'] ?? 'Extra');
                                        $ctype = $component['type'] ?? 'product';
                                        $inputKind = $component['input_kind'] ?? 'checkbox';
                                        $price = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
                                        $isChecked = isset($componentSelections[$compId]);
                                        $inputValue = $componentValues[$compId] ?? '';
                                        $quantityValue = max(0, (int)$inputValue);
                                        $hasCost = $ctype === 'product' && $price !== 0.0;
                                        $isRequiredFlag = !empty($component['is_required']);
                                        $isRequiredProduct = $ctype === 'product' && $hasCost && $isRequiredFlag;
                                        $isRequiredConsent = !$hasCost && $isRequiredFlag && $inputKind === 'checkbox';
                                        $description = trim((string)($component['description'] ?? ''));
                                        $descriptionHtml = $description !== '' ? render_wysiwyg($description) : '';
                                        $showSelector = $ctype === 'product' && !$isRequiredProduct;
                                        ?>
                                        <div class="col-12 form-section">
                                            <div class="component-card border rounded p-3" data-price="<?php echo h($price); ?>" data-product="<?php echo $ctype === 'product' ? '1' : '0'; ?>" data-required="<?php echo $isRequiredProduct ? '1' : '0'; ?>">
                                                <div class="fw-bold mb-2"><?php echo h($label); ?></div>
                                                <?php if ($descriptionHtml !== ''): ?>
                                                    <div class="text-muted small mb-3"><?php echo $descriptionHtml; ?></div>
                                                <?php endif; ?>
                                                <?php if ($inputKind === 'none'): ?>
                                                    <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($price)); ?></div><?php endif; ?>
                                                <?php elseif ($inputKind === 'quantity'): ?>
                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            step="1"
                                                            class="form-control component-input component-quantity"
                                                            id="component_<?php echo $compId; ?>"
                                                            name="component_value[<?php echo $compId; ?>]"
                                                            value="<?php echo h((string)$quantityValue); ?>"
                                                            style="max-width: 120px;"
                                                        >
                                                        <?php if ($hasCost): ?>
                                                            <span class="badge bg-light text-dark border">£<?php echo h(number_format((float)$price, 2)); ?> each</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($inputKind === 'checkbox'): ?>
                                                    <?php if ($isRequiredProduct): ?>
                                                        <!-- Mandatory price: no control, just a fixed line item -->
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <div class="fw-semibold mb-0">Included automatically</div>
                                                            <span class="badge bg-light text-dark border">Included £<?php echo h(number_format((float)$price, 2)); ?></span>
                                                        </div>
                                                        <div class="text-muted small mb-1">Required add-on, automatically included in your total.</div>
                                                        <input type="hidden" name="component[<?php echo $compId; ?>]" value="1">
                                                    <?php elseif ($isRequiredConsent): ?>
                                                        <div class="form-check d-flex align-items-center gap-2 mb-1">
                                                            <input class="form-check-input component-toggle component-consent-required" type="checkbox" value="1" id="component_select_<?php echo $compId; ?>" name="component[<?php echo $compId; ?>]" <?php echo isset($componentSelections[$compId]) ? 'checked' : ''; ?> required>
                                                            <label class="form-check-label fw-semibold" for="component_select_<?php echo $compId; ?>">Required to continue</label>
                                                        </div>
                                                        <div class="text-muted small">Must be accepted to continue.</div>
                                                    <?php else: ?>
                                                        <!-- Optional price: explicit opt-in control -->
                                                        <div class="form-check d-flex align-items-center gap-2 mb-1">
                                                            <input class="form-check-input component-toggle" type="checkbox" value="1" id="component_select_<?php echo $compId; ?>" name="component_select[<?php echo $compId; ?>]" <?php echo isset($componentSelectFlags[$compId]) ? 'checked' : ''; ?> data-price="<?php echo h($price); ?>">
                                                            <label class="form-check-label fw-semibold" for="component_select_<?php echo $compId; ?>"><?php echo $hasCost ? 'Add this option' : 'Select this option'; ?></label>
                                                            <?php if ($hasCost): ?>
                                                                <span class="badge bg-light text-dark border">+£<?php echo h(number_format((float)$price, 2)); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-muted small">Optional add-on. Tick to include.</div>
                                                    <?php endif; ?>
                                                <?php elseif ($inputKind === 'textarea'): ?>
                                                    <textarea class="form-control component-input wysiwyg-field" id="component_<?php echo $compId; ?>" name="component_value[<?php echo $compId; ?>]" rows="3" placeholder="Enter response"><?php echo h($inputValue); ?></textarea>
                                                    <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($price)); ?></div><?php endif; ?>
                                                <?php else: ?>
                                                    <input type="text" class="form-control component-input" id="component_<?php echo $compId; ?>" name="component_value[<?php echo $compId; ?>]" value="<?php echo h($inputValue); ?>" placeholder="Enter response">
                                                    <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($price)); ?></div><?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <div class="col-12">
                                        <div class="cta-row d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                            <div class="text-muted small" id="classPriceSummary">Total: £0.00</div>
                                            <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 w-100 w-md-auto">
                                                <div class="text-danger small d-none" id="formErrorSummary"></div>
                                                <div class="d-flex gap-2 w-100 w-md-auto">
                                                    <button class="btn btn-success btn-enter w-100 w-md-auto" id="submitEntry" type="submit" <?php echo $isFull ? 'disabled' : ''; ?>>
                                                        <?php echo $isFull ? 'Event full' : 'Enter event'; ?>
                                                    </button>
                                                    <a class="btn btn-outline-secondary btn-secondary-quiet w-100 w-md-auto" href="<?php echo h($basePath); ?>/basket">View basket<?php echo $basketCount ? ' (' . $basketCount . ')' : ''; ?></a>
                                                </div>
                                            </div>
                                        </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card-soft p-4">
                    <p class="mb-0">Try another event from the list.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php render_tinymce_bootstrap(); ?>
    <script>
        const classSelect = document.getElementById('classSelect');
        const priceHint = document.getElementById('classPriceHint');
        const priceSummary = document.getElementById('classPriceSummary');
        const form = document.getElementById('entryForm');
        const submitEntry = document.getElementById('submitEntry');
        const juniorRideFields = document.getElementById('juniorRideFields');
        const accompanyingAdult = document.getElementById('accompanyingAdult');

        // Optional person/horse prefills (data is baked into the page; no API calls).
        const peopleData = <?php echo json_encode(array_values($people), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const memberEligibilityByPerson = <?php echo json_encode($memberPriceEligibleByPerson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const memberActiveByPerson = <?php echo json_encode($peopleWithActiveMembership, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const memberPriceUsedByPerson = <?php echo json_encode($memberPriceUsedByPerson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const horsesData = <?php echo json_encode(array_values($horses), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const prefillPerson = document.getElementById('prefillPerson');
        const prefillHorse = document.getElementById('prefillHorse');
        const personIdInput = document.getElementById('personId');
        const horseIdInput = document.getElementById('horseId');

        const setIfEmpty = (el, value) => {
            if (!el || value == null) return;
            const current = (el.value ?? '').trim();
            if (current !== '') return;
            el.value = String(value);
        };

        const applyPrefill = (kind, data) => {
            if (!form) return;
            const targets = form.querySelectorAll('[data-prefill]');
            targets.forEach((el) => {
                const key = el.getAttribute('data-prefill') || '';
                if (!key.startsWith(kind + '.')) return;
                const field = key.slice(kind.length + 1);
                if (kind === 'person' && field === 'full_name') {
                    const fullName = [data.first_name || '', data.last_name || ''].join(' ').trim();
                    setIfEmpty(el, fullName);
                    return;
                }
                if (kind === 'horse' && field === 'name') {
                    el.value = String(data.name || '');
                    return;
                }
                if (Object.prototype.hasOwnProperty.call(data, field)) {
                    setIfEmpty(el, data[field]);
                }
            });
        };

        if (prefillPerson) {
            prefillPerson.addEventListener('change', () => {
                const id = parseInt(prefillPerson.value || '0', 10) || 0;
                if (personIdInput) personIdInput.value = id ? String(id) : '';
                const person = peopleData.find((p) => parseInt(p.id, 10) === id);
                if (person) applyPrefill('person', person);
                updateMemberPriceAvailability();
                syncJuniorRideFields();
                setPriceCopy();
                updateTotal();
            });
        }
        if (prefillHorse) {
            prefillHorse.addEventListener('change', () => {
                const id = parseInt(prefillHorse.value || '0', 10) || 0;
                if (horseIdInput) horseIdInput.value = id ? String(id) : '';
                const horse = horsesData.find((h) => parseInt(h.id, 10) === id);
                if (horse) applyPrefill('horse', horse);
            });
            if (prefillHorse.value) {
                prefillHorse.dispatchEvent(new Event('change'));
            }
        }

        const selectedPersonId = () => parseInt(prefillPerson?.value || '0', 10) || 0;
        const selectedPersonEligible = () => {
            const id = selectedPersonId();
            return !!(id && memberEligibilityByPerson && memberEligibilityByPerson[id]);
        };

        const updateMemberPriceAvailability = () => {
            if (!classSelect) return;
            const eligible = selectedPersonEligible();
            const memberOptions = Array.from(classSelect.options || []).filter((opt) => opt.dataset.memberPrice === '1');
            memberOptions.forEach((opt) => {
                const lockedLabel = opt.dataset.labelLocked || opt.textContent;
                const baseLabel = opt.dataset.label || opt.textContent;
                opt.disabled = !eligible;
                opt.textContent = eligible ? baseLabel : lockedLabel;
            });
            const selectedOpt = classSelect.selectedOptions[0];
            if (!eligible && selectedOpt?.dataset?.memberPrice === '1') {
                classSelect.value = '';
            }
        };

        const setPriceCopy = () => {
            if (!classSelect) return;
            const opt = classSelect.selectedOptions[0];
            const price = opt?.dataset?.price ?? '';
            const label = opt?.textContent?.trim() ?? '';
            const personId = selectedPersonId();
            let memberNote = 'To unlock member rates, choose a member above.';
            if (personId) {
                if (!memberActiveByPerson?.[personId]) {
                    memberNote = 'Selected person does not have an active membership.';
                } else if (memberPriceUsedByPerson?.[personId]) {
                    memberNote = 'Member rate already used for this event.';
                } else {
                    memberNote = 'Member rates unlocked for the selected person.';
                }
            }
            const hintText = price
                ? `Price for this class: ${price} ${memberNote}`
                : `Price will show when you pick a class. ${memberNote}`;
            if (priceHint) {
                priceHint.textContent = hintText;
            }
            if (priceSummary) {
                priceSummary.textContent = price ?
                    `Selected: ${label || 'class'} — ${price}` :
                    'Select a class to see the price.';
            }
        };

        const selectedClassIsJuniorRide = () => {
            const opt = classSelect?.selectedOptions?.[0];
            return opt?.dataset?.juniorRide === '1';
        };
        const selectedPersonIsJunior = () => {
            const opt = prefillPerson?.selectedOptions?.[0];
            return opt?.dataset?.personJunior === '1';
        };

        const syncJuniorRideFields = () => {
            const isJuniorRide = selectedClassIsJuniorRide() || selectedPersonIsJunior();
            if (juniorRideFields) {
                juniorRideFields.classList.toggle('d-none', !isJuniorRide);
            }
            if (accompanyingAdult) {
                accompanyingAdult.dataset.required = isJuniorRide ? 'true' : 'false';
                accompanyingAdult.required = isJuniorRide;
                if (!isJuniorRide) {
                    const messageEl = form?.querySelector('[data-validation-for="accompanying_adult"]');
                    accompanyingAdult.classList.remove('is-invalid');
                    if (messageEl) {
                        messageEl.classList.add('d-none');
                    }
                }
            }
        };

        const validateField = (field) => {
            if (!form) return true;
            const name = field?.name ?? '';
            const value = (field?.value ?? '').trim();
            const messageEl = form.querySelector(`[data-validation-for="${name}"]`);
            let isValid = value !== '';
            if (isValid && field.type === 'email') {
                isValid = /\S+@\S+\.\S+/.test(value);
            }
            if (messageEl) {
                messageEl.classList.toggle('d-none', isValid);
            }
            field.classList.toggle('is-invalid', !isValid);
            return isValid;
        };

        const focusFirstInvalid = () => {
            if (!form) return false;
            const requiredFields = Array.from(form.querySelectorAll('[data-required="true"]'));
            for (const field of requiredFields) {
                const ok = validateField(field);
                if (!ok) {
                    field.focus({preventScroll:true});
                    field.scrollIntoView({behavior: 'smooth', block: 'center'});
                    const label = form.querySelector(`label[for="${field.id}"]`);
                    const summary = document.getElementById('formErrorSummary');
                    if (summary) {
                        const labelText = label?.textContent?.trim() || 'This field';
                        summary.textContent = `${labelText} is required.`;
                        summary.classList.remove('d-none');
                    }
                    return true;
                }
            }
            const consentBoxes = Array.from(form.querySelectorAll('.component-consent-required'));
            for (const box of consentBoxes) {
                if (!box.checked) {
                    box.classList.add('is-invalid');
                    box.scrollIntoView({behavior: 'smooth', block: 'center'});
                    box.focus({preventScroll:true});
                    const summary = document.getElementById('formErrorSummary');
                    if (summary) {
                        summary.textContent = 'Please accept the required option to continue.';
                        summary.classList.remove('d-none');
                    }
                    return true;
                }
            }
            const summary = document.getElementById('formErrorSummary');
            if (summary) {
                summary.classList.add('d-none');
                summary.textContent = '';
            }
            return false;
        };

        const validateForm = () => {
            if (!form) return true;
            const requiredFields = Array.from(form.querySelectorAll('[data-required="true"]'));
            let allValid = requiredFields.every((field) => validateField(field));
            const requiredConsents = Array.from(form.querySelectorAll('.component-consent-required'));
            requiredConsents.forEach((box) => {
                const ok = box.checked;
                box.classList.toggle('is-invalid', !ok);
                if (!ok) {
                    allValid = false;
                }
            });
            const summary = document.getElementById('formErrorSummary');
            if (summary) {
                if (allValid) {
                    summary.classList.add('d-none');
                    summary.textContent = '';
                } else {
                    summary.textContent = 'Please complete the highlighted required fields.';
                    summary.classList.remove('d-none');
                }
            }
            return allValid;
        };

        const parsePrice = (val) => {
            if (typeof val !== 'string') return Number(val) || 0;
            const num = val.replace(/[^0-9.\\-]/g, '');
            return parseFloat(num) || 0;
        };

        const updateTotal = () => {
            let total = 0;
            const selectedOpt = classSelect?.selectedOptions?.[0];
            if (selectedOpt?.dataset?.price) {
                total += parsePrice(selectedOpt.dataset.price);
            }
            document.querySelectorAll('.component-card').forEach((card) => {
                const isProduct = card.dataset.product === '1';
                if (!isProduct) {
                    return;
                }
                const price = parsePrice(card.dataset.price || '0');
                const isRequired = card.dataset.required === '1';
                const toggle = card.querySelector('.component-toggle');
                const qtyInput = card.querySelector('.component-quantity');
                const quantity = qtyInput ? Math.max(0, parseInt(qtyInput.value || '0', 10) || 0) : 0;
                const include = isRequired || (qtyInput ? quantity > 0 : (toggle && toggle.checked));
                if (include) {
                    total += qtyInput ? (price * quantity) : price;
                    card.querySelectorAll('.component-input').forEach((inp) => {
                        if (!isRequired && !inp.classList.contains('component-quantity')) {
                            inp.disabled = false;
                        }
                    });
                } else {
                    card.querySelectorAll('.component-input').forEach((inp) => {
                        if (!inp.classList.contains('component-quantity')) {
                            inp.disabled = true;
                        }
                    });
                }
            });
            if (priceSummary) {
                priceSummary.textContent = `Total: £${total.toFixed(2)}`;
            }
        };

        if (form) {
            form.addEventListener('input', (e) => {
                const target = e.target;
                if (target?.matches('[data-required="true"]')) {
                    validateField(target);
                    validateForm();
                }
                if (target?.classList?.contains('component-toggle') || target?.classList?.contains('component-quantity') || target === classSelect) {
                    updateTotal();
                }
                if (target === classSelect) {
                    syncJuniorRideFields();
                }
            });
            form.addEventListener('change', (e) => {
                const target = e.target;
                if (target?.matches('[data-required="true"]')) {
                    validateField(target);
                    validateForm();
                }
                if (target?.classList?.contains('component-toggle') || target?.classList?.contains('component-quantity') || target === classSelect) {
                    updateTotal();
                }
                if (target === classSelect) {
                    syncJuniorRideFields();
                }
            });
            form.addEventListener('submit', (e) => {
                syncJuniorRideFields();
                if (!validateForm()) {
                    e.preventDefault();
                    focusFirstInvalid();
                }
            });
        }

        if (submitEntry) {
            submitEntry.addEventListener('click', () => {
                if (!validateForm()) {
                    focusFirstInvalid();
                }
            });
        }

        if (classSelect) {
            classSelect.addEventListener('change', () => {
                setPriceCopy();
                syncJuniorRideFields();
                updateTotal();
            });
            updateMemberPriceAvailability();
            setPriceCopy();
            syncJuniorRideFields();
        }
        updateTotal();
        if (window.tinymce) {
            tinymce.init(window.ildraTinyMceConfig({
                selector: 'textarea.wysiwyg-field',
            }));
        }
    </script>
</body>

</html>
