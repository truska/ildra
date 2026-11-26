<?php if (empty($isLoggedIn) || empty($currentUser)) return; ?>
<style>
#entryPersonModal .modal-content {
 max-height:calc(100vh - var(--bs-modal-margin) - var(--bs-modal-margin));
 max-height:calc(100dvh - var(--bs-modal-margin) - var(--bs-modal-margin));
}
#entryPersonForm { display:flex;flex-direction:column;flex:1 1 auto;min-height:0;overflow:hidden; }
#entryPersonForm .modal-body { min-height:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable;padding:1rem; }
#entryPersonForm .modal-footer { flex-shrink:0;padding:.65rem 1rem; }
#entryPersonForm .form-label { margin-bottom:.25rem;font-size:.9rem; }
</style>
<div class="modal fade" id="entryPersonModal" tabindex="-1" aria-labelledby="entryPersonTitle" aria-hidden="true">
 <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5" id="entryPersonTitle">Another Person</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <form id="entryPersonForm" action="<?php echo h($basePath); ?>/entry_person.php" method="post" novalidate>
   <div class="modal-body">
    <?php echo csrf_input(); ?><input type="hidden" name="stage" value="email">
    <div class="alert alert-danger d-none" id="entryPersonError" role="alert"></div>
    <p class="small text-muted mb-2">Enter the rider’s email address. We’ll use their existing record if they already have one.</p>
    <div class="mb-2"><label class="form-label" for="entryPersonEmail">Email address <span class="text-danger">*</span></label><input class="form-control" type="email" id="entryPersonEmail" name="email" autocomplete="email" maxlength="255" required></div>
    <div id="entryPersonDetails" hidden>
     <p class="small text-muted mb-2">No existing rider was found. Add their details to People.</p>
     <div class="row">
      <?php foreach (['first_name'=>'First name','last_name'=>'Surname','phone'=>'Phone number','address'=>'Address','postcode'=>'Postcode','emergency_contact_name'=>'Emergency contact name','emergency_contact_phone'=>'Emergency contact phone'] as $field=>$label): ?>
       <div class="col-md-6 mb-2"><label class="form-label" for="entryPerson_<?php echo h($field); ?>"><?php echo h($label); ?> <span class="text-danger">*</span></label><?php if ($field==='address'): ?><textarea class="form-control" id="entryPerson_<?php echo h($field); ?>" name="<?php echo h($field); ?>" rows="2" required></textarea><?php else: ?><input class="form-control" id="entryPerson_<?php echo h($field); ?>" name="<?php echo h($field); ?>" type="<?php echo str_contains($field,'phone')?'tel':'text'; ?>" required><?php endif; ?></div>
      <?php endforeach; ?>
      <div class="col-md-6 mb-2"><label class="form-label" for="entryPersonClassification">Junior or Senior <span class="text-danger">*</span></label><select class="form-select" id="entryPersonClassification" name="junior_or_senior" required><option value="">Choose...</option><option>Junior</option><option>Senior</option></select></div>
      <div class="col-md-6 mb-2"><label class="form-label" for="entryPersonDob">Date of birth (required for juniors)</label><input class="form-control" type="date" id="entryPersonDob" name="dob" max="<?php echo date('Y-m-d'); ?>"></div>
     </div>
    </div>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success" id="entryPersonSubmit">Continue</button></div>
  </form>
 </div></div>
</div>
