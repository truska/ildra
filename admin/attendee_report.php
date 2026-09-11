<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$isAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'manager', 'organiser'], true);
if (!$isAdmin) { header('Location: index.php'); exit; }
$eventId = max(0, (int)($_GET['event_id'] ?? 0));
$event = $eventId ? fetchEventById($pdo, $eventId) : null;
if (!$event || ($event['form_profile'] ?? 'ride') !== 'attendees') { admin_layout_start('Attendee organiser list', 'events'); echo '<div class="alert alert-warning">Choose an attendee-profile event.</div>'; admin_layout_end(); exit; }

$stmt = $pdo->prepare("SELECT bi.*, b.booking_ref, b.created_at AS booking_created_at, b.contact_name, b.contact_email, b.contact_phone FROM booking_items bi LEFT JOIN bookings b ON b.new_id=bi.booking_id WHERE bi.event_id=:event_id AND COALESCE(bi.is_withdrawn,0)=0 ORDER BY b.created_at ASC, bi.id ASC");
$stmt->execute([':event_id'=>$eventId]);
$entries = array_map('hydrate_booking_item', $stmt->fetchAll() ?: []);
$choiceLabels = []; $summary = [];
foreach ($entries as $entry) foreach (attendee_booking_details((array)($entry['metadata'] ?? [])) as $attendee) foreach ($attendee['choices'] as $choice) {
    [$label, $value] = array_pad(explode(': ', $choice, 2), 2, '');
    if ($label !== '') { $choiceLabels[$label] = true; if ($value !== '') $summary[$label][$value] = ($summary[$label][$value] ?? 0) + 1; }
}
$choiceLabels = array_keys($choiceLabels);
$print = !empty($_GET['print']);
if ($print): ?>
<!doctype html><html><head><meta charset="utf-8"><title><?php echo h($event['title']); ?> organiser list</title><style>@page{size:A4 landscape;margin:10mm}body{font:12px Arial;color:#111}table{width:100%;border-collapse:collapse}th,td{border:1px solid #333;padding:6px;vertical-align:top}th{background:#eee;text-align:left}.actions{margin-bottom:12px}@media print{.actions{display:none}}</style></head><body><div class="actions"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<h1><?php echo h($event['title']); ?></h1><p>Event organiser list · <?php echo h(format_display_date($event['event_date'] ?? null, 'Date TBC')); ?></p>
<table><thead><tr><th>Booking / contact</th><th>Attendee</th><th>Ticket</th><?php foreach ($choiceLabels as $label): ?><th><?php echo h($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($entries as $entry): $attendees=attendee_booking_details((array)($entry['metadata'] ?? [])); $span=max(1,count($attendees)); foreach ($attendees as $index=>$attendee): ?><tr><?php if($index===0): ?><td rowspan="<?php echo $span; ?>"><strong><?php echo h((string)($entry['booking_ref'] ?? '')); ?></strong><br><?php echo h((string)($entry['contact_name'] ?? '')); ?><br><?php echo h((string)($entry['contact_email'] ?? '')); ?></td><?php endif; ?><td><?php echo h($attendee['name']); ?></td><td><?php echo h($attendee['ticket']); ?></td><?php foreach($choiceLabels as $label): $value=''; foreach($attendee['choices'] as $choice) if(str_starts_with($choice,$label.': ')) $value=substr($choice,strlen($label)+2); ?><td><?php echo h($value ?: '—'); ?></td><?php endforeach; ?></tr><?php endforeach; endforeach; ?></tbody></table>
<h2>Catering summary</h2><?php foreach($summary as $label=>$values): ?><h3><?php echo h($label); ?></h3><ul><?php foreach($values as $value=>$count): ?><li><?php echo h($value); ?>: <?php echo (int)$count; ?></li><?php endforeach; ?></ul><?php endforeach; ?></body></html><?php exit; endif;
admin_layout_start('Attendee organiser list', 'events');
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted"><?php echo h(format_display_date($event['event_date'] ?? null, 'Date TBC')); ?></div><h5 class="mb-0"><?php echo h($event['title']); ?> — organiser list</h5></div><div><a class="btn btn-outline-primary" target="_blank" href="attendee_report.php?event_id=<?php echo $eventId; ?>&print=1">Print organiser list</a> <a class="btn btn-outline-secondary" href="event_entries.php?event_id=<?php echo $eventId; ?>">Back to entries</a></div></div>
<div class="card-soft p-3 mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Booking / contact</th><th>Attendee</th><th>Ticket</th><?php foreach($choiceLabels as $label): ?><th><?php echo h($label); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($entries as $entry): $attendees=attendee_booking_details((array)($entry['metadata'] ?? []));$span=max(1,count($attendees));foreach($attendees as $index=>$attendee): ?><tr><?php if($index===0): ?><td rowspan="<?php echo $span; ?>"><strong><?php echo h((string)($entry['booking_ref'] ?? '')); ?></strong><br><?php echo h((string)($entry['contact_name'] ?? '')); ?><br><span class="text-muted"><?php echo h((string)($entry['contact_email'] ?? '')); ?></span></td><?php endif; ?><td><?php echo h($attendee['name']); ?></td><td><?php echo h($attendee['ticket']); ?></td><?php foreach($choiceLabels as $label): $value='';foreach($attendee['choices'] as $choice)if(str_starts_with($choice,$label.': '))$value=substr($choice,strlen($label)+2);?><td><?php echo h($value ?: '—'); ?></td><?php endforeach; ?></tr><?php endforeach; endforeach; ?></tbody></table></div></div>
<div class="card-soft p-3"><h6>Catering summary</h6><div class="row g-3"><?php foreach($summary as $label=>$values): ?><div class="col-md-4"><strong><?php echo h($label); ?></strong><ul class="mb-0"><?php foreach($values as $value=>$count): ?><li><?php echo h($value); ?>: <?php echo (int)$count; ?></li><?php endforeach; ?></ul></div><?php endforeach; ?></div></div>
<?php admin_layout_end();
