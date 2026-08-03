<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';
require_once __DIR__ . '/../bookings_store.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canManageFinance = in_array($currentRole, ['superadmin', 'admin'], true);
if (!$canManageFinance) {
    header('Location: index.php');
    exit;
}

ensure_finance_tables($pdo, $alerts);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'adjust_balance') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $direction = $_POST['direction'] === 'debit' ? 'debit' : 'credit';
        $amountRaw = $_POST['amount'] ?? '0';
        $reason = trim((string)($_POST['reason'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $kind = $_POST['kind'] ?? 'manual_credit';

        $amount = price_to_number($amountRaw);
        if ($userId <= 0) {
            $alerts[] = ['type' => 'danger', 'message' => 'Select a user.'];
        }
        if ($amount <= 0) {
            $alerts[] = ['type' => 'danger', 'message' => 'Enter an amount greater than zero.'];
        }
        if (!$alerts) {
            $signedAmount = $direction === 'debit' ? -1 * $amount : $amount;
            $type = $direction === 'debit' ? 'manual_debit' : $kind;
            if (record_finance_transaction($pdo, [
                'user_id' => $userId,
                'type' => $type,
                'amount' => $signedAmount,
                'reference' => $reference !== '' ? $reference : 'admin-adjustment',
                'notes' => $reason !== '' ? $reason : null,
                'metadata' => ['actor' => $currentUser['email'] ?? 'admin'],
            ], $alerts)) {
                $successMessage = 'Balance updated.';
            }
        }
        if ($alerts) {
            $_SESSION['flash_alerts'] = $alerts;
        }
        if ($successMessage) {
            $_SESSION['flash_success'] = $successMessage;
        }
        header('Location: finance.php');
        exit;
    }
}

$allUsers = fetchAllUsersForAdmin($pdo, $alerts);
$balances = fetch_credit_balances($pdo, 500);
$sortKey = $_GET['sort'] ?? 'when';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$transactionsDisplayed = fetch_finance_transactions($pdo, 150, (string)$sortKey, strtoupper($sortDir));
$events = fetchEvents($pdo, false);
$eventStats = [];
$eventRefunds = [];
$eventSortKey = $_GET['event_sort'] ?? 'date';
$eventSortDir = strtolower($_GET['event_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

if ($pdo) {
    ensure_bookings_tables($pdo);
    if ($events) {
        try {
            $stmt = $pdo->query("
                SELECT
                    event_id,
                    COUNT(*) AS entry_total,
                    SUM(CASE WHEN COALESCE(is_withdrawn, 0) = 1 THEN 1 ELSE 0 END) AS withdrawn_total,
                    SUM(price) AS gross_fees
                FROM booking_items
                GROUP BY event_id
            ");
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $eventId = (int)($row['event_id'] ?? 0);
                if ($eventId <= 0) {
                    continue;
                }
                $eventStats[$eventId] = [
                    'entries' => (int)($row['entry_total'] ?? 0),
                    'withdrawn' => (int)($row['withdrawn_total'] ?? 0),
                    'gross' => (float)($row['gross_fees'] ?? 0),
                ];
            }
        } catch (PDOException $e) {
            $eventStats = [];
        }

        if (ensure_finance_tables($pdo)) {
            try {
                $stmt = $pdo->query("
                    SELECT
                        bi.event_id AS event_id,
                        SUM(ft.amount) AS refunds_total
                    FROM finance_transactions ft
                    JOIN booking_items bi
                        ON bi.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ft.metadata, '$.booking_item_id')) AS UNSIGNED)
                    WHERE ft.type = 'entry_refund'
                    GROUP BY bi.event_id
                ");
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    $eventId = (int)($row['event_id'] ?? 0);
                    if ($eventId <= 0) {
                        continue;
                    }
                    $eventRefunds[$eventId] = (float)($row['refunds_total'] ?? 0);
                }
            } catch (PDOException $e) {
                $eventRefunds = [];
            }
        }
    }
}

function finance_event_sort_link(string $key, string $label, string $currentKey, string $currentDir): string
{
    $dir = ($currentKey === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '↕';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? '↑' : '↓';
    }
    $query = $_GET;
    $query['event_sort'] = $key;
    $query['event_dir'] = $dir;
    $query['tab'] = 'events';
    $url = '?' . http_build_query($query);
    return '<a class="text-decoration-none text-dark sort-link" href="' . h($url) . '">'
        . h($label)
        . '<span class="sort-arrow" aria-hidden="true">' . h($arrow) . '</span>'
        . '</a>';
}

