<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../finance.php';
require_once __DIR__ . '/../bookings_store.php';

$isAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager', 'organiser'], true);
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$sortKey = $_GET['sort'] ?? 'placed';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$manage = ($_GET['manage'] ?? '') === '1';
$printMode = ($_GET['print'] ?? '') === 'ride_list';

$sortFields = [
    'booking_ref' => 'b.booking_ref',
    'placed' => 'b.created_at',
    'contact' => 'b.contact_name',
    'entry' => 'bi.event_title',
    'class' => 'meta_class_label',
    'rider' => 'meta_rider_name',
    'horse' => 'meta_horse_name',
    'price' => 'bi.price',
];
$event = $eventId ? fetchEventById($pdo, $eventId) : null;

// Ensure withdrawal columns exist before running entry list queries.
if ($pdo && $eventId > 0) {
    ensure_bookings_tables($pdo);
}

function sort_link_entries(int $eventId, string $key, string $label, string $currentKey, string $currentDir, bool $manage): string
{
    $dir = ($currentKey === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '↕';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? '↑' : '↓';
    }
    $url = '?event_id=' . $eventId . '&sort=' . urlencode($key) . '&dir=' . urlencode($dir);
    if ($manage) {
        $url .= '&manage=1';
    }
    return '<a class="text-decoration-none text-dark sort-link" href="' . h($url) . '">' . h($label) . '<span class="sort-arrow">' . h($arrow) . '</span></a>';
}

