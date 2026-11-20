<?php // Advisory only: no save, publish, preview or send action is blocked. ?>
<dialog id="ride-emergency-warning" class="border-0 rounded-3 p-0 shadow" aria-labelledby="ride-emergency-warning-title" style="max-width:480px;width:calc(100% - 2rem)">
    <div class="modal-content">
        <div class="modal-header p-3"><h2 class="modal-title h5" id="ride-emergency-warning-title">No Emergency Number added yet</h2></div>
        <div class="modal-body p-3">You can continue with Rider Notes. Add an emergency number in the event settings when it is available.</div>
        <div class="modal-footer p-3"><form method="dialog"><button class="btn btn-success" autofocus>Continue</button></form></div>
    </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const warning = document.getElementById('ride-emergency-warning');
    if (warning && typeof warning.showModal === 'function') warning.showModal();
});
</script>
