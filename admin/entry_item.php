<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$mode = ($_GET['mode'] ?? '') === 'edit' ? 'edit' : 'view';
$returnEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

function fetch_booking_item_with_booking(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            bi.*,
            b.new_id AS booking_db_id,
            b.booking_ref,
            b.user_id,
            b.contact_name,
            b.contact_email,
            b.contact_phone,
            b.created_at AS booking_created_at,
            e.title AS event_title_live
        FROM booking_items bi
        LEFT JOIN bookings b ON bi.booking_id = b.new_id
        LEFT JOIN events e ON bi.event_id = e.id
        WHERE bi.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $itemId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return hydrate_booking_item($row);
}

function normalise_meta_value(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return (string)$value;
}

function parse_meta_value(string $value): mixed
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if ($trimmed === '1') {
        return true;
    }
    if ($trimmed === '0') {
        return false;
    }
    if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return $value;
}

$item = $itemId ? fetch_booking_item_with_booking($pdo, $itemId) : null;
if (!$item) {
    admin_layout_start('Entry', 'events');
    echo '<div class="alert alert-danger">Entry not found.</div>';
    admin_layout_end();
    exit;
}

$meta = $item['metadata'] ?? [];
if (!is_array($meta)) {
    $meta = [];
}

$eventTitle = (string)($item['event_title_live'] ?? $item['event_title'] ?? 'Entry');
$pageTitle = $eventTitle;

$bookingRef = (string)($item['booking_ref'] ?? '');
$bookingPlaced = $item['booking_created_at'] ?? null;
$bookingPlacedText = format_display_datetime($bookingPlaced, '—');
$contactName = (string)($item['contact_name'] ?? '');
$contactEmail = (string)($item['contact_email'] ?? '');
$contactPhone = (string)($item['contact_phone'] ?? '');
$price = format_price($item['price'] ?? 0);
$storedPriceNumber = price_to_number($item['price'] ?? 0);

$event = null;
$eventId = (int)($item['event_id'] ?? 0);
$classOptions = [];
$entryComponents = [];
$entryForm = [];
$entryComponentsById = [];
if ($eventId > 0) {
    $event = fetchEventById($pdo, $eventId);
    if ($event) {
        $eventTypeId = (int)($event['event_type_id'] ?? 0);
        $entryComponents = fetchEventEntryComponents($pdo, $eventId, $eventTypeId);
        $entryForm = event_entry_form($event, $entryComponents);
        foreach ($entryComponents as $c) {
            $entryComponentsById[(int)($c['id'] ?? 0)] = $c;
        }
        $pricingRows = fetchEventPricingRows($pdo, $eventId);
        foreach ($pricingRows as $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            $label = trim((string)($row['class_name'] ?? ($row['class_code'] ?? '')));
            $code = trim((string)($row['class_code'] ?? ''));
            if ($label === '' && $code === '') {
                continue;
            }
            $priceRaw = (float)($row['price'] ?? 0);
            $classOptions[] = [
                'pricing_row_id' => (int)($row['id'] ?? 0),
                'code' => $code !== '' ? $code : $label,
                'label' => $label !== '' ? $label : $code,
                'price' => format_price($priceRaw),
                'price_raw' => $priceRaw,
                'is_member_price' => !empty($row['is_member_price']),
                'is_junior_ride' => !empty($row['is_junior_ride']),
            ];
        }
        if (!$classOptions) {
            $classesDecoded = json_decode((string)($event['classes_offered'] ?? ''), true);
            if (is_array($classesDecoded)) {
                foreach ($classesDecoded as $cls) {
                    $label = $cls['label'] ?? ($cls['code'] ?? '');
                    $code = $cls['code'] ?? $label;
                    $classPrice = $cls['price'] ?? '';
                    if ($label === '' && $code === '') {
                        continue;
                    }
                    $classOptions[] = [
                        'code' => $code,
                        'label' => $label ?: $code,
                        'price' => $classPrice === '' ? '' : format_price($classPrice),
                        'price_raw' => $classPrice === '' ? 0.0 : price_to_number($classPrice),
                    ];
                }
            }
        }
    }
}