function fetch_entries_for_event(PDO $pdo, int $eventId, string $orderBy, string $orderDir, string $scope): array
{
    $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
    $allowedCols = [
        'b.booking_ref',
        'b.created_at',
        'b.contact_name',
        'bi.event_title',
        'meta_class_label',
        'meta_rider_name',
        'meta_horse_name',
        'bi.price'
    ];
    if (!in_array($orderBy, $allowedCols, true)) {
        $orderBy = 'b.created_at';
    }
    $withdrawnClause = 'COALESCE(bi.is_withdrawn, 0) = 0';
    if ($scope === 'withdrawn') {
        $withdrawnClause = 'COALESCE(bi.is_withdrawn, 0) = 1';
    } elseif ($scope === 'all') {
        $withdrawnClause = '1=1';
    }
    $stmt = $pdo->prepare("
        SELECT
            bi.*,
            JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.class_label')) AS meta_class_label,
            JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.rider_name')) AS meta_rider_name,
            JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.horse_name')) AS meta_horse_name,
            b.booking_ref,
            b.created_at AS booking_created_at,
            b.user_id,
            b.contact_name,
            b.contact_email,
            b.contact_phone
        FROM booking_items bi
        LEFT JOIN bookings b ON (bi.booking_id = b.new_id)
        WHERE bi.event_id = :eid
          AND $withdrawnClause
        ORDER BY $orderBy $orderDir, bi.id $orderDir
    ");
    $stmt->execute([':eid' => $eventId]);
    $rows = $stmt->fetchAll() ?: [];
    return array_map('hydrate_booking_item', $rows);
}

function format_fee_status(float $paid, float $due): array
{
    $diff = $paid - $due;
    if (abs($due) < 0.005 && abs($paid) < 0.005) {
        return ['label' => 'NIL (£0.00)', 'class' => 'fee-nil'];
    }
    if (abs($diff) < 0.005) {
        return ['label' => 'PAID (' . format_price($due) . ')', 'class' => 'fee-paid'];
    }
    if ($diff > 0) {
        return ['label' => 'OVERPAID by ' . format_price($diff), 'class' => 'fee-overpaid'];
    }
    return ['label' => 'UNDERPAID by ' . format_price(abs($diff)), 'class' => 'fee-underpaid'];
}

function entry_rosette_label(array $entry): string
{
    $meta = $entry['metadata'] ?? [];
    $components = is_array($meta['components'] ?? null) ? $meta['components'] : [];
    foreach ($components as $component) {
        if (!is_array($component)) {
            continue;
        }
        $name = trim((string)($component['name'] ?? $component['label'] ?? ''));
        if (strcasecmp($name, 'Rosette') !== 0) {
            continue;
        }
        $inputKind = (string)($component['input_kind'] ?? 'checkbox');
        if ($inputKind === 'quantity') {
            return max(0, (int)($component['quantity'] ?? $component['value'] ?? 0)) > 0 ? 'Yes' : 'No';
        }
        if ($inputKind === 'checkbox') {
            return 'Yes';
        }
        $value = trim((string)($component['value'] ?? ''));
        return $value !== '' ? 'Yes' : 'No';
    }
    return 'No';
}

function rider_name_parts(array $entry): array
{
    $meta = $entry['metadata'] ?? [];
    $rider = trim((string)($meta['rider_name'] ?? $entry['rider_name'] ?? ''));
    if ($rider === '') {
        return ['first' => '', 'surname' => ''];
    }
    $parts = preg_split('/\s+/', $rider) ?: [$rider];
    if (count($parts) === 1) {
        return ['first' => '', 'surname' => $parts[0]];
    }
    $surname = (string)array_pop($parts);
    $first = trim(implode(' ', $parts));
    return ['first' => $first, 'surname' => $surname];
}

$orderBy = $sortFields[$sortKey] ?? 'b.created_at';
$entries = ($event && $pdo) ? fetch_entries_for_event($pdo, $eventId, $orderBy, strtoupper($sortDir), 'active') : [];
$withdrawnEntries = ($event && $pdo) ? fetch_entries_for_event($pdo, $eventId, $orderBy, strtoupper($sortDir), 'withdrawn') : [];

$printEntries = $entries;
usort($printEntries, static function (array $a, array $b): int {
    $nameA = rider_name_parts($a);
    $nameB = rider_name_parts($b);
    $surnameCompare = strcasecmp($nameA['surname'], $nameB['surname']);
    if ($surnameCompare !== 0) {
        return $surnameCompare;
    }
    $firstCompare = strcasecmp($nameA['first'], $nameB['first']);
    if ($firstCompare !== 0) {
        return $firstCompare;
    }
    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
});

// Identify which withdrawn entries were also refunded (using finance transaction metadata.booking_item_id).
$withdrawnCount = 0;
$refundedCount = 0;
$refundedIds = [];
if ($event && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM booking_items WHERE event_id = :eid AND COALESCE(is_withdrawn, 0) = 1");
        $stmt->execute([':eid' => $eventId]);
        $withdrawnIdsAll = array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
        $withdrawnCount = count($withdrawnIdsAll);

        if ($withdrawnIdsAll) {
            $placeholders = implode(',', array_fill(0, count($withdrawnIdsAll), '?'));
            $q = $pdo->prepare("
                SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) AS booking_item_id
                FROM finance_transactions
                WHERE type = 'entry_refund'
                  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) IN ($placeholders)
            ");
            $q->execute($withdrawnIdsAll);
            foreach ($q->fetchAll() ?: [] as $row) {
                $id = (int)($row['booking_item_id'] ?? 0);
                if ($id > 0) {
                    $refundedIds[$id] = true;
                }
            }
            $refundedCount = count($refundedIds);
        }
    } catch (PDOException $e) {
        $withdrawnCount = 0;
        $refundedCount = 0;
        $refundedIds = [];
    }
}

