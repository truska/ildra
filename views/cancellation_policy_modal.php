<?php
/** Shared Cancellation Policy popup; callers supply $siteSettings. */
$cancellationPolicyHtml=trim((string)($siteSettings['cancellation_policy_html']??''));
?>
<div class="modal fade" id="cancellationPolicyModal" tabindex="-1" aria-labelledby="cancellationPolicyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="cancellationPolicyModalLabel">Cancellation Policy</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-break">
                <?php if ($cancellationPolicyHtml !== ''): ?>
                    <?php echo render_wysiwyg($cancellationPolicyHtml); ?>
                <?php else: ?>
                    <p class="mb-0">The cancellation policy has not been added yet.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
