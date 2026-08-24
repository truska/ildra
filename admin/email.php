<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/table_sort.php';

// Email admin is admin+ only (nav hides it, but enforce here too).
$roleKey = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($roleKey, ['superadmin', 'admin', 'manager'], true)) {
    header('Location: ' . $adminBase . '/index.php');
    exit;
}

$view = (string)($_GET['view'] ?? 'log');
if ($view === 'settings') {
    if ($roleKey !== 'superadmin') {
        $_SESSION['flash_alerts'] = [['type' => 'danger', 'message' => 'Live email settings are restricted to Superadmin users.']];
        header('Location: ' . $adminBase . '/email.php');
        exit;
    }
    ensureEmailTables($pdo);
    $emailSettings = getEmailSettings($pdo);
    $siteSettings = getSiteSettings($pdo);
    $testResult = null;
    $enableBlockedWarning = false;
    $enableBlockedDetail = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_settings') {
        $data = [
            'email_enabled' => isset($_POST['email_enabled']) ? '1' : '0',
            'email_test_redirect_enabled' => isset($_POST['email_test_redirect_enabled']) ? '1' : '0',
            'email_test_redirect_to' => trim((string)($_POST['email_test_redirect_to'] ?? '')),
            'email_subject_prefix' => trim((string)($_POST['email_subject_prefix'] ?? '')),
            'email_from_name' => trim((string)($_POST['email_from_name'] ?? '')),
            'email_from_email' => trim((string)($_POST['email_from_email'] ?? '')),
            'email_reply_to' => trim((string)($_POST['email_reply_to'] ?? '')),
            'email_return_path' => trim((string)($_POST['email_return_path'] ?? '')),
            'email_cc_default' => trim((string)($_POST['email_cc_default'] ?? '')),
            'email_bcc_default' => trim((string)($_POST['email_bcc_default'] ?? '')),
            'email_smtp_host' => trim((string)($_POST['email_smtp_host'] ?? '')),
            'email_smtp_port' => (string)((int)($_POST['email_smtp_port'] ?? 587)),
            'email_smtp_secure' => trim((string)($_POST['email_smtp_secure'] ?? 'tls')),
        ];
        $requestedEnable = $data['email_enabled'] === '1';
        $provider = strtolower((string)($emailSettings['email_provider'] ?? 'php_mail'));

        if (saveEmailSettings($pdo, $data, $alerts)) {
            $_SESSION['flash_success'] = 'Email settings saved.';
            header('Location: ' . $adminBase . '/email.php');
            exit;
        }

        if ($requestedEnable) {
            $enableBlockedWarning = true;
            $missing = [];
            if ($data['email_from_email'] === '' || !filter_var($data['email_from_email'], FILTER_VALIDATE_EMAIL)) {
                $missing[] = 'valid From email';
            }
            if ($provider === 'smtp' && $data['email_smtp_host'] === '') {
                $missing[] = 'SMTP host';
            }
            $resolvedSettings = array_merge($emailSettings, $data);
            if ($provider === 'smtp' && trim((string)($resolvedSettings['email_smtp_username'] ?? '')) === '') {
                $missing[] = 'SMTP username';
            }
            if ($provider === 'smtp' && (string)($resolvedSettings['email_smtp_password'] ?? '') === '') {
                $missing[] = 'SMTP password';
            }
            $detail = $missing
                ? (' Missing: ' . implode(', ', $missing) . '.')
                : '';
            $enableBlockedDetail = $detail;
            $alerts[] = [
                'type' => 'warning',
                'message' => 'Email was not enabled.' . $detail . ' Please fix and save again.',
            ];
        }

        $emailSettings = array_merge($emailSettings, $data);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'send_test') {
        $to = trim((string)($_POST['test_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid test recipient email address.'];
        } else {
            $siteSettings = getSiteSettings($pdo);
            $emailSettings = getEmailSettings($pdo);

            $token = strtoupper(bin2hex(random_bytes(3)));
            $subject = subject_with_prefix($emailSettings, 'Email test ' . $token);
            $now = date('d M Y H:i:s');

            $smtpHost = (string)($emailSettings['email_smtp_host'] ?? '');
            $smtpPort = (string)($emailSettings['email_smtp_port'] ?? '');
            $smtpSecure = (string)($emailSettings['email_smtp_secure'] ?? '');
            $fromEmail = (string)($emailSettings['email_from_email'] ?? '');
            $fromName = (string)($emailSettings['email_from_name'] ?? '');
            $provider = strtolower((string)($emailSettings['email_provider'] ?? 'php_mail'));
            $delivery = $provider === 'smtp'
                ? 'SMTP via ' . $smtpHost . ':' . $smtpPort . ' (' . $smtpSecure . ')'
                : 'PHP mail()';

            $html = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;">'
                . '<h2 style="margin:0 0 8px;">ILDRA email test</h2>'
                . '<div style="color:#555;margin-bottom:14px;">Sent at ' . h($now) . '</div>'
                . '<div style="padding:12px;border:1px solid rgba(0,0,0,0.08);border-radius:12px;background:#fff;">'
                . '<div><strong>From:</strong> ' . h($fromName) . ' &lt;' . h($fromEmail) . '&gt;</div>'
                . '<div><strong>To:</strong> ' . h($to) . '</div>'
                . '<div><strong>Delivery:</strong> ' . h($delivery) . '</div>'
                . '</div>'
                . '<p style="color:#476146;margin-top:14px;">This test uses the site\'s current outbound email configuration and logs the result.</p>'
                . '</body></html>';

            $text = "ILDRA email test\n"
                . "Sent at {$now}\n"
                . "From: {$fromName} <{$fromEmail}>\n"
                . "To: {$to}\n"
                . "Delivery: {$delivery}"
                . "\n\n"
                . "This test uses the site's current outbound email configuration and logs the result.\n";

            $ok = send_logged_email($pdo, $to, $subject, $html, $text, [
                'type' => 'test',
                'token' => $token,
                'admin_user_id' => (int)($currentUser['id'] ?? 0),
                'admin_email' => (string)($currentUser['email'] ?? ''),
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_secure' => $smtpSecure,
            ]);

            $stmt = $pdo->prepare("SELECT id, status, error_message FROM email_log WHERE subject = :subject ORDER BY id DESC LIMIT 1");
            $stmt->execute([':subject' => $subject]);
            $row = $stmt->fetch() ?: null;

            $testResult = [
                'ok' => $ok,
                'subject' => $subject,
                'to' => $to,
                'log_id' => $row ? (int)($row['id'] ?? 0) : 0,
                'status' => $row ? (string)($row['status'] ?? '') : ($ok ? 'sent' : 'failed'),
                'error' => $row ? (string)($row['error_message'] ?? '') : '',
            ];
        }
    }

    admin_layout_start('Live Email Settings', 'tech_email');
    ?>
    <style>
        .panel-soft {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 14px;
        }
        .panel-title {
            font-weight: 800;
            letter-spacing: 0.01em;
            margin: 0;
        }
        .panel-subtitle {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin: 0.25rem 0 0;
        }
        .help-row {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 0.35rem;
        }
        .debug-kv {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 8px 14px;
            font-size: 0.92rem;
        }
        .debug-kv .k { color: var(--text-muted); font-weight: 700; }
        .debug-kv .v { font-weight: 600; color: var(--text-main); }
    </style>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <div class="text-muted">Email settings</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?php echo h($adminBase); ?>/email.php">Back to email log</a>
        </div>
    </div>

    <div class="card-soft p-4">
        <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="action" value="save_settings">

            <div class="panel-soft p-3 p-lg-4 mb-3">
                <p class="panel-title">Testing redirect</p>
                <p class="panel-subtitle">Temporarily redirects all outgoing email to a single address.</p>

                <div class="row g-3 align-items-end mt-1">
                    <div class="col-12 col-lg-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="email_test_redirect_enabled" name="email_test_redirect_enabled" value="1" <?php echo ((string)($emailSettings['email_test_redirect_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="email_test_redirect_enabled">Redirect all outgoing emails</label>
                        </div>
                        <div class="help-row">When enabled, CC/BCC are ignored and every message is delivered only to the address below.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_test_redirect_to">Redirect to</label>
                        <input class="form-control" id="email_test_redirect_to" name="email_test_redirect_to" value="<?php echo h((string)($emailSettings['email_test_redirect_to'] ?? 'clients@truska.com')); ?>" placeholder="clients@truska.com">
                        <div class="help-row">Default: <code>clients@truska.com</code></div>
                    </div>
                </div>
            </div>

            <div class="panel-soft p-3 p-lg-4 mb-3">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                    <div>
                        <p class="panel-title">General</p>
                        <p class="panel-subtitle">Identity and subject formatting.</p>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="email_enabled" name="email_enabled" value="1" <?php echo ((string)($emailSettings['email_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="email_enabled">Enable outbound email</label>
                    </div>
                </div>

                <div class="help-row">Uses authenticated SMTP from private server config when available, ignoring stale database SMTP secrets. Every send is logged and checkout never blocks on failures.</div>
                <?php if ($enableBlockedWarning): ?>
                    <div class="alert alert-warning py-2 px-3 mt-2 mb-0">
                        Email is still off because required settings are incomplete.<?php echo h($enableBlockedDetail); ?>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mt-2 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold" for="email_subject_prefix">Subject prefix</label>
                        <input class="form-control" id="email_subject_prefix" name="email_subject_prefix" value="<?php echo h((string)($emailSettings['email_subject_prefix'] ?? '')); ?>" placeholder="TEST">
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold" for="email_from_name">From name</label>
                        <input class="form-control" id="email_from_name" name="email_from_name" value="<?php echo h((string)($emailSettings['email_from_name'] ?? '')); ?>" placeholder="<?php echo h((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title'])); ?>">
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold" for="email_from_email">From email</label>
                        <input class="form-control" id="email_from_email" name="email_from_email" value="<?php echo h((string)($emailSettings['email_from_email'] ?? '')); ?>" placeholder="no-reply@example.com">
                        <div class="help-row">Delivery identity used in the From header. This may be a no-reply SMTP address and is not shown in the email signature.</div>
                    </div>
                    <div class="col-12">
                        <div class="help-row">Example subject: <code>[TEST]</code> Purchase Confirmation …</div>
                    </div>
                </div>
            </div>

            <div class="panel-soft p-3 p-lg-4 mb-3">
                <p class="panel-title">Reply & routing</p>
                <p class="panel-subtitle">Optional reply handling and default CC/BCC.</p>

                <div class="row g-3 mt-1 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_reply_to">Reply-to / public contact email</label>
                        <input class="form-control" id="email_reply_to" name="email_reply_to" value="<?php echo h((string)($emailSettings['email_reply_to'] ?? '')); ?>" placeholder="support@example.com">
                        <div class="help-row">Stored in <code>site_settings.email_reply_to</code>, used in the Reply-To header, and displayed in customer email signatures.</div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_return_path">Return-path (optional)</label>
                        <input class="form-control" id="email_return_path" name="email_return_path" value="<?php echo h((string)($emailSettings['email_return_path'] ?? '')); ?>" placeholder="bounces@example.com">
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_cc_default">Default CC (optional)</label>
                        <input class="form-control" id="email_cc_default" name="email_cc_default" value="<?php echo h((string)($emailSettings['email_cc_default'] ?? '')); ?>" placeholder="cc1@example.com, cc2@example.com">
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_bcc_default">Default BCC (optional)</label>
                        <input class="form-control" id="email_bcc_default" name="email_bcc_default" value="<?php echo h((string)($emailSettings['email_bcc_default'] ?? '')); ?>" placeholder="audit@example.com">
                    </div>
                </div>
            </div>

            <div class="panel-soft p-3 p-lg-4">
                <p class="panel-title">SMTP Delivery</p>
                <p class="panel-subtitle">Resolved SMTP settings used for outbound email when credentials are available.</p>

                <?php
                $smtpUserResolved = trim((string)($emailSettings['email_smtp_username'] ?? ''));
                $smtpPassResolved = (string)($emailSettings['email_smtp_password'] ?? '');
                $smtpCredsConfigured = $smtpUserResolved !== '' && $smtpPassResolved !== '';
                ?>

                <div class="row g-3 mt-1 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_smtp_host">Host</label>
                        <input class="form-control" id="email_smtp_host" name="email_smtp_host" value="<?php echo h((string)($emailSettings['email_smtp_host'] ?? '')); ?>" placeholder="email-smtp.eu-west-1.amazonaws.com">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label fw-semibold" for="email_smtp_port">Port</label>
                        <input class="form-control" id="email_smtp_port" name="email_smtp_port" inputmode="numeric" value="<?php echo h((string)($emailSettings['email_smtp_port'] ?? '587')); ?>">
                    </div>
                    <div class="col-6 col-lg-4">
                        <label class="form-label fw-semibold" for="email_smtp_secure">Security</label>
                        <select class="form-select" id="email_smtp_secure" name="email_smtp_secure">
                            <?php
                            $secure = (string)($emailSettings['email_smtp_secure'] ?? 'tls');
                            foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None'] as $val => $label) {
                                $sel = $secure === $val ? 'selected' : '';
                                echo '<option value="' . h($val) . '" ' . $sel . '>' . h($label) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_smtp_username">Username</label>
                        <input class="form-control" id="email_smtp_username" value="<?php echo h($smtpUserResolved); ?>" autocomplete="username" disabled>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="email_smtp_password">Password</label>
                        <input class="form-control" id="email_smtp_password" type="password" value="" placeholder="<?php echo $smtpCredsConfigured ? '(resolved from config or preferences.csv)' : '(not configured)'; ?>" autocomplete="current-password" disabled>
                    </div>
                    <div class="col-12">
                        <div class="help-row">SMTP host, port, security and credentials resolve from private server config when present. Database-stored SMTP secrets are ignored and are not written back.</div>
                    </div>
                </div>
            </div>

            <div class="panel-soft p-3 p-lg-4 mt-3">
                <p class="panel-title">Test email</p>
                <p class="panel-subtitle">Sends a test message using the current outbound email setup and logs the result.</p>

                <div class="row g-3 align-items-end mt-1">
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="test_to">Send test to</label>
                        <input class="form-control" id="test_to" name="test_to" value="<?php echo h((string)($_POST['test_to'] ?? 'clients@truska.com')); ?>" placeholder="you@example.com">
                        <div class="help-row">If SES is in sandbox mode, the recipient must be verified in SES.</div>
                    </div>
                    <div class="col-12 col-lg-6 d-flex justify-content-lg-end">
                        <button class="btn btn-outline-secondary" type="submit" name="action" value="send_test">Send test email</button>
                    </div>
                </div>

                <?php if ($testResult): ?>
                    <?php
                    $badgeClass = $testResult['status'] === 'sent' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                    ?>
                    <div class="mt-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge rounded-pill <?php echo h($badgeClass); ?>"><?php echo h($testResult['status']); ?></span>
                            <div class="fw-semibold">Test result</div>
                            <?php if (!empty($testResult['log_id'])): ?>
                                <a class="small text-decoration-none" href="<?php echo h($adminBase); ?>/email_view.php?id=<?php echo (int)$testResult['log_id']; ?>">View log</a>
                            <?php endif; ?>
                        </div>
                        <div class="debug-kv">
                            <div class="k">To</div><div class="v"><?php echo h((string)$testResult['to']); ?></div>
                            <div class="k">Subject</div><div class="v"><?php echo h((string)$testResult['subject']); ?></div>
                            <?php if (!empty($testResult['error'])): ?>
                                <div class="k">Error</div><div class="v"><?php echo h((string)$testResult['error']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-success" type="submit" name="action" value="save_settings">Save settings</button>
                <a class="btn btn-outline-secondary" href="<?php echo h($adminBase); ?>/email.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    admin_layout_end();
    exit;
}

admin_layout_start('Email', 'email');
ensureEmailTables($pdo);

// Log view (default)
$pageSize = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;

$sortKey = (string)($_GET['sort'] ?? 'id');
$sortDir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

$sortMap = [
    'id' => 'el.id',
    'sent_at' => 'COALESCE(el.sent_at, el.created_at)',
    'to' => 'el.to_email',
    'subject' => 'el.subject',
    'status' => 'el.status',
];
$sortSql = $sortMap[$sortKey] ?? $sortMap['id'];

$filters = [
    'id' => trim((string)($_GET['filter_id'] ?? '')),
    'to' => trim((string)($_GET['filter_to'] ?? '')),
    'subject' => trim((string)($_GET['filter_subject'] ?? '')),
    'status' => trim((string)($_GET['filter_status'] ?? '')),
    'sent' => trim((string)($_GET['filter_sent'] ?? '')),
];
$hasFilters = implode('', $filters) !== '';

$emailOptions = $pdo->query("SELECT DISTINCT to_email FROM email_log WHERE to_email <> '' ORDER BY to_email ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$statusOptions = $pdo->query("SELECT DISTINCT status FROM email_log WHERE status <> '' ORDER BY status ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];

$where = [];
$params = [];
if ($filters['id'] !== '') {
    $where[] = 'CAST(el.id AS CHAR) LIKE :filter_id';
    $params[':filter_id'] = '%' . $filters['id'] . '%';
}
if ($filters['to'] !== '') {
    $where[] = 'el.to_email = :filter_to';
    $params[':filter_to'] = $filters['to'];
}
if ($filters['subject'] !== '') {
    $where[] = 'el.subject LIKE :filter_subject';
    $params[':filter_subject'] = '%' . $filters['subject'] . '%';
}
if ($filters['status'] !== '') {
    $where[] = 'el.status = :filter_status';
    $params[':filter_status'] = $filters['status'];
}
if ($filters['sent'] !== '') {
    $where[] = "COALESCE(DATE_FORMAT(el.sent_at, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(el.created_at, '%Y-%m-%d %H:%i:%s')) LIKE :filter_sent";
    $params[':filter_sent'] = '%' . $filters['sent'] . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totalAll = (int)$pdo->query("SELECT COUNT(*) FROM email_log")->fetchColumn();
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_log el {$whereSql}");
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $pages);
$offset = ($page - 1) * $pageSize;

$stmt = $pdo->prepare("
    SELECT el.id, el.status, el.to_email, el.subject, el.body_text, el.body_html, el.error_message, el.sent_at, el.created_at
    FROM email_log el
    {$whereSql}
    ORDER BY {$sortSql} {$sortDir}
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll() ?: [];

?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
    <div>
        <div class="text-muted">Email log</div>
        <div class="small text-muted">Showing <?php echo (int)$total; ?><?php echo $hasFilters ? ' of ' . (int)$totalAll : ''; ?></div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?php echo h($adminBase); ?>/email.php?view=settings">Email settings</a>
    </div>
</div>

<div class="card-soft p-4">
    <div class="table-responsive">
        <form method="get" class="m-0">
            <input type="hidden" name="sort" value="<?php echo h($sortKey); ?>">
            <input type="hidden" name="dir" value="<?php echo h($sortDir); ?>">
            <table class="table table-sm align-middle mb-0 admin-data-table">
                <thead>
                <tr>
                    <th style="width: 72px;"><?php echo admin_sort_link('id', '#', $sortKey, $sortDir); ?></th>
                    <th><?php echo admin_sort_link('to', 'To', $sortKey, $sortDir); ?></th>
                    <th><?php echo admin_sort_link('subject', 'Subject', $sortKey, $sortDir); ?></th>
                    <th style="width: 130px;"><?php echo admin_sort_link('status', 'Status', $sortKey, $sortDir); ?></th>
                    <th style="width: 170px;"><?php echo admin_sort_link('sent_at', 'Sent', $sortKey, $sortDir); ?></th>
                    <th style="width: 110px;"></th>
                </tr>
                <tr class="admin-table-filter-row">
                    <th>
                        <div class="admin-table-filter">
                            <input class="form-control" type="search" name="filter_id" value="<?php echo h($filters['id']); ?>" placeholder="ID">
                        </div>
                    </th>
                    <th>
                        <div class="admin-table-filter">
                            <select class="form-select" name="filter_to">
                                <option value="">All emails</option>
                                <?php foreach ($emailOptions as $emailOption): ?>
                                    <?php $emailOption = (string)$emailOption; ?>
                                    <option value="<?php echo h($emailOption); ?>" <?php echo $filters['to'] === $emailOption ? 'selected' : ''; ?>><?php echo h($emailOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </th>
                    <th>
                        <div class="admin-table-filter">
                            <input class="form-control" type="search" name="filter_subject" value="<?php echo h($filters['subject']); ?>" placeholder="Subject">
                        </div>
                    </th>
                    <th>
                        <div class="admin-table-filter">
                            <select class="form-select" name="filter_status">
                                <option value="">All</option>
                                <?php foreach ($statusOptions as $statusOption): ?>
                                    <?php $statusOption = (string)$statusOption; ?>
                                    <option value="<?php echo h($statusOption); ?>" <?php echo $filters['status'] === $statusOption ? 'selected' : ''; ?>><?php echo h($statusOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </th>
                    <th>
                        <div class="admin-table-filter">
                            <input class="form-control" type="search" name="filter_sent" value="<?php echo h($filters['sent']); ?>" placeholder="YYYY-MM-DD">
                        </div>
                    </th>
                    <th>
                        <div class="admin-table-filter-actions">
                            <button class="btn btn-sm btn-success" type="submit">Filter</button>
                            <?php if ($hasFilters): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($adminBase); ?>/email.php?sort=<?php echo h($sortKey); ?>&dir=<?php echo h($sortDir); ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </th>
                </tr>
                </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-muted">No emails yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $id = (int)($r['id'] ?? 0);
                    $status = (string)($r['status'] ?? '');
                    $to = (string)($r['to_email'] ?? '');
                    $subject = (string)($r['subject'] ?? '');
                    $sent = $r['sent_at'] ?? $r['created_at'] ?? null;
                    $snippetSource = (string)($r['body_text'] ?? '');
                    if ($snippetSource === '') {
                        $snippetSource = strip_tags((string)($r['body_html'] ?? ''));
                    }
                    $snippetSource = preg_replace('/\\s+/', ' ', trim($snippetSource)) ?: '';
                    $snippet = function_exists('mb_substr') ? mb_substr($snippetSource, 0, 120) : substr($snippetSource, 0, 120);
                    $badgeClass = 'bg-secondary-subtle text-secondary';
                    if ($status === 'sent') {
                        $badgeClass = 'bg-success-subtle text-success';
                    } elseif ($status === 'bounced') {
                        $badgeClass = 'bg-warning-subtle text-warning';
                    } elseif ($status === 'failed') {
                        $badgeClass = 'bg-danger-subtle text-danger';
                    }
                    ?>
                    <tr>
                        <td class="text-muted fw-semibold">#<?php echo (int)$id; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo h($to); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo h($subject); ?></div>
                            <?php if ($snippet !== ''): ?>
                                <div class="small text-muted"><?php echo h($snippet); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge rounded-pill <?php echo h($badgeClass); ?>"><?php echo h($status); ?></span></td>
                        <td class="text-muted"><?php echo h(format_display_datetime($sent, '')); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($adminBase); ?>/email_view.php?id=<?php echo (int)$id; ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            </table>
        </form>
    </div>

    <?php if ($pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">Page <?php echo (int)$page; ?> of <?php echo (int)$pages; ?></div>
            <div class="btn-group">
                <?php
                $baseQuery = $_GET;
                $baseQuery['page'] = max(1, $page - 1);
                $prevUrl = '?' . http_build_query($baseQuery);
                $baseQuery['page'] = min($pages, $page + 1);
                $nextUrl = '?' . http_build_query($baseQuery);
                ?>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo h($prevUrl); ?>">Prev</a>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo h($nextUrl); ?>">Next</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_layout_end(); ?>
