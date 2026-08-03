<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$isAdmin = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
$sourceEventId = (int)($_POST['source_event_id'] ?? $_GET['source_id'] ?? 0);
$sourceEvent = $sourceEventId > 0 ? fetchEventById($pdo, $sourceEventId) : null;
$rideDate = trim((string)($_POST['ride_date'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $alerts[] = ['type' => 'danger', 'message' => 'Only admins can duplicate events.'];
    } elseif (!$sourceEvent) {
        $alerts[] = ['type' => 'danger', 'message' => 'Choose a valid event to copy.'];
    } else {
        $newEventId = duplicateEventAsDraft($pdo, $sourceEventId, $rideDate, $alerts);
        if ($newEventId) {
            $_SESSION['flash_success'] = 'Draft event created from template.';
            header('Location: event_edit.php?id=' . (int)$newEventId);
            exit;
        }
    }
}

admin_layout_start('Duplicate Event', 'events');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <div class="small text-muted">Create a new draft from an existing ride</div>
        <h5 class="mb-0">Duplicate Event</h5>
    </div>
    <div>
        <a class="btn btn-outline-secondary" href="events.php">Back to events</a>
    </div>
</div>

<?php if (!$sourceEvent): ?>
    <div class="card-soft p-4">
        <p class="mb-3 text-muted">The event you tried to copy could not be found.</p>
        <a class="btn btn-outline-secondary" href="events.php">Back to events</a>
    </div>
<?php else: ?>
    <div class="card-soft p-4" style="max-width: 760px;">
        <div class="mb-4">
            <div class="small text-muted">Copying from</div>
            <div class="fw-semibold"><?php echo h($sourceEvent['title'] ?? 'Untitled event'); ?></div>
            <div class="text-muted small">
                <?php echo h(format_display_date($sourceEvent['event_date'] ?? null, 'Date TBC')); ?>
                <?php if (!empty($sourceEvent['event_type_name'] ?? '')): ?>
                    · <?php echo h($sourceEvent['event_type_name']); ?>
                <?php endif; ?>
                <?php if (!empty($sourceEvent['venue'] ?? '')): ?>
                    · <?php echo h($sourceEvent['venue']); ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="source_event_id" value="<?php echo (int)$sourceEventId; ?>">

            <div class="mb-3">
                <label class="form-label" for="ride_date">Ride date</label>
                <input
                    class="form-control"
                    id="ride_date"
                    name="ride_date"
                    type="date"
                    required
                    value="<?php echo h($rideDate); ?>"
                    style="max-width: 220px;"
                >
                <div class="form-text">
                    This creates a draft and reapplies the normal date defaults for start/end times and entry open/close windows.
                </div>
            </div>

            <div class="small text-muted mb-4">
                Title, organiser, venue, entry limit, entry form builder, and event type copy over. Classes are carried over using current default prices for this event type where matches are available.
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-success" type="submit">Create draft</button>
                <a class="btn btn-outline-secondary" href="events.php">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>
<?php
admin_layout_end();
