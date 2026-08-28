<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<style>
:root {
    --site-btn-main-bg: var(--yellow, #dce705);
    --site-btn-main-bg-hover: #c7d300;
    --site-btn-main-text: var(--green, #0f5d2d);
    --site-btn-main-border: rgba(255, 255, 255, 0.08);
    --site-btn-secondary-bg: var(--accent, #146118);
    --site-btn-secondary-bg-hover: var(--green-alt, #1f7c24);
    --site-btn-secondary-text: #fff;
    --site-btn-secondary-border: rgba(255, 255, 255, 0.18);
    --site-btn-tertiary-bg: var(--green, #0f5d2d);
    --site-btn-tertiary-bg-hover: var(--green-alt, #1f7c24);
    --site-btn-tertiary-text: #fff;
    --site-btn-tertiary-border: rgba(255, 255, 255, 0.24);
    --site-btn-hover-shadow: 0 10px 24px rgba(15, 93, 45, 0.18);
}

/* Site-wide links use colour and controls for emphasis, never underlines. */
a,
a:hover,
a:focus,
a:active,
a:visited {
    text-decoration: none !important;
}

.btn,
.button1,
.button2,
.button3,
.btn-main,
.btn-secondary-site,
.btn-tertiary-site {
    border-radius: 6px;
    font-weight: 800;
    line-height: 1.2;
    text-decoration: none;
    transition: background-color 0.22s ease, border-color 0.22s ease, color 0.22s ease, box-shadow 0.22s ease, transform 0.22s ease;
}

.button1,
.button2,
.button3,
.btn-main,
.btn-secondary-site,
.btn-tertiary-site {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    min-height: 44px;
    padding: 0.72rem 1.15rem;
    border: 1px solid transparent;
    text-align: center;
}

.btn:hover:not(:disabled):not(.disabled),
.btn:focus-visible:not(:disabled):not(.disabled),
.button1:hover,
.button1:focus-visible,
.button2:hover,
.button2:focus-visible,
.button3:hover,
.button3:focus-visible,
.btn-main:hover,
.btn-main:focus-visible,
.btn-secondary-site:hover,
.btn-secondary-site:focus-visible,
.btn-tertiary-site:hover,
.btn-tertiary-site:focus-visible {
    transform: translateY(-1px);
    box-shadow: var(--site-btn-hover-shadow);
}

.button1,
.btn-main,
.cta-btn {
    background: var(--site-btn-main-bg);
    border-color: var(--site-btn-main-border);
    color: var(--site-btn-main-text);
}

.button1:hover,
.button1:focus-visible,
.btn-main:hover,
.btn-main:focus-visible,
.cta-btn:hover,
.cta-btn:focus-visible {
    background: var(--site-btn-main-bg-hover);
    border-color: var(--site-btn-main-bg-hover);
    color: var(--site-btn-main-text);
}

.button2,
.btn-secondary-site,
.btn-success {
    background: var(--site-btn-secondary-bg);
    border-color: var(--site-btn-secondary-bg);
    color: var(--site-btn-secondary-text);
}

.button2:hover,
.button2:focus-visible,
.btn-secondary-site:hover,
.btn-secondary-site:focus-visible,
.btn-success:hover:not(:disabled):not(.disabled),
.btn-success:focus-visible:not(:disabled):not(.disabled) {
    background: var(--site-btn-secondary-bg-hover);
    border-color: var(--site-btn-secondary-bg-hover);
    color: var(--site-btn-secondary-text);
}

.button3,
.btn-tertiary-site,
.btn-outline-success {
    background: var(--site-btn-tertiary-bg);
    border-color: var(--site-btn-tertiary-border);
    color: var(--site-btn-tertiary-text);
}

.button3:hover,
.button3:focus-visible,
.btn-tertiary-site:hover,
.btn-tertiary-site:focus-visible,
.btn-outline-success:hover:not(:disabled):not(.disabled),
.btn-outline-success:focus-visible:not(:disabled):not(.disabled) {
    background: var(--site-btn-tertiary-bg-hover);
    border-color: rgba(255, 255, 255, 0.38);
    color: var(--site-btn-tertiary-text);
}

.btn-lg.button1,
.btn-lg.button2,
.btn-lg.button3,
.btn-lg.btn-main,
.btn-lg.btn-secondary-site,
.btn-lg.btn-tertiary-site {
    min-height: 50px;
    padding: 0.86rem 1.45rem;
}

.person-type-select {
    font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-weight: 400;
}

/* Shared image-ready site header */
.site-header {
    --site-header-image: url('/filestore/images/banners/lg/marshallstown-railway-1-2017.jpg');
    position: relative;
    z-index: 20;
    isolation: isolate;
}
.site-header-banner {
    min-height: 210px;
    background-color: transparent;
    background-image: var(--site-header-image);
    background-blend-mode: normal;
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
    position: relative;
    z-index: 2;
    overflow: visible;
}
.site-header-slides { position:absolute; inset:0; overflow:hidden; z-index:0; }
.site-header-slide { position:absolute; inset:0; background-position:center; background-repeat:no-repeat; background-size:cover; opacity:0; transform:translateX(-4%); transition:opacity .7s ease,transform .7s ease; }
.site-header-slide.is-active { opacity:1; transform:translateX(0); }
.site-header-banner.has-banner-carousel { background-image:none; }
.site-header-banner.has-banner-carousel .header-banner-inner { position:relative; z-index:2; }
.site-header-carousel-nav { position:absolute; z-index:4; left:50%; bottom:12px; transform:translateX(-50%); display:flex; align-items:center; gap:10px; padding:5px 9px; border-radius:999px; background:rgba(12,42,18,.58); }
.site-header-carousel-arrow { width:32px; height:32px; border:1px solid rgba(255,255,255,.65); border-radius:50%; padding:0; background:rgba(0,0,0,.18); color:#fff; font-size:1.7rem; line-height:25px; }
.site-header-carousel-dots { display:flex; align-items:center; gap:7px; }
.site-header-carousel-dot { width:10px; height:10px; border:1px solid #fff; border-radius:50%; padding:0; background:transparent; }
.site-header-carousel-dot.is-active { background:#fff; }
.site-header .navbar { position: relative; z-index: 1; }
.site-header-home .site-header-banner { min-height: 320px; }
@media (prefers-reduced-motion: reduce) { .site-header-slide { transition:none; transform:none; } }
.header-banner-inner {
    position: relative;
    z-index: 2;
    min-height: inherit;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding-top: 28px;
    padding-bottom: 28px;
}
.brand-block,
.nav-actions-panel {
    background: rgba(12, 42, 18, 0.58);
    border: 1px solid rgba(255,255,255,0.22);
    box-shadow: 0 12px 34px rgba(12,42,18,0.18);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
}
.brand-block {
    border-radius: 18px;
    padding: 12px;
}
.nav-actions-panel {
    margin-top: 12px;
    padding: 10px;
    border-radius: 12px;
    position: relative;
    z-index: 20;
}
.nav-shell { gap: 1rem; }
.mobile-nav-short-name { display: none; }
.inside-mobile-calendar { display: none; }
.home-banner-identity {
    position: absolute;
    left: 50%;
    bottom: 24%;
    transform: translateX(-50%);
    padding: 10px 20px 12px;
    border: 1px solid rgba(255,255,255,.22);
    border-radius: 14px;
    background: rgba(12,42,18,.58);
    box-shadow: 0 12px 34px rgba(12,42,18,.18);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    color: #fff;
    font-size: clamp(3rem, 6vw, 5rem);
    line-height: .9;
    font-weight: 800;
    letter-spacing: .04em;
    text-shadow: 0 3px 18px rgba(0,0,0,.72);
    z-index: 5;
}
.home-intro-bar { background: #3f7555; color: #fff; }
.home-intro-inner {
    min-height: 162px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 2rem;
    padding-top: 22px;
    padding-bottom: 22px;
}
.home-intro-established { font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.home-intro-title { margin-top: .3rem; font-size: clamp(1.9rem, 4vw, 3.6rem); line-height: 1.12; font-weight: 500; }
.home-intro-tagline { margin-top: .35rem; font-size: clamp(.95rem, 1.6vw, 1.22rem); font-weight: 400; }
.home-intro-join { flex: 0 0 auto; min-width: 180px; padding: .7rem 1.5rem; font-size: 1.05rem; }
.home-intro-actions { align-self: stretch; display: flex; flex-direction: column; align-items: stretch; justify-content: space-between; gap: .55rem; text-align: right; }
.home-intro-short-name { margin-top: 1.25rem; letter-spacing: .1em; text-align: center; text-indent: .1em; }
.home-mobile-controls { display: none; }
.home-mobile-quick-action { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; min-height: 44px; padding: .45rem .6rem; border: 1px solid rgba(255,255,255,.8); border-radius: 4px; background: transparent; color: #fff; font-size: .76rem; font-weight: 700; line-height: 1; text-decoration: none; white-space: nowrap; }
.home-mobile-quick-action:disabled { opacity: .65; }
.home-mobile-toggler { display: none; align-self: flex-end; }
.navbar {
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
}
.logo-badge {
    width: 140px;
    height: 140px;
    background: #fffbef;
    border-radius: 50%;
    display: grid;
    place-items: center;
    border: 4px solid #dce705;
    box-shadow: 0 14px 40px rgba(0,0,0,0.18);
    color: #0f5d2d;
    font-weight: 800;
}
.logo-badge img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    display: block;
}
.brand-block {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 230px;
}
.brand-logo-only {
    min-width: auto;
}
.brand-logo-only .brand-text {
    display: none;
}
.nav-link,
.dropdown-item {
    font-weight: 600;
    letter-spacing: 0.02em;
}
.brand-text small {
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgba(255,255,255,0.8);
    font-weight: 700;
}
.brand-text strong { display: block; font-size: 1.05rem; }
.nav-primary { gap: 0.35rem; justify-content: flex-start; }
.nav-item-with-children { position: relative; }
.nav-parent {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.55rem 0.9rem;
    border-radius: 10px;
    border: 1px solid transparent;
    background: transparent;
    color: rgba(255,255,255,0.92);
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    min-height: 44px;
    white-space: nowrap;
    width: auto;
}
.nav-parent:hover,
.nav-parent:focus-visible {
    background: rgba(255,255,255,0.1);
    color: #fff;
}
.nav-parent:focus-visible {
    outline: 2px solid rgba(255,255,255,0.6);
    outline-offset: 2px;
}
.nav-parent .chevron {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-right: 2px solid currentColor;
    border-bottom: 2px solid currentColor;
    transform: rotate(45deg);
    transition: transform 0.2s ease, color 0.2s ease;
}
.nav-parent.is-open {
    background: rgba(255,255,255,0.16);
}
.nav-parent.is-open .chevron {
    transform: rotate(-135deg);
}
.nav-primary .nav-link {
    padding: 0.55rem 0.9rem;
    border-radius: 10px;
    color: rgba(255,255,255,0.92);
    white-space: nowrap;
}
.nav-primary .nav-link:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
}
.nav-caret { color: #fff; padding: 0 0.25rem; }
.nav-submenu {
    position: absolute;
    top: 100%;
    left: 0;
    min-width: 220px;
    background: #fff;
    border-radius: 12px;
    padding: 0.35rem 0.15rem;
    box-shadow: 0 14px 40px rgba(0,0,0,0.14);
    border: 1px solid rgba(0,0,0,0.06);
    z-index: 5;
}
.nav-submenu.show { display: block; }
.nav-submenu[hidden] { display: none !important; }
.nav-submenu .dropdown-item {
    padding: 0.55rem 0.9rem;
    min-height: 44px;
    display: flex;
    align-items: center;
    color: #0f2d17;
    font-weight: 700;
    border-radius: 8px;
    transition: background-color .15s ease, color .15s ease;
}
.nav-submenu .dropdown-item:hover,
.nav-submenu .dropdown-item:focus {
    background-color: #198754;
    color: #fff;
}
.nav-submenu .dropdown-divider {
    height: 2px;
    margin: 0.45rem 0.9rem;
    border: 0;
    background-color: #198754;
    opacity: 1;
}

/* FIXED UTILITY BUTTONS */
.nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.guest-help-actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
.guest-login-btn { height: auto; min-height: 48px; }

.account-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.account-wrapper .dropdown-menu { z-index: 30; }

.utility-greeting {
    font-size: 12px;
    color: rgba(255,255,255,0.85);
    margin-bottom: 2px;
    line-height: 1;
    text-align: left;
}

.utility-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.45);
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 14px;
    line-height: 1.2;
    font-weight: 600;
    color: #fff;
    height: 40px;
    min-width: 110px;
    white-space: nowrap;
    text-align: center;
}

.utility-btn:hover {
    background: rgba(255,255,255,0.22);
    color: #fff;
}

.utility-btn.dropdown-toggle::after {
    margin-left: 6px;
}

.header-icon-btn {
    width: 40px;
    min-width: 40px;
    padding: 0;
    font-size: 1rem;
}
.header-icon-btn.dropdown-toggle::after { display: none; }

@media (hover: hover) and (pointer: fine) {
    .account-wrapper .dropdown:hover > .dropdown-menu,
    .account-wrapper .dropdown:focus-within > .dropdown-menu {
        display: block;
        margin-top: 0;
    }
}

.basket-link {
    color: #fff;
    position: relative;
}
.basket-link:hover { color: #fff; }
.basket-count {
    background: #ffc107;
    color: #0f2d17;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 12px;
    line-height: 1;
    min-width: 18px;
    text-align: center;
    display: inline-block;
    min-height: 18px;
    transition: opacity 0.15s ease;
    position: absolute;
    top: -7px;
    right: -7px;
}
.basket-count.is-empty {
    visibility: hidden;
    opacity: 0;
}
.basket-hover-value {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    z-index: 1100;
    width: max-content;
    max-width: 220px;
    padding: 0.5rem 0.7rem;
    border-radius: 6px;
    background: #fff;
    color: #0f2d17;
    box-shadow: 0 10px 24px rgba(15, 47, 31, 0.2);
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.2;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translateY(-4px);
    transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease;
}
.basket-link:hover .basket-hover-value,
.basket-link:focus-visible .basket-hover-value {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

/* Hide page hero on non-home pages; opt-in via body.show-page-hero */
.page-hero {
    display: none;
}
body.show-page-hero .page-hero {
    display: block;
}

/* Older iPad landscape and similar widths: show the full menu, compactly. */
@media (min-width: 992px) and (max-width: 1199.98px) {
    .nav-shell { gap: 0; }
    .nav-primary {
        justify-content: space-between;
        gap: 0.1rem;
    }
    .nav-primary > .nav-item { flex: 0 1 auto; }
    .nav-parent,
    .nav-primary .nav-link {
        padding: 0.55rem 0.5rem;
        font-size: 0.76rem;
        letter-spacing: 0;
    }
    .nav-parent { gap: 4px; }
    .nav-parent .chevron { width: 8px; height: 8px; }
}

@media (max-width: 991.98px) {
    .navbar { min-height: 54px; }
    .navbar-toggler { margin: 7px 0; }
    .nav-primary { width: 100%; }
    .nav-item-with-children { width: 100%; }
    .nav-parent {
        width: 100%;
        justify-content: space-between;
        border: 1px solid rgba(255,255,255,0.15);
        margin-bottom: 0.25rem;
    }
    .nav-submenu {
        position: static;
        box-shadow: none;
        background: rgba(255,255,255,0.08);
        padding: 0.25rem 0;
        margin-top: 0.25rem;
        border-radius: 10px;
    }
    .nav-submenu .dropdown-item {
        color: #0f2d17;
        font-weight: 600;
    }
    .nav-submenu .dropdown-divider { background-color: rgba(255,255,255,0.75); }
    .site-header-home .site-header-banner { min-height: 260px; }
    .home-intro-inner { min-height: 150px; }
}

@media (max-width: 575.98px) {
    .site-header-banner { min-height: 165px; }
    .site-header-home .site-header-banner { min-height: 190px; }
    .header-banner-inner {
        gap: 0.5rem;
        padding-top: 16px;
        padding-bottom: 16px;
    }
    .brand-block { min-width: 0; padding: 8px; }
    .brand-text { display: none; }
    .logo-badge { width: 88px; height: 88px; border-width: 3px; }
    .nav-actions-panel { margin-top: 6px; padding: 7px; }
    .nav-actions { gap: 7px; }
    .nav-actions-panel .utility-btn:not(.header-icon-btn) {
        min-width: 0;
        padding-left: 10px;
        padding-right: 10px;
        font-size: 0.78rem;
    }
    .site-header-home .home-banner-identity { display: none; }
    .site-header-home .header-help-btn { display: none; }
    .home-intro-inner { position: relative; min-height: 0; gap: 1rem; padding-top: 16px; padding-bottom: 76px; }
    .home-intro-title { font-size: 1.65rem; }
    .home-intro-tagline { font-size: .9rem; }
    .home-intro-join { min-width: 92px; padding: .55rem .8rem; font-size: .9rem; }
    .site-header-home .home-mobile-controls {
        display: flex;
        position: absolute;
        right: 12px;
        bottom: 14px;
        left: 12px;
        align-items: center;
        justify-content: flex-start;
        gap: .55rem;
    }
    .site-header-home .home-mobile-toggler {
        display: block;
        margin: 0 0 0 auto;
        padding: .5rem .65rem;
        border-color: rgba(255,255,255,.8);
    }
    .site-header-home .home-mobile-toggler .navbar-toggler-icon {
        width: 1.75rem;
        height: 1.75rem;
        filter: brightness(0) invert(1);
    }
    .site-header-home > .navbar > .nav-shell > .navbar-toggler { display: none; }
    .site-header-home > .navbar { min-height: 0; }
    .site-header-inner .nav-shell {
        display: grid;
        grid-template-columns: 48px minmax(0, 1fr) 48px;
        align-items: center;
        width: 100%;
        gap: .5rem;
    }
    .site-header-inner .inside-mobile-calendar {
        display: inline-flex;
        width: 48px;
        height: 48px;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255,255,255,.8);
        border-radius: 4px;
        color: #fff;
        font-size: 1.25rem;
        text-decoration: none;
    }
    .site-header-inner .mobile-nav-short-name {
        display: block;
        color: #fff;
        font-size: 1.2rem;
        font-weight: 800;
        line-height: 1.2;
        text-align: center;
    }
    .site-header-inner .main-navbar-toggler {
        width: 48px;
        height: 48px;
        margin: 0;
        padding: .5rem;
    }
    .site-header-inner #mainNav { grid-column: 1 / -1; }
}
</style>
