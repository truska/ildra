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
<style>:root{--green:#146118;--green-alt:#1f7c24;--cream:#f7f8f1;--text-main:#0c2a12;--muted:#476146}body{background:var(--cream);color:var(--text-main);font-family:'Manrope',system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}.third-party-image{display:block;width:100%;height:auto;max-height:600px;object-fit:contain;border-radius:12px}.third-party-copy{overflow-wrap:anywhere}.third-party-copy img{max-width:100%;height:auto}</style>
<?php include __DIR__ . '/header_styles.php'; ?></head><body>
<?php include __DIR__ . '/header.php'; ?>
<header class="py-4 bg-light border-bottom"><div class="container"><div class="row align-items-center g-3"><div class="col-12 col-lg-9"><div class="small text-uppercase text-muted fw-bold">3rd Party Event</div><h1 class="fw-bold mb-1"><?php echo $event ? h($event['title']) : 'Event not found'; ?></h1><?php if ($event): ?><div class="text-muted"><?php echo h(format_display_date($event['event_date'])); ?> · <?php echo h($event['venue']); ?></div><?php endif; ?></div><?php include __DIR__ . '/page_heading_actions.php'; ?></div></div></header>
<main class="py-4"><div class="container">
<div class="d-flex justify-content-end align-items-start mb-3">
    <div class="d-flex flex-column align-items-end gap-2">
        <a title="ILDRA Events Calendar" class="btn btn-outline-success btn-sm" href="<?php echo h($basePath); ?>/events">Back to Events</a>
        <?php if ($event && $canEditPublicEvent): ?><a class="event-editor-tool" href="<?php echo h($basePath); ?>/admin/third_party_event_edit.php?id=<?php echo (int)$event['id']; ?>" target="_blank" rel="noopener" title="Edit event" aria-label="Edit event"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></a><?php endif; ?>
    </div>
</div>
<?php if ($event): ?>
<?php if ($event['status'] !== 'published'): ?><div class="alert alert-warning">Admin preview — this event is a draft.</div><?php endif; ?>
<div class="row g-4">
<div class="col-12 col-lg-8"><div class="third-party-copy mb-4"><?php echo render_wysiwyg((string)$event['description']); ?></div>
<p class="text-muted small">This event is run by another organisation. Please use their website for entries and enquiries.</p>
<a class="btn button1" href="<?php echo h($event['external_entry_url']); ?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Enter on organiser’s website<span class="visually-hidden"> (opens in a new tab)</span></a>
</div>
<div class="col-12 col-lg-4<?php echo $eventImage ? '' : ' d-none d-lg-block'; ?>">
<?php if ($eventImage): ?><img class="third-party-image" src="<?php echo h(mediaBatchImageUrl($eventImageBatch, $eventImage, 'lg')); ?>" alt="<?php echo h($eventImage['alt_text'] ?: $event['title']); ?>"><?php endif; ?>
</div></div>
<?php else: ?><div class="alert alert-info">We could not find that event.</div><?php endif; ?>
</div></main><?php include __DIR__ . '/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body></html>
