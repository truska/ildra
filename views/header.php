<?php
$navItemEventsUrl = $navItemEventsUrl ?? ($basePath . '/events');
$headerBasket = $_SESSION['basket'] ?? [];
$basketCount = $basketCount ?? count($headerBasket);
$headerBasketTotal = 0.0;
foreach ($headerBasket as $headerBasketItem) {
    $headerBasketTotal += price_to_number($headerBasketItem['price'] ?? 0);
}
$siteSettings = $siteSettings ?? ($siteSettingsBootstrap ?? defaultSiteSettings());
$headerShortName = trim((string)($siteSettings['company_short_name'] ?? $siteSettings['hero_title'] ?? 'ILDRA'));
$headerLogoUrl = trim((string)($siteSettings['sponsor_image_url'] ?? ''));
$hasHeaderLogo = $headerLogoUrl !== '';
$brandClass = $hasHeaderLogo ? 'brand-logo-only' : '';
$logoAlt = trim((string)($siteSettings['hero_title'] ?? 'ILDRA'));
$headerIsHome = isset($headerIsHome) ? (bool)$headerIsHome : false;
$headerPage = isset($headerPage) && is_array($headerPage) ? $headerPage : null;
$headerBatch = $headerPage ? mediaBatchFind($pdo ?? null, 'page_banner', 'page', (int)($headerPage['id'] ?? 0)) : null;
$headerBatchImages = $headerBatch ? mediaBatchImages($pdo ?? null, (int)$headerBatch['id']) : [];
if (!$headerBatchImages) {
    $fallbackPurpose = $headerIsHome ? 'default_home_banner' : 'default_inside_banner';
    $headerBatch = mediaBatchFind($pdo ?? null, $fallbackPurpose, 'site', 0);
    $headerBatchImages = $headerBatch ? mediaBatchImages($pdo ?? null, (int)$headerBatch['id']) : [];
}
// Preserve the pre-existing shared banner until the new defaults have been populated.
if (!$headerBatchImages) {
    $headerBatch = mediaBatchFind($pdo ?? null, 'site_header', 'site', 0);
    $headerBatchImages = $headerBatch ? mediaBatchImages($pdo ?? null, (int)$headerBatch['id']) : [];
}
$headerBannerUrl = ($headerBatch && $headerBatchImages) ? mediaBatchImageUrl($headerBatch, $headerBatchImages[0], 'lg') : '';
$headerCarouselImages = $headerIsHome ? $headerBatchImages : array_slice($headerBatchImages, 0, 1);
if (!function_exists('page_url')) {
    function page_url(array $page): string
    {
        $slug = page_destination_slug($page);
        global $basePath;
        return $basePath . '/pages/' . rawurlencode($slug);
    }
}

