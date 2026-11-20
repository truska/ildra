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
        <p class="text-muted">Please complete each step. Your progress is saved when you choose Next.</p>
        <ol class="setup-progress" aria-label="Setup progress"><?php foreach(($setupSteps ?? ['password'=>'Password & user settings','person'=>'Person settings','horses'=>'Horses','shares'=>'Shares','finish'=>'Finish']) as $key=>$label): ?><li <?= ($step==='user'?'password':$step)===$key?'aria-current="step"':'' ?>><?= h($label) ?></li><?php endforeach; ?></ol>
        <?php if($step!=='horses'): ?><form method="post" action="/migrate.php" novalidate><?= csrf_input() ?><input type="hidden" name="action" value="<?= h(in_array($step,['password','user'],true)?'account':$step) ?>"><div class="row mt-4"><?php endif; ?>
        <?php if(in_array($step,['password','user'],true)): ?>
            <h2 class="h4">Password and user settings</h2><div class="col-12"><?= onboardingCopyHtml('password', $siteSettings) ?></div>
            <?php migrationField('new_password','New password',[],$errors,'password');migrationField('confirm_password','Confirm new password',[],$errors,'password'); ?>
            <div class="col-12"><?= onboardingCopyHtml('user', $siteSettings) ?></div>
            <?php migrationField('first_name','First name',$values,$errors);migrationField('last_name','Surname',$values,$errors);migrationField('email','Login email',$values,$errors,'email',true,true); ?>
        <?php elseif($step==='person'): ?>
            <h2 class="h4">Complete your person settings</h2><div class="col-12"><?= onboardingCopyHtml('person', $siteSettings) ?></div>
            <p class="small text-muted"><span class="text-danger">*</span> Mandatory fields</p>
            <?php foreach(['first_name'=>'First name','last_name'=>'Surname','email'=>'Email address','phone'=>'Phone number','address'=>'Full Postal Address','postcode'=>'Postcode','junior_or_senior'=>'Junior or Senior','dob'=>'Date of birth (required for juniors)','emergency_contact_name'=>'Emergency contact name','emergency_contact_phone'=>'Emergency contact phone'] as $field=>$label) migrationField($field,$label,$values,$errors,match($field){'email'=>'email','phone','emergency_contact_phone'=>'tel','address'=>'textarea','junior_or_senior'=>'classification','dob'=>'date',default=>'text'},$field!=='dob'); ?>
            <fieldset class="mb-4"><legend class="h6">Person email preferences</legend><?php migrationPreferenceFields($values,'person'); ?><p class="small text-muted">Only select this where the person agrees. Essential entry messages are sent separately.</p></fieldset>
            <section class="col-12 mb-4"><div class="border rounded p-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h3 class="h5 mb-0">Membership Options <small class="fw-normal" style="font-size:0.65em;font-variant:small-caps">Optional</small></h3>
                    <button class="btn btn-success ms-sm-auto" type="button" id="membership-information-button" aria-haspopup="dialog" aria-controls="membership-information">Membership Information</button>
                </div>
                <p>Choose a membership for <?= (int)($membershipYear??date('Y')) ?> and add it to your basket. Your person details above will be saved. You can pay after completing onboarding, or choose Next without purchasing now.</p>
                <?php foreach(($_SESSION['basket']??[]) as $item): if(($item['booking_type']??'')==='membership' && (int)($item['member_id']??0)===(int)($state['person_id']??0)): ?><p class="alert alert-success" role="status"><?= h($item['membership_name']) ?> — £<?= number_format((float)$item['price'],2) ?> in your basket.</p><?php endif; endforeach; ?>
                <?php if(!empty($membershipOffers)): ?>
                <?php if(isset($errors['membership_type_id'])): ?><div id="membership-type-error" class="text-danger mb-1"><?= h($errors['membership_type_id']) ?></div><?php endif; ?>
                <label class="form-label" for="migration-membership-type">Membership type</label>
                <select id="migration-membership-type" name="membership_type_id" class="form-select mb-3 <?= isset($errors['membership_type_id'])?'is-invalid':'' ?>" <?= isset($errors['membership_type_id'])?'aria-invalid="true" aria-describedby="membership-type-error"':'' ?>>
                    <option value="">Choose a membership type</option>
                    <?php foreach($membershipOffers as $offer): ?><option value="<?= (int)$offer['id'] ?>" <?= (int)($values['membership_type_id']??0)===(int)$offer['id']?'selected':'' ?>><?= h($offer['name']) ?> — £<?= number_format((float)$offer['cost'],2) ?></option><?php endforeach; ?>
                </select>
                <?php foreach($membershipOffers as $offer): ?><input type="hidden" name="membership_quotes[<?= (int)$offer['id'] ?>]" value="<?= h(migrationMembershipQuote($offer,(int)$membershipYear)) ?>"><?php endforeach; ?>
                <button class="btn btn-success" name="add_membership" value="1" type="submit">Add membership to basket</button>
                <?php else: ?><p class="text-muted mb-0">No memberships are currently available to purchase. You can continue with Next.</p><?php endif; ?>
            </div></section>
        <?php elseif($step==='horses'): ?>
            <?php require __DIR__.'/migration_horse_choices.php'; ?>
        <?php elseif($step==='shares'): ?>
            <h2 class="h4">Shares</h2><div class="col-12"><?= onboardingCopyHtml('shares', $siteSettings) ?><?php if (empty($shareCount)): ?><p>No shares to review.</p><?php else: ?><p><?= (int)$shareCount ?> existing shared records or pending requests. Sharing permissions are unchanged by this step.</p><?php endif; ?></div>
        <?php else: ?>
            <h2 class="h4">Ready to finish</h2><div class="col-12"><?= onboardingCopyHtml('finish', $siteSettings) ?></div>
        <?php endif; ?>
        <?php if($step!=='horses'): ?></div><button class="btn btn-success" type="submit"><?= $step==='finish'?'Finish':'Next' ?></button></form><?php endif; ?>
        <div class="d-flex flex-wrap gap-3 mt-3">
            <?php if($step==='horses' && !empty($horseAction)): ?><a class="btn btn-outline-secondary" href="/migrate.php">Back to horses</a><?php elseif(!in_array($step,['password','user'],true)): ?><form method="post" action="/migrate.php"><?= csrf_input() ?><input type="hidden" name="action" value="back"><button class="btn btn-outline-secondary">Back</button></form><?php endif; ?>
            <?php if(!in_array($step,['password','user'],true)): ?><div class="d-flex flex-wrap align-items-center gap-2"><a class="btn btn-outline-secondary" href="/account?logout=1" aria-describedby="migration-resume-note">Save progress and sign out</a><span class="small text-muted" id="migration-resume-note">You will be asked to continue this process when you next log in.</span></div><?php endif; ?>
        </div>
    <?php elseif($invitation): ?>
        <p>This invitation is for <strong><?= h($invitation['email']) ?></strong>.</p>
            <div class="mb-3"><?= array_key_exists('onboarding_copy_welcome',$siteSettings)?onboardingCopyHtml('welcome',$siteSettings):nl2br(h((string)($pageCopy['introduction']??''))) ?></div>
        <?php if(!$currentUser && empty($canInvitationStart)): ?><p>Sign in to your existing protected account, then return here to begin setting your password and reviewing your details.</p><a class="btn btn-success" href="/account">Sign in</a><a class="btn btn-outline-secondary ms-2" href="/migrate.php">Continue after signing in</a>
        <?php elseif(($currentUser && (int)$invitation['user_id']!==$uid) || !empty($_SESSION['act_as_original_user'])): ?><div class="alert alert-warning">You are signed in to a different account. Sign out and sign in as the invited account before continuing.</div><a class="btn btn-outline-secondary" href="/account?logout=1">Sign out</a>
        <?php else: ?>
            <p>Choose Continue to set your password and review your user, person and horse settings. A Shares step is included for later setup.</p>
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
<?php if($completedNow && !empty($state['completed_at'])): ?>
<dialog id="migration-done" aria-labelledby="migration-done-title" class="border-0 rounded-4 p-4"><h2 id="migration-done-title" class="h4">All done — thank you!</h2><?= onboardingCopyHtml('thanks', $siteSettings) ?><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="/account">Close</a><?php if(!empty($_SESSION['basket'])): ?><a class="btn btn-success" href="/checkout">Checkout your basket</a><?php endif; ?></div></dialog><script>(()=>{const done=document.getElementById('migration-done');done.addEventListener('cancel',event=>{event.preventDefault();window.location.assign('/account');});if(done.showModal)done.showModal();else done.setAttribute('open','');})();</script>
<?php endif; ?>
<?php if($step==='person'): ?>
<dialog id="membership-information" aria-labelledby="membership-information-title" class="border-0 rounded-3 p-0" style="width:calc(100% - 2rem);max-width:700px;max-height:85vh">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3">
        <h2 class="h4 mb-0" id="membership-information-title">Membership Types</h2>
        <button class="btn-close" type="button" data-close-membership-information aria-label="Close Membership Information"></button>
    </div>
    <div class="p-3">
        <?php foreach(($membershipOffers??[]) as $offer): ?>
            <section class="mb-4">
                <h3 class="h5"><?= h($offer['name']) ?></h3>
                <?php if(trim((string)($offer['description']??''))!==''): ?><div><?= nl2br(h((string)$offer['description'])) ?></div><?php endif; ?>
            </section>
        <?php endforeach; ?>
        <?php if(empty($membershipOffers)): ?><p>No memberships are currently available.</p><?php endif; ?>
    </div>
    <div class="p-3 border-top text-end"><button class="btn btn-success" type="button" data-close-membership-information>Close</button></div>
</dialog>
<style>#membership-information::backdrop{background:rgba(0,0,0,.5)}</style>
<script>
(()=>{
    const dialog=document.getElementById('membership-information');
    document.getElementById('membership-information-button').addEventListener('click',()=>dialog.showModal());
    dialog.querySelectorAll('[data-close-membership-information]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
})();
</script>
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
