<?php
declare(strict_types=1);
if (!isset($activeTab, $pdo) || $activeTab !== 'events' || !function_exists('canManageArchives') || !canManageArchives((string)($currentUser['role'] ?? ''))) { http_response_code(404); exit; }
$events = array_values(array_filter(fetchEvents($pdo, false, true), static fn(array $event): bool => ($event['archive_state'] ?? 'active') === 'archived'));
$canRestore = adminActionAllowed($pdo, 'events.delete', strtolower((string)($currentUser['role'] ?? '')));
?>
<h3 class="h5">Archived events</h3>
<p class="text-muted">Events archived without entries, rider notes or ride reports are retained here for later deletion. Restore returns an event as an unpublished draft.</p>
<div class="card-soft p-3"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date</th><th>Event</th><th>Venue</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($events as $event): ?>
<tr><td><?php echo h(admin_display_date($event['event_date'])); ?></td><td><?php echo h($event['title']); ?></td><td><?php echo h($event['venue']); ?></td><td>
<?php if ($canRestore): ?><form method="post" data-confirm-message="Restore this archived event as an unpublished draft?" data-confirm-button="Restore As Draft"><?php echo csrf_input(); ?><input type="hidden" name="tab" value="events"><input type="hidden" name="action" value="restore_event"><input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>"><button class="btn btn-sm btn-outline-success">Restore As Draft</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$events): ?><tr><td colspan="4" class="text-muted">No archived events.</td></tr><?php endif; ?>
</tbody></table></div></div>