function nav_menu_id(string $key): string
{
    $clean = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $key));
    return 'nav-menu-' . trim($clean, '-');
}
?>
<?php
$isActingAs = !empty($_SESSION['act_as_original_user']) && is_array($_SESSION['act_as_original_user']);
$actorUser = $isActingAs ? ($_SESSION['act_as_original_user'] ?? null) : null;
$targetUser = $isActingAs ? ($_SESSION['user'] ?? null) : null;
$actorEmail = is_array($actorUser) ? (string)($actorUser['email'] ?? '') : '';
$targetEmail = is_array($targetUser) ? (string)($targetUser['email'] ?? '') : '';
$targetName = is_array($targetUser) ? trim((string)($targetUser['first_name'] ?? '') . ' ' . (string)($targetUser['last_name'] ?? '')) : '';
$targetLabel = $targetName !== '' ? $targetName : ($targetEmail !== '' ? $targetEmail : 'user');
$exitActAsUrl = ($basePath ?? '') . '/?exit_act_as=1&return=' . rawurlencode(($basePath ?? '') . '/admin/users.php');
?>
<?php include __DIR__ . '/development_banner.php'; ?>
<?php if ($isActingAs): ?>
    <style>
        body { padding-bottom: 44px; }
        .ildra-impersonation-bar {
            background: #b00020;
            color: #fff;
            font-weight: 700;
            letter-spacing: 0.01em;
            padding: 0.45rem 0.75rem;
            z-index: 3000;
        }
        .ildra-impersonation-bar .impersonation-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .ildra-impersonation-top {
            position: sticky;
            top: 0;
        }
        .ildra-impersonation-bottom {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
        }
        .ildra-impersonation-bar .impersonation-meta {
            display: inline-flex;
            gap: 0.35rem;
            align-items: baseline;
            flex-wrap: wrap;
        }
        .ildra-impersonation-bar .impersonation-pill {
            display: inline-block;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.22);
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-weight: 800;
            white-space: nowrap;
        }
        .ildra-impersonation-bar a.btn {
            white-space: nowrap;
        }
    </style>
    <div class="ildra-impersonation-bar ildra-impersonation-top" role="status" aria-live="polite">
        <div class="impersonation-inner">
            <div class="impersonation-meta">
                <span class="impersonation-pill">Impersonation</span>
                <span>Logged in as <?php echo h($actorEmail ?: 'admin'); ?> acting as <?php echo h($targetLabel); ?><?php echo $targetEmail ? ' (' . h($targetEmail) . ')' : ''; ?>.</span>
            </div>
            <a class="btn btn-light btn-sm fw-bold" href="<?php echo h($exitActAsUrl); ?>">Exit</a>
        </div>
    </div>
    <div class="ildra-impersonation-bar ildra-impersonation-bottom" role="status" aria-live="polite">
        <div class="impersonation-inner">
            <div class="impersonation-meta">
                <span class="impersonation-pill">Impersonation</span>
                <span>Logged in as <?php echo h($actorEmail ?: 'admin'); ?> acting as <?php echo h($targetLabel); ?><?php echo $targetEmail ? ' (' . h($targetEmail) . ')' : ''; ?>.</span>
            </div>
            <a class="btn btn-light btn-sm fw-bold" href="<?php echo h($exitActAsUrl); ?>">Exit</a>
        </div>
    </div>