if ($events) {
    $allowedEventSort = ['date', 'title', 'type', 'entries', 'withdrawn', 'refunds', 'gross', 'net'];
    if (!in_array($eventSortKey, $allowedEventSort, true)) {
        $eventSortKey = 'date';
    }
    usort($events, function (array $a, array $b) use ($eventSortKey, $eventSortDir, $eventStats, $eventRefunds): int {
        $dir = $eventSortDir === 'asc' ? 1 : -1;
        $va = '';
        $vb = '';
        if ($eventSortKey === 'date') {
            $va = (string)($a['event_date'] ?? '');
            $vb = (string)($b['event_date'] ?? '');
        } elseif ($eventSortKey === 'title') {
            $va = mb_strtolower((string)($a['title'] ?? ''));
            $vb = mb_strtolower((string)($b['title'] ?? ''));
        } elseif ($eventSortKey === 'type') {
            $va = mb_strtolower((string)($a['event_type_name'] ?? ''));
            $vb = mb_strtolower((string)($b['event_type_name'] ?? ''));
        } elseif (in_array($eventSortKey, ['entries', 'withdrawn', 'gross', 'net', 'refunds'], true)) {
            $ida = (int)($a['id'] ?? 0);
            $idb = (int)($b['id'] ?? 0);
            $statsA = $eventStats[$ida] ?? ['entries' => 0, 'withdrawn' => 0, 'gross' => 0.0];
            $statsB = $eventStats[$idb] ?? ['entries' => 0, 'withdrawn' => 0, 'gross' => 0.0];
            $refundA = (float)($eventRefunds[$ida] ?? 0.0);
            $refundB = (float)($eventRefunds[$idb] ?? 0.0);
            if ($eventSortKey === 'entries') {
                $va = (string)($statsA['entries'] ?? 0);
                $vb = (string)($statsB['entries'] ?? 0);
            } elseif ($eventSortKey === 'withdrawn') {
                $va = (string)($statsA['withdrawn'] ?? 0);
                $vb = (string)($statsB['withdrawn'] ?? 0);
            } elseif ($eventSortKey === 'refunds') {
                $va = (string)$refundA;
                $vb = (string)$refundB;
            } elseif ($eventSortKey === 'gross') {
                $va = (string)($statsA['gross'] ?? 0.0);
                $vb = (string)($statsB['gross'] ?? 0.0);
            } else {
                $va = (string)(($statsA['gross'] ?? 0.0) - $refundA);
                $vb = (string)(($statsB['gross'] ?? 0.0) - $refundB);
            }
        }

        if (is_numeric($va) && is_numeric($vb)) {
            $na = (float)$va;
            $nb = (float)$vb;
            if ($na === $nb) {
                return 0;
            }
            return ($na < $nb ? -1 : 1) * $dir;
        }
        if ($va === $vb) {
            return 0;
        }
        return ($va < $vb ? -1 : 1) * $dir;
    });
}

