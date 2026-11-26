<?php require_once __DIR__.'/../onboarding_copy.php';
$allowInvitationClosure=$allowInvitationClosure??true;
$showMigrationChoice=!empty($invitation) && ((!empty($currentUser) && (int)$invitation['user_id']===(int)($uid??0)) || (empty($currentUser) && !empty($canInvitationStart))) && empty($_SESSION['act_as_original_user']) && !$step && empty($state['completed_at']);
 ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complete your account setup | <?= h($siteSettings['hero_title']??'ILDRA') ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"><?php require __DIR__.'/back_navigation_styles.php'; ?><style>body{background:#f7f8f1;color:#0c2a12}.setup-panel{max-width:850px;background:#fff;border-radius:18px;padding:2rem;margin:2rem auto;box-shadow:0 18px 48px #0001}.setup-progress{display:flex;flex-wrap:wrap;gap:.6rem;list-style:none;padding:0}.setup-progress li{padding:.35rem .8rem;border-radius:2rem;background:#eef3eb}.setup-progress [aria-current=step]{background:#146118;color:#fff}</style></head><body>
<main class="container"><div class="setup-panel">
    <div class="small text-muted mb-2"><?= h($siteSettings['hero_title']??'ILDRA') ?></div><h1 class="h3"><?= h($siteSettings['onboarding_heading']??'Complete your account setup') ?></h1>
    <?php if(!empty($successMessage)): ?><div class="alert alert-success" role="status"><?= h($successMessage) ?></div><?php endif; ?>
    <?php if($general): ?><div class="alert alert-danger" role="alert"><?= h($general) ?></div><?php endif; ?>
    <?php if(!empty($closureComplete)): ?>
        <h2 class="h4">Your account is closed</h2><p>Your login and imported person record have been archived. You have been signed out and will not continue through migration.</p><a class="btn btn-outline-secondary" href="/">Close</a>
    <?php elseif(!empty($state['completed_at'])): ?>
        <h2 class="h4">All done</h2><?= onboardingCopyHtml('thanks', $siteSettings) ?><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="/account">Close</a><?php if(!empty($_SESSION['basket'])): ?><a class="btn btn-success" href="/checkout">Checkout your basket</a><?php endif; ?></div>
    <?php elseif($step): ?>
        <form method="post" action="/migrate.php" novalidate><?= csrf_input() ?><input type="hidden" name="action" value="account"><div class="row mt-4">
            <h2 class="h4">Password and user settings</h2><div class="col-12"><?= onboardingCopyHtml('password', $siteSettings) ?></div>
            <?php migrationField('new_password','New password',[],$errors,'password');migrationField('confirm_password','Confirm new password',[],$errors,'password'); ?>
            <div class="col-12"><?= onboardingCopyHtml('user', $siteSettings) ?></div>
            <?php migrationField('first_name','First name',$values,$errors);migrationField('last_name','Surname',$values,$errors);migrationField('email','Login email',$values,$errors,'email',true,true); ?>
        </div>
        <div class="d-flex flex-wrap align-items-stretch gap-2">
            <button class="btn btn-success" type="submit">Continue</button>
        </div></form>
    <?php elseif($invitation): ?>
        <p>This invitation is for <strong><?= h($invitation['email']) ?></strong>.</p>
            <div class="mb-3"><?= array_key_exists('onboarding_copy_welcome',$siteSettings)?onboardingCopyHtml('welcome',$siteSettings):nl2br(h((string)($pageCopy['introduction']??''))) ?></div>
        <?php if(!$currentUser && empty($canInvitationStart)): ?><p>Sign in to your existing protected account, then return here to begin setting your password and reviewing your details.</p><a class="btn btn-success" href="/account">Sign in</a><a class="btn btn-outline-secondary ms-2" href="/migrate.php">Continue after signing in</a>
        <?php elseif(($currentUser && (int)$invitation['user_id']!==$uid) || !empty($_SESSION['act_as_original_user'])): ?><div class="alert alert-warning">You are signed in to a different account. Sign out and sign in as the invited account before continuing.</div><a class="btn btn-outline-secondary" href="/account?logout=1">Sign out</a>
        <?php else: ?>
            <p>Choose Continue to set your password and confirm your login details to complete your account setup.</p>
            <div class="d-flex flex-wrap gap-2"><form method="post" action="/migrate.php"><?= csrf_input() ?><input type="hidden" name="action" value="accept"><button class="btn btn-success">Continue</button></form><?php if($allowInvitationClosure): ?><button type="button" class="btn btn-outline-danger" id="migration-close-open">Close My Account</button><?php endif; ?></div>
        <?php endif; ?>
    <?php else: ?><p><?= $token!==''?'This invitation is invalid or has expired. Please contact us for a new invitation.':'Open the personal setup link in your invitation email to begin.' ?></p><?php if($currentUser): ?><a href="/account">My account</a><?php endif; ?>
    <?php endif; ?>
</div></main>
<?php if($showMigrationChoice && $allowInvitationClosure): ?>
<dialog id="migration-close" aria-labelledby="migration-close-title" aria-describedby="migration-close-explanation" class="border-0 rounded-4 p-4" style="max-width:560px">
    <h2 id="migration-close-title" class="h4">Close My Account?</h2>
    <p id="migration-close-explanation"><?= nl2br(h(migrationClosureMessage($pageCopy??[]))) ?></p>
    <?php if(!empty($closureProtected)): ?><p class="text-danger">This protected or administrative account cannot be closed here.</p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 align-items-end">
        <form method="dialog"><button class="btn btn-outline-secondary">Back</button></form>
        <?php if(empty($closureProtected)): ?><form method="post" action="/migrate.php"><?= csrf_input() ?><input type="hidden" name="action" value="close_account"><input type="hidden" name="copy_revision" value="<?= (int)($pageCopy['revision']??0) ?>"><label class="d-block mb-3"><input type="checkbox" name="closure_confirmed" value="1" required> I confirm I want to close my account.</label><button class="btn btn-danger">Yes, close my account</button></form><?php endif; ?>
    </div>
</dialog>
<script>document.getElementById('migration-close-open').addEventListener('click',()=>{const modal=document.getElementById('migration-close');if(modal.showModal)modal.showModal();else modal.setAttribute('open','');});</script>
<?php endif; ?>
<script>
document.querySelectorAll('[data-migration-password-toggle]').forEach(button=>{
    button.addEventListener('click',()=>{
        const input=document.getElementById(button.getAttribute('aria-controls'));
        const revealed=input.type==='password';
        input.type=revealed?'text':'password';
        const icon=button.querySelector('i');
        icon.classList.toggle('fa-eye',!revealed);
        icon.classList.toggle('fa-eye-slash',revealed);
        button.setAttribute('aria-pressed',String(revealed));
        button.setAttribute('aria-label',(revealed?'Hide ':'Show ')+button.dataset.passwordLabel);
    });
});
</script>
</body></html>
