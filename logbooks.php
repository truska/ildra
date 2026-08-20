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
if (!$isLoggedIn) {
    $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please sign in to register a horse or buy a logbook.']];
    header('Location: ' . $basePath . '/account');
    exit;
}
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$basketCount = count($basket);
$horses = $isLoggedIn ? array_values(array_filter(
    fetchHorsesForUser($pdo, (int)($currentUser['id'] ?? 0)),
    static fn(array $horse): bool => empty($horse['is_linked'])
)) : [];
$logbookTypes = fetchHorseLogbookTypes($pdo, true);
$preselectedHorseId = max(0, (int)($_GET['horse_id'] ?? 0));
$preselectedHorse = null;
foreach ($horses as $horse) {
    if ((int)($horse['id'] ?? 0) === $preselectedHorseId) {
        $preselectedHorse = $horse;
        break;
    }
}
$isHorseRegistrationFlow = $preselectedHorse !== null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'register_only') {
    if (!$isLoggedIn) {
        $_SESSION['flash_alerts'] = [['type'=>'warning','message'=>'Please login to register a horse.']];
        header('Location: ' . $basePath . '/account');
        exit;
    }
    $horseId = (int)($_POST['horse_id'] ?? 0);
    $horse = $horseId > 0 ? fetchHorseForUserById($pdo, (int)$currentUser['id'], $horseId) : null;
    if ($horse && empty($horse['is_linked'])) {
        $_SESSION['flash_success'] = 'Horse registered successfully. You can now select it on event entry forms.';
        header('Location: ' . $basePath . '/account?view=horses');
        exit;
    }
    $alerts[] = ['type'=>'danger','message'=>'The horse registration could not be confirmed.'];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_logbook') {
    if (!$isLoggedIn) {
        $_SESSION['flash_alerts'] = [['type' => 'warning', 'message' => 'Please login to buy a logbook.']];
        header('Location: ' . $basePath . '/account');
        exit;
    }

    $typeId = (int)($_POST['logbook_type_id'] ?? 0);
    $logbookType = fetchHorseLogbookTypeById($pdo, $typeId);
    if (!$logbookType || strtolower((string)($logbookType['status'] ?? '')) !== 'published') {
        $alerts[] = ['type' => 'danger', 'message' => 'Logbook type not available.'];
    } else {
        $horseId = (int)($_POST['horse_id'] ?? 0);
        $horseName = '';
        foreach ($horses as $h) {
            if ((int)($h['id'] ?? 0) === $horseId) {
                $horseName = (string)($h['name'] ?? '');
                break;
            }
        }
        if ($horseId <= 0 || $horseName === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Select a horse for this logbook.'];
        }

        $logbookYear = (int)($logbookType['valid_year'] ?? (int)date('Y'));
        // prevent duplicates in basket
        foreach ($basket as $item) {
            if (($item['booking_type'] ?? '') === 'horse_logbook'
                && (int)($item['horse_id'] ?? 0) === $horseId
                && (int)($item['logbook_year'] ?? 0) === $logbookYear) {
                $alerts[] = ['type' => 'warning', 'message' => 'That horse already has this year\'s logbook in the basket.'];
                break;
            }
        }
        // prevent duplicates in DB
        if (!$alerts && horse_has_logbook_for_year($pdo, $horseId, $logbookYear)) {
            $alerts[] = ['type' => 'warning', 'message' => 'That horse already has a logbook for this year.'];
        }

        if (!$alerts) {
            $entry = [
                'id' => uniqid('log', true),
                'booking_type' => 'horse_logbook',
                'logbook_type_id' => $logbookType['id'],
                'logbook_year' => $logbookYear,
                'horse_id' => $horseId,
                'horse_name' => $horseName,
                'membership_name' => $logbookType['name'],
                'event_title' => 'Horse logbook: ' . ($horseName !== '' ? $horseName : 'Horse'),
                'class_label' => 'Horse logbook ' . $logbookYear,
                'price' => $logbookType['cost'] ?? '0',
            ];
            $basket[] = $entry;
            $_SESSION['basket'] = $basket;
            $_SESSION['basket_last_added'] = time();
            saveBasketForSession($pdo, session_id(), $basket, $currentUser['id'] ?? null, $_SESSION['basket_last_added']);
            $_SESSION['flash_success'] = 'Logbook added to basket.';
            header('Location: ' . $basePath . '/basket');
            exit;
        }
    }
}