<?php endif; ?>
<header class="site-header<?php echo $headerIsHome ? ' site-header-home' : ' site-header-inner'; ?>"<?php echo $headerBannerUrl !== '' ? ' style="--site-header-image:url(\'' . h($headerBannerUrl) . '\')"' : ''; ?>>
    <div class="site-header-banner<?php echo count($headerCarouselImages) > 1 ? ' has-banner-carousel' : ''; ?>" data-banner-carousel data-interval="<?php echo max(2, (int)($siteSettings['home_carousel_interval_seconds'] ?? 6)) * 1000; ?>">
        <?php if ($headerBatch && $headerCarouselImages): ?><div class="site-header-slides" aria-hidden="true">
            <?php foreach ($headerCarouselImages as $bannerIndex => $bannerImage): ?><div class="site-header-slide<?php echo $bannerIndex === 0 ? ' is-active' : ''; ?>" style="background-image:url('<?php echo h(mediaBatchImageUrl($headerBatch, $bannerImage, 'lg')); ?>')"></div><?php endforeach; ?>
        </div><?php endif; ?>
        <?php if ($headerIsHome && count($headerCarouselImages) > 1): ?>
            <div class="site-header-carousel-nav" aria-label="Homepage banner navigation">
                <button type="button" class="site-header-carousel-arrow" data-banner-prev aria-label="Previous banner">&#8249;</button>
                <div class="site-header-carousel-dots">
                    <?php foreach ($headerCarouselImages as $bannerIndex => $_bannerImage): ?><button type="button" class="site-header-carousel-dot<?php echo $bannerIndex === 0 ? ' is-active' : ''; ?>" data-banner-index="<?php echo $bannerIndex; ?>" aria-label="Show banner <?php echo $bannerIndex + 1; ?>" aria-current="<?php echo $bannerIndex === 0 ? 'true' : 'false'; ?>"></button><?php endforeach; ?>
                </div>
                <button type="button" class="site-header-carousel-arrow" data-banner-next aria-label="Next banner">&#8250;</button>
            </div>
        <?php endif; ?>
        <div class="container header-banner-inner">
        <a class="navbar-brand brand-block fw-bold <?php echo h($brandClass); ?>" href="<?php echo h($basePath); ?>/">
            <div class="logo-badge fs-2">
                <?php if ($hasHeaderLogo): ?>
                    <img src="<?php echo h($headerLogoUrl); ?>" alt="<?php echo h($logoAlt); ?>">
                <?php else: ?>
                    IR
                <?php endif; ?>
            </div>
            <div class="brand-text">
                <small>Irish Long Distance</small>
                <strong>Endurance Riding</strong>
            </div>
            </a>
            <?php if ($headerIsHome): ?>
                <div class="home-banner-identity">I L D R A</div>
            <?php endif; ?>
            <?php include __DIR__ . '/header_actions.php'; ?>
        </div>
    </div>
    <?php if ($headerIsHome): ?>
        <div class="home-intro-bar">
            <div class="container home-intro-inner">
                <div class="home-intro-copy">
                    <div class="home-intro-established"><?php echo h((string)($siteSettings['home_established_text'] ?? 'Established in 1990')); ?></div>
                    <div class="home-intro-title"><?php echo h((string)($siteSettings['home_heading_text'] ?? 'Endurance Riding Ireland')); ?></div>
                    <div class="home-intro-tagline"><?php echo h((string)($siteSettings['hero_tagline'] ?? 'Home for Endurance Riding in Ireland')); ?></div>
                </div>
                <div class="home-intro-actions navbar-dark">
                    <div class="home-intro-title home-intro-short-name"><?php echo h($headerShortName); ?></div>
                    <a class="btn button1 home-intro-join" href="<?php echo h((string)($siteSettings['hero_cta_url'] ?? '/memberships')); ?>"><?php echo h((string)($siteSettings['hero_cta_label'] ?? 'JOIN')); ?></a>
                    <div class="home-mobile-controls">
                        <a class="home-mobile-quick-action" href="<?php echo h($basePath); ?>/events"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span>Calendar</span></a>
                        <button class="home-mobile-quick-action" type="button" disabled><span>AN Other</span></button>
                        <button class="navbar-toggler home-mobile-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-success bg-gradient py-0">
        <div class="container nav-shell">
        <?php if (!$headerIsHome): ?>
            <a class="inside-mobile-calendar" href="<?php echo h($basePath); ?>/events" aria-label="Calendar"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></a>
            <div class="mobile-nav-short-name"><?php echo h($headerShortName); ?></div>
        <?php endif; ?>
        <button class="navbar-toggler main-navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav nav-primary align-items-lg-center w-100">
                <?php foreach ($navTree as $groupKey => $group): ?>
                    <?php if (!$group['pages']) { continue; } ?>
                    <?php
                        $firstPage = $group['pages'][0];
                        $primaryUrl = $basePath . '/pages/' . rawurlencode($groupKey . '-overview');
                        $hasDropdown = (count($group['pages']) > 1) || $groupKey === 'events';
                        $menuId = nav_menu_id((string)$groupKey);
                        $overviewLabel = trim((string)($group['label'] ?? 'Overview')) . ' overview';
                        $showOverview = !array_key_exists('menu_overview_' . $groupKey, $siteSettings) || !empty($siteSettings['menu_overview_' . $groupKey]);
                    ?>
                    <?php if (!$hasDropdown): ?>
                        <li class="nav-item">
                            <a class="nav-link text-uppercase" href="<?php echo h($primaryUrl); ?>"><?php echo h($group['label']); ?></a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item nav-item-with-children">
                            <button class="nav-parent text-uppercase" type="button" data-target="<?php echo h($menuId); ?>" aria-expanded="false">
                                <span><?php echo h($group['label']); ?></span>
                                <span class="chevron" aria-hidden="true"></span>
                            </button>
                            <ul class="nav-submenu list-unstyled mb-0" id="<?php echo h($menuId); ?>" hidden>
                                <?php if ($showOverview): ?><li><a class="dropdown-item fw-semibold" href="<?php echo h($primaryUrl); ?>"><?php echo h($overviewLabel); ?></a></li><li><hr class="dropdown-divider"></li><?php endif; ?>
                                <?php foreach ($group['pages'] as $page): ?>
                                    <?php $pageMenuUrl = page_url($page); ?>
                                    <li><a class="dropdown-item" href="<?php echo h($pageMenuUrl); ?>"><?php echo h($page['title']); ?></a></li>
                                    <?php if (!empty($page['menu_divider_below'])): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        </div>
    </nav>
