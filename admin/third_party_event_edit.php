<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
if (!$isAdmin) { http_response_code(403); exit('Only admins can manage events.'); }
$eventId = max(0, (int)($_GET['id'] ?? 0));
$event = $eventId ? fetchEventById($pdo, $eventId) : null;
if ($eventId && (!$event || !isThirdPartyEvent($event))) { http_response_code(404); exit('Third-party event not found.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $savedId = saveThirdPartyEvent($pdo, $_POST, $alerts, $eventId);
    if ($savedId) {
        $eventId = $savedId;
        if (isset($_FILES['image']) && (int)$_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $batch = mediaBatchGetOrCreate($pdo, 'event_images', 'event', $eventId, 'Event image: ' . trim((string)$_POST['title']), 'events');
            $errors = [];
            if ($batch) {
                $images = mediaBatchImages($pdo, (int)$batch['id']);
                $sizes = ['original' => null, 'lg' => 1200, 'sm' => 300, 'xs' => 150];
                if ($images) mediaBatchReplaceImage($pdo, $batch, (int)$images[0]['id'], $_FILES['image'], $sizes, $errors);
                else mediaBatchUpload($pdo, $batch, $_FILES['image'], $sizes, $errors);
            } else $errors[] = 'Could not prepare event image storage.';
            foreach ($errors as $error) $alerts[] = ['type' => 'danger', 'message' => $error];
        }
        if (!$alerts) {
            $_SESSION['flash_success'] = 'Third-party event saved.';
            header('Location: events.php?view=third-party#event-' . $eventId);
            exit;
        }
    }
    $event = array_replace($event ?: [], $_POST);
}
$event = $event ?: ['title' => '', 'event_date' => '', 'venue' => '', 'description' => '', 'external_entry_url' => '', 'status' => 'draft'];
$batch = $eventId ? mediaBatchFind($pdo, 'event_images', 'event', $eventId) : null;
$images = $batch ? mediaBatchImages($pdo, (int)$batch['id']) : [];
admin_layout_start($eventId ? 'Edit 3rd Party Event' : 'Add 3rd Party Event', 'events');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0"><?php echo $eventId ? 'Edit' : 'Add'; ?> 3rd Party Event</h5><a class="btn btn-outline-secondary" href="events.php?view=third-party">Back to 3rd Party Events</a></div>
<div class="card-soft p-4">
<p class="small text-muted">For rides and events run by other organisations. Entries are handled on the linked website.</p>
<form method="post" action="third_party_event_edit.php<?php echo $eventId ? '?id=' . $eventId : ''; ?>" enctype="multipart/form-data" class="row g-3">
<input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
<div class="col-12"><label class="form-label" for="event-title">Heading</label><input id="event-title" class="form-control" name="title" maxlength="255" required value="<?php echo h($event['title']); ?>"></div>
<div class="col-md-6"><label class="form-label" for="event-date">Date</label><input id="event-date" class="form-control" type="date" name="event_date" required value="<?php echo h($event['event_date']); ?>"></div>
<div class="col-md-6"><label class="form-label" for="event-location">Location</label><input id="event-location" class="form-control" name="venue" maxlength="255" required value="<?php echo h($event['venue']); ?>"></div>
<div class="col-12"><label class="form-label" for="event-text">Text</label><textarea id="event-text" class="form-control wysiwyg-field" name="description" rows="8"><?php echo h($event['description']); ?></textarea></div>
<div class="col-12"><label class="form-label" for="event-image">Image</label><?php if ($images): ?><div class="mb-2"><img src="<?php echo h(mediaBatchImageUrl($batch, $images[0], 'sm')); ?>" alt="Current event image" class="rounded" style="max-width:300px;max-height:200px"></div><?php endif; ?><input id="event-image" class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><div class="form-text">Optional. Upload a new image to replace the current one.</div></div>
<div class="col-12"><label class="form-label" for="event-link">Entry Link URL</label><input id="event-link" class="form-control" type="url" name="external_entry_url" maxlength="2048" placeholder="https://" required value="<?php echo h($event['external_entry_url']); ?>"></div>
<div class="col-md-6"><label class="form-label" for="event-status">Status</label><select id="event-status" class="form-select" name="status"><option value="draft" <?php echo $event['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option><option value="published" <?php echo $event['status'] === 'published' ? 'selected' : ''; ?>>Published</option></select></div>
<div class="col-12"><button class="btn btn-success">Save 3rd Party Event</button></div>
</form></div>
<?php render_tinymce_bootstrap(); ?>
<script>if (window.tinymce) tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end(); ?>
