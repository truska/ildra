<?php
// Included by the Finance page after its normal authentication/permission checks.
if (!isset($monthlyReports, $canCreatePayout, $stripeIsTest)) { http_response_code(404); exit; }
$includeCollectedMonths = !empty($_GET['include_collected']);
$selectedMonth = (string)($_GET['month'] ?? '');
$selectedReport = finance_month_valid($selectedMonth) ? ($monthlyReports[$selectedMonth] ?? null) : null;
$money = static fn(int $pence): string => ($pence < 0 ? '-' : '') . format_price(abs($pence) / 100);
$txDate = static fn(array $tx, string $key): string => isset($tx[$key]) ? (new DateTimeImmutable('@' . (int)$tx[$key]))->setTimezone(new DateTimeZone('Europe/London'))->format('d M Y H:i:s') : format_display_datetime($tx['created_at'], '');
$monthLabel = static fn(string $month): string => (new DateTimeImmutable($month . '-01', new DateTimeZone('Europe/London')))->format('F Y');
?>
<section class="card-soft p-3 mt-3 finance-section" data-finance-section="memberships">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div><h6 class="mb-1 fw-bold">Memberships &amp; Logbooks</h6>
            <div class="text-muted small">Stripe payments grouped by payment transaction month (Europe/London), regardless of the membership or logbook year. Memberships and logbooks share one collection balance.</div>
        </div>
        <div class="small text-end text-nowrap" id="monthly-stripe-summary">Stripe available: checking…</div>
    </div>
    <form method="get" class="mb-3"><input type="hidden" name="tab" value="memberships">
        <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="include_collected" value="1" <?php echo $includeCollectedMonths ? 'checked' : ''; ?> onchange="this.form.submit()"> Include fully collected months</label>
    </form>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead class="table-light"><tr><th>Month</th><th class="text-end">Memberships</th><th class="text-end">Logbooks</th><th class="text-end">Stripe received</th><th class="text-end">Stripe fees</th><th class="text-end">Refunds &amp; credits</th><th class="text-end">Net funds</th><th class="text-end">Collected</th><th class="text-end">Remaining</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php $visibleMonths = 0; foreach ($monthlyReports as $month => $report):
            if (!$includeCollectedMonths && $report['remaining'] === 0 && !$report['reserved'] && !$report['missing_fees'] && $month !== $selectedMonth && !$report['reconciliation']) continue;
            $visibleMonths++;
            $_SESSION['finance_month_keys'][$month] ??= bin2hex(random_bytes(16));
        ?>
            <tr>
                <td class="fw-semibold text-nowrap"><?php echo h($monthLabel($month)); ?></td>
                <?php foreach (['membership','horse_logbook','payments','fees','refunds','net','collected','remaining'] as $column): ?>
                <td class="text-end text-nowrap <?php echo $column === 'remaining' && $report[$column] < 0 ? 'text-danger fw-semibold' : ''; ?>"><?php echo in_array($column, ['fees','net','remaining'], true) && $report['missing_fees'] ? 'Incomplete' : $money($report[$column]); ?><?php if ($column === 'remaining' && $report['reserved']): ?><div class="small text-muted"><?php echo $money($report['reserved']); ?> awaiting confirmation</div><?php endif; ?></td>
                <?php endforeach; ?>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-success" href="finance.php?tab=memberships&amp;month=<?php echo h($month); ?>&amp;include_collected=<?php echo (int)$includeCollectedMonths; ?>#month-detail">View</a>
                <?php if ($canCreatePayout): ?><button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#collectMonthModal" data-month="<?php echo h($month); ?>" data-label="<?php echo h($monthLabel($month)); ?>" data-remaining="<?php echo $report['remaining']; ?>" data-key="<?php echo h($_SESSION['finance_month_keys'][$month]); ?>" <?php echo !$stripeIsTest || $report['remaining'] <= 0 || $report['reserved'] || $report['missing_fees'] || $report['reconciliation'] ? 'disabled' : ''; ?>>Collect from Stripe</button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$visibleMonths): ?><tr><td colspan="10" class="text-muted">No months have an outstanding balance. Include fully collected months to see their history.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <div class="small text-muted mt-2">Membership and logbook amounts show their share of Stripe receipts. Mixed-basket payments and fees are apportioned. Account-credit-only purchases are excluded. A negative remaining balance is a shortfall after refunds.</div>

    <?php if ($selectedReport): $report = $selectedReport; ?>
    <div id="month-detail" class="border-top mt-4 pt-3">
        <div class="d-flex justify-content-between mb-3"><h6><?php echo h($monthLabel($selectedMonth)); ?> — transactions and collections</h6><a class="btn btn-sm btn-outline-secondary" href="finance.php?tab=memberships&amp;include_collected=<?php echo (int)$includeCollectedMonths; ?>">Close</a></div>
        <?php if ($report['reconciliation']): ?><div class="alert alert-warning">A refund has more than one possible original payment. Reconcile its payment month before collecting.</div><?php endif; ?>
        <?php if ($report['missing_fees']): ?><div class="alert alert-warning">Stripe fees are missing for <?php echo (int)$report['missing_fees']; ?> payment(s). Collections are blocked until these are reconciled.</div><?php endif; ?>
        <?php if ($report['reserved']): ?><div class="alert alert-warning">A collection is awaiting Stripe confirmation. Its amount is reserved; confirm it below before collecting again.</div><?php endif; ?>
        <h6>Transactions</h6>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>ID</th><th>Transaction date</th><th>Movement</th><th>Booking reference</th><th>Account holder</th><th class="text-end">Amount</th><th class="text-end">Stripe fee</th><th class="text-end">Net</th></tr></thead><tbody>
        <?php foreach ($report['transactions'] as $tx): ?><tr><td>T-<?php echo (int)$tx['id']; ?></td><td><?php echo h($txDate($tx, 'paid_timestamp')); ?></td><td><?php echo h($tx['report_kind']); ?></td><td><?php echo h($tx['reference']); ?></td><td><?php echo h($tx['person_name'] ?? '—'); ?></td><td class="text-end"><?php echo $money($tx['allocated_amount']); ?></td><td class="text-end"><?php echo $tx['fee_known'] ? $money($tx['allocated_fee']) : 'Unknown'; ?></td><td class="text-end"><?php echo $tx['fee_known'] ? $money($tx['allocated_amount'] - $tx['allocated_fee']) : 'Incomplete'; ?></td></tr><?php endforeach; ?>
        <?php if (!$report['transactions']): ?><tr><td colspan="8">No matching transactions.</td></tr><?php endif; ?>
        </tbody></table></div>
        <h6 class="mt-3">Collection history</h6>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>Requested</th><th>Administrator</th><th class="text-end">Amount</th><th>Status</th><th>Expected arrival</th><th>Stripe / bank reference</th><th>Notes</th><th></th></tr></thead><tbody>
        <?php foreach ($report['payouts'] as $tx): $meta = $tx['metadata']; ?>
        <tr><td><?php echo h($txDate($tx, 'requested_timestamp')); ?></td><td><?php echo h($meta['actor'] ?? ''); ?></td><td class="text-end"><?php echo $money(abs((int)round((float)$tx['amount'] * 100))); ?></td><td><?php echo h(ucfirst((string)($meta['stripe_status'] ?? 'unknown'))); ?><?php if (!empty($meta['last_error'])): ?><div class="small text-danger"><?php echo h($meta['last_error']); ?></div><?php endif; ?></td><td><?php echo !empty($meta['arrival_date']) ? h(date('j M Y', (int)$meta['arrival_date'])) : '—'; ?></td><td><?php echo h($tx['reference']); ?><div class="small text-muted"><?php echo h($meta['statement_descriptor'] ?? ''); ?></div></td><td><?php echo h($tx['notes'] ?? ''); ?></td><td>
        <?php if ($canCreatePayout && $stripeIsTest && !in_array($meta['stripe_status'] ?? '', ['failed','canceled','paid'], true)): ?><form method="post" action="finance.php"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="collect_membership_month"><input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>"><input type="hidden" name="request_key" value="<?php echo h($meta['collection_request_key']); ?>"><input type="hidden" name="refresh_collection" value="1"><button class="btn btn-sm btn-outline-secondary"><?php echo empty($meta['stripe_payout_id']) ? 'Confirm collection' : 'Refresh status'; ?></button></form><?php endif; ?>
        </td></tr><?php endforeach; ?>
        <?php if (!$report['payouts']): ?><tr><td colspan="8" class="text-muted">No collections have been requested for this month.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="small text-muted">Later refunds and credits appear against the original payment month. Failed or cancelled collections release the balance when their status is refreshed.</div>
    </div>
    <?php elseif ($selectedMonth !== ''): ?><div class="alert alert-info mt-3">No monthly records were found for that month.</div><?php endif; ?>
