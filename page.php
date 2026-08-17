<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';
$pathSlug = '';

// Helper for PHP 7 compatibility (str_starts_with is PHP 8)
$startsWith = function (?string $haystack, string $needle): bool {
    return $haystack !== null && strpos($haystack, $needle) === 0;
};

// Prefer deriving the slug from the URL path; fall back to query params if needed.
if (isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = $uri ? ltrim($uri, '/') : '';
    $basePrefix = ltrim((string)$basePath, '/');
    if ($basePrefix !== '' && $startsWith($uri, $basePrefix . '/')) {
        $uri = substr($uri, strlen($basePrefix) + 1);
    }
    if ($startsWith($uri, 'pages/')) {
        $pathSlug = trim(substr($uri, strlen('pages/')), '/');
    } else {
        $pos = strpos($uri, 'pages/');
        if ($pos !== false) {
            $pathSlug = trim(substr($uri, $pos + strlen('pages/')), '/');
        }
    }
}
if ($pathSlug === '') {
    $pathSlug = trim($_GET['path'] ?? $_GET['page'] ?? $_GET['slug'] ?? '');
}

$page = null;
if ($pathSlug !== '') {
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND is_published = 1 LIMIT 1");
        $stmt->execute([':slug' => $pathSlug]);
        $page = $stmt->fetch() ?: null;
    } else {
        foreach (defaultPages() as $candidate) {
            if (strcasecmp((string)($candidate['slug'] ?? ''), $pathSlug) === 0) {
                $page = $candidate;
                break;
            }
        }
    }
}
if ($page && $pathSlug !== '' && strcasecmp((string)($page['slug'] ?? ''), $pathSlug) !== 0 && $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $pathSlug]);
    $page = $stmt->fetch() ?: null;
}

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$overviewGroupFromPath = null;
foreach (NAV_GROUPS as $groupKey => $_groupLabel) {
    if (in_array($groupKey, ['home', 'not-on-menu'], true)) continue;
    if (strcasecmp($pathSlug, $groupKey . '-overview') === 0) {
        $overviewGroupFromPath = $groupKey;
        break;
    }
}
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$pageFromList = null;
if ($pathSlug !== '') {
    foreach ($pages as $candidate) {
        if (strcasecmp((string)($candidate['slug'] ?? ''), $pathSlug) === 0) {
            $pageFromList = $candidate;
            break;
        }
    }
    if ($pageFromList) {
        $page = $pageFromList;
    }
}

if ($overviewGroupFromPath !== null) {
    foreach ($pages as $candidate) {
        if (strtolower((string)($candidate['nav_group'] ?? '')) === $overviewGroupFromPath) {
            $pageFromList = $candidate;
            $page = $candidate;
            break;
        }
    }
}


if (!$page && $pathSlug && $pdo) {
    // Allow admins to preview unpublished if logged in
    if ($canViewAdmin) {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $pathSlug]);
        $page = $stmt->fetch() ?: null;
    }
}

$renderPage = $pageFromList ?? $page;
$dynamicSections = $renderPage ? page_dynamic_sections($pdo, $renderPage, $basePath) : ['before_content' => '', 'after_body' => ''];
$advertising = fetchAdvertising($pdo, true);
$pageImageBatch = $renderPage ? mediaBatchFind($pdo, 'page_images', 'page', (int)($renderPage['id'] ?? 0)) : null;
$pageImages = $pageImageBatch ? mediaBatchImages($pdo, (int)$pageImageBatch['id']) : [];
$pageElements = $renderPage ? fetchPageContentElements($pdo, (int)($renderPage['id'] ?? 0), true) : [];

// A group's first page is its optional dropdown overview destination. Present it as
// a visual menu while this feature is enabled for that top-level menu section.
$renderGroup = strtolower((string)($renderPage['nav_group'] ?? ''));
$groupPages = $renderGroup !== '' ? array_values(array_filter($pages, static fn(array $candidate): bool =>
    strtolower((string)($candidate['nav_group'] ?? '')) === $renderGroup
)) : [];
$showGroupOverview = !array_key_exists('menu_overview_' . $renderGroup, $siteSettings)
    || !empty($siteSettings['menu_overview_' . $renderGroup]);