$bookingTotals = [];
if ($event && $pdo && ($entries || $withdrawnEntries)) {
    $refs = [];
    foreach (array_merge($entries, $withdrawnEntries) as $entry) {
        $ref = trim((string)($entry['booking_ref'] ?? ''));
        if ($ref !== '') {
            $refs[$ref] = true;
        }
    }
    if ($refs) {
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        $refValues = array_keys($refs);
        try {
            $stmt = $pdo->prepare("
                SELECT
                    b.booking_ref,
                    SUM(bi.price) AS total_all,
                    SUM(CASE WHEN COALESCE(bi.is_withdrawn, 0) = 0 THEN bi.price ELSE 0 END) AS total_active,
                    SUM(CASE WHEN COALESCE(bi.is_withdrawn, 0) = 1 THEN bi.price ELSE 0 END) AS total_withdrawn
                FROM booking_items bi
                LEFT JOIN bookings b ON bi.booking_id = b.new_id
                WHERE b.booking_ref IN ($placeholders)
                GROUP BY b.booking_ref
            ");
            $stmt->execute($refValues);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $ref = (string)($row['booking_ref'] ?? '');
                if ($ref === '') {
                    continue;
                }
                $bookingTotals[$ref] = [
                    'all' => (float)($row['total_all'] ?? 0),
                    'active' => (float)($row['total_active'] ?? 0),
                    'withdrawn' => (float)($row['total_withdrawn'] ?? 0),
                    'refund' => 0.0,
                ];
            }
        } catch (PDOException $e) {
            $bookingTotals = [];
        }
        if ($bookingTotals && ensure_finance_tables($pdo)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT reference, SUM(amount) AS refund_total
                    FROM finance_transactions
                    WHERE type = 'entry_refund'
                      AND reference IN ($placeholders)
                    GROUP BY reference
                ");
                $stmt->execute($refValues);
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    $ref = (string)($row['reference'] ?? '');
                    if ($ref !== '' && isset($bookingTotals[$ref])) {
                        $bookingTotals[$ref]['refund'] = (float)($row['refund_total'] ?? 0);
                    }
                }
            } catch (PDOException $e) {
                // ignore refund totals on failure
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event && $pdo) {
    ensure_bookings_tables($pdo);
    $action = $_POST['action'] ?? '';
    $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    if ($itemId <= 0) {
        $_SESSION['flash_alerts'] = [['type' => 'danger', 'message' => 'Invalid entry.']];
    } else {
        $stmt = $pdo->prepare("
            SELECT bi.id, bi.price, COALESCE(bi.is_withdrawn, 0) AS is_withdrawn, bi.booking_id, b.booking_ref, b.user_id
            FROM booking_items bi
            LEFT JOIN bookings b ON bi.booking_id = b.new_id
            WHERE bi.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch();
        if (!$row) {
            $_SESSION['flash_alerts'] = [['type' => 'danger', 'message' => 'Entry not found.']];
        } elseif ((int)$row['is_withdrawn'] === 1) {
            $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Entry is already withdrawn.']];
        } else {
            $alerts = [];
            if ($action === 'withdraw_refund') {
                $userId = (int)($row['user_id'] ?? 0);
                if ($userId <= 0) {
                    $alerts[] = ['type' => 'danger', 'message' => 'Cannot refund: booking has no user.'];
                } else {
                    $actorName = trim((string)(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));
                    if ($actorName === '') {
                        $actorName = (string)($currentUser['email'] ?? 'Admin');
                    }
                    $refundAmount = price_to_number($row['price'] ?? 0);
                    $refundNotes = 'Entry refunded and withdrawn by admin';
                    if ($actorName !== '') {
                        $refundNotes .= ' (' . $actorName . ')';
                    }
                    record_finance_transaction($pdo, [
                        'user_id' => $userId,
                        'type' => 'entry_refund',
                        'amount' => $refundAmount,
                        'reference' => (string)($row['booking_ref'] ?? null),
                        'notes' => $refundNotes,
                        'metadata' => [
                            'event_id' => $eventId,
                            'booking_item_id' => $itemId,
                            'actor_user_id' => (int)($currentUser['id'] ?? 0),
                            'actor_name' => $actorName,
                        ],
                    ], $alerts);
                }
            }
            if (!$alerts && ($action === 'withdraw' || $action === 'withdraw_refund')) {
                $upd = $pdo->prepare("
                    UPDATE booking_items
                    SET is_withdrawn = 1,
                        withdrawn_at = NOW(),
                        withdrawn_by_user_id = :by
                    WHERE id = :id
                    LIMIT 1
                ");
                $upd->execute([':by' => (int)($currentUser['id'] ?? 0), ':id' => $itemId]);
            }
            $_SESSION['flash_alerts'] = $alerts ?: [['type' => 'success', 'message' => 'Entry withdrawn.']];
        }
    }
    $redir = 'event_entries.php?event_id=' . $eventId;
    if ($manage) {
        $redir .= '&manage=1';
    }
    $redir .= '&sort=' . urlencode((string)$sortKey) . '&dir=' . urlencode((string)$sortDir);
    header('Location: ' . $redir);
    exit;
}

$pageTitle = $event ? (string)($event['title'] ?? 'Event') : 'Event entries';

if ($event && $printMode) {
    $rideDateText = format_display_date($event['event_date'] ?? null, 'Date TBC');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(($event['title'] ?? 'Ride list') . ' ride list'); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            margin: 0;
            font-size: 12px;
            line-height: 1.35;
        }
        .print-shell {
            width: 90%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 14px 0 18px;
        }
        .print-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 14px;
        }
        .print-actions a,
        .print-actions button {
            border: 1px solid #888;
            background: #fff;
            color: #111;
            padding: 7px 10px;
            font-size: 12px;
            text-decoration: none;
            cursor: pointer;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 4px;
        }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 14px;
        }
        .header-main {
            flex: 1 1 auto;
            min-width: 0;
        }
        .header-organiser {
            flex: 0 0 auto;
            text-align: right;
            font-size: 12px;
            white-space: nowrap;
        }
        .subtitle {
            margin: 0;
            font-size: 13px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #333;
            padding: 6px 7px;
            vertical-align: top;
        }
        th {
            text-align: left;
            background: #f2f2f2;
        }
        td.blank-col {
            height: 24px;
            width: 11%;
        }
        .small-note {
            margin-top: 8px;
            color: #444;
            font-size: 11px;
        }
        @media print {
            .print-shell {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
            }
            .print-actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-shell">
        <div class="print-actions">
            <button type="button" onclick="window.print()">Print</button>
            <button type="button" onclick="window.close()">Close window</button>
        </div>

        <div class="header-row">
            <div class="header-main">
                <h1><?php echo h((string)($event['title'] ?? 'Ride list')); ?></h1>
                <div class="subtitle">Ride date: <?php echo h($rideDateText); ?></div>
            </div>
            <?php if (!empty($event['organiser'])): ?>
                <div class="header-organiser">Ride Organiser: <?php echo h((string)$event['organiser']); ?></div>
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:6%;">#</th>
                    <th style="width:16%;">Surname</th>
                    <th style="width:16%;">First name</th>
                    <th style="width:17%;">Horse Name</th>
                    <th style="width:17%;">Class</th>
                    <th style="width:10%;">Rosette</th>
                    <th style="width:10%;">Out</th>
                    <th style="width:10%;">In</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($printEntries as $index => $entry): ?>
                    <?php
                        $meta = $entry['metadata'] ?? [];
                        $nameParts = rider_name_parts($entry);
                        $horse = trim((string)($meta['horse_name'] ?? $entry['horse_name'] ?? ''));
                        $classLabel = trim((string)($meta['class_label'] ?? $entry['class_label'] ?? ''));
                        $rosetteLabel = entry_rosette_label($entry);
                    ?>
                    <tr>
                        <td><?php echo (int)($index + 1); ?></td>
                        <td><?php echo h($nameParts['surname'] !== '' ? $nameParts['surname'] : '—'); ?></td>
                        <td><?php echo h($nameParts['first'] !== '' ? $nameParts['first'] : '—'); ?></td>
                        <td><?php echo h($horse !== '' ? $horse : '—'); ?></td>
                        <td><?php echo h($classLabel !== '' ? $classLabel : '—'); ?></td>
                        <td><?php echo h($rosetteLabel); ?></td>
                        <td class="blank-col"></td>
                        <td class="blank-col"></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$printEntries): ?>
                    <tr>
                        <td colspan="8">No accepted entries yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="small-note">This print view is suitable for A4 portrait and can also be saved as PDF from the browser print dialog.</div>
    </div>
</body>
</html>
<?php
    exit;
}

