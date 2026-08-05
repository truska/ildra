<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';

$basket = $_SESSION['basket'] ?? [];
$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basketCount = count($basket);
$members = $isLoggedIn ? array_values(array_filter(
    fetchMembersForUser($pdo, (int)($currentUser['id'] ?? 0)),
    static fn(array $member): bool => empty($member['is_linked'])
)) : [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_membership') {
    if (!$isLoggedIn) {
        $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please login to buy a membership.']];
        header('Location: ' . $basePath . '/account');
        exit;
    }

    $typeId = (int)($_POST['membership_type_id'] ?? 0);
    $membership = fetchMembershipTypeById($pdo, $typeId);
    if (!$membership) {
        $alerts[] = ['type' => 'danger', 'message' => 'Membership not found.'];
    } else {
        $memberIdRaw = (string)($_POST['member_id'] ?? '');
        $memberId = 0;
        $memberName = '';
        $memberNumber = null;

        if ($memberIdRaw === 'new') {
            $created = createMemberForUser(
                $pdo,
                (int)($currentUser['id'] ?? 0),
                (string)($_POST['new_member_first_name'] ?? ''),
                (string)($_POST['new_member_last_name'] ?? ''),
                (string)($_POST['new_member_dob'] ?? ''),
                $alerts
            );
            if ($created) {
                $memberId = (int)($created['id'] ?? 0);
                $memberName = trim((string)($created['first_name'] ?? '') . ' ' . (string)($created['last_name'] ?? ''));
                $memberNumber = $created['member_number'] ?? null;
            }
        } else {
            $memberId = (int)$memberIdRaw;
            foreach ($members as $m) {
                if ((int)($m['id'] ?? 0) === $memberId) {
                    $memberName = trim((string)($m['first_name'] ?? '') . ' ' . (string)($m['last_name'] ?? ''));
                    $memberNumber = $m['member_number'] ?? null;
                    break;
                }
            }
            if ($memberId <= 0 || $memberName === '') {
                $alerts[] = ['type' => 'danger', 'message' => 'Please select who this membership is for.'];
            }
        }

        // Derive membership year from the membership window (defaults to current year).
        $membershipYear = null;
        $starts = trim((string)($membership['membership_starts'] ?? ''));
        $ends = trim((string)($membership['membership_ends'] ?? ''));
        if ($starts !== '' && preg_match('/^(\\d{4})-\\d{2}-\\d{2}$/', $starts, $m1)) {
            $membershipYear = (int)$m1[1];
        } elseif ($ends !== '' && preg_match('/^(\\d{4})-\\d{2}-\\d{2}$/', $ends, $m2)) {
            $membershipYear = (int)$m2[1];
        } else {
            $membershipYear = (int)date('Y');
        }

        // Prevent duplicates in basket for the same member/year.
        foreach ($basket as $item) {
            if (($item['booking_type'] ?? '') === 'membership'
                && (int)($item['member_id'] ?? 0) === $memberId
                && (int)($item['membership_year'] ?? 0) === $membershipYear) {
                $alerts[] = ['type' => 'warning', 'message' => 'That member already has this year\'s membership in the basket.'];
                break;
            }
        }

        // Prevent duplicates against existing purchases for the same year (active/pending).
        if (!$alerts && $memberId > 0 && $pdo) {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM membership_purchases
                WHERE member_id = :mid
                  AND status <> 'expired'
                  AND (
                        (starts_at IS NOT NULL AND YEAR(starts_at) = :yr)
                     OR (starts_at IS NULL AND ends_at IS NOT NULL AND YEAR(ends_at) = :yr)
                     OR (starts_at IS NULL AND ends_at IS NULL AND purchased_at IS NOT NULL AND YEAR(purchased_at) = :yr)
                  )
                LIMIT 1
            ");
            $stmt->execute([':mid' => $memberId, ':yr' => $membershipYear]);
            if ($stmt->fetchColumn()) {
                $alerts[] = ['type' => 'warning', 'message' => 'That member already has a membership for this year.'];
            }
        }

        if ($alerts) {
            // fall through to render errors
        } else {
            $entry = [
                'id' => uniqid('mem', true),
                'booking_type' => 'membership',
                'membership_type_id' => $membership['id'],
                'membership_year' => $membershipYear,
                'member_id' => $memberId,
                'member_number' => $memberNumber,
                'member_name' => $memberName,
                'membership_name' => $membership['name'],
                'event_title' => 'Membership: ' . ($membership['name'] ?? ''),
                'class_label' => 'Membership: ' . ($membership['name'] ?? ''),
                'rider_name' => $memberName,
                'horse_name' => '',
                'contact_email' => $currentUser['email'] ?? '',
                'contact_phone' => '',
                'price' => $membership['cost'] ?? '0',
            ];
            $basket[] = $entry;
            $_SESSION['basket'] = $basket;
            $_SESSION['basket_last_added'] = time();
            saveBasketForSession($pdo, session_id(), $basket, $currentUser['id'] ?? null, $_SESSION['basket_last_added']);
            $_SESSION['flash_success'] = 'Membership added to basket.';
            header('Location: ' . $basePath . '/basket');
            exit;
        }
    }
}

