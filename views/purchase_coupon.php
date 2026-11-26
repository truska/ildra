<?php
// Shared basket/checkout controls; keep coupon submission outside the payment form.
$couponInputFailed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_coupon' && $couponInputError !== '');
?>
<div class="row g-2 mb-3">
    <div class="col-6 d-flex">
        <button type="button" class="btn btn-sm btn-light border rounded-pill fw-light w-100 h-100 text-center" data-bs-toggle="modal" data-bs-target="#purchaseCouponModal"><?php echo $summaryCoupon ? 'Change' : 'Add'; ?><br>coupon</button>
    </div>
    <div class="col-6 d-flex">
        <span class="btn btn-sm btn-light border rounded-pill w-100 h-100 text-center<?php echo $userBalance < 0 ? ' text-danger' : ''; ?>">
            <span class="fw-light"><?php echo $userBalance < 0 ? 'Debit balance' : 'Credit available'; ?></span><br><strong><?php echo h(format_price(abs($userBalance))); ?></strong>
        </span>
    </div>
</div>
<div class="modal fade" id="purchaseCouponModal" tabindex="-1" aria-labelledby="purchaseCouponTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title fs-5" id="purchaseCouponTitle">Coupon code</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="action" value="apply_coupon">
                <label class="form-label fw-semibold" for="purchaseCouponCode"><?php echo $summaryCoupon ? 'Use a different coupon' : 'Enter your coupon code'; ?></label>
                <?php if ($couponInputFailed): ?><div class="text-danger small mb-1" id="purchaseCouponError"><?php echo h($couponInputError); ?></div><?php endif; ?>
                <input class="form-control<?php echo $couponInputFailed ? ' is-invalid' : ''; ?>" id="purchaseCouponCode" name="coupon_code" placeholder="AB12-CD34" value="<?php echo h($couponInputFailed ? (string)($_POST['coupon_code'] ?? '') : ''); ?>" required<?php echo $couponInputFailed ? ' aria-invalid="true" aria-describedby="purchaseCouponError"' : ''; ?>>
                <div class="form-text mb-3">One coupon may be used per purchase. It is applied before account credit.</div>
                <button type="submit" class="btn btn-success"><?php echo $summaryCoupon ? 'Replace coupon' : 'Apply coupon'; ?></button>
            </form>
            <?php if (isset($savedCoupon) && $savedCoupon): ?>
                <form method="post" class="mt-3"><input type="hidden" name="action" value="remove_coupon"><button class="btn btn-sm btn-outline-secondary">Remove saved coupon</button></form>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('purchaseCouponModal');
    modal.addEventListener('shown.bs.modal', () => document.getElementById('purchaseCouponCode').focus());
    <?php if ($couponInputFailed): ?>bootstrap.Modal.getOrCreateInstance(modal).show();<?php endif; ?>
});
</script>