admin_layout_start($pageTitle, 'events');
?>
<style>
    .entries-card { border-radius: 18px; border: 1px solid rgba(0,0,0,0.06); background: #fff; box-shadow: 0 14px 40px rgba(0,0,0,0.06); }
    .entries-table thead th { background: #f5f7f3; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.03em; }
    .entries-table td { vertical-align: middle; }
    .sort-link { display: inline-flex; align-items: center; gap: 0.35rem; }
    .sort-arrow { display: inline-block; width: 1ch; text-align: center; color: #888; }
    .withdrawn-row { opacity: 0.75; }
    .fee-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.6rem; border-radius: 999px; font-weight: 700; letter-spacing: 0.02em; white-space: nowrap; }
    .fee-paid { background: #1f7c24; color: #fff; }
    .fee-nil { background: #f3f4f6; color: #111827; border: 1px solid rgba(0,0,0,0.08); }
    .fee-overpaid { background: #f3f4f6; color: #111827; border: 1px solid rgba(0,0,0,0.08); }
    .fee-underpaid { background: #b91c1c; color: #fff; }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <?php if ($event): ?>
            <?php
                $entryCount = count($entries);
                $limitEnabled = !empty($event['capacity_enabled']);
                $limit = (int)($event['capacity_limit'] ?? 0);
                $isLimited = $limitEnabled && $limit > 0;
                $withdrawnOnlyCount = max(0, $withdrawnCount - $refundedCount);
            ?>
            <div class="small text-muted">Event entries</div>
            <div class="fw-semibold"><?php echo (int)$entryCount; ?> Accepted</div>
            <?php if ($isLimited): ?>
                <div class="text-muted small">Event limited to <?php echo (int)$limit; ?></div>
            <?php endif; ?>
            <div class="text-muted small"><?php echo (int)$withdrawnCount; ?> Withdrawn</div>
            <div class="text-muted small">
                Of which <?php echo (int)$withdrawnOnlyCount; ?> Withdrawn Only,
                <?php echo (int)$refundedCount; ?> Withdrawn &amp; Refunded
            </div>
        <?php else: ?>
            <div class="text-muted small">Select an event to view entries.</div>
        <?php endif; ?>
    </div>
    <div class="admin-page-actions">
        <a class="btn btn-outline-secondary has-icon" href="events.php"><i class="fa-solid fa-arrow-left btn-icon"></i><span class="btn-label">Back to events</span></a>
        <?php if ($event): ?>
            <a class="btn btn-outline-primary has-icon" href="event_entries.php?event_id=<?php echo (int)$eventId; ?>&print=ride_list" target="_blank" rel="noopener"><i class="fa-solid fa-print btn-icon"></i><span class="btn-label">Print Ride List</span></a>
            <?php if (!$manage): ?>
                <a class="btn btn-outline-secondary has-icon" href="event_entries.php?event_id=<?php echo (int)$eventId; ?>&manage=1"><i class="fa-solid fa-sliders btn-icon"></i><span class="btn-label">Manage entries</span></a>
            <?php else: ?>
                <a class="btn btn-outline-secondary has-icon" href="event_entries.php?event_id=<?php echo (int)$eventId; ?>"><i class="fa-solid fa-check btn-icon"></i><span class="btn-label">Done</span></a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($event): ?>
            <a class="btn btn-outline-success has-icon" href="event_edit.php?id=<?php echo (int)$eventId; ?>"><i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit event</span></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$event): ?>
    <div class="alert alert-info">No event selected. Use the entry list link from the events page.</div>
<?php else: ?>
    <div class="entries-card p-3">
        <div class="table-responsive">
        <table class="table table-sm align-middle entries-table">
	            <thead>
	                <tr>
	                    <th><?php echo sort_link_entries((int)$eventId, 'booking_ref', 'Booking ref', $sortKey, $sortDir, $manage); ?></th>
	                    <th><?php echo sort_link_entries((int)$eventId, 'placed', 'Placed', $sortKey, $sortDir, $manage); ?></th>
	                    <th><?php echo sort_link_entries((int)$eventId, 'contact', 'Contact', $sortKey, $sortDir, $manage); ?></th>
	                    <th><?php echo sort_link_entries((int)$eventId, 'entry', 'Entry', $sortKey, $sortDir, $manage); ?></th>
	                    <th><?php echo sort_link_entries((int)$eventId, 'class', 'Class', $sortKey, $sortDir, $manage); ?></th>
	                    <th><?php echo sort_link_entries((int)$eventId, 'rider', 'Rider', $sortKey, $sortDir, $manage); ?></th>
	                    <th><?php echo sort_link_entries((int)$eventId, 'horse', 'Horse', $sortKey, $sortDir, $manage); ?></th>
                    <th>Rosette</th>
                    <th><?php echo sort_link_entries((int)$eventId, 'price', 'Fees Due (£)', $sortKey, $sortDir, $manage); ?></th>
	                    <th class="text-end">Actions</th>
	                </tr>
	            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php
                        $meta = $entry['metadata'] ?? [];
                        $classLabel = $meta['class_label'] ?? ($entry['class_label'] ?? '');
                        $rider = $meta['rider_name'] ?? ($entry['rider_name'] ?? '');
                        $horse = $meta['horse_name'] ?? ($entry['horse_name'] ?? '');
                        $entryPrice = price_to_number($entry['price'] ?? 0);
                        $price = format_price($entryPrice);
                        $placedAt = $entry['booking_created_at'] ?? $entry['created_at'] ?? null;
                        $placedText = format_display_datetime($placedAt, '—');
                        $contactName = $entry['contact_name'] ?? '';
                        if ($contactName === '' && !empty($entry['contact_email'])) {
                            $contactName = $entry['contact_email'];
                        }
                        $bookingRef = (string)($entry['booking_ref'] ?? '');
                        $totals = $bookingRef !== '' && isset($bookingTotals[$bookingRef]) ? $bookingTotals[$bookingRef] : ['all' => 0.0, 'active' => 0.0, 'withdrawn' => 0.0, 'refund' => 0.0];
                        $paidPool = $totals['all'] - $totals['refund'];
                        $activeTotal = $totals['active'] > 0 ? $totals['active'] : 0.0;
                        $isWithdrawnRow = !empty($entry['is_withdrawn']);
                        if ($isWithdrawnRow) {
                            $withdrawnTotal = $totals['withdrawn'] > 0 ? $totals['withdrawn'] : 0.0;
                            $refundShare = $withdrawnTotal > 0 ? ($totals['refund'] * ($entryPrice / $withdrawnTotal)) : 0.0;
                            $paidForEntry = max(0.0, $entryPrice - $refundShare);
                            $feeDue = 0.0;
                        } else {
                            $paidForEntry = $activeTotal > 0 ? ($paidPool * ($entryPrice / $activeTotal)) : 0.0;
                            $feeDue = $entryPrice;
                        }
                        $feeStatus = format_fee_status($paidForEntry, $feeDue);
                        $rosetteLabel = entry_rosette_label($entry);
                    ?>
                    <tr class="<?php echo !empty($entry['is_withdrawn']) ? 'withdrawn-row' : ''; ?>">
                        <td class="fw-semibold text-muted text-dark"><?php echo h($entry['booking_ref'] ?? ''); ?></td>
                        <td class="small text-muted"><?php echo h($placedText); ?></td>
                        <td class="small">
                            <div><?php echo h($contactName ?: '—'); ?></div>
                            <?php if (!empty($entry['contact_email'])): ?><div class="text-muted"><?php echo h($entry['contact_email']); ?></div><?php endif; ?>
                            <?php if (!empty($entry['contact_phone'])): ?><div class="text-muted"><?php echo h($entry['contact_phone']); ?></div><?php endif; ?>
                        </td>
                        <td class="small fw-semibold">
                            <?php echo h($entry['event_title'] ?? ''); ?>
                            <?php if (!empty($entry['is_withdrawn'])): ?>
                                <span class="badge bg-light text-dark border ms-2">Withdrawn</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo h($classLabel ?: '—'); ?></td>
                        <td class="small"><?php echo h($rider ?: '—'); ?></td>
                        <td class="small"><?php echo h($horse ?: '—'); ?></td>
                        <td class="small"><?php echo h($rosetteLabel); ?></td>
                        <td class="small"><span class="fee-badge <?php echo h($feeStatus['class']); ?>"><?php echo h($feeStatus['label']); ?></span></td>
                        <td class="text-end">
                            <?php if (!$manage): ?>
                                <div class="btn-group-mobile" role="group" aria-label="Entry actions">
                                    <?php
                                    $itemId = (int)($entry['id'] ?? 0);
                                    $hubGuid = ($pdo && $itemId > 0) ? ensure_booking_item_guid($pdo, $itemId) : null;
                                    ?>
                                    <a class="btn btn-sm btn-outline-secondary has-icon" href="entry_item.php?item_id=<?php echo $itemId; ?>&event_id=<?php echo (int)$eventId; ?>"><i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View</span></a>
                                    <a class="btn btn-sm btn-outline-success has-icon" href="entry_item.php?item_id=<?php echo $itemId; ?>&mode=edit&event_id=<?php echo (int)$eventId; ?>"><i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span></a>
                                    <?php if ($hubGuid): ?>
                                        <a class="btn btn-sm btn-outline-primary has-icon" href="<?php echo h($siteBase . '/entry_hub.php?code=' . urlencode((string)$hubGuid)); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square btn-icon"></i><span class="btn-label">Hub</span></a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="btn-row-mobile justify-content-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Withdraw this entry?');">
                                        <input type="hidden" name="action" value="withdraw">
                                        <input type="hidden" name="item_id" value="<?php echo (int)($entry['id'] ?? 0); ?>">
                                        <button class="btn btn-sm btn-outline-secondary has-icon"><i class="fa-solid fa-ban btn-icon"></i><span class="btn-label">Withdraw</span></button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Withdraw and refund this entry as credit?');">
                                        <input type="hidden" name="action" value="withdraw_refund">
                                        <input type="hidden" name="item_id" value="<?php echo (int)($entry['id'] ?? 0); ?>">
                                        <button class="btn btn-sm btn-outline-danger has-icon"><i class="fa-solid fa-money-bill-transfer btn-icon"></i><span class="btn-label">Withdraw + refund</span></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$entries): ?>
                    <tr><td colspan="10" class="text-muted small">No entries yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($event && $withdrawnEntries): ?>
    <div class="entries-card p-3 mt-3">
        <div class="small text-muted mb-2">Withdrawn entries</div>
        <div class="table-responsive">
        <table class="table table-sm align-middle entries-table">
            <thead>
                <tr>
                    <th>Booking ref</th>
                    <th>Placed</th>
                    <th>Contact</th>
                    <th>Entry</th>
                    <th>Class</th>
                    <th>Rider</th>
                    <th>Horse</th>
                    <th>Rosette</th>
                    <th>Fees Due (£)</th>
                    <th class="text-end">Status</th>
                </tr>
            </thead>
            <tbody>
	                <?php foreach ($withdrawnEntries as $entry): ?>
	                    <?php
	                        $meta = $entry['metadata'] ?? [];
	                        $classLabel = $meta['class_label'] ?? ($entry['class_label'] ?? '');
	                        $rider = $meta['rider_name'] ?? ($entry['rider_name'] ?? '');
	                        $horse = $meta['horse_name'] ?? ($entry['horse_name'] ?? '');
	                        $entryPrice = price_to_number($entry['price'] ?? 0);
	                        $price = format_price($entryPrice);
	                        $placedAt = $entry['booking_created_at'] ?? $entry['created_at'] ?? null;
	                        $placedText = format_display_datetime($placedAt, '—');
	                        $contactName = $entry['contact_name'] ?? '';
	                        if ($contactName === '' && !empty($entry['contact_email'])) {
	                            $contactName = $entry['contact_email'];
	                        }
                            $isRefunded = !empty($refundedIds[(int)($entry['id'] ?? 0)]);
                            $bookingRef = (string)($entry['booking_ref'] ?? '');
                            $totals = $bookingRef !== '' && isset($bookingTotals[$bookingRef]) ? $bookingTotals[$bookingRef] : ['all' => 0.0, 'active' => 0.0, 'withdrawn' => 0.0, 'refund' => 0.0];
                            $withdrawnTotal = $totals['withdrawn'] > 0 ? $totals['withdrawn'] : 0.0;
                            $refundShare = $withdrawnTotal > 0 ? ($totals['refund'] * ($entryPrice / $withdrawnTotal)) : 0.0;
                            $paidForEntry = max(0.0, $entryPrice - $refundShare);
                            $feeStatus = format_fee_status($paidForEntry, 0.0);
                            $rosetteLabel = entry_rosette_label($entry);
	                    ?>
	                    <tr class="withdrawn-row">
	                        <td class="fw-semibold text-muted text-dark"><?php echo h($entry['booking_ref'] ?? ''); ?></td>
	                        <td class="small text-muted"><?php echo h($placedText); ?></td>
	                        <td class="small">
	                            <div><?php echo h($contactName ?: '—'); ?></div>
	                            <?php if (!empty($entry['contact_email'])): ?><div class="text-muted"><?php echo h($entry['contact_email']); ?></div><?php endif; ?>
	                        </td>
	                        <td class="small fw-semibold"><?php echo h($entry['event_title'] ?? ''); ?></td>
	                        <td class="small"><?php echo h($classLabel ?: '—'); ?></td>
	                        <td class="small"><?php echo h($rider ?: '—'); ?></td>
	                        <td class="small"><?php echo h($horse ?: '—'); ?></td>
                            <td class="small"><?php echo h($rosetteLabel); ?></td>
	                        <td class="small"><span class="fee-badge <?php echo h($feeStatus['class']); ?>"><?php echo h($feeStatus['label']); ?></span></td>
	                        <td class="text-end small text-muted"><?php echo $isRefunded ? 'Withdrawn &amp; refunded' : 'Withdrawn'; ?></td>
	                    </tr>
	                <?php endforeach; ?>
	            </tbody>
	        </table>
            </div>
	    </div>
<?php endif; ?>

<?php
admin_layout_end();
