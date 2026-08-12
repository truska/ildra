<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';
$pathSlug = '';

// Helper for PHP 7 compatibility (str_starts_with is PHP 8)
$startsWith = function (?string $haystack, string $needle): bool {
    return $haystack !== null && strpos($haystack, $needle) === 0;
};

// Prefer deriving the slug from the URL path; fall back to query params if needed.
if (isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = $uri ? ltrim($uri, '/') : '';
    $basePrefix = ltrim((string)$basePath, '/');
    if ($basePrefix !== '' && $startsWith($uri, $basePrefix . '/')) {
        $uri = substr($uri, strlen($basePrefix) + 1);
    }
    if ($startsWith($uri, 'pages/')) {
        $pathSlug = trim(substr($uri, strlen('pages/')), '/');
    } else {
        $pos = strpos($uri, 'pages/');
        if ($pos !== false) {
            $pathSlug = trim(substr($uri, $pos + strlen('pages/')), '/');
        }
    }
}
if ($pathSlug === '') {
    $pathSlug = trim($_GET['path'] ?? $_GET['page'] ?? $_GET['slug'] ?? '');
}

$page = null;
if ($pathSlug !== '') {
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND is_published = 1 LIMIT 1");
        $stmt->execute([':slug' => $pathSlug]);
        $page = $stmt->fetch() ?: null;
    } else {
        foreach (defaultPages() as $candidate) {
            if (strcasecmp((string)($candidate['slug'] ?? ''), $pathSlug) === 0) {
                $page = $candidate;
                break;
            }
        }
    }
}
if ($page && $pathSlug !== '' && strcasecmp((string)($page['slug'] ?? ''), $pathSlug) !== 0 && $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $pathSlug]);
    $page = $stmt->fetch() ?: null;
}

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$pageFromList = null;
if ($pathSlug !== '') {
    foreach ($pages as $candidate) {
        if (strcasecmp((string)($candidate['slug'] ?? ''), $pathSlug) === 0) {
            $pageFromList = $candidate;
            break;
        }
    }
    if ($pageFromList) {
        $page = $pageFromList;
    }
}


if (!$page && $pathSlug && $pdo) {
    // Allow admins to preview unpublished if logged in
    if ($canViewAdmin) {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $pathSlug]);
        $page = $stmt->fetch() ?: null;
    }
}

$renderPage = $pageFromList ?? $page;
$dynamicSections = $renderPage ? page_dynamic_sections($pdo, $renderPage, $basePath) : ['before_content' => '', 'after_body' => ''];
$advertising = fetchAdvertising($pdo, true);
$pageImageBatch = $renderPage ? mediaBatchFind($pdo, 'page_images', 'page', (int)($renderPage['id'] ?? 0)) : null;
$pageImages = $pageImageBatch ? mediaBatchImages($pdo, (int)$pageImageBatch['id']) : [];
$pageElements = $renderPage ? fetchPageContentElements($pdo, (int)($renderPage['id'] ?? 0), true) : [];
foreach ($pageElements as &$pageElement) {
    $pageElement['image_batch'] = mediaBatchFind($pdo, 'content_element_images', 'page_content_element', (int)$pageElement['id']);
    $pageElement['images'] = $pageElement['image_batch'] ? mediaBatchImages($pdo, (int)$pageElement['image_batch']['id']) : [];
}
unset($pageElement);