$prefill = [
    'class_code' => (string)($meta['class_code'] ?? ''),
    'rider_name' => (string)($meta['rider_name'] ?? ''),
    'horse_name' => (string)($meta['horse_name'] ?? ''),
    'contact_email' => (string)($meta['contact_email'] ?? ''),
    'contact_phone' => (string)($meta['contact_phone'] ?? ''),
];

function resolve_saved_class_option(array $meta, array $classOptions): ?array
{
    $savedPricingRowId = (int)($meta['pricing_row_id'] ?? 0);
    $savedClassCode = trim((string)($meta['class_code'] ?? ''));
    $savedClassLabel = trim((string)($meta['class_label'] ?? ''));
    $savedIsMember = array_key_exists('is_member_price', $meta) ? !empty($meta['is_member_price']) : null;

    foreach ($classOptions as $cls) {
        if ($savedPricingRowId > 0 && (int)($cls['pricing_row_id'] ?? 0) === $savedPricingRowId) {
            return $cls;
        }
    }
    foreach ($classOptions as $cls) {
        if ($savedClassCode !== '' && (string)($cls['code'] ?? '') === $savedClassCode) {
            if ($savedIsMember === null || $savedIsMember === !empty($cls['is_member_price'])) {
                return $cls;
            }
        }
    }
    foreach ($classOptions as $cls) {
        if ($savedClassLabel !== '' && (string)($cls['label'] ?? '') === $savedClassLabel) {
            return $cls;
        }
    }
    return null;
}