$navItemEventsUrl = $basePath . '/events';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horse logbooks | <?php echo h($siteSettings['hero_title']); ?></title>
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
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="page-hero">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-white-50">Logbooks</p>
            <h1 class="fw-bold mb-1">Horse logbooks</h1>
            <div class="text-white-50">Register or renew a logbook for your horses.</div>
        </div>
    </header>

    <main class="py-5">
        <div class="container">
            <?php include __DIR__ . '/views/alerts.php'; ?>
            <div class="card-soft p-4" id="available-logbooks">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="section-title mb-0">Available logbooks</div>
                </div>
                <?php if (!$logbookTypes): ?>
                    <div class="text-muted small">No logbooks available right now.</div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($logbookTypes as $type): ?>
                            <div class="col-12">
                                <div class="card-soft h-100 p-4">
                                    <div class="row g-4">
                                        <div class="col-12 <?php echo $isHorseRegistrationFlow ? 'col-lg-6' : ''; ?>">
                                            <div class="fw-bold"><?php echo h($type['name']); ?></div>
                                            <div class="text-muted small">Valid year: <?php echo h((string)($type['valid_year'] ?? date('Y'))); ?></div>
                                            <div class="chip my-3"><?php echo h(format_price($type['cost'] ?? 0)); ?></div>
                                    <?php
                                    $description = trim((string)($type['description'] ?? ''));
                                    if ($description !== ''):
                                    ?>
                                        <div class="text-muted small mb-2"><?php echo h($description); ?></div>
                                    <?php endif; ?>
                                    <?php if ($isLoggedIn): ?>
                                        <?php if ($isHorseRegistrationFlow): ?>
                                            <div class="mb-3">
                                                <label class="form-label small mb-1">Horse</label>
                                                <div class="form-control bg-light fw-semibold"><?php echo h((string)$preselectedHorse['name']); ?></div>
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-6 d-grid">
                                                    <form method="post" class="d-grid h-100">
                                                        <input type="hidden" name="action" value="add_logbook">
                                                        <input type="hidden" name="logbook_type_id" value="<?php echo (int)$type['id']; ?>">
                                                        <input type="hidden" name="horse_id" value="<?php echo (int)$preselectedHorse['id']; ?>">
                                                        <button class="btn btn-success">Add Logbook to Basket</button>
                                                    </form>
                                                </div>
                                                <div class="col-6 d-grid">
                                                    <form method="post" class="d-grid h-100">
                                                        <input type="hidden" name="action" value="register_only">
                                                        <input type="hidden" name="horse_id" value="<?php echo (int)$preselectedHorse['id']; ?>">
                                                        <button class="btn btn-outline-success">Register Only</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                        <form method="POST" class="row g-2 align-items-end mb-2">
                                            <input type="hidden" name="action" value="add_logbook">
                                            <input type="hidden" name="logbook_type_id" value="<?php echo (int)$type['id']; ?>">
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small mb-1">Horse</label>
                                                <select class="form-select form-select-sm" name="horse_id" required>
                                                    <option value="" <?php echo $preselectedHorseId <= 0 ? 'selected' : ''; ?> disabled>Select a horse…</option>
                                                    <?php foreach ($horses as $horse): ?>
                                                        <option value="<?php echo (int)($horse['id'] ?? 0); ?>" <?php echo $preselectedHorseId === (int)($horse['id'] ?? 0) ? 'selected' : ''; ?>>
                                                            <?php echo h($horse['name'] ?? 'Horse'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="text-muted small mt-1">One logbook per horse per year.</div>
                                            </div>
                                            <div class="col-12 col-md-auto d-grid">
                                                <button class="btn btn-success btn-enter">Add Logbook to Basket</button>
                                            </div>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="cta-row d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mt-2">
                                            <div class="text-muted small">Sign in to buy or renew logbooks.</div>
                                            <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 w-100">
                                                <a class="btn btn-success btn-enter w-100" href="<?php echo h($basePath); ?>/account">Login / Register<br>Membership</a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                        </div>
                                        <?php if ($isHorseRegistrationFlow): ?>
                                            <div class="col-12 col-lg-6">
                                                <div class="cta-row h-100">
                                                    <h2 class="h5 fw-bold">Which option should I choose?</h2>
                                                    <p><strong>Buy Logbook</strong> if you want to take part in competitive rides or registered VPRs.</p>
                                                    <p class="mb-0"><strong>Register Only</strong> if you only need the horse available on entry forms. Without a logbook, the horse cannot claim mileage awards or take part in competitive rides.</p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
