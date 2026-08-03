<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Email admin is admin+ only (nav hides it, but enforce here too).
$roleKey = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($roleKey, ['superadmin', 'admin'], true)) {
    header('Location: ' . $adminBase . '/index.php');
    exit;
}

ensureEmailTables($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash_alerts'] = [['type' => 'danger', 'message' => 'Missing email id.']];
    header('Location: ' . $adminBase . '/email.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM email_log WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$email = $stmt->fetch();
if (!$email) {
    $_SESSION['flash_alerts'] = [['type' => 'danger', 'message' => 'Email not found.']];
    header('Location: ' . $adminBase . '/email.php');
    exit;
}

admin_layout_start('Email', 'email');

$status = (string)($email['status'] ?? '');
$badgeClass = 'bg-secondary-subtle text-secondary';
if ($status === 'sent') {
    $badgeClass = 'bg-success-subtle text-success';
} elseif ($status === 'failed') {
    $badgeClass = 'bg-danger-subtle text-danger';
}

$sentAt = $email['sent_at'] ?? null;
$createdAt = $email['created_at'] ?? null;
$toEmail = (string)($email['to_email'] ?? '');
$cc = (string)($email['cc'] ?? '');
$bcc = (string)($email['bcc'] ?? '');
$subject = (string)($email['subject'] ?? '');
$err = (string)($email['error_message'] ?? '');
$htmlBody = (string)($email['body_html'] ?? '');
$textBody = (string)($email['body_text'] ?? '');
$metaJson = (string)($email['meta_json'] ?? '');
$metaData = null;
if ($metaJson !== '') {
    $decoded = json_decode($metaJson, true);
    if (is_array($decoded)) {
        $metaData = $decoded;
    }
}

?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
        <span class="badge rounded-pill <?php echo h($badgeClass); ?>"><?php echo h($status); ?></span>
        <div class="text-muted">Email #<?php echo (int)$id; ?></div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?php echo h($adminBase); ?>/email.php">Back to email log</a>
    </div>
</div>

<div class="card-soft p-4 mb-4">
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="text-muted small">To</div>
            <div class="fw-semibold"><?php echo h($toEmail); ?></div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="text-muted small">Sent</div>
            <div class="fw-semibold"><?php echo h(format_display_datetime($sentAt, '')); ?></div>
            <?php if (!$sentAt): ?>
                <div class="small text-muted">Created: <?php echo h(format_display_datetime($createdAt, '')); ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12">
            <div class="text-muted small">Subject</div>
            <div class="fw-semibold"><?php echo h($subject); ?></div>
        </div>

        <?php if ($cc !== ''): ?>
            <div class="col-12 col-lg-6">
                <div class="text-muted small">CC</div>
                <div class="fw-semibold"><?php echo h($cc); ?></div>
            </div>
        <?php endif; ?>
        <?php if ($bcc !== ''): ?>
            <div class="col-12 col-lg-6">
                <div class="text-muted small">BCC</div>
                <div class="fw-semibold"><?php echo h($bcc); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($err !== ''): ?>
            <div class="col-12">
                <div class="alert alert-danger mb-0">
                    <div class="fw-semibold">Error</div>
                    <div><?php echo h($err); ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card-soft p-4 mb-4">
    <div class="fw-bold mb-2">HTML preview</div>
    <?php if ($htmlBody === ''): ?>
        <div class="text-muted">No HTML body stored.</div>
    <?php else: ?>
        <iframe
            sandbox
            style="width:100%;height:420px;border:1px solid rgba(0,0,0,0.08);border-radius:12px;background:#fff;"
            srcdoc="<?php echo h($htmlBody); ?>"></iframe>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card-soft p-4">
            <div class="fw-bold mb-2">Plain text</div>
            <pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;"><?php echo h($textBody); ?></pre>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card-soft p-4">
            <div class="fw-bold mb-2">HTML source</div>
            <pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;"><?php echo h($htmlBody); ?></pre>
        </div>
    </div>
</div>

<?php if ($metaData !== null): ?>
    <div class="card-soft p-4 mt-4">
        <div class="fw-bold mb-2">Debug metadata</div>
        <pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;"><?php echo h(json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
<?php endif; ?>

<?php admin_layout_end(); ?>
