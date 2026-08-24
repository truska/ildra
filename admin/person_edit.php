<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$currentRole = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($currentRole, ['superadmin', 'admin', 'manager'], true)) {
    header('Location: index.php');
    exit;
}

ensureMembershipTables($pdo);
$personId = max(0, (int)($_GET['id'] ?? $_POST['person_id'] ?? 0));
$peopleReturnUrl = (string)($_SESSION['admin_list_returns']['people'] ?? 'people.php');
if (!preg_match('/^people\.php(?:\?[^#]*)?$/', $peopleReturnUrl)) $peopleReturnUrl = 'people.php';
$peopleReturnWithRow = $peopleReturnUrl . ($personId > 0 ? '#person-' . $personId : '');
$person = null;
if ($pdo && $personId > 0) {
    $stmt = $pdo->prepare("SELECT p.*, u.email AS owner_email FROM people p LEFT JOIN users u ON u.id = p.owner_user_id WHERE p.id = :id LIMIT 1");
    $stmt->execute([':id' => $personId]);
    $person = $stmt->fetch() ?: null;
}
if (!$person) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Person not found.']];
    header('Location: ' . $peopleReturnUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $dob = trim((string)($_POST['dob'] ?? ''));
    $memberNumber = trim((string)($_POST['member_number'] ?? ''));
    $juniorSenior = trim((string)($_POST['junior_or_senior'] ?? ''));
    $generalEmailOptIn = !empty($_POST['general_email_opt_in']) ? 1 : 0;
    $rideNoticeOptIn = !empty($_POST['ride_notice_opt_in']) ? 1 : 0;
    $renewalReminderOptIn = !empty($_POST['renewal_reminder_opt_in']) ? 1 : 0;

    if ($firstName === '' || $lastName === '') $alerts[] = ['type' => 'danger', 'message' => 'First name and last name are required.'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid email address.'];
    if ($dob !== '' && !DateTimeImmutable::createFromFormat('Y-m-d', $dob)) $alerts[] = ['type' => 'danger', 'message' => 'Enter a valid date of birth.'];
    if ($memberNumber !== '' && (!ctype_digit($memberNumber) || (int)$memberNumber <= 0)) $alerts[] = ['type' => 'danger', 'message' => 'Member number must be a positive number.'];
    if (!in_array($juniorSenior, ['', 'Junior', 'Senior'], true)) $juniorSenior = '';

    if (!$alerts) {
        try {
            $update = $pdo->prepare("UPDATE people SET member_number = :member_number, first_name = :first_name, last_name = :last_name, dob = :dob, email = :email, general_email_opt_in = :general_opt_in, ride_notice_opt_in = :ride_notice_opt_in, renewal_reminder_opt_in = :renewal_opt_in, phone = :phone, address = :address, postcode = :postcode, junior_or_senior = :junior_or_senior, emergency_contact_name = :emergency_name, emergency_contact_phone = :emergency_phone, is_archived = :is_archived, updated_at = NOW() WHERE id = :id LIMIT 1");
            $update->execute([
                ':member_number' => $memberNumber !== '' ? (int)$memberNumber : null,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':dob' => $dob !== '' ? $dob : null,
                ':email' => $email !== '' ? $email : null,
                ':general_opt_in' => $generalEmailOptIn,
                ':ride_notice_opt_in' => $rideNoticeOptIn,
                ':renewal_opt_in' => $renewalReminderOptIn,
                ':phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
                ':address' => trim((string)($_POST['address'] ?? '')) ?: null,
                ':postcode' => trim((string)($_POST['postcode'] ?? '')) ?: null,
                ':junior_or_senior' => $juniorSenior !== '' ? $juniorSenior : null,
                ':emergency_name' => trim((string)($_POST['emergency_contact_name'] ?? '')) ?: null,
                ':emergency_phone' => trim((string)($_POST['emergency_contact_phone'] ?? '')) ?: null,
                ':is_archived' => !empty($_POST['is_archived']) ? 1 : 0,
                ':id' => $personId,
            ]);
            $_SESSION['flash_success'] = 'Person updated.';
            header('Location: ' . $peopleReturnWithRow);
            exit;
        } catch (PDOException $e) {
            $alerts[] = ['type' => 'danger', 'message' => 'Could not update this person. Check that the member number is not already in use.'];
        }
    }
    $person = array_merge($person, $_POST);
}

admin_layout_start('Edit person', 'people');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="small text-muted">People</div><h5 class="mb-0">Edit person</h5></div>
    <a class="btn btn-outline-secondary" href="<?php echo h($peopleReturnWithRow); ?>">Back to people</a>
</div>
<div class="card-soft p-4">
    <form method="post" class="row g-3">
        <input type="hidden" name="person_id" value="<?php echo (int)$personId; ?>">
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">First name</label><input class="form-control" name="first_name" required value="<?php echo h((string)($person['first_name'] ?? '')); ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">Last name</label><input class="form-control" name="last_name" required value="<?php echo h((string)($person['last_name'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Member number</label><input type="number" min="1" class="form-control" name="member_number" value="<?php echo h((string)($person['member_number'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Date of birth</label><input type="date" class="form-control" name="dob" value="<?php echo h((string)($person['dob'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Junior or Senior</label><select class="form-select" name="junior_or_senior"><option value="">Not set</option><option value="Junior" <?php echo ($person['junior_or_senior'] ?? '') === 'Junior' ? 'selected' : ''; ?>>Junior</option><option value="Senior" <?php echo ($person['junior_or_senior'] ?? '') === 'Senior' ? 'selected' : ''; ?>>Senior</option></select></div>
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" class="form-control" name="email" value="<?php echo h((string)($person['email'] ?? '')); ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label fw-semibold">Phone</label><input class="form-control" name="phone" value="<?php echo h((string)($person['phone'] ?? '')); ?>"></div>
        <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="personGeneralEmail" name="general_email_opt_in" value="1" <?php echo !empty($person['general_email_opt_in']) ? 'checked' : ''; ?>><label class="form-check-label" for="personGeneralEmail">Subscribed to general news and announcements</label></div>
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="personRideNotice" name="ride_notice_opt_in" value="1" <?php echo !empty($person['ride_notice_opt_in']) ? 'checked' : ''; ?>><label class="form-check-label" for="personRideNotice">Subscribed to the weekly Ride Notice</label></div>
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="personRenewalReminder" name="renewal_reminder_opt_in" value="1" <?php echo !empty($person['renewal_reminder_opt_in'] ?? 1) ? 'checked' : ''; ?>><label class="form-check-label" for="personRenewalReminder">Receives renewal reminders</label></div>
            <div class="form-text">Only record a subscription where this person has agreed to receive it. Essential entry-related messages are separate.</div>
        </div>
        <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea class="form-control" name="address" rows="3"><?php echo h((string)($person['address'] ?? '')); ?></textarea></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Postcode</label><input class="form-control" name="postcode" value="<?php echo h((string)($person['postcode'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Emergency contact</label><input class="form-control" name="emergency_contact_name" value="<?php echo h((string)($person['emergency_contact_name'] ?? '')); ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fw-semibold">Emergency phone</label><input class="form-control" name="emergency_contact_phone" value="<?php echo h((string)($person['emergency_contact_phone'] ?? '')); ?>"></div>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="personArchived" name="is_archived" value="1" <?php echo !empty($person['is_archived']) ? 'checked' : ''; ?>><label class="form-check-label" for="personArchived">Archived</label></div></div>
        <div class="col-12"><div class="small text-muted mb-3">Owned by: <?php echo h((string)($person['owner_email'] ?? 'Unknown user')); ?></div><button class="btn btn-success">Save changes</button> <a class="btn btn-outline-secondary" href="<?php echo h($peopleReturnWithRow); ?>">Cancel</a></div>
    </form>
</div>
<?php admin_layout_end(); ?>
