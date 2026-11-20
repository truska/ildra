<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../import_workspace.php';
if (!$pdo || !roleIsDeveloper($currentRole)) { http_response_code(403); exit('Developer access required.'); }
$workspaceConfig = importWorkspaceConfig();
$workspaceHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
try {
    $protectedId = importWorkspaceAuthorize($pdo, $currentUser, $workspaceConfig, $workspaceHost);
} catch (RuntimeException $e) {
    http_response_code(403); exit(h($e->getMessage()));
}
$preview = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'reset') {
            $saved = $_SESSION['import_workspace_preview'] ?? null;
            unset($_SESSION['import_workspace_preview']);
            if (!is_array($saved) || !hash_equals($saved['token'], (string)($_POST['preview_token'] ?? ''))) {
                throw new RuntimeException('Preview expired. Create a new preview.');
            }
            if (($_POST['confirmation'] ?? '') !== 'RESET DEV IMPORT DATA' || empty($_POST['quiet'])) {
                throw new RuntimeException('Confirm the reset phrase and that testing, scheduled sends and payment processing have stopped.');
            }
            $backup = importWorkspaceRun($pdo, $currentUser, $workspaceConfig, $workspaceHost, $saved);
            unset($_SESSION['basket'], $_SESSION['basket_last_added']);
            $successMessage = 'Development data reset completed. Your protected login remains. Private backup: ' . $backup;
        } elseif (($_POST['action'] ?? '') === 'preview') {
            $plan = importWorkspacePlan($pdo, $protectedId);
            $preview = ['actor_id'=>$protectedId, 'plan'=>$plan, 'snapshot'=>importWorkspaceSnapshot($pdo, $plan), 'time'=>time(), 'token'=>bin2hex(random_bytes(24))];
            $_SESSION['import_workspace_preview'] = $preview;
        }
    } catch (Throwable $e) {
        error_log('Import workspace: ' . $e->getMessage());
        $alerts[] = ['type'=>'danger', 'message'=>($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Reset failed; database changes were rolled back. Check the server log.'];
    }
}
admin_layout_start('Import workspace', 'import_workspace');
?>
<div class="card-soft p-4">
    <div class="small text-muted">Tech · Development only</div>
    <h4>Import workspace</h4>
    <p>Prepare a clean development database for repeated import rehearsals. <strong><?= h((string)$currentUser['email']) ?></strong> is the protected Developer login and will remain usable.</p>
    <div class="alert alert-warning">Reset permanently deletes all other logins, all people, registered horses, entries, bookings, results, transactions, credits, payment requests, memberships, logbooks, shares, issued coupons, recipient/send history and other users’ sign-in tokens. A full private database backup is required and created automatically before deletion.</div>
    <p>Kept: website content and images, calendar and event setup, pricing, reference/type definitions, awards, menu, email templates and campaign definitions, development tasks, private source files and historical database snapshots. The required “Not Registered” horse option remains. Optional links to removed accounts are cleared; task authorship is transferred to the protected login while existing author names remain.</p>
    <p>Campaigns return to Draft with empty recipient lists, and campaign live sending is switched off. Your email transport and testing redirect stay as configured. Membership numbering restarts at 1000; database IDs continue increasing. No emails are sent and no Stripe records are deleted or refunded by this tool.</p>
    <hr>
    <h5>CSV delivery</h5>
    <p>Upload the original sample using SFTP or the file manager to <code>../private/source-data/</code>, inside a dated delivery folder. Keep the file outside the public web root. Expected first-stage columns: <code>email,first_name,last_name</code>.</p>
    <p>Uploading and resetting do not import the file. CSV mapping, duplicate review and the import preview are the next stage once the sample is ready. Invitations and onboarding remain separate work.</p>
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="preview">
        <button class="btn btn-outline-success">Preview development reset</button>
    </form>
</div>
<?php if ($preview): ?>
<div class="card-soft p-4 mt-4">
    <h5>Reset preview</h5>
    <p>One protected login will remain; all people will be removed. Review these deletion counts:</p>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Data</th><th class="text-end">Records to delete</th></tr></thead><tbody>
    <?php foreach ($preview['snapshot']['counts'] as $table=>$count): ?>
        <tr><td><?= h($table) ?></td><td class="text-end"><?= (int)$count ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p>Backups are stored outside the website in <code>../private/import-workspace-backups/</code>. Reset is cancelled if backup creation fails or data changes after this preview. The preview expires after 15 minutes.</p>
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="preview_token" value="<?= h($preview['token']) ?>">
        <label class="d-block mb-3"><input type="checkbox" name="quiet" value="1" required> Testing, scheduled sends and payment/webhook processing have stopped; other browser baskets are closed.</label>
        <label class="form-label" for="import-reset-confirmation">Type RESET DEV IMPORT DATA to confirm</label>
        <input class="form-control mb-3" id="import-reset-confirmation" name="confirmation" autocomplete="off" pattern="RESET DEV IMPORT DATA" required>
        <button class="btn btn-danger">Back up and reset development data</button>
    </form>
</div>
<?php endif; ?>
<?php admin_layout_end(); ?>
