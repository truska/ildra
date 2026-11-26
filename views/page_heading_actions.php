<?php // Desktop shortcuts alongside an existing page heading. ?>
<div class="col-lg-auto page-heading-shortcuts d-none d-lg-flex justify-content-end align-items-center">
    <nav class="page-heading-actions" aria-label="Calendar, membership and logbooks">
        <a title="Ride Calendar" class="btn button1 btn-sm" href="<?php echo h($basePath ?? ''); ?>/events"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span>Calendar</span></a>
        <a title="Join Now - Membership Open" class="btn button1 btn-sm" href="<?php echo h($basePath ?? ''); ?>/memberships"><i class="fa-solid fa-id-card" aria-hidden="true"></i><span>Membership</span></a>
        <a title="Horse Logbooks" class="btn button1 btn-sm" href="<?php echo h($basePath ?? ''); ?>/logbooks"><i class="fa-solid fa-book" aria-hidden="true"></i><span>Logbooks</span></a>
    </nav>
</div>