$componentSelections = [];
$componentValues = [];
$componentSelectFlags = [];
// Track which priced products were selected on the original entry so we can prevent price changes.
$originalProductSelections = [];
if (!empty($meta['components']) && is_array($meta['components'])) {
    foreach ($meta['components'] as $c) {
        $cid = (int)($c['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $inputKind = (string)($c['input_kind'] ?? 'checkbox');
        $ctype = (string)($c['type'] ?? '');
        if ($ctype === 'product') {
            $originalProductSelections[$cid] = max(1, (int)($c['quantity'] ?? ($inputKind === 'quantity' ? (int)($c['value'] ?? 0) : 1)));
        }
        if ($inputKind === 'checkbox') {
            $componentSelectFlags[$cid] = 1;
            $componentSelections[$cid] = 1;
        } else {
            $componentValues[$cid] = (string)($c['value'] ?? '');
        }
    }
}

function calculate_entry_total(?array $selectedClassOption, array $entryComponents, array $selectedProductQuantities): float
{
    $base = (float)($selectedClassOption['price_raw'] ?? 0.0);
    $extras = 0.0;
    foreach ($entryComponents as $component) {
        $compId = (int)($component['id'] ?? 0);
        if ($compId <= 0) {
            continue;
        }
        $ctype = (string)($component['type'] ?? 'product');
        if ($ctype !== 'product') {
            continue;
        }
        $price = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
        if ($price === 0.0) {
            continue;
        }
        $isRequired = !empty($component['is_required']);
        $inputKind = (string)($component['input_kind'] ?? 'checkbox');
        $quantity = max(0, (int)($selectedProductQuantities[$compId] ?? 0));
        $isSelected = $isRequired || $quantity > 0;
        if ($isSelected) {
            $extras += $inputKind === 'quantity' ? $price * max(1, $quantity) : $price;
        }
    }
    return round($base + $extras, 2);
}

$selectedClassOption = resolve_saved_class_option($meta, $classOptions);
if ($selectedClassOption && ($prefill['class_code'] ?? '') === '') {
    $prefill['class_code'] = (string)($selectedClassOption['code'] ?? '');
}

$computedTotalNumber = $event && $selectedClassOption
    ? calculate_entry_total($selectedClassOption, $entryComponents, $originalProductSelections)
    : $storedPriceNumber;
$computedTotalText = format_price($computedTotalNumber);
$storedVsComputedMismatch = abs($computedTotalNumber - $storedPriceNumber) > 0.009;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($mode === 'edit') && (($_POST['action'] ?? '') === 'save_entry')) {
    $classCode = trim((string)($_POST['class_code'] ?? ''));
    $riderName = trim((string)($_POST['rider_name'] ?? ''));
    $horseName = trim((string)($_POST['horse_name'] ?? ''));
    $entryContactEmail = trim((string)($_POST['contact_email'] ?? ''));
    $entryContactPhone = trim((string)($_POST['contact_phone'] ?? ''));
    $postComponentValues = $_POST['component_value'] ?? [];
    $postComponentSelections = $_POST['component'] ?? [];
    $postComponentSelectFlags = $_POST['component_select'] ?? [];

    $alerts = $_SESSION['flash_alerts'] ?? [];
    if ($classCode === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Please choose a class.'];
    }
    if ($riderName === '' || $horseName === '' || $entryContactEmail === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Rider name, horse name and contact email are required.'];
    }

    $selectedClass = null;
    foreach ($classOptions as $cls) {
        if ((string)$cls['code'] === $classCode) {
            $selectedClass = $cls;
            break;
        }
    }
    if (!$selectedClass) {
        $alerts[] = ['type' => 'danger', 'message' => 'Selected class is not valid for this event.'];
    }

    // Price protection: do not allow edits that would change the financial total for the entry.
    $originalClassCode = (string)($meta['class_code'] ?? '');
    if ($classCode !== '' && $originalClassCode !== '' && $classCode !== $originalClassCode) {
        $alerts[] = ['type' => 'danger', 'message' => 'This change would alter the entry price (class change). Price-affecting edits are not allowed here.'];
    }
    // Compare optional priced products selection states.
    foreach ($entryComponents as $component) {
        $compId = (int)($component['id'] ?? 0);
        if ($compId <= 0) {
            continue;
        }
        $ctype = (string)($component['type'] ?? 'product');
        if ($ctype !== 'product') {
            continue;
        }
        $priceVal = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
        if ($priceVal === 0.0) {
            continue;
        }
        $isRequired = !empty($component['is_required']);
        if ($isRequired) {
            continue;
        }
        $inputKind = (string)($component['input_kind'] ?? 'checkbox');
        $wasSelected = isset($originalProductSelections[$compId]);
        $nowSelected = $inputKind === 'quantity'
            ? (max(0, (int)($postComponentValues[$compId] ?? 0)) > 0)
            : isset($postComponentSelectFlags[$compId]);
        $quantityChanged = $inputKind === 'quantity'
            && max(0, (int)($originalProductSelections[$compId] ?? 0)) !== max(0, (int)($postComponentValues[$compId] ?? 0));
        if ($wasSelected !== $nowSelected || $quantityChanged) {
            $alerts[] = ['type' => 'danger', 'message' => 'This change would alter the entry price (optional add-on). Price-affecting edits are not allowed here.'];
            break;
        }
    }

    if (!$alerts) {
        // Keep product components exactly as originally stored (to prevent accidental price changes).
        $existingComponents = [];
        if (!empty($meta['components']) && is_array($meta['components'])) {
            $existingComponents = $meta['components'];
        }
        $componentsById = [];
        foreach ($existingComponents as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid > 0) {
                $componentsById[$cid] = $c;
            }
        }

        $componentsSelected = [];
        // Add original product components first (including mandatory add-ons).
        foreach ($componentsById as $cid => $c) {
            if (($c['type'] ?? '') === 'product') {
                $componentsSelected[] = $c;
            }
        }

        // Rebuild non-product components from the posted form (questions / info).
        foreach ($entryComponents as $component) {
            $compId = (int)($component['id'] ?? 0);
            if ($compId <= 0) {
                continue;
            }
            $type = (string)($component['type'] ?? 'product');
            if ($type === 'product') {
                continue;
            }
            $inputKind = (string)($component['input_kind'] ?? 'checkbox');
            $label = $component['label_override'] ?? ($component['name'] ?? 'Extra');
            $priceVal = 0.0;

            $selectedFlag = false;
            $rawValue = '';
            if ($inputKind === 'checkbox') {
                $selectedFlag = isset($postComponentSelectFlags[$compId]) || isset($postComponentSelections[$compId]);
            } elseif ($inputKind === 'quantity') {
                $rawValue = (string)max(0, (int)($postComponentValues[$compId] ?? 0));
                $selectedFlag = (int)$rawValue > 0;
            } else {
                $rawValue = trim((string)($postComponentValues[$compId] ?? ''));
                $selectedFlag = $rawValue !== '';
            }
            if (!$selectedFlag) {
                continue;
            }
            $quantity = $inputKind === 'quantity' ? max(0, (int)$rawValue) : null;
            $componentsSelected[] = [
                'id' => $compId,
                'label' => $label,
                'name' => $component['name'] ?? $label,
                'type' => $type,
                'price' => $priceVal,
                'input_kind' => $inputKind,
                'value' => $inputKind === 'checkbox' ? null : $rawValue,
                'quantity' => $quantity,
                'line_total' => $inputKind === 'quantity' ? round($priceVal * (int)$rawValue, 2) : null,
            ];
        }

        // Recalculate components_total from the preserved product selections, and keep class/base price unchanged.
        $basePrice = price_to_number($meta['base_price'] ?? 0);
        $componentsTotal = 0.0;
        foreach ($entryComponents as $component) {
            $compId = (int)($component['id'] ?? 0);
            if ($compId <= 0) {
                continue;
            }
            $ctype = (string)($component['type'] ?? 'product');
            if ($ctype !== 'product') {
                continue;
            }
            $p = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
            if ($p === 0.0) {
                continue;
            }
            $isRequired = !empty($component['is_required']);
            $inputKind = (string)($component['input_kind'] ?? 'checkbox');
            $quantity = max(0, (int)($originalProductSelections[$compId] ?? 0));
            $isSelected = $isRequired || $quantity > 0;
            if ($isSelected) {
                $componentsTotal += $inputKind === 'quantity' ? ($p * max(1, $quantity)) : $p;
            }
        }

        $newMeta = $meta;
        $newMeta['rider_name'] = $riderName;
        $newMeta['horse_name'] = $horseName;
        $newMeta['contact_email'] = $entryContactEmail;
        $newMeta['contact_phone'] = $entryContactPhone;
        $newMeta['components'] = $componentsSelected;
        $newMeta['components_total'] = round($componentsTotal, 2);

        // Persist metadata only; price stays unchanged here.
        $stmt = $pdo->prepare("UPDATE booking_items SET metadata = :meta WHERE id = :id LIMIT 1");
        $stmt->execute([
            ':meta' => json_encode($newMeta, JSON_UNESCAPED_UNICODE),
            ':id' => $itemId,
        ]);

        $_SESSION['flash_success'] = 'Changes saved.';
        unset($_SESSION['flash_alerts']);
        $redirect = 'entry_item.php?item_id=' . $itemId . '&mode=edit';
        if ($returnEventId) {
            $redirect .= '&event_id=' . $returnEventId;
        }
        header('Location: ' . $redirect);
        exit;
    }
    $_SESSION['flash_alerts'] = $alerts;
    $redirect = 'entry_item.php?item_id=' . $itemId . '&mode=edit';
    if ($returnEventId) {
        $redirect .= '&event_id=' . $returnEventId;
    }
    header('Location: ' . $redirect);
    exit;
}
?>

