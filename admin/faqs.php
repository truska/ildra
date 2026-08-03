<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage FAQs.'];
    } elseif ($action === 'delete_faq') {
        $faqId = (int)($_POST['faq_id'] ?? 0);
        if ($faqId > 0 && deleteFaq($pdo, $faqId, $alerts)) {
            $successMessage = 'FAQ deleted.';
        }
    }
    if ($alerts) {
        $_SESSION['flash_alerts'] = $alerts;
    }
    if ($successMessage) {
        $_SESSION['flash_success'] = $successMessage;
    }
    header('Location: faqs.php');
    exit;
}

$faqs = fetchFaqs($pdo);

$sortKey = $_GET['sort'] ?? 'order';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$allowedSortKeys = ['question', 'order'];
if (!in_array($sortKey, $allowedSortKeys, true)) {
    $sortKey = 'order';
}

// Preserve current default ordering when no explicit sort is requested.
$hasExplicitSort = array_key_exists('sort', $_GET) || array_key_exists('dir', $_GET);
if ($hasExplicitSort) {
    usort($faqs, function (array $a, array $b) use ($sortKey, $sortDir): int {
        $dir = $sortDir === 'asc' ? 1 : -1;
        if ($sortKey === 'question') {
            $va = mb_strtolower((string)($a['question'] ?? ''));
            $vb = mb_strtolower((string)($b['question'] ?? ''));
            if ($va === $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $dir;
        }
        $va = (int)($a['display_order'] ?? 0);
        $vb = (int)($b['display_order'] ?? 0);
        if ($va === $vb) {
            return 0;
        }
        return ($va < $vb ? -1 : 1) * $dir;
    });
} else {
    // Don't highlight any sort column by default.
    $sortKey = '__none__';
    $sortDir = 'asc';
}

admin_layout_start('FAQs', 'faqs');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Manage FAQs</div>
        <h5 class="mb-0">FAQs</h5>
    </div>
    <div>
        <a class="btn btn-success" href="faq_edit.php">Add FAQ</a>
    </div>
</div>

<div class="card-soft p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th><?php echo admin_sort_link('question', 'Question', (string)$sortKey, (string)$sortDir); ?></th>
                    <th><?php echo admin_sort_link('order', 'Order', (string)$sortKey, (string)$sortDir); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($faqs as $faq): ?>
                    <tr>
                        <td><?php echo h($faq['question']); ?></td>
                        <td><?php echo h((string)($faq['display_order'] ?? 0)); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-success" href="faq_edit.php?id=<?php echo (int)$faq['id']; ?>">Edit</a>
                            <?php if ($isAdmin): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this FAQ?');">
                                    <input type="hidden" name="action" value="delete_faq">
                                    <input type="hidden" name="faq_id" value="<?php echo (int)$faq['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$faqs): ?>
                    <tr><td colspan="3" class="text-muted">No FAQs yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
admin_layout_end();