$isMenuOverview = $renderPage && $showGroupOverview && count($groupPages) > 1 && $overviewGroupFromPath !== null;
$menuOverviewTitle = (NAV_GROUPS[$renderGroup] ?? ucwords(str_replace('-', ' ', $renderGroup))) . ' Overview';
if ($isMenuOverview) {
    ob_start();
    ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-4 justify-content-center mt-2">
        <?php foreach ($groupPages as $menuPage): ?>
            <?php
            $menuImageBatch = mediaBatchFind($pdo, 'page_images', 'page', (int)$menuPage['id']);
            $menuImages = $menuImageBatch ? mediaBatchImages($pdo, (int)$menuImageBatch['id']) : [];
            $menuImage = $menuImages[0] ?? null;
            $menuImageUrl = $menuImage ? mediaBatchImageUrl($menuImageBatch, $menuImage, 'md') : '/filestore/images/award-placeholder.svg';
            ?>
            <div class="col">
                <?php $menuPageUrl = $renderGroup === 'events' && (int)$menuPage['id'] === (int)$groupPages[0]['id'] ? $basePath . '/events' : $basePath . '/pages/' . rawurlencode((string)$menuPage['slug']); ?>
                <a class="card h-100 text-decoration-none text-reset shadow-sm position-relative overflow-hidden" href="<?php echo h($menuPageUrl); ?>">
                    <img class="card-img-top" style="height: 170px; object-fit: cover;" src="<?php echo h($menuImageUrl); ?>" alt="<?php echo h((string)($menuImage['alt_text'] ?? $menuPage['title'])); ?>">
                    <div class="card-body">
                        <h2 class="h5 card-title text-success"><?php echo h($menuPage['title']); ?></h2>
                        <p class="card-text small mb-0"><?php echo h((string)($menuPage['excerpt'] ?? '')); ?></p>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    $dynamicSections['after_body'] .= (string)ob_get_clean();
}
foreach ($pageElements as &$pageElement) {
    if (($pageElement['content_type'] ?? 'rich_text') !== 'current_events_calendar') continue;
    $calendarEvents = array_values(array_filter(fetchEvents($pdo, true), static fn(array $event): bool => strtolower((string)($event['status'] ?? '')) === 'published'));
    usort($calendarEvents, static fn(array $a, array $b): int => strcmp((string)($a['event_date'] ?? ''), (string)($b['event_date'] ?? '')));
    ob_start(); ?>
    <style>.current-events-calendar .d-flex.flex-column.align-items-md-end.gap-2 > .d-flex { flex-direction: column; align-items: flex-end; }</style>
    <div class="current-events-calendar mt-4">
        <?php $currentMonth = ''; ?>
        <?php if ($calendarEvents): ?><?php foreach ($calendarEvents as $event): ?>
            <?php
            $eventDate = (string)($event['event_date'] ?? '');
            $monthLabel = $eventDate ? strtoupper(date('F Y', strtotime($eventDate))) : '';
            if ($monthLabel && $monthLabel !== $currentMonth) { $currentMonth = $monthLabel; echo '<div class="small fw-bold text-muted mt-4 mb-2">' . h($currentMonth) . '</div>'; }
            $dateLabel = $eventDate ? date('jS M Y', strtotime($eventDate)) : 'Date TBC';
            $endDate = (string)($event['end_date'] ?? '');
            if ($endDate && $endDate !== $eventDate) $dateLabel .= ' to ' . date('jS M Y', strtotime($endDate));
            $eventUrl = $basePath . '/events/' . (int)$event['id'] . '-' . rawurlencode(slugify((string)$event['title']));
            $classes = class_names_from_pricing_rows(fetchEventPricingRows($pdo, (int)$event['id']));
            if (!$classes) $classes = class_names_from_classes_offered($event['classes_offered'] ?? '');
            $entryOpenAt = $event['entry_open_at'] ?? null;
            $nonMemberOpenAt = $event['non_member_entry_open_at'] ?? null;
            $entryCloseAt = $event['entry_close_at'] ?? null;
            if (!$entryOpenAt && $eventDate) $entryOpenAt = date('Y-m-d 00:00:00', strtotime($eventDate . ' -1 month'));
            if (!$nonMemberOpenAt && $entryOpenAt) $nonMemberOpenAt = date('Y-m-d H:i:s', strtotime((string)$entryOpenAt . ' +1 week'));
            if (!$entryCloseAt && $eventDate) $entryCloseAt = date('Y-m-d 23:59:59', strtotime($eventDate . ' -1 week'));
            $now = new DateTimeImmutable('now');
            $entryOpenDt = $entryOpenAt ? new DateTimeImmutable((string)$entryOpenAt) : null;
            $nonMemberOpenDt = $nonMemberOpenAt ? new DateTimeImmutable((string)$nonMemberOpenAt) : null;
            $entryCloseDt = $entryCloseAt ? new DateTimeImmutable((string)$entryCloseAt) : null;
            $capacity = (int)($event['capacity_limit'] ?? 0); $isLimited = !empty($event['capacity_enabled']) && $capacity > 0;
            $entryCount = (int)($event['entry_count'] ?? 0); $isFull = $isLimited && $entryCount >= $capacity;
            $entriesClosed = $entryCloseDt && $now > $entryCloseDt;
            $closingSoon = $entryCloseDt && !$entriesClosed && $entryCloseDt <= $now->modify('+4 days');
            ?>
            <a class="d-block border rounded-3 p-3 mb-2 text-decoration-none text-reset shadow-sm" href="<?php echo h($eventUrl); ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2"><div><div class="fw-semibold text-success"><?php echo h($dateLabel); ?></div><div class="fs-5 fw-bold"><?php echo h((string)$event['title']); ?></div><?php if (!empty($event['venue'])): ?><div class="text-muted small"><?php echo h((string)$event['venue']); ?></div><?php endif; ?><?php if ($classes): ?><div class="text-muted small mt-1">Classes: <?php echo h(implode(', ', $classes)); ?></div><?php endif; ?><?php if (!empty($event['description'])): ?><div class="text-muted small mt-1 fst-italic"><?php echo h((string)$event['description']); ?></div><?php endif; ?></div><div class="d-flex flex-column align-items-md-end gap-2"><span class="btn btn-sm btn-outline-success">View ride &amp; enter</span><div class="d-flex flex-wrap justify-content-md-end gap-2"><?php if ($entryOpenDt): ?><span class="badge <?php echo $now < $entryOpenDt ? 'text-bg-secondary' : 'text-bg-success'; ?>">Members <?php echo h($now < $entryOpenDt ? 'open ' . $entryOpenDt->format('j M H:i') : 'open'); ?></span><?php endif; ?><?php if ($nonMemberOpenDt): ?><span class="badge <?php echo $now < $nonMemberOpenDt ? 'text-bg-secondary' : 'text-bg-success'; ?>">Non-members <?php echo h($now < $nonMemberOpenDt ? 'open ' . $nonMemberOpenDt->format('j M H:i') : 'open'); ?></span><?php endif; ?><?php if ($entriesClosed): ?><span class="badge text-bg-secondary">Entries closed</span><?php elseif ($entryCloseDt): ?><span class="badge text-bg-light border">Closes <?php echo h($entryCloseDt->format('j M H:i')); ?></span><?php endif; ?><?php if ($closingSoon): ?><span class="fw-bold text-danger small align-self-center">CLOSING SOON</span><?php endif; ?><?php if ($isLimited): ?><span class="badge <?php echo $isFull ? 'text-bg-danger' : 'text-bg-light border'; ?>"><?php echo $isFull ? 'Event full' : $entryCount . '/' . $capacity . ' places'; ?></span><?php endif; ?></div></div></div>
            </a>
        <?php endforeach; ?><?php else: ?><div class="alert alert-info mb-0">No current events are published.</div><?php endif; ?>
    </div><?php $pageElement['body_html']=(string)($pageElement['body_html']??'').(string)ob_get_clean();
}
unset($pageElement);
foreach ($pageElements as &$pageElement) {
    if (($pageElement['content_type'] ?? 'rich_text') !== 'membership_options') {
        continue;
    }
    $membershipTypes = fetchMembershipTypes($pdo, true);
    $membershipHref = $basePath . ($isLoggedIn ? '/memberships' : '/account');
    ob_start();
    ?>
    <div class="membership-options mt-4">
        <?php if ($membershipTypes): ?>
            <div class="row g-3">
                <?php foreach ($membershipTypes as $membershipType): ?>
                    <div class="col-md-6">
                        <div class="border rounded-4 h-100 p-3 bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div class="fw-bold fs-5"><?php echo h((string)$membershipType['name']); ?></div>
                                <span class="badge text-bg-success fs-6"><?php echo h(format_price($membershipType['cost'] ?? 0)); ?></span>
                            </div>
                            <?php if (trim((string)($membershipType['description'] ?? '')) !== ''): ?><div class="text-muted small mt-2"><?php echo h((string)$membershipType['description']); ?></div><?php endif; ?>
                            <?php if (!empty($membershipType['membership_starts']) || !empty($membershipType['membership_ends'])): ?><div class="text-muted small mt-3"><strong>Membership period:</strong> <?php echo h(format_display_date($membershipType['membership_starts'] ?? null, '')); ?> — <?php echo h(format_display_date($membershipType['membership_ends'] ?? null, '')); ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4"><a class="btn btn-success" href="<?php echo h($membershipHref); ?>">Join now</a></div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Membership options will be available soon.</div>
        <?php endif; ?>
    </div>
    <?php
    $pageElement['body_html'] = (string)($pageElement['body_html'] ?? '') . (string)ob_get_clean();
}
unset($pageElement);
foreach ($pageElements as &$pageElement) {
    if (($pageElement['content_type'] ?? 'rich_text') !== 'awards_winners') continue;
    $awards = fetchAwards($pdo, true); $winners = fetchAwardWinners($pdo, 0, true);
    $winnerMap = []; foreach ($winners as $winner) $winnerMap[(int)$winner['award_id']][] = $winner;
    ob_start(); ?>
    <div class="awards-winners mt-4 row g-4">
        <?php foreach ($awards as $award): ?>
            <?php
            $winnerCollapseId = 'award-winners-' . (int)$award['id'];
            // Awards are presented after the calendar year has finished, so begin with last year.
            $recentAwardYears = [(int)date('Y') - 1, (int)date('Y') - 2];
            $awardWinners = $winnerMap[(int)$award['id']] ?? [];
            $winnersByYear = [];
            foreach ($awardWinners as $winner) $winnersByYear[(int)$winner['award_year']][] = $winner;
            $olderWinners = array_values(array_filter($awardWinners, static fn(array $winner): bool => (int)$winner['award_year'] < min($recentAwardYears)));
            ?>
            <?php $awardImageUrl = !empty($award['image_asset_id']) && ($asset = fetchAssetLibraryById($pdo, (int)$award['image_asset_id'])) ? assetLibraryPublicUrl($asset, 'sm') : (!empty($award['legacy_image_filename']) ? $basePath . '/filestore/images/awards/originals/' . rawurlencode(basename((string)$award['legacy_image_filename'])) : '/filestore/images/award-placeholder.svg'); ?>
            <div class="col-12"><div class="card-soft p-4 h-100"><div class="row g-3 align-items-start"><div class="col-8"><h3 class="h4 mb-2"><?php echo h($award['name']); ?></h3><div class="page-body"><?php echo (string)($award['description_html'] ?? ''); ?></div><div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Year</th><th>Winner</th></tr></thead><tbody><?php foreach ($recentAwardYears as $awardYear): ?><tr><td><?php echo $awardYear; ?></td><td><?php if (!empty($winnersByYear[$awardYear])): ?><?php foreach ($winnersByYear[$awardYear] as $winner): ?><div><?php echo render_wysiwyg((string)$winner['winner_name']); ?></div><?php endforeach; ?><?php else: ?><span class="text-muted">Not awarded</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php if ($olderWinners): ?><button class="btn btn-sm btn-outline-success mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo h($winnerCollapseId); ?>" aria-expanded="false" aria-controls="<?php echo h($winnerCollapseId); ?>">Show More Winners</button><div class="collapse" id="<?php echo h($winnerCollapseId); ?>"><div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Year</th><th>Winner</th></tr></thead><tbody><?php foreach ($olderWinners as $winner): ?><tr><td><?php echo (int)$winner['award_year']; ?></td><td><?php echo render_wysiwyg((string)$winner['winner_name']); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?></div><div class="col-4 align-self-start"><img class="w-100 rounded border bg-light p-2" style="min-height:210px;object-fit:contain;" src="<?php echo h($awardImageUrl); ?>" alt="<?php echo h($award['name']); ?>"></div></div></div></div>
        <?php endforeach; ?>
        <?php if (!$awards): ?><div class="col-12"><div class="alert alert-info mb-0">Awards will be available soon.</div></div><?php endif; ?>
    </div>
    <?php $pageElement['body_html']=(string)($pageElement['body_html']??'').(string)ob_get_clean();
}
unset($pageElement);
foreach ($pageElements as &$pageElement) {
    if (($pageElement['content_type'] ?? 'rich_text') !== 'ride_prices') {
        continue;
    }
    $rideSchemes = array_values(array_filter(fetchPricingSchemes($pdo), static function (array $scheme): bool {
        if (stripos((string)($scheme['name'] ?? ''), 'ride') !== false) {
            return true;
        }
        foreach ((array)($scheme['event_types'] ?? []) as $eventType) {
            if (stripos((string)($eventType['name'] ?? ''), 'ride') !== false) {
                return true;
            }
        }
        return false;
    }));
    ob_start();
    ?>
    <div class="ride-prices mt-4">
        <?php if ($rideSchemes): ?>
            <?php foreach ($rideSchemes as $rideScheme): ?>
                <?php $rideRows = fetchPricingSchemeRows($pdo, (int)($rideScheme['id'] ?? 0)); ?>
                <?php if ($rideRows): ?>
                    <div class="mb-4">
                        <div class="fw-bold mb-2"><?php echo h((string)$rideScheme['name']); ?></div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light"><tr><th>Class name</th><th>Entry type</th><th>Rider</th><th class="text-end">Price</th></tr></thead>
                                <tbody>
                                    <?php foreach ($rideRows as $rideRow): ?>
                                        <tr>
                                            <td><?php echo h((string)($rideRow['class_name'] ?? '')); ?></td>
                                            <td><?php echo !empty($rideRow['is_member_price']) ? 'Member' : 'Non-member'; ?></td>
                                            <td><?php echo !empty($rideRow['is_junior_ride']) ? 'Junior' : 'Senior'; ?></td>
                                            <td class="text-end fw-semibold"><?php echo h(format_price($rideRow['price'] ?? 0)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info mb-0">Ride prices will be available soon.</div>
        <?php endif; ?>
    </div>
    <?php
    $pageElement['body_html'] = (string)($pageElement['body_html'] ?? '') . (string)ob_get_clean();
}
unset($pageElement);
foreach ($pageElements as &$pageElement) {
    if (($pageElement['content_type'] ?? 'rich_text') !== 'horse_logbook_information') {
        continue;
    }
    // There is one horse logbook category. Use the current/latest published offering.
    $logbookTypes = array_slice(fetchHorseLogbookTypes($pdo, true), 0, 1);
    $logbookHref = $basePath . ($isLoggedIn ? '/logbooks' : '/account');
    ob_start();
    ?>
    <div class="horse-logbook-information mt-4">
        <?php if ($logbookTypes): ?>
            <?php $logbookType = $logbookTypes[0]; ?>
            <div class="border rounded-4 p-4 bg-light-subtle">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-3">
                    <div>
                        <div class="fw-bold fs-5"><?php echo h((string)$logbookType['name']); ?></div>
                        <?php if (trim((string)($logbookType['description'] ?? '')) !== ''): ?><div class="text-muted small mt-2"><?php echo h((string)$logbookType['description']); ?></div><?php endif; ?>
                        <div class="text-muted small mt-3"><strong>Logbook year:</strong> <?php echo (int)($logbookType['valid_year'] ?? date('Y')); ?></div>
                    </div>
                    <span class="badge text-bg-success fs-6 align-self-sm-start"><?php echo h(format_price($logbookType['cost'] ?? 0)); ?></span>
                </div>
            </div>
            <div class="mt-4"><a class="btn btn-success" href="<?php echo h($logbookHref); ?>">Get a horse logbook</a></div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Horse logbooks will be available soon.</div>
        <?php endif; ?>
    </div>
    <?php
    $pageElement['body_html'] = (string)($pageElement['body_html'] ?? '') . (string)ob_get_clean();
}
unset($pageElement);
foreach ($pageElements as &$pageElement) {
    $pageElement['image_batch'] = mediaBatchFind($pdo, 'content_element_images', 'page_content_element', (int)$pageElement['id']);
    $pageElement['images'] = $pageElement['image_batch'] ? mediaBatchImages($pdo, (int)$pageElement['image_batch']['id']) : [];
}
unset($pageElement);

if (!$renderPage) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $renderPage ? h($renderPage['title']) . ' | ' . h($siteSettings['hero_title']) : 'Page not found'; ?></title>
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
        .page-body {
            color: #476146;
        }
        .page-body > :last-child {
            margin-bottom: 0;
        }
        .page-body a:not(.btn) {
            color: #244a29;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .page-body a:not(.btn):hover,
        .page-body a:not(.btn):focus {
            color: #476146;
            text-decoration: none;
        }
        .page-advertising { display: grid; gap: 0.8rem; }
        .page-advertising-item { display: block; border-radius: 12px; overflow: hidden; background: #fff; }
        .page-advertising-item img { display: block; width: 100%; height: auto; max-height: 110px; object-fit: contain; }
        .page-gallery-main { display:flex; width:100%; justify-content:center; border:0; padding:0; background:none; cursor:zoom-in; }
        .page-gallery-main.no-lightbox { cursor:default; }
        .page-gallery-main img { display:block; width:auto; max-width:100%; height:auto; max-height:520px; object-fit:contain; border-radius:12px; }
        .page-gallery-caption { margin-top:.55rem; color:var(--muted); font-size:.9rem; }
        .page-gallery-thumbs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.45rem; margin-top:.65rem; }
        .page-gallery-thumb { border:2px solid transparent; border-radius:8px; padding:0; overflow:hidden; background:#fff; }
        .page-gallery-thumb.active { border-color:var(--green-alt); }
        .page-gallery-thumb img { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; }
        .page-lightbox { position:fixed; inset:0; z-index:4000; display:none; place-items:center; padding:2rem; background:rgba(0,0,0,.9); }
        .page-lightbox.open { display:grid; }
        .page-lightbox img { max-width:94vw; max-height:88vh; object-fit:contain; }
        .page-lightbox-figure { margin:0; max-width:94vw; text-align:center; }
        .page-lightbox-caption { margin-top:.65rem; color:#fff; font-size:1rem; }
        .page-lightbox-close { position:absolute; right:1rem; top:.5rem; border:0; background:none; color:#fff; font-size:2.5rem; }
        .page-lightbox-nav { position:absolute; top:50%; transform:translateY(-50%); border:0; border-radius:999px; width:3rem; height:3rem; background:rgba(255,255,255,.16); color:#fff; font-size:2rem; line-height:1; }
        .page-lightbox-prev { left:1rem; }
        .page-lightbox-next { right:1rem; }
        .page-content-elements { margin-top:2rem; }
        .page-content-element { margin-bottom:1.5rem; }
        .page-content-element .element-text { padding:1.5rem; }
        @media (max-width: 767.98px) { .page-lightbox { padding:1rem; } .page-lightbox-nav { width:2.5rem; height:2.5rem; } .page-lightbox-prev { left:.25rem; } .page-lightbox-next { right:.25rem; } }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="py-3" style="background: #f5f7ef; border-bottom: 1px solid rgba(0,0,0,0.05);">
        <div class="container">
            <?php if (!$isMenuOverview): ?><p class="mb-1 text-uppercase small fw-bold text-muted"><?php echo h($siteSettings['hero_subtitle']); ?></p><?php endif; ?>
            <h1 class="fw-bold mb-1" style="color: var(--text-main);"><?php echo $renderPage ? h($isMenuOverview ? $menuOverviewTitle : $renderPage['title']) : 'Page not found'; ?></h1>
            <?php if ($renderPage && !$isMenuOverview): ?>
                <div class="text-muted"><?php echo h($renderPage['excerpt'] ?? ''); ?></div>
            <?php else: ?>
                <div class="text-muted">We could not find that page.</div>
            <?php endif; ?>
        </div>
    </header>

    <main class="pt-0 pb-5">
        <div class="container">
            <?php echo $dynamicSections['before_content']; ?>

            <div class="row g-4 mt-2">
                <?php if (!$isMenuOverview && $pageImages && $pageImageBatch): ?>
                <div class="col-lg-4">
                    <div class="card-soft p-3 page-gallery" data-page-gallery>
                        <?php $mainImage=$pageImages[0]; ?>
                        <button type="button" class="page-gallery-main<?php echo empty($mainImage['lightbox_enabled']) ? ' no-lightbox' : ''; ?>" data-lightbox-enabled="<?php echo !empty($mainImage['lightbox_enabled']) ? '1' : '0'; ?>" data-lightbox-src="<?php echo h(mediaBatchImageUrl($pageImageBatch,$mainImage,'original')); ?>" aria-label="<?php echo !empty($mainImage['lightbox_enabled']) ? 'Enlarge image' : 'Page image'; ?>">
                            <img src="<?php echo h(mediaBatchImageUrl($pageImageBatch,$mainImage,'md')); ?>" alt="<?php echo h($mainImage['alt_text'] ?: $mainImage['title'] ?: $renderPage['title']); ?>" title="<?php echo h($mainImage['title'] ?: ''); ?>">
                        </button>
                        <div class="page-gallery-caption"><?php echo h($mainImage['caption'] ?: ''); ?></div>
                        <?php if(count($pageImages)>1): ?><div class="page-gallery-thumbs">
                            <?php foreach($pageImages as $index=>$image): ?><button type="button" class="page-gallery-thumb <?php echo $index===0?'active':''; ?>" data-md="<?php echo h(mediaBatchImageUrl($pageImageBatch,$image,'md')); ?>" data-full="<?php echo h(mediaBatchImageUrl($pageImageBatch,$image,'original')); ?>" data-lightbox-enabled="<?php echo !empty($image['lightbox_enabled']) ? '1' : '0'; ?>" data-alt="<?php echo h($image['alt_text'] ?: $image['title'] ?: $renderPage['title']); ?>" data-title="<?php echo h($image['title'] ?: ''); ?>" data-caption="<?php echo h($image['caption'] ?: ''); ?>"><img src="<?php echo h(mediaBatchImageUrl($pageImageBatch,$image,'xs')); ?>" alt=""></button><?php endforeach; ?>
                        </div><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="<?php echo $isMenuOverview ? 'col-12' : ($pageImages ? 'col-lg-6' : 'col-lg-10'); ?>">
                    <div class="card-soft p-4">
                        <?php if ($renderPage): ?>
                            <?php if (!$isMenuOverview): ?><div class="lead mb-3"><?php echo h($renderPage['excerpt'] ?? ''); ?></div><div class="page-body"><?php echo (string)($renderPage['body_html'] ?? ''); ?></div><?php endif; ?>
                            <?php echo $dynamicSections['after_body']; ?>
                            <?php if (!$isMenuOverview): ?>
                            <?php
                            $pageButtonUrl = trim((string)($renderPage['button_url'] ?? ''));
                            if ($pageButtonUrl === '' && !empty($renderPage['button_asset_id'])) {
                                $pageButtonAsset = fetchAssetLibraryById($pdo, (int)$renderPage['button_asset_id']);
                                if ($pageButtonAsset && empty($pageButtonAsset['archived'])) $pageButtonUrl = assetLibraryPublicUrl($pageButtonAsset);
                            }
                            ?>
                            <?php if (!empty($renderPage['button_name']) && $pageButtonUrl !== ''): ?>
                                <?php $pageButtonTarget = ($renderPage['button_target'] ?? '_self') === '_blank' ? '_blank' : '_self'; ?>
                                <div class="mt-4 text-start">
                                    <a class="btn button2" href="<?php echo h($pageButtonUrl); ?>" title="<?php echo h($renderPage['button_title'] ?: $renderPage['button_name']); ?>" target="<?php echo h($pageButtonTarget); ?>"<?php echo $pageButtonTarget === '_blank' ? ' rel="noopener"' : ''; ?>><?php echo h($renderPage['button_name']); ?></a>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mb-0">Try another page from the navigation above.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$isMenuOverview): ?><div class="col-lg-2">
                    <?php if ($advertising): ?>
                        <div class="page-advertising" aria-label="Promotions">
                            <?php foreach ($advertising as $advert): ?>
                                <?php if (empty($advert['image'])) continue; ?>
                                <?php $advertImage = image_upload_public_path('advertising', 'sm', (string)$advert['image']); ?>
                                <?php $advertTarget = ($advert['link_target'] ?? '_blank') === '_self' ? '_self' : '_blank'; ?>
                                <?php if (!empty($advert['url'])): ?><a class="page-advertising-item card-soft" href="<?php echo h($advert['url']); ?>" target="<?php echo h($advertTarget); ?>"<?php echo $advertTarget === '_blank' ? ' rel="noopener sponsored"' : ''; ?>><?php else: ?><div class="page-advertising-item card-soft"><?php endif; ?>
                                    <img src="<?php echo h($advertImage); ?>" alt="<?php echo h($advert['name']); ?>" title="<?php echo h($advert['title'] ?: $advert['name']); ?>" loading="lazy">
                                <?php if (!empty($advert['url'])): ?></a><?php else: ?></div><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="card-soft p-3 mt-3">
                        <div class="fw-bold mb-2">Back to home</div>
                        <a class="btn btn-outline-success w-100" href="<?php echo h($basePath); ?>/">ILDRA Home</a>
                    </div>
                </div><?php endif; ?>
            </div>
            <?php if ($pageElements): ?>
            <div class="page-content-elements">
                <?php $nextAutoSide='left'; foreach($pageElements as $element):
                    $elementImages=$element['images']; $elementBatch=$element['image_batch']; $layout=(string)$element['layout'];
                    $hasElementImages=$elementImages && $layout!=='text_only';
                    if(!$hasElementImages){$side='left';$nextAutoSide='left';}
                    elseif($layout==='image_left'){$side='left';}
                    elseif($layout==='image_right'){$side='right';}
                    else{$side=$nextAutoSide;$nextAutoSide=$nextAutoSide==='left'?'right':'left';}
                ?>
                <section id="<?php echo h($element['anchor_slug'] ?: image_upload_slug($element['heading'] ?: $element['name'])); ?>" class="page-content-element card-soft overflow-hidden">
                    <div class="row g-0 align-items-start justify-content-center">
                        <?php if($hasElementImages): ?><div class="col-lg-4 <?php echo $side==='right'?'order-lg-2':''; ?>"><div class="p-3 page-gallery" data-page-gallery><?php $mainImage=$elementImages[0]; ?><button type="button" class="page-gallery-main" data-lightbox-src="<?php echo h(mediaBatchImageUrl($elementBatch,$mainImage,'original')); ?>"><img src="<?php echo h(mediaBatchImageUrl($elementBatch,$mainImage,'md')); ?>" alt="<?php echo h($mainImage['alt_text']?:$mainImage['title']?:$element['heading']); ?>"></button><div class="page-gallery-caption"><?php echo h($mainImage['caption']?:''); ?></div><?php if(count($elementImages)>1): ?><div class="page-gallery-thumbs"><?php foreach($elementImages as$i=>$image): ?><button type="button" class="page-gallery-thumb <?php echo $i===0?'active':''; ?>" data-md="<?php echo h(mediaBatchImageUrl($elementBatch,$image,'md')); ?>" data-full="<?php echo h(mediaBatchImageUrl($elementBatch,$image,'original')); ?>" data-alt="<?php echo h($image['alt_text']?:$image['title']?:$element['heading']); ?>" data-title="<?php echo h($image['title']?:''); ?>" data-caption="<?php echo h($image['caption']?:''); ?>"><img src="<?php echo h(mediaBatchImageUrl($elementBatch,$image,'xs')); ?>" alt=""></button><?php endforeach; ?></div><?php endif; ?></div></div><?php endif; ?>
                        <div class="<?php echo $hasElementImages?'col-lg-6':'col-lg-10'; ?> <?php echo $side==='right'?'order-lg-1':''; ?> element-text"><?php if(!empty($element['heading'])): ?><h2 class="h3 mb-3"><?php echo h($element['heading']); ?></h2><?php endif; ?><div class="page-body"><?php echo (string)$element['body_html']; ?></div></div>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="page-lightbox" id="page-lightbox" role="dialog" aria-modal="true" aria-label="Image preview"><button type="button" class="page-lightbox-close" aria-label="Close">&times;</button><button type="button" class="page-lightbox-nav page-lightbox-prev" aria-label="Previous image">&#8249;</button><figure class="page-lightbox-figure"><img src="" alt=""><figcaption class="page-lightbox-caption"></figcaption></figure><button type="button" class="page-lightbox-nav page-lightbox-next" aria-label="Next image">&#8250;</button></div>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
    (function(){
        const galleries=Array.from(document.querySelectorAll('[data-page-gallery]')), box=document.getElementById('page-lightbox');
        if(!galleries.length||!box)return;
        const boxImg=box.querySelector('img'),boxCaption=box.querySelector('.page-lightbox-caption');let active=null;
        galleries.forEach(gallery=>{const main=gallery.querySelector('.page-gallery-main'),mainImg=main.querySelector('img'),caption=gallery.querySelector('.page-gallery-caption'),thumbs=Array.from(gallery.querySelectorAll('.page-gallery-thumb'));const state={gallery,main,mainImg,caption,thumbs,current:0};state.select=index=>{if(!thumbs.length)return;state.current=(index+thumbs.length)%thumbs.length;const thumb=thumbs[state.current];thumbs.forEach(x=>x.classList.remove('active'));thumb.classList.add('active');mainImg.src=thumb.dataset.md;mainImg.alt=thumb.dataset.alt||'';main.dataset.lightboxSrc=thumb.dataset.full;main.dataset.lightboxEnabled=thumb.dataset.lightboxEnabled||'1';main.classList.toggle('no-lightbox',main.dataset.lightboxEnabled==='0');caption.textContent=thumb.dataset.caption||'';if(box.classList.contains('open')){if(main.dataset.lightboxEnabled==='0'){close();return;}boxImg.src=thumb.dataset.full;boxImg.alt=thumb.dataset.alt||'';boxCaption.textContent=thumb.dataset.caption||'';}};thumbs.forEach((thumb,index)=>thumb.addEventListener('click',()=>state.select(index)));main.addEventListener('click',()=>{if(main.dataset.lightboxEnabled==='0')return;active=state;box.querySelectorAll('.page-lightbox-nav').forEach(button=>button.hidden=thumbs.length<2);boxImg.src=main.dataset.lightboxSrc;boxImg.alt=mainImg.alt;boxCaption.textContent=caption.textContent;box.classList.add('open');document.body.style.overflow='hidden';});});
        function close(){box.classList.remove('open');boxImg.src='';boxCaption.textContent='';document.body.style.overflow='';}
        box.querySelector('.page-lightbox-close').addEventListener('click',close);box.querySelector('.page-lightbox-prev').addEventListener('click',()=>{if(active)active.select(active.current-1);});box.querySelector('.page-lightbox-next').addEventListener('click',()=>{if(active)active.select(active.current+1);});
        box.addEventListener('click',e=>{if(e.target===box)close();});document.addEventListener('keydown',e=>{if(!box.classList.contains('open'))return;if(e.key==='Escape')close();if(e.key==='ArrowLeft'&&active)active.select(active.current-1);if(e.key==='ArrowRight'&&active)active.select(active.current+1);});
    })();
    </script>
    <script>
    document.querySelectorAll('[data-bs-target^="#award-winners-"]').forEach(function (button) {
        var panel = document.querySelector(button.getAttribute('data-bs-target'));
        if (!panel) return;
        panel.addEventListener('shown.bs.collapse', function () { button.textContent = 'Hide More Winners'; });
        panel.addEventListener('hidden.bs.collapse', function () { button.textContent = 'Show More Winners'; });
    });
    </script>
</body>
</html>
