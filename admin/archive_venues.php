<?php
declare(strict_types=1);
if (!isset($activeTab, $pdo) || $activeTab !== 'venues' || !function_exists('canManageArchives') || !canManageArchives((string)($currentUser['role'] ?? ''))) { http_response_code(404); exit; }
$venues = array_values(array_filter(fetchVenues($pdo, true), static fn(array $venue): bool => ($venue['archive_state'] ?? 'active') === 'archived'));
$canRestore = (($currentUser['role'] ?? '') === 'admin') || ((int)($currentUser['level'] ?? 0) >= 4);
?>
<h3 class="h5">Archived venues</h3>
<p class="text-muted">Venues archived without linked events are retained here for later deletion. Restore returns a venue as a draft; review and save it in Venues before using it for new events.</p>
<div class="card-soft p-3"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Venue</th><th>Address</th><th>Postcode</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($venues as $venue): ?>
<tr><td><?php echo h($venue['name']); ?></td><td><?php echo h($venue['address'] ?? ''); ?></td><td><?php echo h($venue['postcode'] ?? ''); ?></td><td>
<?php if ($canRestore): ?><form method="post" data-confirm-message="Restore this venue as a draft?" data-confirm-button="Restore As Draft"><?php echo csrf_input(); ?><input type="hidden" name="tab" value="venues"><input type="hidden" name="action" value="restore_venue"><input type="hidden" name="id" value="<?php echo (int)$venue['id']; ?>"><button class="btn btn-sm btn-outline-success">Restore As Draft</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$venues): ?><tr><td colspan="4" class="text-muted">No archived venues.</td></tr><?php endif; ?>
</tbody></table></div></div>
