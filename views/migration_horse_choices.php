<?php $horseAction=$horseAction??'';$selectedHorse=$selectedHorse??null;$horseChoiceStates=$horseChoiceStates??[];$logbookOffer=$logbookOffer??null; ?>
<?php if($selectedHorse && $horseAction!==''): $hid=(int)$selectedHorse['id']; ?>
    <?php if($horseAction==='logbook'): ?>
        <h2 class="h4">Buy a logbook for <?= h($selectedHorse['name']) ?></h2>
        <?php if($logbookOffer): ?>
            <?php if(!empty($horseAlreadyHasLogbook)): ?><p>This horse already has a <?= (int)$logbookOffer['valid_year'] ?> logbook. Confirm its details to return to the list.</p><?php else: ?><p><?= (int)$logbookOffer['valid_year'] ?> logbook: <strong>£<?= number_format((float)$logbookOffer['cost'],2) ?></strong>. Complete the required details to add it to your basket. Payment happens at checkout after onboarding.</p><?php endif; ?>
            <p class="small text-muted"><span class="text-danger">*</span> Mandatory fields</p>
            <form method="post" action="/migrate.php?horse_id=<?= $hid ?>&amp;horse_action=logbook" autocomplete="off" novalidate><?= csrf_input() ?><input type="hidden" name="action" value="horse_choice"><input type="hidden" name="horse_choice" value="logbook"><input type="hidden" name="horse_id" value="<?= $hid ?>"><input type="hidden" name="logbook_quote" value="<?= h(migrationLogbookQuote($logbookOffer)) ?>"><div class="row"><?php migrationRenderHorse($selectedHorse,$errors,$horseQualifications); ?></div><button class="btn btn-success"><?= !empty($horseAlreadyHasLogbook)?'Confirm details and return to horses':'Add logbook to basket and return to horses' ?></button></form>
        <?php else: ?><p>No logbook is currently available. Return to the horse list to choose another option.</p><?php endif; ?>
    <?php elseif($horseAction==='keep'): ?>
        <h2 class="h4">Confirm horse’s name</h2><p>Confirm this horse's name to keep it on your account without adding a logbook to the basket.</p>
        <form method="post" action="/migrate.php?horse_id=<?= $hid ?>&amp;horse_action=keep" novalidate><?= csrf_input() ?><input type="hidden" name="action" value="horse_choice"><input type="hidden" name="horse_choice" value="keep"><input type="hidden" name="horse_id" value="<?= $hid ?>"><div class="row"><?php migrationField('name','Horse name',$selectedHorse,$errors); ?></div><button class="btn btn-success">Confirm name and return to horses</button></form>
    <?php else: ?>
        <h2 class="h4">Remove <?= h($selectedHorse['name']) ?> from the system?</h2><p>Confirming will archive this horse, retain its existing history and remove its unpaid logbook items from your basket.</p>
        <form method="post" action="/migrate.php?horse_id=<?= $hid ?>&amp;horse_action=archive"><?= csrf_input() ?><input type="hidden" name="action" value="horse_choice"><input type="hidden" name="horse_choice" value="archive"><input type="hidden" name="horse_id" value="<?= $hid ?>">
        <?php if(isset($errors['archive_confirmed'])): ?><div class="text-danger small mb-1" id="migration-archive-error"><?= h($errors['archive_confirmed']) ?></div><?php endif; ?>
        <label class="d-block mb-3"><input type="checkbox" name="archive_confirmed" value="1" required <?= isset($errors['archive_confirmed'])?'class="is-invalid" aria-invalid="true" aria-describedby="migration-archive-error"':'' ?>> I confirm I want to remove this horse.</label><button class="btn btn-danger">Confirm removal</button></form>
    <?php endif; ?>
