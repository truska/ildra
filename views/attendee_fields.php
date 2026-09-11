<?php if ((string)$i === '__INDEX__') $a = []; ?>
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">First name</label><input required class="form-control" name="attendees[<?php echo h((string)$i); ?>][first_name]" value="<?php echo h((string)($a['first_name'] ?? '')); ?>"></div>
    <div class="col-md-4"><label class="form-label">Surname</label><input required class="form-control" name="attendees[<?php echo h((string)$i); ?>][last_name]" value="<?php echo h((string)($a['last_name'] ?? '')); ?>"></div>
    <div class="col-md-4"><label class="form-label">Ticket type</label><select required class="form-select" name="attendees[<?php echo h((string)$i); ?>][ticket]"><option value="">Choose...</option><?php foreach ($tickets as $t): ?><option value="<?php echo h((string)$t['value']); ?>" data-price="<?php echo h((string)price_to_number($t['price'] ?? 0)); ?>" <?php echo (($a['ticket'] ?? '') === (string)$t['value']) ? 'selected' : ''; ?>><?php echo h($t['label'].' — '.format_price(price_to_number($t['price'] ?? 0))); ?></option><?php endforeach; ?></select></div>
</div>
<?php attendee_options($perPerson, 'attendees['.$i.'][choices]', (array)($a['choices'] ?? [])); ?>
