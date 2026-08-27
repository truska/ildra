<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
if (empty($_SESSION['faq_list_csrf'])) {
    $_SESSION['faq_list_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['faq_list_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $alerts[] = ['type' => 'danger', 'message' => 'Your session token expired. Please try again.'];
    } elseif (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage FAQs.'];
    } elseif ($action === 'reorder_faqs') {
        $orderedIds = array_values(array_map('intval', (array)($_POST['faq_order'] ?? [])));
        $currentIds = array_map(static fn(array $faq): int => (int)$faq['id'], fetchFaqs($pdo));
        $validIds = $orderedIds;
        $expectedIds = $currentIds;
        sort($validIds);
        sort($expectedIds);
        if (count($orderedIds) !== count(array_unique($orderedIds)) || $validIds !== $expectedIds) {
            $alerts[] = ['type' => 'danger', 'message' => 'The FAQ list changed before the new order was saved. Please try again.'];
        } else {
            try {
                $pdo->beginTransaction();
                $updateOrder = $pdo->prepare('UPDATE faqs SET display_order = :display_order WHERE id = :id');
                foreach ($orderedIds as $index => $faqId) {
                    $updateOrder->execute([':display_order' => ($index + 1) * 10, ':id' => $faqId]);
                }
                $pdo->commit();
                $successMessage = 'FAQ order saved.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $alerts[] = ['type' => 'danger', 'message' => 'Could not save the FAQ order.'];
            }
        }
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

$allFaqs = fetchFaqs($pdo);
$filterForm = 'faq-filter-form';
$tableColumns = [
    'question' => [
        'label' => 'Question', 'sortable' => true, 'filter' => 'text',
        'placeholder' => 'Search question or answer', 'form' => $filterForm,
        'filter_match' => static fn(array $faq, string $needle): bool => stripos(
            (string)($faq['question'] ?? '') . ' ' . strip_tags((string)($faq['answer'] ?? '')),
            $needle
        ) !== false,
    ],
    'order' => [
        'label' => 'Order', 'field' => 'display_order', 'sortable' => true,
        'filter' => 'text', 'compare' => 'number', 'form' => $filterForm,
        'placeholder' => 'Order',
    ],
];
$table = admin_table_prepare($allFaqs, $tableColumns, 'order');
$faqs = $table['rows'];
$hasFilters = count(array_filter($table['filters'], static fn(string $value): bool => $value !== '')) > 0;
$canReorder = !$hasFilters
    && $table['sort_key'] === 'order'
    && $table['sort_dir'] === 'asc'
    && count($faqs) === count($allFaqs);

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
    <form method="get" id="<?php echo h($filterForm); ?>"></form>
    <?php echo admin_table_record_count($table, 'FAQ', 'FAQs'); ?>
    <?php if ($faqs && $canReorder): ?>
        <div class="d-none d-md-flex flex-wrap align-items-center gap-2 mb-3" id="faq-reorder-toolbar">
            <button class="btn btn-sm btn-outline-success" type="button" id="faq-reorder-start"><i class="fa-solid fa-arrow-down-up-across-line me-1" aria-hidden="true"></i>Reorder</button>
            <form method="post" class="d-none align-items-center gap-2 m-0" id="faq-reorder-form">
                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="reorder_faqs">
                <span class="small text-muted">Drag the handles, then save.</span>
                <button class="btn btn-sm btn-success" type="submit">Save order</button>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="faq-reorder-cancel">Cancel</button>
            </form>
        </div>
    <?php elseif ($faqs): ?>
        <div class="d-none d-md-flex mb-3"><a class="btn btn-sm btn-outline-success" href="faqs.php?sort=order&amp;dir=asc&amp;per_page=500"><i class="fa-solid fa-arrow-down-up-across-line me-1" aria-hidden="true"></i>Reorder <span class="fw-normal">(reset before reordering)</span></a></div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="faq-order-table">
            <thead class="table-light">
                <tr>
                    <th class="d-none d-md-table-cell faq-drag-column"><span class="visually-hidden">Reorder</span></th>
                    <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_heading($key, $column, $table['sort_key'], $table['sort_dir']); ?></th><?php endforeach; ?>
                    <th class="text-end">Actions</th>
                </tr>
                <tr class="admin-table-filter-row">
                    <th class="d-none d-md-table-cell faq-drag-column"></th>
                    <?php foreach ($tableColumns as $key => $column): ?><th><?php echo admin_table_filter($key, $column, $table['filters']); ?></th><?php endforeach; ?>
                    <th class="text-end"><button class="btn btn-sm btn-outline-secondary" form="<?php echo h($filterForm); ?>">Filter</button> <a class="btn btn-sm btn-link" href="faqs.php">Clear</a></th>
                </tr>
            </thead>
            <tbody id="faq-order-rows">
                <?php foreach ($faqs as $faq): ?>
                    <tr data-faq-id="<?php echo (int)$faq['id']; ?>">
                        <td class="d-none d-md-table-cell faq-drag-column"><button class="faq-drag-handle" type="button" disabled aria-label="Move <?php echo h($faq['question']); ?>"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i></button></td>
                        <td><?php echo h($faq['question']); ?></td>
                        <td><?php echo h((string)($faq['display_order'] ?? 0)); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-success" href="faq_edit.php?id=<?php echo (int)$faq['id']; ?>">Edit</a>
                            <?php if ($isAdmin): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this FAQ?');">
                                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="action" value="delete_faq">
                                    <input type="hidden" name="faq_id" value="<?php echo (int)$faq['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$faqs): ?>
                    <tr><td colspan="4" class="text-muted">No FAQs yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_table_pagination($table); ?>
</div>
<style>
    .faq-drag-column{width:42px}.faq-drag-handle{border:0;background:transparent;color:#98a098;padding:.35rem .55rem;border-radius:6px}.faq-drag-handle:not(:disabled){color:#146118;cursor:grab;touch-action:none}.faq-drag-handle:not(:disabled):active{cursor:grabbing}.faq-reorder-active tr[data-faq-id]{background:#f5faf3}.faq-row-dragging{opacity:.55;box-shadow:0 4px 14px rgba(15,45,23,.16)}
    @media(max-width:767.98px){.faq-drag-column,#faq-reorder-toolbar{display:none!important}}
</style>
<?php if ($faqs && $canReorder): ?>
<script>
(()=>{if(window.innerWidth<768)return;const table=document.getElementById('faq-order-table'),rows=document.getElementById('faq-order-rows'),start=document.getElementById('faq-reorder-start'),form=document.getElementById('faq-reorder-form'),cancel=document.getElementById('faq-reorder-cancel');if(!table||!rows||!start||!form||!cancel)return;const handles=[...rows.querySelectorAll('.faq-drag-handle')];let dragged=null,originalOrder=[];const finish=()=>{if(!dragged)return;dragged.classList.remove('faq-row-dragging');dragged=null};start.addEventListener('click',()=>{originalOrder=[...rows.querySelectorAll('tr[data-faq-id]')].map(row=>row.dataset.faqId);table.classList.add('faq-reorder-active');handles.forEach(handle=>handle.disabled=false);start.classList.add('d-none');form.classList.remove('d-none');form.classList.add('d-flex')});cancel.addEventListener('click',()=>{const byId=new Map([...rows.querySelectorAll('tr[data-faq-id]')].map(row=>[row.dataset.faqId,row]));originalOrder.forEach(id=>rows.appendChild(byId.get(id)));finish();handles.forEach(handle=>handle.disabled=true);table.classList.remove('faq-reorder-active');form.classList.add('d-none');form.classList.remove('d-flex');start.classList.remove('d-none')});handles.forEach(handle=>{handle.addEventListener('pointerdown',event=>{if(handle.disabled)return;dragged=handle.closest('tr[data-faq-id]');dragged.classList.add('faq-row-dragging');handle.setPointerCapture(event.pointerId);event.preventDefault()});handle.addEventListener('pointermove',event=>{if(!dragged)return;const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('tr[data-faq-id]');if(!target||target===dragged||target.parentElement!==rows)return;const rect=target.getBoundingClientRect();rows.insertBefore(dragged,event.clientY<rect.top+rect.height/2?target:target.nextSibling)});handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish)});form.addEventListener('submit',()=>{form.querySelectorAll('input[name="faq_order[]"]').forEach(input=>input.remove());rows.querySelectorAll('tr[data-faq-id]').forEach(row=>{const input=document.createElement('input');input.type='hidden';input.name='faq_order[]';input.value=row.dataset.faqId;form.appendChild(input)})})})();
</script>
<?php endif; ?>
<?php
admin_layout_end();
