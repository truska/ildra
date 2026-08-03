<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$memberships = fetchMemberships($pdo);

$sortKey = $_GET['sort'] ?? 'status';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$allowedSortKeys = ['member', 'email', 'type', 'status', 'starts', 'ends', 'purchased', 'amount'];
$hasExplicitSort = array_key_exists('sort', $_GET) || array_key_exists('dir', $_GET);
if (!in_array($sortKey, $allowedSortKeys, true)) {
    $sortKey = 'status';
}

if ($hasExplicitSort) {
    usort($memberships, function (array $a, array $b) use ($sortKey, $sortDir): int {
        $dir = $sortDir === 'asc' ? 1 : -1;
        if ($sortKey === 'amount') {
            $va = (float)($a['amount'] ?? 0);
            $vb = (float)($b['amount'] ?? 0);
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($sortKey === 'member') {
            $va = trim((string)($a['member_name'] ?? ''));
            $vb = trim((string)($b['member_name'] ?? ''));
            if ($va === '') {
                $va = (string)($a['user_email'] ?? '');
            }
            if ($vb === '') {
                $vb = (string)($b['user_email'] ?? '');
            }
            $va = mb_strtolower($va);
            $vb = mb_strtolower($vb);
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($sortKey === 'email') {
            $va = mb_strtolower((string)($a['user_email'] ?? ''));
            $vb = mb_strtolower((string)($b['user_email'] ?? ''));
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($sortKey === 'type') {
            $va = mb_strtolower((string)($a['membership_name'] ?? ''));
            $vb = mb_strtolower((string)($b['membership_name'] ?? ''));
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($sortKey === 'status') {
            $statusOrder = ['active' => 0, 'pending' => 1, 'expired' => 2];
            $sa = $statusOrder[strtolower((string)($a['status'] ?? ''))] ?? 99;
            $sb = $statusOrder[strtolower((string)($b['status'] ?? ''))] ?? 99;
            if ($sa === $sb) {
                return 0;
            }
            return ($sa < $sb ? -1 : 1) * $dir;
        }
        if ($sortKey === 'starts') {
            $va = (string)($a['starts_at'] ?? '');
            $vb = (string)($b['starts_at'] ?? '');
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        if ($sortKey === 'ends') {
            $va = (string)($a['ends_at'] ?? '');
            $vb = (string)($b['ends_at'] ?? '');
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        // purchased
        $va = (string)($a['purchased_at'] ?? '');
        $vb = (string)($b['purchased_at'] ?? '');
        if ($va === $vb) {
            return 0;
        }
        return ($va < $vb ? -1 : 1) * $dir;
    });
} else {
    // Keep the existing default ordering: active → pending → expired, then newest purchased.
    usort($memberships, function ($a, $b) {
        $statusOrder = ['active' => 0, 'pending' => 1, 'expired' => 2];
        $sa = $statusOrder[strtolower($a['status'] ?? '')] ?? 99;
        $sb = $statusOrder[strtolower($b['status'] ?? '')] ?? 99;
        if ($sa === $sb) {
            return strcmp((string)($b['purchased_at'] ?? ''), (string)($a['purchased_at'] ?? ''));
        }
        return $sa <=> $sb;
    });
    $sortKey = '__none__';
    $sortDir = 'asc';
}

admin_layout_start('Members', 'members');
?>
<style>
    .eyebrow {
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-size: 0.72rem;
        color: var(--text-muted);
        font-weight: 700;
    }
    .notes { color: var(--text-muted); font-size: 0.9rem; }
    .modern-table table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .modern-table thead th {
        background: #f8faf7;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 0.85rem;
        color: #4c5a4c;
        border-bottom: 1px solid var(--border-soft);
    }
    .modern-table th, .modern-table td { padding: 0.85rem; }
    .modern-table tbody tr {
        border-bottom: 1px solid var(--border-soft);
    }
    .modern-table tbody tr:hover {
        background: #f7fbf6;
    }
</style>
<section class="card-soft p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <div class="eyebrow">Memberships</div>
            <h6 class="mb-0 fw-bold">Members (active & expired)</h6>
            <div class="notes">Structured view with hover affordances and aligned dates.</div>
        </div>
    </div>
    <div class="modern-table">
	        <table class="table align-middle mb-0">
	            <thead>
	                <tr>
	                    <th><?php echo admin_sort_link('member', 'Member', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('email', 'Email', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('type', 'Type', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('status', 'Status', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('starts', 'Period', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('purchased', 'Purchased', (string)$sortKey, (string)$sortDir); ?></th>
	                    <th><?php echo admin_sort_link('amount', 'Amount', (string)$sortKey, (string)$sortDir); ?></th>
	                </tr>
	            </thead>
            <tbody>
                <?php foreach ($memberships as $m): ?>
                    <tr>
                        <td>
                            <?php
                            $displayName = trim((string)($m['member_name'] ?? ''));
                            if ($displayName === '') {
                                $displayName = '—';
                            }
                            echo h($displayName);
                            $memberNumber = trim((string)($m['member_number'] ?? ''));
                            if ($memberNumber !== '') {
                                echo '<div class="text-muted small">' . h($memberNumber) . '</div>';
                            }
                            ?>
                        </td>
                        <td><?php echo h($m['user_email'] ?? ''); ?></td>
                        <td><?php echo h($m['membership_name'] ?? ''); ?></td>
                        <td class="text-capitalize"><?php echo h($m['status'] ?? ''); ?></td>
                        <td class="text-muted">
                            <div><?php echo h(format_display_date($m['starts_at'] ?? null, '')); ?></div>
                            <div><?php echo h(format_display_date($m['ends_at'] ?? null, '')); ?></div>
                        </td>
                        <td class="text-muted"><?php echo h(format_display_date($m['purchased_at'] ?? null, '')); ?></td>
                        <td class="fw-semibold"><?php echo '£' . h(number_format((float)($m['amount'] ?? 0), 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$memberships): ?>
                    <tr><td colspan="7" class="text-muted">No memberships yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
admin_layout_end();
