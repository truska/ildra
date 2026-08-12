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
.site-header .navbar { position: relative; z-index: 1; }
.site-header-home .site-header-banner { min-height: 320px; }
.header-banner-inner {
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
}
.nav-submenu .dropdown-divider { margin: 0.35rem 0; }

/* FIXED UTILITY BUTTONS */
.nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

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

@media (max-width: 1199.98px) {
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
}

@media (max-width: 575.98px) {
    .site-header-banner { min-height: 165px; }
    .site-header-home .site-header-banner { min-height: 220px; }
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
}
</style>
