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
        <?php
        $helpContext = (string)($_SERVER['REQUEST_URI'] ?? '/');
        for ($depth = 0; $depth < 5; $depth++) {
            $helpPath = (string)(parse_url($helpContext, PHP_URL_PATH) ?? '');
            if (!preg_match('~/help(?:\.php)?$~', $helpPath)) {
                break;
            }
            $helpQuery = (string)(parse_url($helpContext, PHP_URL_QUERY) ?? '');
            parse_str($helpQuery, $helpParams);
            if (!isset($helpParams['from']) || !is_string($helpParams['from']) || $helpParams['from'] === '') {
                break;
            }
            $helpContext = $helpParams['from'];
        }
        $helpHref = ($basePath ?: '') . '/help?from=' . rawurlencode($helpContext);
        ?>
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
                        <li><a class="dropdown-item" href="<?php echo h($basePath); ?>/account?view=my-account">My Account</a></li>
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
            <a class="utility-btn header-icon-btn header-help-btn" href="<?php echo h($helpHref); ?>" target="_blank" rel="noopener" aria-label="Help for this page (opens in a new tab)" title="Help for this page (opens in a new tab)">
                <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                <span class="visually-hidden">Help</span>
            </a>
        <?php else: ?>
            <div class="guest-help-actions">
                <a class="utility-btn guest-login-btn text-center" href="<?php echo h($basePath); ?>/account">Login / Register<br>Membership</a>
                <a class="utility-btn header-icon-btn header-help-btn" href="<?php echo h($helpHref); ?>" target="_blank" rel="noopener" aria-label="Help for this page (opens in a new tab)" title="Help for this page (opens in a new tab)">
                    <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                    <span class="visually-hidden">Help</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
