<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../finance.php';
require_once __DIR__ . '/../bookings_store.php';
require_once __DIR__ . '/table_sort.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageFinance = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManageFinance) {
    header('Location: index.php');
    exit;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$event = $eventId > 0 ? fetchEventById($pdo, $eventId) : null;

ensure_finance_tables($pdo, $alerts);
ensure_bookings_tables($pdo);

$entries = [];
$refundByItemId = [];
$transactions = [];
$grossFees = 0.0;
$refundTotal = 0.0;
$withdrawnCount = 0;
$refundedCount = 0;
$entrySortKey = $_GET['entry_sort'] ?? 'placed';
$entrySortDir = strtolower($_GET['entry_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$txSortKey = $_GET['tx_sort'] ?? 'date';
$txSortDir = strtolower($_GET['tx_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

function finance_detail_sort_link(string $prefix, string $key, string $label, string $currentKey, string $currentDir): string
{
    $dir = ($currentKey === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '↕';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? '↑' : '↓';
    }
    $query = $_GET;
    $query[$prefix . '_sort'] = $key;
    $query[$prefix . '_dir'] = $dir;
    $url = '?' . http_build_query($query);
    return '<a class="text-decoration-none text-dark sort-link" href="' . h($url) . '">'
        . h($label)
        . '<span class="sort-arrow" aria-hidden="true">' . h($arrow) . '</span>'
        . '</a>';
}

if ($event && $pdo) {
    $stmt = $pdo->prepare("
        SELECT
            bi.*,
            b.booking_ref,
            b.contact_name,
            b.created_at AS booking_created_at,
            JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.class_label')) AS class_label,
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.rider_name')), ''),
                NULLIF(b.contact_name, ''),
                b.contact_email
            ) AS person_name
        FROM booking_items bi
        LEFT JOIN bookings b ON bi.booking_id = b.new_id
        WHERE bi.event_id = :event_id
        ORDER BY b.created_at DESC, bi.id DESC
    ");
    $stmt->execute([':event_id' => $eventId]);
    $entries = $stmt->fetchAll() ?: [];

    foreach ($entries as $entry) {
        $grossFees += (float)($entry['price'] ?? 0);
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_count,
               SUM(CASE WHEN COALESCE(is_withdrawn, 0) = 1 THEN 1 ELSE 0 END) AS withdrawn_count
        FROM booking_items
        WHERE event_id = :event_id
    ");
    $stmt->execute([':event_id' => $eventId]);
    $countRow = $stmt->fetch() ?: [];
    $withdrawnCount = (int)($countRow['withdrawn_count'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT
            CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) AS UNSIGNED) AS booking_item_id,
            SUM(amount) AS refund_total
        FROM finance_transactions
        WHERE type = 'entry_refund'
        GROUP BY booking_item_id
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $itemId = (int)($row['booking_item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $refundByItemId[$itemId] = (float)($row['refund_total'] ?? 0);
    }

    $refs = [];
    foreach ($entries as $entry) {
        $ref = trim((string)($entry['booking_ref'] ?? ''));
        if ($ref !== '') {
            $refs[$ref] = true;
        }
        $itemId = (int)($entry['id'] ?? 0);
        $refundTotal += $refundByItemId[$itemId] ?? 0.0;
    }

    $types = ['payment_simulated', 'checkout'];
    if ($refs) {
        $refValues = array_keys($refs);
        $placeholders = implode(',', array_fill(0, count($refValues), '?'));
        $typePlaceholders = implode(',', array_fill(0, count($types), '?'));

        $stmt = $pdo->prepare("
            SELECT ft.*,
                   COALESCE(
                       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.contact_name')), ''),
                       NULLIF(b.contact_name, ''),
                       NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                       u.email
                   ) AS person_name
            FROM finance_transactions ft
            LEFT JOIN bookings b ON b.booking_ref = ft.reference
            LEFT JOIN users u ON u.id = ft.user_id
            WHERE ft.reference IN ($placeholders)
              AND ft.type IN ($typePlaceholders)
            ORDER BY ft.created_at DESC, ft.id DESC
        ");
        $stmt->execute(array_merge($refValues, $types));
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $row['is_booking_level'] = true;
            $transactions[] = $row;
        }
    }

    $stmt = $pdo->prepare("
        SELECT ft.*,
               COALESCE(
                   NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.rider_name')), ''),
                   NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.contact_name')), ''),
                   NULLIF(b.contact_name, ''),
                   NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                   u.email
               ) AS person_name
        FROM finance_transactions ft
        JOIN booking_items bi
            ON bi.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.booking_item_id')) AS UNSIGNED)
        LEFT JOIN bookings b ON b.new_id = bi.booking_id
        LEFT JOIN users u ON u.id = ft.user_id
        WHERE ft.type = 'entry_refund'
          AND bi.event_id = :event_id
        ORDER BY ft.created_at DESC, ft.id DESC
    ");
    $stmt->execute([':event_id' => $eventId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $transactions[] = $row;
    }

    $refundedIds = [];
    $stmt = $pdo->prepare("
        SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) AS booking_item_id
        FROM finance_transactions
        WHERE type = 'entry_refund'
          AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.booking_item_id')) IN (
            SELECT id FROM booking_items WHERE event_id = :event_id AND COALESCE(is_withdrawn, 0) = 1
          )
    ");
    $stmt->execute([':event_id' => $eventId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = (int)($row['booking_item_id'] ?? 0);
        if ($id > 0) {
            $refundedIds[$id] = true;
        }
    }
    $refundedCount = count($refundedIds);
}

$netRevenue = $grossFees - $refundTotal;
$acceptedCount = max(0, count($entries) - $withdrawnCount);
$withdrawnOnlyCount = max(0, $withdrawnCount - $refundedCount);
$pageTitle = $event ? 'Finance · ' . ($event['title'] ?? 'Event') : 'Event finance';
admin_layout_start($pageTitle, 'finance');
?>
<style>
    .finance-detail-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
    .finance-detail-header { display: flex; gap: 1rem; flex-wrap: wrap; }
    .finance-detail-header .meta { min-width: 180px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Event finance</div>
        <h5 class="mb-0"><?php echo h($event['title'] ?? 'Event'); ?></h5>
        <?php if ($event): ?>
            <div class="text-muted small"><?php echo h($event['event_date'] ?: 'Date TBC'); ?> · <?php echo h($event['event_type_name'] ?? ''); ?></div>
        <?php endif; ?>
    </div>
    <a class="btn btn-outline-secondary" href="finance.php?tab=events">Back to finance</a>
</div>

<?php if (!$event): ?>
    <section class="card-soft p-3">
        <div class="text-muted">Event not found.</div>
    </section>
<?php else: ?>
    <section class="card-soft p-3 mb-3">
        <div class="finance-detail-header">
            <div class="meta">
                <div class="small text-muted">Gross fees</div>
                <div class="fw-semibold"><?php echo format_price($grossFees); ?></div>
            </div>
            <div class="meta">
                <div class="small text-muted">Refunds</div>
                <div class="fw-semibold"><?php echo format_price($refundTotal); ?></div>
            </div>
            <div class="meta">
                <div class="small text-muted">Net revenue</div>
                <div class="fw-semibold"><?php echo format_price($netRevenue); ?></div>
            </div>
            <div class="meta">
                <div class="small text-muted">Event entries</div>
                <div class="fw-semibold"><?php echo $acceptedCount; ?> Accepted</div>
                <?php if (!empty($event['capacity_enabled']) && (int)($event['capacity_limit'] ?? 0) > 0): ?>
                    <div class="text-muted small">Event limited to <?php echo (int)($event['capacity_limit'] ?? 0); ?></div>
                <?php endif; ?>
                <div class="text-muted small"><?php echo $withdrawnCount; ?> Withdrawn</div>
                <div class="text-muted small">
                    Of which <?php echo $withdrawnOnlyCount; ?> Withdrawn Only, <?php echo $refundedCount; ?> Withdrawn &amp; Refunded
                </div>
            </div>
        </div>
    </section>

    <div class="finance-detail-grid">
        <section class="card-soft p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Transactions</div>
                <div class="text-muted small">Payments + refunds tied to this event</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo finance_detail_sort_link('tx', 'date', 'Date', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'type', 'Type', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'reference', 'Reference', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('tx', 'person', 'Person', (string)$txSortKey, (string)$txSortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('tx', 'amount', 'Amount', (string)$txSortKey, (string)$txSortDir); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $allowedTxSort = ['date', 'type', 'reference', 'person', 'amount'];
                        if (!in_array($txSortKey, $allowedTxSort, true)) {
                            $txSortKey = 'date';
                        }
                        usort($transactions, function (array $a, array $b) use ($txSortKey, $txSortDir): int {
                            $dir = $txSortDir === 'asc' ? 1 : -1;
                            if ($txSortKey === 'date') {
                                return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')) * $dir;
                            }
                            if ($txSortKey === 'type') {
                                return strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? '')) * $dir;
                            }
                            if ($txSortKey === 'reference') {
                                return strcmp((string)($a['reference'] ?? ''), (string)($b['reference'] ?? '')) * $dir;
                            }
                            if ($txSortKey === 'person') {
                                return strcasecmp((string)($a['person_name'] ?? ''), (string)($b['person_name'] ?? '')) * $dir;
                            }
                            $na = (float)($a['amount'] ?? 0);
                            $nb = (float)($b['amount'] ?? 0);
                            if ($na === $nb) {
                                return 0;
                            }
                            return ($na < $nb ? -1 : 1) * $dir;
                        });
                        ?>
                        <?php foreach ($transactions as $tx): ?>
                            <?php
                            $amountVal = (float)($tx['amount'] ?? 0);
                            $type = (string)($tx['type'] ?? '');
                            $typeLabel = str_replace('_', ' ', $type);
                            if (!empty($tx['is_booking_level'])) {
                                $typeLabel .= ' (booking)';
                            }
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo h(format_display_datetime($tx['created_at'] ?? null, '')); ?></td>
                                <td class="text-capitalize"><?php echo h($typeLabel); ?></td>
                                <td><?php echo h($tx['reference'] ?? ''); ?></td>
                                <td><?php echo h($tx['person_name'] ?? '—'); ?></td>
                                <td class="text-end"><?php echo ($amountVal < 0 ? '-' : '') . format_price(abs($amountVal)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$transactions): ?>
                            <tr><td colspan="5" class="text-muted">No transactions yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card-soft p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Entries</div>
                <div class="text-muted small">Fees + refunds per entry</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo finance_detail_sort_link('entry', 'booking_ref', 'Booking ref', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'placed', 'Placed', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'person', 'Person', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th><?php echo finance_detail_sort_link('entry', 'class', 'Class', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('entry', 'fee', 'Fee', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('entry', 'refund', 'Refund', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                            <th class="text-end"><?php echo finance_detail_sort_link('entry', 'net', 'Net', (string)$entrySortKey, (string)$entrySortDir); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $allowedEntrySort = ['booking_ref', 'placed', 'person', 'class', 'fee', 'refund', 'net'];
                        if (!in_array($entrySortKey, $allowedEntrySort, true)) {
                            $entrySortKey = 'placed';
                        }
                        usort($entries, function (array $a, array $b) use ($entrySortKey, $entrySortDir, $refundByItemId): int {
                            $dir = $entrySortDir === 'asc' ? 1 : -1;
                            if ($entrySortKey === 'booking_ref') {
                                return strcmp((string)($a['booking_ref'] ?? ''), (string)($b['booking_ref'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'placed') {
                                return strcmp((string)($a['booking_created_at'] ?? ''), (string)($b['booking_created_at'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'class') {
                                return strcmp((string)($a['class_label'] ?? ''), (string)($b['class_label'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'person') {
                                return strcasecmp((string)($a['person_name'] ?? ''), (string)($b['person_name'] ?? '')) * $dir;
                            }
                            if ($entrySortKey === 'fee') {
                                $na = (float)($a['price'] ?? 0);
                                $nb = (float)($b['price'] ?? 0);
                            } elseif ($entrySortKey === 'refund') {
                                $na = (float)($refundByItemId[(int)($a['id'] ?? 0)] ?? 0);
                                $nb = (float)($refundByItemId[(int)($b['id'] ?? 0)] ?? 0);
                            } else {
                                $na = (float)($a['price'] ?? 0) - (float)($refundByItemId[(int)($a['id'] ?? 0)] ?? 0);
                                $nb = (float)($b['price'] ?? 0) - (float)($refundByItemId[(int)($b['id'] ?? 0)] ?? 0);
                            }
                            if ($na === $nb) {
                                return 0;
                            }
                            return ($na < $nb ? -1 : 1) * $dir;
                        });
                        ?>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $fee = (float)($entry['price'] ?? 0);
                            $itemId = (int)($entry['id'] ?? 0);
                            $refundAmount = $itemId > 0 ? (float)($refundByItemId[$itemId] ?? 0) : 0.0;
                            $netAmount = $fee - $refundAmount;
                            ?>
                            <tr>
                                <td><?php echo h($entry['booking_ref'] ?? ''); ?></td>
                                <td class="text-muted small"><?php echo h(format_display_datetime($entry['booking_created_at'] ?? null, '')); ?></td>
                                <td><?php echo h($entry['person_name'] ?? '—'); ?></td>
                                <td><?php echo h($entry['class_label'] ?? ''); ?></td>
                                <td class="text-end"><?php echo format_price($fee); ?></td>
                                <td class="text-end"><?php echo format_price($refundAmount); ?></td>
                                <td class="text-end fw-semibold"><?php echo format_price($netAmount); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$entries): ?>
                            <tr><td colspan="7" class="text-muted">No entries yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php endif; ?>

<?php admin_layout_end(); ?>