<?php admin_layout_start($pageTitle, 'events'); ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <div class="small text-muted">Entry</div>
        <div class="text-muted small"><?php echo h($mode === 'edit' ? 'Edit mode' : 'View mode'); ?></div>
    </div>
    <div class="admin-page-actions">
        <?php if ($returnEventId): ?>
            <a class="btn btn-outline-secondary has-icon" href="event_entries.php?event_id=<?php echo (int)$returnEventId; ?>"><i class="fa-solid fa-arrow-left btn-icon"></i><span class="btn-label">Back to entries</span></a>
        <?php else: ?>
            <a class="btn btn-outline-secondary has-icon" href="events.php"><i class="fa-solid fa-arrow-left btn-icon"></i><span class="btn-label">Back to events</span></a>
        <?php endif; ?>
        <?php if ($mode === 'view'): ?>
            <a class="btn btn-outline-success has-icon" href="entry_item.php?item_id=<?php echo (int)$itemId; ?>&mode=edit<?php echo $returnEventId ? '&event_id=' . (int)$returnEventId : ''; ?>"><i class="fa-solid fa-pen-to-square btn-icon"></i><span class="btn-label">Edit</span></a>
        <?php else: ?>
            <a class="btn btn-outline-secondary has-icon" href="entry_item.php?item_id=<?php echo (int)$itemId; ?><?php echo $returnEventId ? '&event_id=' . (int)$returnEventId : ''; ?>"><i class="fa-solid fa-eye btn-icon"></i><span class="btn-label">View</span></a>
        <?php endif; ?>
    </div>