$membershipTypes = fetchMembershipTypes($pdo, true);
$navItemEventsUrl = $basePath . '/events';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memberships | <?php echo h($siteSettings['hero_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --green: #146118;
            --green-alt: #1f7c24;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .page-hero {
            background: linear-gradient(120deg, rgba(20, 97, 24, 0.9), rgba(20, 97, 24, 0.75)), url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            padding: 2.5rem 0;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.12), transparent 32%);
            z-index: 0;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .section-title::before {
            content: '';
            width: 40px;
            height: 4px;
            border-radius: 999px;
            background: var(--green);
            display: inline-block;
        }
        .chip {
            background: rgba(20, 97, 24, 0.08);
            border: 1px solid rgba(20, 97, 24, 0.2);
            color: var(--green);
            border-radius: 999px;
            padding: 4px 10px;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .cta-row {
            border-radius: 14px;
            background: rgba(20, 97, 24, 0.06);
            padding: 1rem;
        }
        .btn-enter {
            min-width: 180px;
            box-shadow: 0 10px 30px rgba(20, 97, 24, 0.22);
        }
        .btn-secondary-quiet {
            border-color: #d1ded1;
            color: var(--muted);
        }
        .btn-secondary-quiet:hover {
            border-color: var(--green);
            color: var(--green);
        }
        .field-hint {
            color: rgba(71, 97, 70, 0.95);
        }
        .new-member-panel {
            border: 1px dashed rgba(20, 97, 24, 0.25);
            background: rgba(20, 97, 24, 0.04);
            border-radius: 14px;
            padding: 0.85rem;
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-white-50">Memberships</p>
            <h1 class="fw-bold mb-1">Join or renew</h1>
            <div class="text-white-50">Choose the right membership and add to your basket.</div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="section-title mb-0">Available memberships</div>
                </div>
                <?php if (!$membershipTypes): ?>
                    <div class="text-muted small">No memberships available right now.</div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($membershipTypes as $type): ?>
                            <div class="col-12">
	                                <div class="card-soft h-100 p-3">
	                                    <div class="d-flex justify-content-between align-items-start mb-2">
	                                        <div>
	                                            <div class="fw-bold"><?php echo h($type['name']); ?></div>
	                                        </div>
	                                        <div class="chip"><?php echo h(format_price($type['cost'] ?? 0)); ?></div>
	                                    </div>
	                                    <?php
	                                    $description = trim((string)($type['description'] ?? ''));
	                                    $name = trim((string)($type['name'] ?? ''));
	                                    if ($description !== '' && mb_strtolower($description) !== mb_strtolower($name)):
	                                    ?>
	                                        <div class="text-muted small mb-2"><?php echo h($description); ?></div>
	                                    <?php endif; ?>
	                                    <div class="text-muted small mb-3">
	                                        <span class="field-hint">Membership period:</span>
	                                        <?php echo h(format_display_date($type['membership_starts'] ?? null, '')); ?> — <?php echo h(format_display_date($type['membership_ends'] ?? null, '')); ?>
	                                    </div>
	                                    <?php if ($isLoggedIn): ?>
	                                        <form method="POST">
	                                            <input type="hidden" name="action" value="add_membership">
	                                            <input type="hidden" name="membership_type_id" value="<?php echo (int)$type['id']; ?>">
	                                            <div class="row g-2 align-items-end mb-2">
	                                                <div class="col-12 col-md-6">
	                                                    <label class="form-label small mb-1">Membership for</label>
	                                                    <select class="form-select form-select-sm js-member-select person-type-select" name="member_id" required>
	                                                        <option value="" selected disabled>Select a member…</option>
	                                                        <?php if ($members): ?>
	                                                            <optgroup label="Existing members">
	                                                                <?php foreach ($members as $member): ?>
	                                                                    <?php
	                                                                    $number = trim((string)($member['member_number'] ?? ''));
	                                                                    $nameLabel = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
                                                                        $personType = personRecordType($member, $currentUser ?? []);
	                                                                    $optionLabel = personRecordTypeMarker($personType) . ' ' . ($number !== '' ? ($number . ' · ' . $nameLabel) : $nameLabel);
	                                                                    ?>
	                                                                    <option value="<?php echo (int)($member['id'] ?? 0); ?>">
	                                                                        <?php echo h($optionLabel !== '' ? $optionLabel : ('Member #' . (int)($member['id'] ?? 0))); ?>
	                                                                    </option>
	                                                                <?php endforeach; ?>
	                                                            </optgroup>
	                                                        <?php endif; ?>
	                                                        <optgroup label="Add a new member">
	                                                            <option value="new">New member…</option>
	                                                        </optgroup>
	                                                    </select>
	                                                    <div class="text-muted small mt-1">Choose who this membership will be assigned to.</div>
	                                                </div>
	                                                <div class="col-12 js-new-member-panel" style="display:none">
	                                                    <div class="new-member-panel">
	                                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
	                                                            <div class="fw-bold small">New member details</div>
	                                                            <div class="text-muted small">Required</div>
	                                                        </div>
	                                                        <div class="row g-2">
	                                                            <div class="col-12 col-md-4">
	                                                                <label class="form-label small mb-1">First name</label>
	                                                                <input class="form-control form-control-sm js-new-member-field" type="text" name="new_member_first_name" autocomplete="given-name">
	                                                            </div>
	                                                            <div class="col-12 col-md-4">
	                                                                <label class="form-label small mb-1">Last name</label>
	                                                                <input class="form-control form-control-sm js-new-member-field" type="text" name="new_member_last_name" autocomplete="family-name">
	                                                            </div>
	                                                            <div class="col-12 col-md-4">
	                                                                <label class="form-label small mb-1">Date of birth</label>
	                                                                <input class="form-control form-control-sm js-new-member-field" type="date" name="new_member_dob">
	                                                            </div>
	                                                        </div>
	                                                    </div>
	                                                </div>
	                                            </div>
	                                            <div class="cta-row d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mt-2">
	                                                <div class="text-muted small">
	                                                    Total: <?php echo h(format_price($type['cost'] ?? 0)); ?>
	                                                </div>
	                                                <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 w-100">
	                                                    <div class="d-flex gap-2 w-100">
	                                                        <button class="btn btn-success btn-enter w-100" type="submit">Add membership</button>
	                                                        <a class="btn btn-outline-secondary btn-secondary-quiet w-100" href="<?php echo h($basePath); ?>/account#my-memberships">View memberships</a>
	                                                    </div>
	                                                </div>
	                                            </div>
	                                        </form>
	                                    <?php else: ?>
	                                        <div class="cta-row d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mt-2">
	                                            <div class="text-muted small">Sign in to buy or renew memberships.</div>
	                                            <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 w-100">
	                                                <a class="btn btn-success btn-enter w-100" href="<?php echo h($basePath); ?>/account">Login / Register</a>
	                                            </div>
	                                        </div>
	                                    <?php endif; ?>
	                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php if ($isLoggedIn): ?>
        <script>
        document.addEventListener('change', (e) => {
            const select = e.target && e.target.classList && e.target.classList.contains('js-member-select') ? e.target : null;
            if (!select) return;
            const form = select.closest('form');
            if (!form) return;
            const showNew = select.value === 'new';
            const panel = form.querySelector('.js-new-member-panel');
            if (panel) {
                panel.style.display = showNew ? '' : 'none';
            }
            form.querySelectorAll('input[name="new_member_first_name"], input[name="new_member_last_name"], input[name="new_member_dob"]').forEach((input) => {
                if (!input) return;
                input.required = showNew;
            });
        });
        </script>
    <?php endif; ?>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
