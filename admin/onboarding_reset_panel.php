<?php if (!empty($resetAvailable)): ?>
<section class="card-soft border border-danger p-4 mt-4" aria-labelledby="onboarding-reset-heading">
    <h2 class="h5" id="onboarding-reset-heading">Reset onboarding test data</h2>
    <p>Development only. Both options remove people, entries/bookings/results, registered horses, shares, memberships/logbooks, transactions, credits/debits and other financial test records, together with old onboarding/invitation state.</p>
    <p>Events, venues, Rider Notes, reports, pricing, content/images, settings and source files are kept. The system “Not Registered” horse remains. A full private backup is created before deletion.</p>
    <ol>
        <li><strong>Keep users:</strong> retain all user accounts and recreate one fresh Person per active user with only their saved name and login email.</li>
        <li><strong>Remove users:</strong> also delete every unprotected user. Only the protected login remains, ready for a new import.</li>
    </ol>
    <p class="small text-muted">No email is sent and no payment is refunded. Email logs and test send history are cleared; campaigns return to Draft and live campaign sending is disabled. Membership numbering restarts at 1000; record IDs are not reused.</p>
    <form method="post" class="d-flex flex-wrap gap-2">
        <?= csrf_input() ?><input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="preview_onboarding_reset">
        <button class="btn btn-outline-danger" name="reset_mode" value="keep_users">1. Reset data — keep users</button>
        <button class="btn btn-danger" name="reset_mode" value="replace_users">2. Reset data and remove users</button>
    </form>
</section>
<?php if ($resetPreview): $resetMode=$resetPreview['plan']['onboarding_mode'];$resetDefinition=onboardingResetModes()[$resetMode]; ?>
<dialog id="onboarding-reset-confirmation" class="border-0 rounded-3 p-0 shadow" aria-labelledby="onboarding-reset-title" style="max-width:760px;width:calc(100% - 2rem);max-height:90dvh">
    <div class="p-4">
        <h2 class="h4" id="onboarding-reset-title"><?= h($resetDefinition['label']) ?></h2>
        <p>Review the records to delete. This cannot be undone from the website.</p>
        <div class="table-responsive" style="max-height:32vh;overflow:auto"><table class="table table-sm"><thead><tr><th>Data</th><th class="text-end">Records to delete</th></tr></thead><tbody>
        <?php foreach($resetPreview['snapshot']['counts'] as $table=>$count): ?><tr><td><?= h(ucwords(str_replace('_',' ',$table))) ?></td><td class="text-end"><?= (int)$count ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if($resetMode==='keep_users'): ?><p>Users stay unchanged. All existing people will be replaced by minimal records from active users’ names and login emails.</p><?php else: ?><p>All people and unprotected user accounts will be deleted. Events, venues, Rider Notes and reports remain.</p><?php endif; ?>
        <p class="small text-muted">A full private backup must finish successfully before deletion. If data changes after this preview, the reset is cancelled. This preview expires after 15 minutes.</p>
        <form method="post">
            <?= csrf_input() ?><input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="run_onboarding_reset"><input type="hidden" name="preview_token" value="<?= h($resetPreview['token']) ?>">
            <?php if(!empty($resetErrors['reset_quiet'])): ?><div class="text-danger small mb-1" id="reset-quiet-error"><?= h($resetErrors['reset_quiet']) ?></div><?php endif; ?>
            <label class="d-block mb-3"><input type="checkbox" name="reset_quiet" value="1" required <?= !empty($resetFormValues['quiet'])?'checked':'' ?> <?= !empty($resetErrors['reset_quiet'])?'class="is-invalid" aria-invalid="true" aria-describedby="reset-quiet-error"':'' ?>> Testing, scheduled sends and payment/webhook processing have stopped; other browser baskets are closed.</label>
            <label class="form-label" for="onboarding-reset-phrase">Type <strong><?= h($resetDefinition['phrase']) ?></strong> to confirm</label>
            <?php if(!empty($resetErrors['reset_confirmation'])): ?><div class="text-danger small mb-1" id="reset-phrase-error"><?= h($resetErrors['reset_confirmation']) ?></div><?php endif; ?>
            <input class="form-control mb-3 <?= !empty($resetErrors['reset_confirmation'])?'is-invalid':'' ?>" id="onboarding-reset-phrase" name="reset_confirmation" value="<?= h((string)($resetFormValues['confirmation']??'')) ?>" autocomplete="off" spellcheck="false" data-required-phrase="<?= h($resetDefinition['phrase']) ?>" required <?= !empty($resetErrors['reset_confirmation'])?'aria-invalid="true" aria-describedby="reset-phrase-error"':'' ?>>
            <div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary" type="button" id="onboarding-reset-cancel">Cancel</button><button class="btn btn-danger" id="onboarding-reset-run" disabled>Back up and reset</button></div>
        </form>
    </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog=document.getElementById('onboarding-reset-confirmation');
    const phrase=document.getElementById('onboarding-reset-phrase');
    const quiet=dialog.querySelector('[name="reset_quiet"]');
    const run=document.getElementById('onboarding-reset-run');
    const update=()=>{run.disabled=phrase.value!==phrase.dataset.requiredPhrase || !quiet.checked;};
    phrase.addEventListener('input',update);quiet.addEventListener('change',update);update();
    document.getElementById('onboarding-reset-cancel').addEventListener('click',()=>dialog.close());
    if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
});
</script>
<?php endif; ?>
<?php endif; ?>
