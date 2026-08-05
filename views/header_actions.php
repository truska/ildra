<?php
declare(strict_types=1);

$headerUserName = trim(
    (string)($currentUser['first_name'] ?? '') . ' ' .
    (string)($currentUser['last_name'] ?? '')
);
if ($headerUserName === '') {
    $headerUserName = trim((string)($currentUser['email'] ?? 'User'));
}
$headerAccountLabel = 'User account: ' . $headerUserName;
?>
<div class="nav-actions-panel">
    <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
            <div class="account-wrapper">
                <div class="dropdown">
                    <button class="utility-btn header-icon-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?php echo h($headerAccountLabel); ?>" title="<?php echo h($headerAccountLabel); ?>">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <span class="visually-hidden">User account</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-header">Signed in as <strong><?php echo h($headerUserName); ?></strong></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/bookings">My bookings</a></li>
                        <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/account#my-memberships">My memberships</a></li>
                        <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/basket">Basket</a></li>
                        <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/account">Account</a></li>
                        <?php if (!empty($canViewAdmin)): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/admin/index.php">Admin</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/account?logout=1">Logout</a></li>
                    </ul>
                </div>
            </div>
            <?php if (!empty($canViewAdmin)): ?>
                <a class="utility-btn header-icon-btn" href="<?php echo h($basePath); ?>/admin/index.php" aria-label="Admin area" title="Admin area">
                    <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i>
                    <span class="visually-hidden">Admin area</span>
                </a>
            <?php endif; ?>
            <a class="utility-btn header-icon-btn basket-link" href="<?php echo h($basePath); ?>/basket" aria-label="Basket" title="Basket">
                <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>
                <span class="basket-count<?php echo $basketCount > 0 ? '' : ' is-empty'; ?>"><?php echo (int)$basketCount; ?></span>
                <span class="basket-hover-value" role="tooltip">Basket total: <?php echo h(format_price($headerBasketTotal)); ?></span>
            </a>
        <?php else: ?>
            <a class="utility-btn" href="<?php echo h($basePath); ?>/account">Login / Register</a>
        <?php endif; ?>
    </div>
</div>
