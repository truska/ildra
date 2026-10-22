<?php // Desktop shortcuts alongside an existing page heading. ?>
<div class="col-lg-3 d-none d-lg-flex justify-content-end align-items-center">
    <nav class="page-heading-actions" aria-label="Membership and logbooks">
        <a title="Join Now - Membership Open" class="btn button1 btn-sm" href="<?php echo h($basePath ?? ''); ?>/pages/membership"><i class="fa-solid fa-id-card" aria-hidden="true"></i><span>Membership</span></a>
        <a title="Horse Logbooks" class="btn button1 btn-sm" href="<?php echo h($basePath ?? ''); ?>/pages/horse-logbooks"><i class="fa-solid fa-book" aria-hidden="true"></i><span>Logbooks</span></a>
    </nav>
</div>
