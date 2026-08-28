<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$eventId = max(0, (int)($_GET['event_id'] ?? 0));
$event = $eventId ? fetchEventById($pdo, $eventId) : null;
$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true) ?: defaultPages();
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager', 'organiser'], true);
$report = $event ? fetchRideReportByEvent($pdo, $eventId, !$canViewAdmin) : null;

if (!$event || !$report || (strtolower((string)($event['status'] ?? '')) !== 'published' && !$canViewAdmin)) {
    $event = null;
    $report = null;
    http_response_code(404);
}

$eventPricingRows = $event ? fetchEventPricingRows($pdo, $eventId) : [];
$galleryBatch = $report ? mediaBatchFind($pdo, 'news_images', 'news_article', (int)$report['id']) : null;
$galleryImages = $galleryBatch ? mediaBatchImages($pdo, (int)$galleryBatch['id']) : [];
$facebookGalleryUrl = $report ? trim((string)($report['facebook_gallery_url'] ?? '')) : '';
$facebookEmbedUrl = $facebookGalleryUrl !== '' ? 'https://www.facebook.com/plugins/post.php?href=' . rawurlencode($facebookGalleryUrl) . '&show_text=true&width=750' : '';
$classesList = class_names_from_pricing_rows($eventPricingRows);
if (!$classesList && $event) $classesList = class_names_from_classes_offered($event['classes_offered'] ?? '');
$capacityLimit = (int)($event['capacity_limit'] ?? 0);
$hasLimit = $event && !empty($event['capacity_enabled']) && $capacityLimit > 0;
$totalEntryCount = (int)($event['entry_count'] ?? 0);
$dateRange = 'Date TBC';
$eventDateText = 'TBC';
$endDateText = '';
$galleryDefaultCaption = $event ? 'ILDRA ' . (string)$event['title'] . ' on ' . (!empty($event['event_date']) ? date('d-M-Y', strtotime((string)$event['event_date'])) : 'Date TBC') : 'ILDRA Ride';
if ($event && !empty($event['event_date'])) {
    $eventDateText = (new DateTimeImmutable((string)$event['event_date']))->format('jS M Y');
    $dateRange = format_display_date($event['event_date'], 'Date TBC');
}
if ($event && !empty($event['end_date']) && $event['end_date'] !== $event['event_date']) {
    $endDateText = (new DateTimeImmutable((string)$event['end_date']))->format('jS M Y');
    $dateRange .= ' to ' . format_display_date($event['end_date'], 'Date TBC');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $event ? h($event['title']) . ' — Ride Report and Results | ' . h($siteSettings['hero_title']) : 'Ride report not found'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root{--green:#146118;--green-alt:#1f7c24;--cream:#f7f8f1;--text-main:#0c2a12;--muted:#476146}
        body{background:var(--cream);color:var(--text-main);font-family:Manrope,system-ui,sans-serif;line-height:1.7}
        .page-hero{background:linear-gradient(120deg,rgba(20,97,24,.9),rgba(20,97,24,.75)),url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;color:#fff;padding:2.5rem 0}
        .card-soft{border-radius:18px;border:1px solid rgba(0,0,0,.04);box-shadow:0 18px 48px rgba(0,0,0,.08);background:#fff}
        .meta-chip{background:rgba(20,97,24,.1);color:var(--green);padding:6px 10px;border-radius:999px;display:inline-flex;font-weight:700}
        .report-gallery-shell{background:#0d3d2d;color:#fff;border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.14);padding:2rem}
        .report-gallery-shell .gallery-intro{color:rgba(255,255,255,.62);letter-spacing:.04em}.report-gallery-stage{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));grid-template-rows:repeat(2,180px);gap:.65rem}
        .report-gallery-stage .report-gallery-thumb:first-child{grid-column:span 2;grid-row:span 2}.report-gallery-thumbnails{display:flex;gap:.55rem;overflow-x:auto;overscroll-behavior-inline:contain;padding:.75rem .1rem .4rem;scrollbar-color:#a7d2b8 rgba(255,255,255,.12)}
        .report-gallery-thumbnails .report-gallery-thumb{flex:0 0 105px;height:70px}.report-gallery-thumbnails .report-gallery-thumb.active{outline:3px solid #fff;outline-offset:1px}
        .report-gallery-thumb{border:0;border-radius:12px;padding:0;overflow:hidden;background:#e7ece3;cursor:zoom-in;position:relative}
        .report-gallery-thumb img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .2s ease}
        .report-gallery-thumb:hover img,.report-gallery-thumb:focus img{transform:scale(1.05)}
        .report-lightbox{position:fixed;inset:0;z-index:5000;display:none;place-items:center;background:rgba(2,12,4,.94);padding:4.5rem 5rem 2rem;color:#fff}
        .report-lightbox.open{display:grid}.report-lightbox figure{margin:0;text-align:center;max-width:100%;max-height:100%}.report-lightbox img{display:block;max-width:90vw;max-height:78vh;object-fit:contain;margin:auto}
        .report-lightbox figcaption{max-width:850px;margin:.75rem auto 0}.report-lightbox-caption{color:rgba(255,255,255,.82)}
        .report-lightbox-close,.report-lightbox-nav{position:absolute;border:0;color:#fff;background:rgba(255,255,255,.12)}.report-lightbox-close{right:1rem;top:.75rem;background:none;font-size:2.5rem}.report-lightbox-nav{top:50%;transform:translateY(-50%);width:3.25rem;height:3.25rem;border-radius:50%;font-size:2rem}.report-lightbox-prev{left:1rem}.report-lightbox-next{right:1rem}
        .facebook-gallery-frame{display:block;width:100%;max-width:750px;height:720px;margin:0 auto;border:0;border-radius:12px;background:#f0f2f5}.facebook-gallery-link{background:#1877f2;border-color:#1877f2}.facebook-gallery-link:hover{background:#1264cf;border-color:#1264cf}
        @media(max-width:991.98px){.report-gallery-stage{grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:220px repeat(2,120px)}.report-gallery-stage .report-gallery-thumb:first-child{grid-column:1/-1;grid-row:auto}}
        @media(max-width:767.98px){.report-gallery-shell{padding:1.25rem}.report-gallery-stage{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:210px repeat(3,105px)}.report-gallery-stage .report-gallery-thumb:first-child{grid-column:1/-1}.report-lightbox{padding:4rem .75rem 1rem}.report-lightbox-nav{top:auto;bottom:1rem}.report-lightbox-prev{left:calc(50% - 4rem)}.report-lightbox-next{right:calc(50% - 4rem)}}
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/views/header.php'; ?>
<header class="page-hero">
    <div class="container d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div><p class="mb-1 text-uppercase small fw-bold text-white-50">Ride Report and Results</p><h1 class="fw-bold mb-1"><?php echo $event ? h($event['title']) : 'Report not found'; ?></h1><?php if ($event): ?><div class="text-white-50"><?php echo h($dateRange); ?><?php echo !empty($event['venue']) ? ' • ' . h($event['venue']) : ''; ?></div><?php endif; ?></div>
        <a class="btn btn-outline-light btn-sm" href="<?php echo h($basePath); ?>/pages/ildra-reports-results">Back to Reports</a>
    </div>
</header>
<main class="py-5"><div class="container">
    <?php if ($event && $report): ?>
    <section class="card-soft p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3"><div class="meta-chip"><span class="fw-bold"><?php echo h($event['title']); ?></span></div><a class="btn btn-outline-success btn-sm" href="<?php echo h($basePath); ?>/pages/ildra-reports-results">Back to Reports</a></div>
        <?php if (!empty($event['description'])): ?><p class="mb-3"><?php echo h($event['description']); ?></p><?php endif; ?>
        <div class="row g-3">
            <div class="col-md-6"><div class="fw-semibold mb-2">Event details</div><ul class="list-unstyled mb-0 text-muted small"><li><strong class="text-dark">Dates:</strong> <?php echo h($eventDateText); ?><?php echo $endDateText ? ' to ' . h($endDateText) : ''; ?></li><li><strong class="text-dark">Venue:</strong> <?php echo h($event['venue'] ?: 'Venue TBC'); ?></li><?php if (!empty($event['organiser'])): ?><li><strong class="text-dark">Organiser:</strong> <?php echo h($event['organiser']); ?></li><?php endif; ?></ul></div>
            <div class="col-md-6"><div class="fw-semibold mb-2">Classes offered</div><?php if ($classesList): ?><ul class="list-unstyled mb-0 text-muted small"><?php foreach ($classesList as $className): ?><li><?php echo h($className); ?></li><?php endforeach; ?></ul><?php else: ?><div class="text-muted small">No classes listed.</div><?php endif; ?><?php if ($hasLimit): ?><div class="text-muted small mt-2">Entries: <?php echo h($totalEntryCount); ?> / <?php echo h($capacityLimit); ?></div><?php endif; ?></div>
        </div>
    </section>
    <section class="card-soft p-4 mb-4"><h2 class="h3 mb-3">Ride Report</h2><div class="page-body"><?php echo render_wysiwyg((string)($report['body_html'] ?? '')); ?></div></section>
    <section class="card-soft p-4"><h2 class="h3 mb-3">Ride Results</h2><div class="page-body"><?php echo render_wysiwyg((string)($report['results_html'] ?? '')); ?></div></section>
    <?php if ($galleryImages && $galleryBatch): ?>
    <section class="report-gallery-shell mt-4" aria-labelledby="ride-gallery-heading">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3"><div><h2 class="h3 mb-1" id="ride-gallery-heading">Gallery Snapshot</h2><div class="gallery-intro small">Scenes from <?php echo h((string)$event['title']); ?>. Select any photograph to expand it.</div></div><button type="button" class="btn btn-sm btn-outline-light" data-open-full-gallery>View Full Gallery</button></div>
        <div class="report-gallery-stage" id="report-gallery">
            <?php foreach (array_slice($galleryImages, 0, 7) as $galleryIndex => $galleryImage): ?>
            <button type="button" class="report-gallery-thumb" data-gallery-index="<?php echo (int)$galleryIndex; ?>" aria-label="Open <?php echo h((string)($galleryImage['title'] ?: $galleryImage['alt_text'] ?: 'gallery image')); ?>">
                <img src="<?php echo h(mediaBatchImageUrl($galleryBatch, $galleryImage, $galleryIndex === 0 ? 'md' : 'sm')); ?>" alt="<?php echo h((string)($galleryImage['alt_text'] ?: $galleryImage['title'] ?: $event['title'])); ?>" loading="lazy">
            </button>
            <?php endforeach; ?>
        </div>
        <div class="report-gallery-thumbnails" aria-label="Gallery thumbnails"><?php foreach ($galleryImages as $galleryIndex => $galleryImage): ?><button type="button" class="report-gallery-thumb<?php echo $galleryIndex===0?' active':''; ?>" data-gallery-index="<?php echo (int)$galleryIndex; ?>" aria-label="Open image <?php echo (int)$galleryIndex+1; ?>"><img src="<?php echo h(mediaBatchImageUrl($galleryBatch,$galleryImage,'xs')); ?>" alt="" loading="lazy"></button><?php endforeach; ?></div>
    </section>
    <div class="report-lightbox" id="report-lightbox" role="dialog" aria-modal="true" aria-label="Ride gallery image viewer">
        <button type="button" class="report-lightbox-close" aria-label="Close">&times;</button><button type="button" class="report-lightbox-nav report-lightbox-prev" aria-label="Previous image">&#8249;</button>
        <figure><img src="" alt=""><figcaption class="report-lightbox-caption"></figcaption></figure>
        <button type="button" class="report-lightbox-nav report-lightbox-next" aria-label="Next image">&#8250;</button>
    </div>
    <script type="application/json" id="report-gallery-data"><?php echo json_encode(array_map(static function(array $image) use ($galleryBatch,$galleryDefaultCaption): array { return ['src'=>mediaBatchImageUrl($galleryBatch,$image,'original'),'alt'=>(string)($image['alt_text']?:$image['title']?:$galleryDefaultCaption),'caption'=>(string)($image['caption']?:$image['title']?:$galleryDefaultCaption)]; }, $galleryImages), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
    <?php endif; ?>
    <?php if ($facebookGalleryUrl !== ''): ?>
    <section class="card-soft p-4 mt-4" aria-labelledby="facebook-gallery-heading">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3"><div><h2 class="h3 mb-1" id="facebook-gallery-heading">Facebook Gallery</h2><div class="text-muted small">More photographs from this ride on Facebook.</div></div><a class="btn btn-primary facebook-gallery-link" href="<?php echo h($facebookGalleryUrl); ?>" target="_blank" rel="noopener noreferrer">Open Facebook Gallery</a></div>
        <iframe class="facebook-gallery-frame" src="<?php echo h($facebookEmbedUrl); ?>" title="Facebook gallery for <?php echo h((string)$event['title']); ?>" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe>
        <div class="small text-muted text-center mt-2">If Facebook does not display the album here, use “Open Facebook Gallery” above.</div>
    </section>
    <?php endif; ?>
    <?php else: ?><div class="alert alert-info">This ride report could not be found.</div><?php endif; ?>
</div></main>
<?php include __DIR__ . '/views/footer.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<?php if ($galleryImages): ?><script>
(function(){const grid=document.getElementById('report-gallery'),box=document.getElementById('report-lightbox'),dataNode=document.getElementById('report-gallery-data'),shell=grid&&grid.closest('.report-gallery-shell');if(!grid||!box||!dataNode||!shell)return;const items=JSON.parse(dataNode.textContent||'[]'),image=box.querySelector('img'),caption=box.querySelector('.report-lightbox-caption'),thumbs=Array.from(shell.querySelectorAll('.report-gallery-thumbnails [data-gallery-index]'));let current=0,touchStart=0;function show(index){current=(index+items.length)%items.length;const item=items[current];image.src=item.src;image.alt=item.alt;caption.textContent=item.caption;thumbs.forEach((thumb,i)=>thumb.classList.toggle('active',i===current));box.classList.add('open');document.body.style.overflow='hidden';box.querySelector('.report-lightbox-prev').hidden=items.length<2;box.querySelector('.report-lightbox-next').hidden=items.length<2;}function close(){box.classList.remove('open');document.body.style.overflow='';image.src='';}shell.addEventListener('click',event=>{const button=event.target.closest('[data-gallery-index]');if(button)show(Number(button.dataset.galleryIndex));});shell.querySelector('[data-open-full-gallery]').addEventListener('click',()=>show(0));box.querySelector('.report-lightbox-close').addEventListener('click',close);box.querySelector('.report-lightbox-prev').addEventListener('click',()=>show(current-1));box.querySelector('.report-lightbox-next').addEventListener('click',()=>show(current+1));box.addEventListener('click',event=>{if(event.target===box)close();});box.addEventListener('wheel',event=>{if(!box.classList.contains('open')||items.length<2)return;event.preventDefault();show(current+(event.deltaY>0||event.deltaX>0?1:-1));},{passive:false});box.addEventListener('touchstart',event=>{touchStart=event.changedTouches[0].clientX;},{passive:true});box.addEventListener('touchend',event=>{const delta=event.changedTouches[0].clientX-touchStart;if(Math.abs(delta)>45)show(current+(delta<0?1:-1));},{passive:true});document.addEventListener('keydown',event=>{if(!box.classList.contains('open'))return;if(event.key==='Escape')close();if(event.key==='ArrowLeft')show(current-1);if(event.key==='ArrowRight')show(current+1);});})();
</script><?php endif; ?>
</body></html>
