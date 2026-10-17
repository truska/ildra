<?php
declare(strict_types=1);

$superSettings = $siteSettings ?? getSiteSettings($pdo ?? null);
if (!super_announcement_visible($superSettings)) return;
$superAsset = fetchAssetLibraryById($pdo ?? null, (int)$superSettings['super_announcement_asset_id']);
$superImage = $superAsset && ($superAsset['asset_type'] ?? '') === 'image' && empty($superAsset['archived']) ? assetLibraryPublicUrl($superAsset, 'md') : '';
$superImageRight = $superSettings['super_announcement_image_side'] === 'right';
?>
<style>
    #superAnnouncement {
        box-sizing: border-box;
        width: min(920px, calc(100% - 2rem));
        max-height: calc(100dvh - 2rem);
        margin: auto;
        padding: clamp(1.25rem, 4vw, 2.5rem);
        border: 0;
        border-radius: 16px;
        background: <?php echo h(announcement_colour((string)$superSettings['super_announcement_bg_colour'], '#0c4522')); ?>;
        color: <?php echo h(announcement_colour((string)$superSettings['super_announcement_text_colour'], '#ffffff')); ?>;
        box-shadow: 0 24px 80px #0006;
        overflow-y: auto;
    }
    #superAnnouncement::backdrop { background: #0009; }
    #superAnnouncement h2, #superAnnouncement h3, #superAnnouncement a { color: inherit; }
    #superAnnouncement .super-title { font-size: clamp(1.6rem, 4vw, 2.25rem); text-align: center; margin: 0 0 1.5rem; }
    #superAnnouncement .super-columns { display: flex; gap: 1.5rem; align-items: center; }
    #superAnnouncement .super-image, #superAnnouncement .super-copy { flex: 1; min-width: 0; }
    #superAnnouncement .super-image img { width: 100%; max-height: 320px; object-fit: contain; border-radius: 8px; }
    #superAnnouncement .super-heading { text-align: center; margin-bottom: 1.5rem; }
    #superAnnouncement .super-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
    #superAnnouncement .super-hide { padding: .65rem 1.1rem; border: 1px solid currentColor; border-radius: 8px; background: transparent; color: inherit; font: inherit; cursor: pointer; transition: background-color .18s ease, box-shadow .18s ease, transform .18s ease; }
    #superAnnouncement .super-hide:hover,
    #superAnnouncement .super-hide:focus-visible {
        background: color-mix(in srgb, currentColor 18%, transparent);
        box-shadow: 0 4px 12px #0004;
        transform: translateY(-1px);
    }
    #superAnnouncement .super-hide:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
    #superAnnouncement .super-hide:active { transform: translateY(0); box-shadow: 0 1px 4px #0003; }
    @media (prefers-reduced-motion: reduce) { #superAnnouncement .super-hide { transition: none; transform: none; } }
    @media (max-width: 767px) { #superAnnouncement .super-columns { flex-direction: column; } #superAnnouncement .super-image { order: 0 !important; } }
</style>
<dialog id="superAnnouncement" aria-labelledby="superAnnouncementTitle" data-notice-token="<?php echo h(super_announcement_token($superSettings)); ?>">
    <h2 class="super-title" id="superAnnouncementTitle"><?php echo h((string)$superSettings['super_announcement_title']); ?></h2>
    <?php if (trim((string)$superSettings['super_announcement_heading']) !== ''): ?><h3 class="super-heading"><?php echo h((string)$superSettings['super_announcement_heading']); ?></h3><?php endif; ?>
    <div class="super-columns">
        <?php if ($superImage !== ''): ?><div class="super-image" <?php echo $superImageRight ? 'style="order: 2"' : ''; ?>><img src="<?php echo h($superImage); ?>" alt="<?php echo h((string)($superAsset['alt_text'] ?? '')); ?>"></div><?php endif; ?>
        <div class="super-copy">
            <?php if (trim((string)$superSettings['super_announcement_subheading']) !== ''): ?><h3><?php echo h((string)$superSettings['super_announcement_subheading']); ?></h3><?php endif; ?>
            <?php echo render_wysiwyg((string)$superSettings['super_announcement_body_html']); ?>
        </div>
    </div>
    <div class="super-actions"><button type="button" class="super-hide super-close" autofocus>Close</button><button type="button" class="super-hide super-hide-30">Hide for 30 days</button></div>
</dialog>
<script>
(() => {
    const notice = document.getElementById('superAnnouncement');
    const storageKey = 'ildra_super_announcement_hidden';
    const token = notice.dataset.noticeToken;
    const sessionKey = storageKey + '_session';
    const isHidden = value => {
        try {
            const saved = JSON.parse(value);
            return saved && saved.token === token && Number.isFinite(saved.expires) && saved.expires > Date.now();
        } catch (error) { return false; }
    };
    let hidden = false;
    try { hidden = isHidden(localStorage.getItem(storageKey)); } catch (error) {}
    try { hidden = hidden || sessionStorage.getItem(sessionKey) === token; } catch (error) {}
    if (hidden) return;
    const close = () => {
        try { sessionStorage.setItem(sessionKey, token); } catch (error) {}
        notice.close();
    };
    const hide = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify({token, expires: Date.now() + 30 * 24 * 60 * 60 * 1000}));
        } catch (error) {}
        notice.close();
    };
    notice.querySelector('.super-close').addEventListener('click', close);
    notice.querySelector('.super-hide-30').addEventListener('click', hide);
    notice.addEventListener('cancel', event => { event.preventDefault(); close(); });
    // Other open tabs on this device follow the 30-day hide choice.
    window.addEventListener('storage', event => {
        if (event.key === storageKey && isHidden(event.newValue) && notice.open) notice.close();
    });
    const show = () => { if (typeof notice.showModal === 'function') notice.showModal(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show, {once: true});
    else show();
})();
</script>