</header>
<script>
    (function () {
        const navParents = Array.from(document.querySelectorAll('.nav-parent'));

        const closeAll = (exceptId = null) => {
            navParents.forEach(btn => {
                const targetId = btn.getAttribute('data-target');
                if (exceptId && targetId === exceptId) return;
                btn.setAttribute('aria-expanded', 'false');
                btn.classList.remove('is-open');
                const menu = document.getElementById(targetId);
                if (menu) {
                    menu.hidden = true;
                    menu.classList.remove('show');
                }
            });
        };

        navParents.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const menu = document.getElementById(targetId);
                if (!menu) return;
                const expanded = btn.getAttribute('aria-expanded') === 'true';
                closeAll(targetId);
                const nextState = !expanded;
                btn.setAttribute('aria-expanded', String(nextState));
                btn.classList.toggle('is-open', nextState);
                menu.hidden = !nextState;
                menu.classList.toggle('show', nextState);
            });
        });

        document.addEventListener('click', (e) => {
            if (window.innerWidth < 992) return; // allow open state on mobile until tapped again
            if (e.target.closest('.nav-item-with-children')) return;
            if (e.target.closest('.navbar')) {
                closeAll();
            }
        });

        window.addEventListener('resize', () => {
            // Reset hidden state on resize to avoid mismatched layouts
            if (window.innerWidth < 992) {
                navParents.forEach(btn => {
                    const menu = document.getElementById(btn.getAttribute('data-target'));
                    if (menu && btn.getAttribute('aria-expanded') === 'true') {
                        menu.hidden = false;
                        menu.classList.add('show');
                    }
                });
            } else {
                closeAll();
            }
        });
    })();
</script>
<?php if ($headerIsHome && count($headerCarouselImages) > 1): ?>
<script>
(() => {
    const carousel = document.querySelector('[data-banner-carousel]');
    if (!carousel || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const slides = Array.from(carousel.querySelectorAll('.site-header-slide'));
    if (slides.length < 2) return;
    const dots = Array.from(carousel.querySelectorAll('[data-banner-index]'));
    const previous = carousel.querySelector('[data-banner-prev]');
    const next = carousel.querySelector('[data-banner-next]');
    let current = 0;
    let timer = 0;
    const interval = Math.max(2000, Number(carousel.dataset.interval) || 6000);
    const show = index => {
        slides[current].classList.remove('is-active');
        dots[current]?.classList.remove('is-active');
        dots[current]?.setAttribute('aria-current', 'false');
        current = (index + slides.length) % slides.length;
        slides[current].classList.add('is-active');
        dots[current]?.classList.add('is-active');
        dots[current]?.setAttribute('aria-current', 'true');
    };
    const stop = () => { window.clearTimeout(timer); timer = 0; };
    const start = () => { stop(); timer = window.setTimeout(function advance() {
        show(current + 1);
        timer = window.setTimeout(advance, interval);
    }, interval); };
    previous?.addEventListener('click', () => { show(current - 1); start(); });
    next?.addEventListener('click', () => { show(current + 1); start(); });
    dots.forEach(dot => dot.addEventListener('click', () => { show(Number(dot.dataset.bannerIndex)); start(); }));
    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', stop);
    carousel.addEventListener('focusout', start);
    start();
})();
</script>
<?php endif; ?>