if (!$renderPage) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $renderPage ? h($renderPage['title']) . ' | ' . h($siteSettings['hero_title']) : 'Page not found'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --green: #146118;
            --green-alt: #1f7c24;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .page-hero {
            background: linear-gradient(120deg, rgba(20, 97, 24, 0.9), rgba(20, 97, 24, 0.75)), url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            padding: 2.5rem 0;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.12), transparent 32%);
            z-index: 0;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .page-body {
            color: #476146;
        }
        .page-body > :last-child {
            margin-bottom: 0;
        }
        .page-body a:not(.btn) {
            color: #244a29;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .page-body a:not(.btn):hover,
        .page-body a:not(.btn):focus {
            color: #476146;
            text-decoration: none;
        }
        .page-advertising { display: grid; gap: 0.8rem; }
        .page-advertising-item { display: block; border-radius: 12px; overflow: hidden; background: #fff; }
        .page-advertising-item img { display: block; width: 100%; height: auto; max-height: 110px; object-fit: contain; }
        .page-gallery-main { display:block; width:100%; border:0; padding:0; background:none; cursor:zoom-in; }
        .page-gallery-main img { display:block; width:100%; height:auto; max-height:520px; object-fit:contain; border-radius:12px; }
        .page-gallery-caption { margin-top:.55rem; color:var(--muted); font-size:.9rem; }
        .page-gallery-thumbs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.45rem; margin-top:.65rem; }
        .page-gallery-thumb { border:2px solid transparent; border-radius:8px; padding:0; overflow:hidden; background:#fff; }
        .page-gallery-thumb.active { border-color:var(--green-alt); }
        .page-gallery-thumb img { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; }
        .page-lightbox { position:fixed; inset:0; z-index:4000; display:none; place-items:center; padding:2rem; background:rgba(0,0,0,.9); }
        .page-lightbox.open { display:grid; }
        .page-lightbox img { max-width:94vw; max-height:88vh; object-fit:contain; }
        .page-lightbox-figure { margin:0; max-width:94vw; text-align:center; }
        .page-lightbox-caption { margin-top:.65rem; color:#fff; font-size:1rem; }
        .page-lightbox-close { position:absolute; right:1rem; top:.5rem; border:0; background:none; color:#fff; font-size:2.5rem; }
        .page-lightbox-nav { position:absolute; top:50%; transform:translateY(-50%); border:0; border-radius:999px; width:3rem; height:3rem; background:rgba(255,255,255,.16); color:#fff; font-size:2rem; line-height:1; }
        .page-lightbox-prev { left:1rem; }
        .page-lightbox-next { right:1rem; }
        .page-content-elements { margin-top:2rem; }
        .page-content-element { margin-bottom:1.5rem; }
        .page-content-element .element-text { padding:1.5rem; }
        @media (max-width: 767.98px) { .page-lightbox { padding:1rem; } .page-lightbox-nav { width:2.5rem; height:2.5rem; } .page-lightbox-prev { left:.25rem; } .page-lightbox-next { right:.25rem; } }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="py-3" style="background: #f5f7ef; border-bottom: 1px solid rgba(0,0,0,0.05);">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-muted"><?php echo h($siteSettings['hero_subtitle']); ?></p>
            <h1 class="fw-bold mb-1" style="color: var(--text-main);"><?php echo $renderPage ? h($renderPage['title']) : 'Page not found'; ?></h1>
            <?php if ($renderPage): ?>
                <div class="text-muted"><?php echo h($renderPage['excerpt'] ?? ''); ?></div>
            <?php else: ?>
                <div class="text-muted">We could not find that page.</div>
            <?php endif; ?>
        </div>
    </header>

    <main class="pt-0 pb-5">
        <div class="container">
            <?php echo $dynamicSections['before_content']; ?>

            <div class="row g-4 mt-2">
                <?php if ($pageImages && $pageImageBatch): ?>
                <div class="col-lg-4">
                    <div class="card-soft p-3 page-gallery" data-page-gallery>
                        <?php $mainImage=$pageImages[0]; ?>
                        <button type="button" class="page-gallery-main" data-lightbox-src="<?php echo h(mediaBatchImageUrl($pageImageBatch,$mainImage,'original')); ?>" aria-label="Enlarge image">
                            <img src="<?php echo h(mediaBatchImageUrl($pageImageBatch,$mainImage,'md')); ?>" alt="<?php echo h($mainImage['alt_text'] ?: $mainImage['title'] ?: $renderPage['title']); ?>" title="<?php echo h($mainImage['title'] ?: ''); ?>">
                        </button>
                        <div class="page-gallery-caption"><?php echo h($mainImage['caption'] ?: ''); ?></div>
                        <?php if(count($pageImages)>1): ?><div class="page-gallery-thumbs">
                            <?php foreach($pageImages as $index=>$image): ?><button type="button" class="page-gallery-thumb <?php echo $index===0?'active':''; ?>" data-md="<?php echo h(mediaBatchImageUrl($pageImageBatch,$image,'md')); ?>" data-full="<?php echo h(mediaBatchImageUrl($pageImageBatch,$image,'original')); ?>" data-alt="<?php echo h($image['alt_text'] ?: $image['title'] ?: $renderPage['title']); ?>" data-title="<?php echo h($image['title'] ?: ''); ?>" data-caption="<?php echo h($image['caption'] ?: ''); ?>"><img src="<?php echo h(mediaBatchImageUrl($pageImageBatch,$image,'xs')); ?>" alt=""></button><?php endforeach; ?>
                        </div><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="<?php echo $pageImages ? 'col-lg-6' : 'col-lg-10'; ?>">
                    <div class="card-soft p-4">
                        <?php if ($renderPage): ?>
                            <div class="lead mb-3"><?php echo h($renderPage['excerpt'] ?? ''); ?></div>
                            <div class="page-body"><?php echo (string)($renderPage['body_html'] ?? ''); ?></div>
                            <?php echo $dynamicSections['after_body']; ?>
                            <?php
                            $pageButtonUrl = trim((string)($renderPage['button_url'] ?? ''));
                            if ($pageButtonUrl === '' && !empty($renderPage['button_asset_id'])) {
                                $pageButtonAsset = fetchAssetLibraryById($pdo, (int)$renderPage['button_asset_id']);
                                if ($pageButtonAsset && empty($pageButtonAsset['archived'])) $pageButtonUrl = assetLibraryPublicUrl($pageButtonAsset);
                            }
                            ?>
                            <?php if (!empty($renderPage['button_name']) && $pageButtonUrl !== ''): ?>
                                <?php $pageButtonTarget = ($renderPage['button_target'] ?? '_self') === '_blank' ? '_blank' : '_self'; ?>
                                <div class="mt-4 text-start">
                                    <a class="btn button2" href="<?php echo h($pageButtonUrl); ?>" title="<?php echo h($renderPage['button_title'] ?: $renderPage['button_name']); ?>" target="<?php echo h($pageButtonTarget); ?>"<?php echo $pageButtonTarget === '_blank' ? ' rel="noopener"' : ''; ?>><?php echo h($renderPage['button_name']); ?></a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mb-0">Try another page from the navigation above.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-2">
                    <?php if ($advertising): ?>
                        <div class="page-advertising" aria-label="Promotions">
                            <?php foreach ($advertising as $advert): ?>
                                <?php if (empty($advert['image'])) continue; ?>
                                <?php $advertImage = image_upload_public_path('advertising', 'sm', (string)$advert['image']); ?>
                                <?php $advertTarget = ($advert['link_target'] ?? '_blank') === '_self' ? '_self' : '_blank'; ?>
                                <?php if (!empty($advert['url'])): ?><a class="page-advertising-item card-soft" href="<?php echo h($advert['url']); ?>" target="<?php echo h($advertTarget); ?>"<?php echo $advertTarget === '_blank' ? ' rel="noopener sponsored"' : ''; ?>><?php else: ?><div class="page-advertising-item card-soft"><?php endif; ?>
                                    <img src="<?php echo h($advertImage); ?>" alt="<?php echo h($advert['name']); ?>" title="<?php echo h($advert['title'] ?: $advert['name']); ?>" loading="lazy">
                                <?php if (!empty($advert['url'])): ?></a><?php else: ?></div><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="card-soft p-3 mt-3">
                        <div class="fw-bold mb-2">Back to home</div>
                        <a class="btn btn-outline-success w-100" href="<?php echo h($basePath); ?>/">ILDRA Home</a>
                    </div>
                </div>
            </div>
            <?php if ($pageElements): ?>
            <div class="page-content-elements">
                <?php $nextAutoSide='left'; foreach($pageElements as $element):
                    $elementImages=$element['images']; $elementBatch=$element['image_batch']; $layout=(string)$element['layout'];
                    $hasElementImages=$elementImages && $layout!=='text_only';
                    if(!$hasElementImages){$side='left';$nextAutoSide='left';}
                    elseif($layout==='image_left'){$side='left';}
                    elseif($layout==='image_right'){$side='right';}
                    else{$side=$nextAutoSide;$nextAutoSide=$nextAutoSide==='left'?'right':'left';}
                ?>
                <section id="<?php echo h($element['anchor_slug'] ?: image_upload_slug($element['heading'] ?: $element['name'])); ?>" class="page-content-element card-soft overflow-hidden">
                    <div class="row g-0 align-items-start justify-content-center">
                        <?php if($hasElementImages): ?><div class="col-lg-4 <?php echo $side==='right'?'order-lg-2':''; ?>"><div class="p-3 page-gallery" data-page-gallery><?php $mainImage=$elementImages[0]; ?><button type="button" class="page-gallery-main" data-lightbox-src="<?php echo h(mediaBatchImageUrl($elementBatch,$mainImage,'original')); ?>"><img src="<?php echo h(mediaBatchImageUrl($elementBatch,$mainImage,'md')); ?>" alt="<?php echo h($mainImage['alt_text']?:$mainImage['title']?:$element['heading']); ?>"></button><div class="page-gallery-caption"><?php echo h($mainImage['caption']?:''); ?></div><?php if(count($elementImages)>1): ?><div class="page-gallery-thumbs"><?php foreach($elementImages as$i=>$image): ?><button type="button" class="page-gallery-thumb <?php echo $i===0?'active':''; ?>" data-md="<?php echo h(mediaBatchImageUrl($elementBatch,$image,'md')); ?>" data-full="<?php echo h(mediaBatchImageUrl($elementBatch,$image,'original')); ?>" data-alt="<?php echo h($image['alt_text']?:$image['title']?:$element['heading']); ?>" data-title="<?php echo h($image['title']?:''); ?>" data-caption="<?php echo h($image['caption']?:''); ?>"><img src="<?php echo h(mediaBatchImageUrl($elementBatch,$image,'xs')); ?>" alt=""></button><?php endforeach; ?></div><?php endif; ?></div></div><?php endif; ?>
                        <div class="<?php echo $hasElementImages?'col-lg-6':'col-lg-10'; ?> <?php echo $side==='right'?'order-lg-1':''; ?> element-text"><?php if(!empty($element['heading'])): ?><h2 class="h3 mb-3"><?php echo h($element['heading']); ?></h2><?php endif; ?><div class="page-body"><?php echo (string)$element['body_html']; ?></div></div>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="page-lightbox" id="page-lightbox" role="dialog" aria-modal="true" aria-label="Image preview"><button type="button" class="page-lightbox-close" aria-label="Close">&times;</button><button type="button" class="page-lightbox-nav page-lightbox-prev" aria-label="Previous image">&#8249;</button><figure class="page-lightbox-figure"><img src="" alt=""><figcaption class="page-lightbox-caption"></figcaption></figure><button type="button" class="page-lightbox-nav page-lightbox-next" aria-label="Next image">&#8250;</button></div>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
    (function(){
        const galleries=Array.from(document.querySelectorAll('[data-page-gallery]')), box=document.getElementById('page-lightbox');
        if(!galleries.length||!box)return;
        const boxImg=box.querySelector('img'),boxCaption=box.querySelector('.page-lightbox-caption');let active=null;
        galleries.forEach(gallery=>{const main=gallery.querySelector('.page-gallery-main'),mainImg=main.querySelector('img'),caption=gallery.querySelector('.page-gallery-caption'),thumbs=Array.from(gallery.querySelectorAll('.page-gallery-thumb'));const state={gallery,main,mainImg,caption,thumbs,current:0};state.select=index=>{if(!thumbs.length)return;state.current=(index+thumbs.length)%thumbs.length;const thumb=thumbs[state.current];thumbs.forEach(x=>x.classList.remove('active'));thumb.classList.add('active');mainImg.src=thumb.dataset.md;mainImg.alt=thumb.dataset.alt||'';main.dataset.lightboxSrc=thumb.dataset.full;caption.textContent=thumb.dataset.caption||'';if(box.classList.contains('open')){boxImg.src=thumb.dataset.full;boxImg.alt=thumb.dataset.alt||'';boxCaption.textContent=thumb.dataset.caption||'';}};thumbs.forEach((thumb,index)=>thumb.addEventListener('click',()=>state.select(index)));main.addEventListener('click',()=>{active=state;box.querySelectorAll('.page-lightbox-nav').forEach(button=>button.hidden=thumbs.length<2);boxImg.src=main.dataset.lightboxSrc;boxImg.alt=mainImg.alt;boxCaption.textContent=caption.textContent;box.classList.add('open');document.body.style.overflow='hidden';});});
        function close(){box.classList.remove('open');boxImg.src='';boxCaption.textContent='';document.body.style.overflow='';}
        box.querySelector('.page-lightbox-close').addEventListener('click',close);box.querySelector('.page-lightbox-prev').addEventListener('click',()=>{if(active)active.select(active.current-1);});box.querySelector('.page-lightbox-next').addEventListener('click',()=>{if(active)active.select(active.current+1);});
        box.addEventListener('click',e=>{if(e.target===box)close();});document.addEventListener('keydown',e=>{if(!box.classList.contains('open'))return;if(e.key==='Escape')close();if(e.key==='ArrowLeft'&&active)active.select(active.current-1);if(e.key==='ArrowRight'&&active)active.select(active.current+1);});
    })();
    </script>
</body>
</html>