</section>

<?php if ($canCreatePayout): ?>
<div class="modal fade" id="collectMonthModal" tabindex="-1" aria-labelledby="collectMonthTitle" aria-hidden="true"><div class="modal-dialog"><form method="post" action="finance.php" class="modal-content">
    <input type="hidden" name="action" value="collect_membership_month"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="month" id="collect-month"><input type="hidden" name="request_key" id="collect-month-key">
    <div class="modal-header"><h5 class="modal-title" id="collectMonthTitle">Collect from Stripe</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="alert alert-warning">Stripe test mode — no real money will move.</div><div class="fw-semibold mb-3" id="collect-month-label"></div>
        <div id="collect-month-balance" class="small mb-3" aria-live="polite">Checking Stripe balance…</div>
        <label class="form-label" for="collect-month-amount">Amount to collect</label><div class="input-group"><span class="input-group-text">£</span><input class="form-control" id="collect-month-amount" name="amount" type="number" min="0.01" step="0.01" required disabled></div>
        <div class="form-text">Collect all or part of the balance, during the month or afterwards.</div>
        <label class="form-label mt-3" for="collect-month-notes">Notes</label><textarea class="form-control" id="collect-month-notes" name="notes" rows="3" maxlength="255"></textarea>
    </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" id="collect-month-submit" disabled>Collect</button></div>