admin_layout_start('Finance', 'finance');
?>
<style>
    .finance-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
    @media (min-width: 992px) {
        .finance-grid { grid-template-columns: 1.2fr 1fr; }
    }
    .pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #f0f5f0;
        color: #0f2d17;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .pill.negative { color: #a61b3f; background: #fde7ed; }
    .pill.positive { color: #0b6c29; background: #e7f6eb; }
    .pill.neutral { color: #0f2d17; background: #eef1ed; }
    .finance-tabs {
        display: inline-flex;
        gap: 0.5rem;
        background: #f0f3ed;
        padding: 6px;
        border-radius: 12px;
        margin-bottom: 1rem;
    }
    .finance-tab {
        border: 1px solid transparent;
        padding: 8px 14px;
        border-radius: 10px;
        font-weight: 700;
        color: #0f1f0f;
        background: transparent;
    }
    .finance-tab.active {
        background: #ffffff;
        border-color: var(--border-soft);
        box-shadow: 0 6px 18px rgba(0,0,0,0.06);
    }
    .finance-section { display: none; }
    .finance-section.active { display: block; }
    .finance-events-table thead th { text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.03em; white-space: nowrap; }
    .finance-events-table td { vertical-align: middle; }
    .finance-events-actions { display: inline-flex; gap: 0.4rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Manage credits, balances, and transactions</div>
        <h5 class="mb-0">Finance</h5>
    </div>
    <div class="finance-tabs" role="tablist" aria-label="Finance sections">
        <button class="finance-tab active" data-finance-tab="transactions" type="button" role="tab" aria-selected="true">Transactions</button>
        <button class="finance-tab" data-finance-tab="events" type="button" role="tab" aria-selected="false">Events</button>
        <button class="finance-tab" data-finance-tab="credits" type="button" role="tab" aria-selected="false">Credits</button>
        <button class="finance-tab" data-finance-tab="balances" type="button" role="tab" aria-selected="false">Balances</button>
    </div>
</div>

<div class="finance-grid finance-section" data-finance-section="credits">
    <section class="card-soft p-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Credits</div>
                <h6 class="mb-0 fw-bold">Adjust user credit</h6>
                <div class="text-muted small">Add or reduce a user’s credit balance. All changes are logged.</div>
            </div>
        </div>
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="adjust_balance">
            <div class="col-12">
                <label class="form-label">User</label>
                <select name="user_id" class="form-select" required>
                    <option value="">Select user...</option>
                    <?php foreach ($allUsers as $user): ?>
                        <?php
                        $uid = (int)($user['id'] ?? 0);
                        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        $label = $name !== '' ? $name . ' (' . ($user['email'] ?? '') . ')' : ($user['email'] ?? 'User #' . $uid);
                        ?>
                        <option value="<?php echo $uid; ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Amount</label>
                <input type="number" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Direction</label>
                <select name="direction" class="form-select">
                    <option value="credit">Credit</option>
                    <option value="debit">Reduce credit</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Transaction type</label>
                <select name="kind" class="form-select">
                    <option value="manual_credit">Manual credit</option>
                    <option value="refund">Refund</option>
                    <option value="manual_debit">Manual debit</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Reason (optional)</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. goodwill credit, adjustment, refund">
            </div>
            <div class="col-12">
                <label class="form-label">Reference (optional)</label>
                <input type="text" name="reference" class="form-control" placeholder="Booking ref, invoice, etc">
            </div>
            <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-success">Save adjustment</button>
            </div>
        </form>
    </section>
</div>

<section class="card-soft p-3 finance-section" data-finance-section="balances">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Balances</div>
            <h6 class="mb-0 fw-bold">User credit balances</h6>
            <div class="text-muted small">Top balances first.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>User</th>
                    <th class="text-end">Balance</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($balances as $bal): ?>
                    <?php
                    $name = trim(($bal['first_name'] ?? '') . ' ' . ($bal['last_name'] ?? ''));
                    $email = $bal['email'] ?? '';
                    $label = $name !== '' ? $name : ($email ?: ('User #' . $bal['user_id']));
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo h($label); ?></div>
                            <div class="text-muted small"><?php echo h($email ?: ('User #' . $bal['user_id'])); ?></div>
                        </td>
                        <td class="text-end fw-semibold"><?php echo '£' . number_format((float)$bal['balance'], 2); ?></td>
                        <td class="text-muted small"><?php echo h(format_display_datetime($bal['updated_at'] ?? null, '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$balances): ?>
                    <tr><td colspan="3" class="text-muted">No balances yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card-soft p-3 mt-3 finance-section" data-finance-section="events">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Events</div>
            <h6 class="mb-0 fw-bold">Event finance summary</h6>
            <div class="text-muted small">Gross fees include withdrawn entries; refunds are subtracted for net revenue.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 finance-events-table">
            <thead class="table-light">
                <tr>
                    <th><?php echo finance_event_sort_link('title', 'Event', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th><?php echo finance_event_sort_link('date', 'Date', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th><?php echo finance_event_sort_link('type', 'Type', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-center"><?php echo finance_event_sort_link('entries', 'Entries', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-center"><?php echo finance_event_sort_link('withdrawn', 'Withdrawn', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('refunds', 'Refunds', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('gross', 'Gross fees', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end"><?php echo finance_event_sort_link('net', 'Net revenue', (string)$eventSortKey, (string)$eventSortDir); ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <?php
                    $eventId = (int)($event['id'] ?? 0);
                    $stats = $eventStats[$eventId] ?? ['entries' => 0, 'withdrawn' => 0, 'gross' => 0.0];
                    $refunds = (float)($eventRefunds[$eventId] ?? 0.0);
                    $gross = (float)($stats['gross'] ?? 0.0);
                    $net = $gross - $refunds;
                    $entries = (int)($stats['entries'] ?? 0);
                    $withdrawn = (int)($stats['withdrawn'] ?? 0);
                    $dateLabel = $event['event_date'] ?: 'Date TBC';
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo h($event['title'] ?? 'Untitled'); ?></td>
                        <td><?php echo h($dateLabel); ?></td>
                        <td class="text-muted"><?php echo h($event['event_type_name'] ?? ''); ?></td>
                        <td class="text-center"><?php echo $entries; ?></td>
                        <td class="text-center"><?php echo $withdrawn; ?></td>
                        <td class="text-end"><?php echo format_price($refunds); ?></td>
                        <td class="text-end"><?php echo format_price($gross); ?></td>
                        <td class="text-end fw-semibold"><?php echo format_price($net); ?></td>
                        <td class="text-end">
                            <div class="finance-events-actions">
                                <a class="btn btn-sm btn-outline-success" href="finance_event.php?event_id=<?php echo $eventId; ?>">View transactions</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$events): ?>
                    <tr><td colspan="9" class="text-muted">No events yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card-soft p-3 mt-3 finance-section" data-finance-section="transactions">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small text-muted text-uppercase fw-bold letter-spacing-1">Transactions</div>
            <h6 class="mb-0 fw-bold">Recent finance activity</h6>
            <div class="text-muted small">Checkouts, refunds, and adjustments are logged here.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><?php echo admin_sort_link('when', 'When', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('user', 'User', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('type', 'Type', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('amount', 'Amount', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('balance', 'Balance after', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('reference', 'Reference', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('notes', 'Notes', (string)$sortKey, (string)$sortDir); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactionsDisplayed as $tx): ?>
                    <?php
                    $name = trim(($tx['first_name'] ?? '') . ' ' . ($tx['last_name'] ?? ''));
                    $email = $tx['email'] ?? '';
                    $label = $name !== '' ? $name : ($email ?: ($tx['user_id'] ? ('User #' . $tx['user_id']) : 'Guest'));
                    $amountVal = (float)$tx['amount'];
                    $isPositive = $amountVal > 0;
                    $isNegative = $amountVal < 0;
                    $amountPill = $isPositive ? 'positive' : ($isNegative ? 'negative' : 'neutral');
                    $notesText = (string)($tx['notes'] ?? '');
                    $meta = $tx['metadata'] ?? [];
                    if (is_array($meta) && ($tx['type'] ?? '') === 'entry_refund' && !empty($meta['actor_name'])) {
                        $notesText = 'Entry refunded and withdrawn by admin (' . (string)$meta['actor_name'] . ')';
                    }
                    ?>
                    <tr>
                        <td class="text-muted small"><?php echo h(format_display_datetime($tx['created_at'] ?? null, '')); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($label); ?></div>
                            <?php if ($email): ?><div class="text-muted small"><?php echo h($email); ?></div><?php endif; ?>
                        </td>
                        <td class="text-capitalize"><?php echo h(str_replace('_', ' ', $tx['type'])); ?></td>
                        <td>
                            <span class="pill <?php echo $amountPill; ?>">
                                <?php echo $isPositive ? '+' : ($isNegative ? '-' : ''); ?>£<?php echo number_format(abs($amountVal), 2); ?>
                            </span>
                        </td>
                        <td><?php echo $tx['balance_after'] !== null ? '£' . number_format((float)$tx['balance_after'], 2) : '—'; ?></td>
                        <td><?php echo h($tx['reference'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo h($notesText); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactionsDisplayed): ?>
                    <tr><td colspan="7" class="text-muted">No transactions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<script>
    (() => {
        const tabs = document.querySelectorAll('[data-finance-tab]');
        const sections = document.querySelectorAll('[data-finance-section]');
        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab') || 'transactions';
        const showSection = (key) => {
            tabs.forEach(tab => {
                const isActive = tab.dataset.financeTab === key;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            sections.forEach(section => {
                section.classList.toggle('active', section.dataset.financeSection === key);
            });
        };
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const key = tab.dataset.financeTab;
                showSection(key);
                const nextParams = new URLSearchParams(window.location.search);
                nextParams.set('tab', key);
                const nextUrl = window.location.pathname + '?' + nextParams.toString();
                window.history.replaceState({}, '', nextUrl);
            });
        });
        showSection(initialTab);

    })();
</script>

<?php admin_layout_end(); ?>
