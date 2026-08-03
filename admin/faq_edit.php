<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$faqId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$faq = $faqId ? fetchFaqById($pdo, $faqId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can manage FAQs.'];
    } else {
        if (saveFaq($pdo, $_POST, $alerts)) {
            $_SESSION['flash_success'] = 'FAQ saved.';
            header('Location: faqs.php');
            exit;
        }
    }
}

$faq = $faq ?? [
    'id' => 0,
    'question' => '',
    'answer' => '',
    'display_order' => 0,
];

admin_layout_start($faqId ? 'Edit FAQ' : 'Add FAQ', 'faqs');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted"><?php echo $faqId ? 'Edit FAQ' : 'Add FAQ'; ?></div>
        <h5 class="mb-0"><?php echo $faqId ? h($faq['question']) : 'New FAQ'; ?></h5>
    </div>
    <div>
        <a class="btn btn-outline-secondary" href="faqs.php">Back to list</a>
    </div>
</div>

<div class="card-soft p-4">
    <form method="POST">
        <input type="hidden" name="action" value="save_faq">
        <input type="hidden" name="faq_id" value="<?php echo h((string)$faq['id']); ?>">
        <div class="mb-3">
            <label class="form-label">Question</label>
            <input type="text" name="question" class="form-control" required value="<?php echo h($faq['question']); ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Answer</label>
            <textarea name="answer" class="form-control wysiwyg-field" rows="8"><?php echo h($faq['answer']); ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Display order</label>
            <input type="number" name="display_order" class="form-control" value="<?php echo h((string)$faq['display_order']); ?>">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success">Save</button>
            <a class="btn btn-outline-secondary" href="faqs.php">Cancel</a>
        </div>
    </form>
</div>
<?php render_tinymce_bootstrap(); ?>
<script>
    (function() {
        if (!window.tinymce) {
            return;
        }
        tinymce.init(window.ildraTinyMceConfig({
            selector: 'textarea.wysiwyg-field'
        }));
    })();
</script>
<?php
admin_layout_end();
