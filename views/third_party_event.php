<?php
// This page deliberately ends the event request before internal entry handling.
$thirdPartyVisible = ($event['status'] ?? '') === 'published' || $canViewAdmin;
if (!$thirdPartyVisible) { http_response_code(404); $event = null; }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $event ? h($event['title']) : 'Event not found'; ?> | <?php echo h($siteSettings['hero_title']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>:root{--green:#146118;--green-alt:#1f7c24;--cream:#f7f8f1;--text-main:#0c2a12;--muted:#476146}body{background:var(--cream);color:var(--text-main);font-family:'Manrope',system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;line-height:1.7}.page-hero{background:linear-gradient(120deg,rgba(20,97,24,.9),rgba(20,97,24,.75)),url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;color:#fff;padding:2.5rem 0}.card-soft{border-radius:18px;border:1px solid rgba(0,0,0,.04);box-shadow:0 18px 48px rgba(0,0,0,.08);background:#fff}@media(max-width:767px){.page-hero{padding:1.75rem 0}}.third-party-image{display:block;width:100%;height:auto;max-height:600px;object-fit:contain;border-radius:12px}.third-party-copy{overflow-wrap:anywhere}.third-party-copy img{max-width:100%;height:auto}</style>
<?php include __DIR__ . '/header_styles.php'; ?></head><body>
<?php include __DIR__ . '/header.php'; ?>
<header class="page-hero"><div class="container">
    <p class="mb-1 text-uppercase small fw-bold text-white-50">3rd Party Event</p>
    <h1 class="fw-bold mb-1"><?php echo $event ? h($event['title']) : 'Event not found'; ?></h1>
    <?php if ($event): ?><div class="text-white-50"><?php echo h(format_display_date($event['event_date'])); ?> · <?php echo h($event['venue']); ?></div><?php endif; ?>
</div></header>
<main class="py-5"><div class="container">
<?php echo render_back_navigation($basePath . '/events', 'Back to Events', 'Event navigation'); ?>
<?php if ($event): ?>
<?php if ($event['status'] !== 'published'): ?><div class="alert alert-warning">Admin preview — this event is a draft.</div><?php endif; ?>
<section class="card-soft p-4 mb-4" aria-labelledby="third-party-event-title">
<div class="d-flex align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0" id="third-party-event-title"><?php echo h($event['title']); ?></h2>
    <?php if ($canEditPublicEvent): ?><a class="event-editor-tool" href="<?php echo h($basePath); ?>/admin/third_party_event_edit.php?id=<?php echo (int)$event['id']; ?>" target="_blank" rel="noopener" title="Edit event" aria-label="Edit event"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></a><?php endif; ?>
</div>
<div class="row g-4 align-items-start event-summary-row">
<div class="col-12 col-lg-8"><div class="third-party-copy mb-4"><?php echo render_wysiwyg((string)$event['description']); ?></div>
<p class="text-muted small">This event is run by another organisation. Please use their website for entries and enquiries.</p>
<a class="btn button1" href="<?php echo h($event['external_entry_url']); ?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Enter on organiser’s website<span class="visually-hidden"> (opens in a new tab)</span></a>
</div>
<div class="col-12 col-lg-4">
<?php if ($eventImage): ?><img class="third-party-image" src="<?php echo h(mediaBatchImageUrl($eventImageBatch, $eventImage, 'lg')); ?>" alt="<?php echo h($eventImage['alt_text'] ?: $event['title']); ?>"><?php else: echo mediaPlaceholderHtml($basePath); endif; ?>
</div></div>
</section>
<?php else: ?><div class="alert alert-info">We could not find that event.</div><?php endif; ?>
</div></main><?php include __DIR__ . '/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body></html>