<?php else: ?>
    <h2 class="h4">Your horses and logbooks</h2>
    <?= onboardingCopyHtml('horses', $siteSettings) ?>
    <?php if($horseRows): ?>
        <?php if($logbookOffer): ?><div class="alert alert-danger" role="alert"><?= (int)$logbookOffer['valid_year'] ?> logbooks cost <strong>£<?= number_format((float)$logbookOffer['cost'],2) ?> each</strong>. You can pay for your basket after completing onboarding.</div><?php endif; ?>
        <?php $resolved=0;foreach($horseRows as $horse): $hid=(int)$horse['id'];$decision=$horseChoiceStates[$hid]??['valid'=>false,'label'=>'Please choose an option'];if($decision['valid'])++$resolved; ?>
            <section class="border rounded p-3 mb-3" aria-labelledby="migration-horse-<?= $hid ?>"><h3 class="h5" id="migration-horse-<?= $hid ?>"><?= h($horse['name']) ?></h3><p class="small text-muted"><?= h($decision['label']) ?></p>
            <?php if(isset($errors['horse_choice_'.$hid])): ?><div class="text-danger small mb-2" role="alert"><?= h($errors['horse_choice_'.$hid]) ?></div><?php endif; ?>
            <div class="row g-2">
                <div class="col-12 col-lg-3"><a class="btn btn-success w-100 h-100 d-flex align-items-center justify-content-center" href="/migrate.php?horse_id=<?= $hid ?>&amp;horse_action=logbook">Buy a Logbook</a></div>
                <div class="col-12 col-lg-3"><form method="post" action="/migrate.php" class="h-100"><?= csrf_input() ?><input type="hidden" name="action" value="horse_choice"><input type="hidden" name="horse_choice" value="keep"><input type="hidden" name="horse_id" value="<?= $hid ?>"><button class="btn btn-outline-secondary w-100 h-100 d-flex align-items-center justify-content-center" type="submit">Logbook not Required</button></form></div>
                <div class="col-12 col-lg-3"><a class="btn btn-outline-danger w-100 h-100 d-flex align-items-center justify-content-center" href="/migrate.php?horse_id=<?= $hid ?>&amp;horse_action=archive">Remove Horse</a></div>
                <div class="col-12 col-lg-3"><button class="btn btn-outline-secondary w-100 h-100 d-flex align-items-center justify-content-center" type="button" data-rename-horse="<?= $hid ?>" data-horse-name="<?= h($horse['name']) ?>">Change Horse’s name</button></div>
            </div>
            </section>
        <?php endforeach; ?><p class="small text-muted"><?= $resolved ?> of <?= count($horseRows) ?> horses completed.</p>
    <?php else: ?><p>No horses to onboard.</p><?php endif; ?>
    <form method="post" action="/migrate.php"><?= csrf_input() ?><input type="hidden" name="action" value="horses"><button class="btn btn-success">Next</button></form>
<?php endif; ?>

<?php if(!$selectedHorse && $horseRows): ?>
<dialog id="migration-rename-horse" aria-labelledby="migration-rename-title" class="border-0 rounded-3 p-0" style="width:calc(100% - 2rem);max-width:550px">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3"><h2 class="h4 mb-0" id="migration-rename-title">Change horse’s name</h2><button class="btn-close" type="button" data-close-horse-rename aria-label="Close"></button></div>
    <form method="post" action="/migrate.php" class="p-3"><?= csrf_input() ?><input type="hidden" name="action" value="horse_rename"><input type="hidden" name="horse_id" id="migration-rename-id" value="<?= (int)($_POST['horse_id']??0) ?>">
        <?php $renameErrors=($action??'')==='horse_rename'?$errors:[];migrationField('name','Horse name',['name'=>($_POST['name']??'')],$renameErrors); ?>
        <div class="d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" type="button" data-close-horse-rename>Cancel</button><button class="btn btn-success" type="submit">Confirm and return to horses</button></div>
    </form>
</dialog>
<style>#migration-rename-horse::backdrop{background:rgba(0,0,0,.5)}#migration-rename-horse .col-md-6{width:100%}</style>
<script>
(()=>{
 const dialog=document.getElementById('migration-rename-horse');
 document.querySelectorAll('[data-rename-horse]').forEach(button=>button.addEventListener('click',()=>{
  document.getElementById('migration-rename-id').value=button.dataset.renameHorse;
  dialog.querySelector('[name="name"]').value=button.dataset.horseName;
  dialog.querySelectorAll('.text-danger').forEach(error=>error.remove());
  const input=dialog.querySelector('[name="name"]');input.classList.remove('is-invalid');input.removeAttribute('aria-invalid');input.removeAttribute('aria-describedby');
  dialog.showModal();
 }));
 dialog.querySelectorAll('[data-close-horse-rename]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
 <?php if(($action??'')==='horse_rename' && (!empty($errors)||!empty($general))): ?>dialog.showModal();<?php endif; ?>
})();
</script>
<?php endif; ?>
