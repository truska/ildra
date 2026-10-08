<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../testing_reset.php';
if (!roleIsSuperadminOrDeveloper($currentRole)) { http_response_code(403); exit('Superadmin access required.'); }
$preview = null;
$choices = (array)($_POST['people'] ?? []);
$keepCalendar = !empty($_POST['keep_calendar']);
if ($pdo && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'reset') {
            $saved = $_SESSION['testing_reset_preview'] ?? null;
            unset($_SESSION['testing_reset_preview']);
            if (!$saved || time() - $saved['time'] > 900 || !hash_equals($saved['token'], (string)($_POST['preview_token'] ?? ''))) throw new RuntimeException('Preview expired. Create a new preview.');
            if (($_POST['confirmation'] ?? '') !== 'RESET TEST DATA' || empty($_POST['quiet'])) throw new RuntimeException('Confirm the reset phrase and that testing/payment processing has stopped.');
            $plan = testingResetPlan($pdo, $saved['calendar'], $saved['choices']);
            if ($plan !== $saved['plan'] || testingResetCounts($pdo, $plan) !== $saved['counts']) throw new RuntimeException('Data changed since preview. Review a new preview.');
            $backup = testingResetRun($pdo, $plan, (int)$currentUser['id'], $saved['choices'], $saved['counts']);
            unset($_SESSION['basket'], $_SESSION['basket_last_added']);
            $successMessage = 'Test data reset completed. Private backup: ' . $backup;
        } else {
            $plan = testingResetPlan($pdo, $keepCalendar, $choices);
            $preview = ['plan'=>$plan, 'counts'=>testingResetCounts($pdo, $plan), 'choices'=>$choices, 'calendar'=>$keepCalendar, 'time'=>time(), 'token'=>bin2hex(random_bytes(24))];
            $_SESSION['testing_reset_preview'] = $preview;
        }
    } catch (Throwable $e) { $alerts[] = ['type'=>'danger', 'message'=>$e->getMessage()]; }
}
$identities = $pdo ? testingResetIdentities($pdo) : [];
admin_layout_start('Reset test data', 'tech');
?>
<h4>Reset test data</h4>
<p>Keep existing logins and the selected person for each account. Testers will need to join again, register their horses and make new entries.</p>
<div class="alert alert-warning">This clears all operational data, including payments, credits, loyalty cards, memberships, horse logbooks, horses, bookings, results, sharing, email history and admin activity history. It clears local Stripe customer links; it does not delete or refund anything at Stripe. Use only for a testing database or the agreed pre-launch cleanup.</div>
<p>Preserved: login credentials, roles, authentication tokens, website pages and content, help, all email campaign data and templates, venues, event types, entry component definitions, pricing schemes, membership/logbook types, awards, development tasks and site settings. Historical migration backup tables are preserved. Member numbers and qualifications on retained people are cleared.</p>
<?php if ($preview): ?>
<div class="card-soft p-4 mb-4">
<h5>Preview — <?= $preview['calendar'] ? 'keep calendar and event setup' : 'remove calendar' ?></h5>
<p><?= count($preview['plan']['keep_people']) ?> people will remain. <?= count($identities) ?> logins will remain.</p>
<table class="table"><thead><tr><th>Data to remove</th><th>Records</th></tr></thead><tbody>
<?php foreach ($preview['counts'] as $table=>$count): ?><tr><td><?= h($table) ?></td><td><?= $count ?></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post">
<input type="hidden" name="action" value="reset"><input type="hidden" name="preview_token" value="<?= h($preview['token']) ?>">
<p>A full database backup is written to private/testing-reset-backups before deletion. Keep the site quiet until this finishes; stop testing, scheduled sends and payment/webhook processing first. Existing browser baskets should be closed and reopened after the reset.</p>
<label class="d-block mb-3"><input type="checkbox" name="quiet" value="1" required> Testing, scheduled sends and payment processing have stopped.</label>
<label class="form-label" for="confirmation">Type RESET TEST DATA to confirm</label>
<input class="form-control mb-3" id="confirmation" name="confirmation" required autocomplete="off" pattern="RESET TEST DATA">
<button class="btn btn-danger">Back up and reset test data</button>
</form></div>
<?php endif; ?>
<form method="post" class="card-soft p-4">
<input type="hidden" name="action" value="preview">
<label class="d-block mb-3"><input type="checkbox" name="keep_calendar" value="1" <?= $keepCalendar ? 'checked' : '' ?>> Keep the calendar, event pricing, components, class setup and ride notes (entries and results are still removed)</label>
<p>Select each login’s own person. Suggestions use a unique matching email or name among people owned by that login. Choose “No existing person” if none represents them; they can create their person after logging in.</p>
<?php foreach ($identities as $user): $selected = $choices[$user['id']] ?? $user['suggested']; ?>
<label class="form-label" for="person-<?= (int)$user['id'] ?>"><?= h($user['email']) ?></label>
<select class="form-select mb-3" name="people[<?= (int)$user['id'] ?>]" id="person-<?= (int)$user['id'] ?>" required>
<option value="">Choose the person to keep</option>
<option value="0" <?= (string)$selected === '0' ? 'selected' : '' ?>>No existing person — create after login</option>
<?php foreach ($user['people'] as $person): ?><option value="<?= (int)$person['id'] ?>" <?= (string)$selected === (string)$person['id'] ? 'selected' : '' ?>><?= h($person['first_name'] . ' ' . $person['last_name'] . ' (#' . $person['id'] . ')') ?></option><?php endforeach; ?>
</select>
<?php endforeach; ?>
<button class="btn btn-outline-success" <?= !$pdo ? 'disabled' : '' ?>>Preview reset</button>
</form>
<?php admin_layout_end(); ?>