</form></div></div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const summary = document.getElementById('monthly-stripe-summary');
    const getBalance = async () => {
        const response = await fetch('finance.php?stripe_balance=1', {headers: {'Accept': 'application/json'}});
        const result = await response.json();
        if (!result.ok) throw new Error(result.error || 'Could not read Stripe balance.');
        return result;
    };
    <?php if ($canCreatePayout && $stripeIsTest): ?>
    getBalance().then(result => { summary.textContent = 'Stripe available: £' + Number(result.available).toFixed(2); }).catch(() => { summary.textContent = 'Stripe balance unavailable'; });
    <?php else: ?>summary.textContent = <?php echo json_encode($stripeIsTest ? 'Stripe collection permission required' : 'Collections require Stripe test mode'); ?>;<?php endif; ?>
    let modalRequest = 0;
    document.getElementById('collectMonthModal')?.addEventListener('show.bs.modal', async event => {
        const request = ++modalRequest;
        const button = event.relatedTarget;
        const remaining = Number(button.dataset.remaining) / 100;
        const amount = document.getElementById('collect-month-amount');
        const submit = document.getElementById('collect-month-submit');
        const balance = document.getElementById('collect-month-balance');
        document.getElementById('collect-month').value = button.dataset.month;
        document.getElementById('collect-month-key').value = button.dataset.key;
        document.getElementById('collect-month-label').textContent = button.dataset.label + ' — Memberships & Logbooks';
        document.getElementById('collect-month-notes').value = '';
        amount.value = ''; amount.disabled = true; submit.disabled = true; balance.textContent = 'Checking Stripe balance…';
        try {
            const result = await getBalance();
            if (request !== modalRequest) return;
            const available = Number(result.available || 0);
            const maximum = Math.floor(Math.max(0, Math.min(remaining, available)) * 100 + 0.001) / 100;
            balance.textContent = 'Month remaining: £' + remaining.toFixed(2) + ' · Stripe available: £' + available.toFixed(2) + ' · Available to collect now: £' + maximum.toFixed(2);
            amount.max = maximum.toFixed(2); amount.value = maximum > 0 ? maximum.toFixed(2) : ''; amount.disabled = maximum <= 0; submit.disabled = maximum <= 0;
        } catch (error) { if (request === modalRequest) balance.textContent = error.message; }
    });
    document.getElementById('collectMonthModal')?.addEventListener('hidden.bs.modal', () => { modalRequest++; });
    document.getElementById('collect-month-submit')?.form.addEventListener('submit', () => { document.getElementById('collect-month-submit').disabled = true; });
});
</script>
