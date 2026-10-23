<?php
if (!isset($legacyBalancePending,$legacyBalanceNotifications) || !$currentUser || !empty($_SESSION['act_as_original_user'])) return;
?>
<?php if ($legacyBalancePending): ?>
<div class="alert alert-info"><strong>Opening balance from the previous system</strong><p class="mb-2">We found an opening balance matching your account email. Please confirm ownership of that email before we transfer it. Credits and amounts owed both carry forward.</p><form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="legacy_balance_email"><button class="btn btn-sm btn-success">Send confirmation email</button></form></div>
<?php endif; ?>
<?php if ($legacyBalanceNotifications): ?>
<div class="modal fade" id="legacyBalanceModal" tabindex="-1" aria-labelledby="legacyBalanceTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="legacyBalanceTitle">Your opening balance has been transferred</h5></div><div class="modal-body"><p>We have transferred the following opening balance from the previous ILDRA system to your account:</p>
<?php foreach ($legacyBalanceNotifications as $opening): $openingPence=legacyBalancePence($opening['opening_amount']); ?><p class="fw-semibold"><?php echo format_price(abs($openingPence)/100); ?> <?php echo $openingPence>0?'credit added':'debit carried forward (amount owed)'; ?></p><?php endforeach; ?>
<p class="mb-0">It appears in your account transactions as an opening balance. Credit is available towards purchases; a debit is included in the amount due when you check out.</p></div><div class="modal-footer"><form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="legacy_balance_acknowledge"><input type="hidden" name="balance_ids" value="<?php echo h(implode(',',array_column($legacyBalanceNotifications,'id'))); ?>"><button class="btn btn-success">Got it<?php echo !empty($_SESSION['legacy_balance_return_checkout'])?' — continue to checkout':''; ?></button></form></div></div></div></div>
<?php endif; ?>