</div>

<div class="card-soft p-3 mb-3">
    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="small text-muted">Booking</div>
            <div class="fw-semibold"><?php echo h($bookingRef ?: '—'); ?></div>
            <div class="text-muted small"><?php echo h($bookingPlacedText); ?></div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="small text-muted">Contact (booking)</div>
            <div class="fw-semibold"><?php echo h($contactName ?: '—'); ?></div>
            <div class="text-muted small"><?php echo h($contactEmail ?: '—'); ?></div>
            <?php if ($contactPhone): ?><div class="text-muted small"><?php echo h($contactPhone); ?></div><?php endif; ?>
        </div>
        <div class="col-12 col-lg-4">
            <div class="small text-muted">Saved price</div>
            <div class="fw-semibold"><?php echo h($price); ?></div>
            <div class="text-muted small">Price edits are locked on this screen.</div>
        </div>
    </div>
</div>

<?php if ($storedVsComputedMismatch): ?>
    <div class="alert alert-warning py-2">
        This entry’s saved price (<?php echo h($price); ?>) does not match the total implied by the selected class/add-ons (<?php echo h($computedTotalText); ?>).
        Editing is still allowed, but price-affecting fields are locked on this screen.
    </div>
<?php endif; ?>

<div class="card-soft p-3">
    <?php if (!$event): ?>
        <div class="text-muted small">This entry is not linked to a published event, so the original entry form cannot be reconstructed.</div>
    <?php else: ?>
        <form method="POST" class="row g-4 px-2 px-md-3" id="entryForm" novalidate>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="action" value="save_entry">
            <?php endif; ?>

            <?php foreach ($entryForm as $block): ?>
                <?php
                    $type = $block['type'] ?? '';
                    $enabled = isset($block['enabled']) ? (bool)$block['enabled'] : true;
                    if (!$enabled) {
                        continue;
                    }
                    $disabledAttr = $mode !== 'edit' ? 'disabled' : '';
                ?>
                <?php if ($type === 'classes'): ?>
                    <div class="col-12 form-section">
                        <div class="fw-bold mb-2">Class selection</div>
                        <label class="form-label" for="classSelect">Class <span class="text-danger">*</span></label>
                        <?php
                            // Price-affecting: class changes alter the total, so keep it locked even in edit mode.
                            $lockClass = 'disabled';
                        ?>
                        <input type="hidden" name="class_code" value="<?php echo h($prefill['class_code'] ?? ''); ?>">
                        <select name="class_code" class="form-select" id="classSelect" data-required="true" <?php echo $lockClass; ?>>
                            <option value="">Choose...</option>
                            <?php foreach ($classOptions as $cls): ?>
                                <option value="<?php echo h($cls['code']); ?>" data-price="<?php echo h((string)$cls['price']); ?>" <?php echo ($prefill['class_code'] ?? '') === (string)$cls['code'] ? 'selected' : ''; ?>>
                                    <?php echo h($cls['label']); ?><?php echo $cls['price'] !== '' ? ' (' . h((string)$cls['price']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small helper-text" id="classPriceHint">Price will show when you pick a class.</div>
                        <div class="validation-message small d-none" data-validation-for="class_code">Please choose a class.</div>
                    </div>
                <?php elseif ($type === 'rider_details'): ?>
                    <div class="col-12 form-section">
                        <div class="fw-bold mb-2">Rider details</div>
                        <div class="mb-0">
                            <label class="form-label" for="riderName">Rider name <span class="text-danger">*</span></label>
                            <input type="text" name="rider_name" id="riderName" class="form-control" data-required="true" value="<?php echo h($prefill['rider_name'] ?? ''); ?>" <?php echo $disabledAttr; ?>>
                            <div class="validation-message small d-none" data-validation-for="rider_name">Rider name is required.</div>
                        </div>
                    </div>
                <?php elseif ($type === 'horse_details'): ?>
                    <div class="col-12 form-section">
                        <div class="fw-bold mb-2">Horse details</div>
                        <div class="mb-0">
                            <label class="form-label" for="horseName">Horse name <span class="text-danger">*</span></label>
                            <input type="text" name="horse_name" id="horseName" class="form-control" data-required="true" value="<?php echo h($prefill['horse_name'] ?? ''); ?>" <?php echo $disabledAttr; ?>>
                            <div class="validation-message small d-none" data-validation-for="horse_name">Horse name is required.</div>
                        </div>
                    </div>
                <?php elseif ($type === 'contact'): ?>
                    <div class="col-12 form-section">
                        <div class="fw-bold mb-2">Contact information</div>
                        <div class="mb-3">
                            <label class="form-label" for="contactEmail">Email <span class="text-danger">*</span></label>
                            <input type="email" name="contact_email" id="contactEmail" class="form-control" value="<?php echo h($prefill['contact_email'] ?? ''); ?>" data-required="true" <?php echo $disabledAttr; ?>>
                            <div class="validation-message small d-none" data-validation-for="contact_email">Please enter a valid email.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="contactPhone">Phone <span class="text-muted">(optional)</span></label>
                            <input type="text" name="contact_phone" id="contactPhone" class="form-control" placeholder="+44..." value="<?php echo h($prefill['contact_phone'] ?? ''); ?>" <?php echo $disabledAttr; ?>>
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
                        $priceValue = price_to_number($component['price_override'] ?? $component['price'] ?? 0);
                        $inputValue = $componentValues[$compId] ?? '';
                        $quantityValue = max(0, (int)$inputValue);
                        $hasCost = $ctype === 'product' && $priceValue !== 0.0;
                        $isRequiredProduct = $ctype === 'product' && !empty($component['is_required']);
                        $isRequiredConsent = !$hasCost && !empty($component['is_required']) && $inputKind === 'checkbox';
                        $description = trim((string)($component['description'] ?? ''));
                        $descriptionHtml = $description !== '' ? render_wysiwyg($description) : '';
                        $showSelector = $ctype === 'product' && !$isRequiredProduct;
                    ?>
                    <div class="col-12 form-section">
                        <div class="component-card border rounded p-3" data-price="<?php echo h($priceValue); ?>" data-product="<?php echo $ctype === 'product' ? '1' : '0'; ?>" data-required="<?php echo $isRequiredProduct ? '1' : '0'; ?>">
                            <div class="fw-bold mb-2"><?php echo h($label); ?></div>
                            <?php if ($descriptionHtml !== ''): ?>
                                <div class="text-muted small mb-3"><?php echo $descriptionHtml; ?></div>
                            <?php endif; ?>
                            <?php if ($inputKind === 'none'): ?>
                                <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($priceValue)); ?></div><?php endif; ?>
                            <?php elseif ($inputKind === 'quantity'): ?>
                                <?php $qtyDisabled = $ctype === 'product' ? 'disabled' : $disabledAttr; ?>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <input type="number" min="0" step="1" class="form-control component-input component-quantity" id="component_<?php echo $compId; ?>" name="component_value[<?php echo $compId; ?>]" value="<?php echo h((string)$quantityValue); ?>" style="max-width: 120px;" <?php echo $qtyDisabled; ?>>
                                    <?php if ($hasCost): ?>
                                        <span class="badge bg-light text-dark border">£<?php echo h(number_format((float)$priceValue, 2)); ?> each</span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($inputKind === 'checkbox'): ?>
                                <?php if ($isRequiredProduct): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="fw-semibold mb-0">Included automatically</div>
                                        <span class="badge bg-light text-dark border">Included £<?php echo h(number_format((float)$priceValue, 2)); ?></span>
                                    </div>
                                    <div class="text-muted small mb-1">Required add-on, automatically included in your total.</div>
                                    <input type="hidden" name="component[<?php echo $compId; ?>]" value="1">
                                <?php elseif ($isRequiredConsent): ?>
                                    <div class="form-check d-flex align-items-center gap-2 mb-1">
                                        <input class="form-check-input component-toggle" type="checkbox" value="1" id="component_select_<?php echo $compId; ?>" name="component[<?php echo $compId; ?>]" <?php echo isset($componentSelections[$compId]) ? 'checked' : ''; ?> <?php echo $disabledAttr; ?>>
                                        <label class="form-check-label fw-semibold" for="component_select_<?php echo $compId; ?>">Required to continue</label>
                                    </div>
                                    <div class="text-muted small">Must be accepted to continue.</div>
                                <?php else: ?>
                                    <?php
                                        // Price-affecting: product add-ons change the total, so keep them locked even in edit mode.
                                        $lockProductToggle = ($ctype === 'product') ? 'disabled' : $disabledAttr;
                                    ?>
                                    <div class="form-check d-flex align-items-center gap-2 mb-1">
                                        <input class="form-check-input component-toggle" type="checkbox" value="1" id="component_select_<?php echo $compId; ?>" name="component_select[<?php echo $compId; ?>]" <?php echo isset($componentSelectFlags[$compId]) ? 'checked' : ''; ?> data-price="<?php echo h($priceValue); ?>" <?php echo $lockProductToggle; ?>>
                                        <label class="form-check-label fw-semibold" for="component_select_<?php echo $compId; ?>"><?php echo $hasCost ? 'Add this option' : 'Select this option'; ?></label>
                                        <?php if ($hasCost): ?>
                                            <span class="badge bg-light text-dark border">+£<?php echo h(number_format((float)$priceValue, 2)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small"><?php echo $ctype === 'product' ? 'Optional add-on. Tick to include.' : 'Tick to include.'; ?></div>
                                    <?php if ($ctype === 'product' && isset($componentSelectFlags[$compId])): ?>
                                        <input type="hidden" name="component_select[<?php echo $compId; ?>]" value="1">
                                    <?php endif; ?>
                                    <?php if (!$showSelector): ?>
                                        <input type="hidden" name="component[<?php echo $compId; ?>]" value="1">
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php elseif ($inputKind === 'textarea'): ?>
                                <textarea class="form-control component-input" id="component_<?php echo $compId; ?>" name="component_value[<?php echo $compId; ?>]" rows="3" placeholder="Enter response" <?php echo $disabledAttr; ?>><?php echo h($inputValue); ?></textarea>
                                <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($priceValue)); ?></div><?php endif; ?>
                            <?php else: ?>
                                <input type="text" class="form-control component-input" id="component_<?php echo $compId; ?>" name="component_value[<?php echo $compId; ?>]" value="<?php echo h($inputValue); ?>" placeholder="Enter response" <?php echo $disabledAttr; ?>>
                                <?php if ($hasCost && !$showSelector): ?><div class="small text-muted mt-1">+<?php echo h(format_price($priceValue)); ?></div><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="col-12">
            <div class="cta-row d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div class="text-muted small" id="classPriceSummary">Total: <?php echo h($computedTotalText); ?></div>
                    <?php if ($mode === 'edit'): ?>
                        <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 w-100 w-md-auto">
                            <div class="text-danger small d-none" id="formErrorSummary"></div>
                            <div class="d-flex gap-2 w-100 w-md-auto flex-column flex-md-row">
                                <button class="btn btn-success has-icon w-100 w-md-auto" id="submitEntry" type="submit"><i class="fa-solid fa-floppy-disk btn-icon"></i><span class="btn-label">Save changes</span></button>
                                <a class="btn btn-outline-secondary has-icon w-100 w-md-auto" href="entry_item.php?item_id=<?php echo (int)$itemId; ?><?php echo $returnEventId ? '&event_id=' . (int)$returnEventId : ''; ?>"><i class="fa-solid fa-xmark btn-icon"></i><span class="btn-label">Cancel</span></a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var successAlerts = document.querySelectorAll('.alert.alert-success');
    if (!successAlerts.length || typeof bootstrap === 'undefined' || !bootstrap.Alert) {
        return;
    }
    window.setTimeout(function () {
        successAlerts.forEach(function (el) {
            bootstrap.Alert.getOrCreateInstance(el).close();
        });
    }, 5000);
});
</script>

<?php
admin_layout_end();
