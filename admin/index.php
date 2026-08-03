<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$pages = fetchPages($pdo);
$events = fetchEvents($pdo);
$faqs = fetchFaqs($pdo);
$contentCounts = contentCounts($pages, $events, $faqs);
$allUsers = fetchAllUsersForAdmin($pdo, $alerts);
$upcomingEvents = fetchEvents($pdo, true);
$nextRide = $upcomingEvents[0] ?? null;
$nextRideSummary = null;

if ($pdo && $nextRide) {
    $nextRideId = (int)($nextRide['id'] ?? 0);
    $basketCount = 0;
    $waitlistCount = 0;
    $maxEntries = !empty($nextRide['capacity_enabled']) ? (int)($nextRide['capacity_limit'] ?? 0) : 0;
    $classCounts = [
        'PR' => 0,
        'VPR' => 0,
        'CTR' => 0,
        'ER' => 0,
    ];

    $classifyClass = static function (array $item): ?string {
        $meta = $item['metadata'] ?? [];
        $code = strtoupper(trim((string)($meta['class_code'] ?? '')));
        $label = strtoupper(trim((string)($meta['class_label'] ?? '')));

        if (in_array($code, ['PR', 'VPR', 'CTR', 'ER'], true)) {
            return $code;
        }
        if (preg_match('/\bVPR\b/', $label)) {
            return 'VPR';
        }
        if (preg_match('/\bCTR\b/', $label)) {
            return 'CTR';
        }
        if (preg_match('/\bER\b/', $label)) {
            return 'ER';
        }
        if (preg_match('/\bPR\b/', $label) || str_contains($label, 'PLEASURE RIDE')) {
            return 'PR';
        }
        return null;
    };

    try {
        $stmt = $pdo->prepare("SELECT * FROM booking_items WHERE event_id = :event_id AND COALESCE(is_withdrawn, 0) = 0");
        $stmt->execute([':event_id' => $nextRideId]);
        $items = array_map('hydrate_booking_item', $stmt->fetchAll() ?: []);
        foreach ($items as $item) {
            $bucket = $classifyClass($item);
            if ($bucket !== null) {
                $classCounts[$bucket]++;
            }
        }
    } catch (PDOException $e) {
        // Keep dashboard resilient; show zeroes if booking item counts cannot be read.
    }

    try {
        $stmt = $pdo->query("SELECT basket_json FROM baskets");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $basketItems = json_decode((string)($row['basket_json'] ?? '[]'), true);
            if (!is_array($basketItems)) {
                continue;
            }
            foreach ($basketItems as $basketItem) {
                if ((int)($basketItem['event_id'] ?? 0) === $nextRideId) {
                    $basketCount++;
                }
            }
        }
    } catch (PDOException $e) {
        // Waitlist/basket reporting is non-blocking on the dashboard.
    }

    $nextRideSummary = [
        'title' => trim((string)($nextRide['title'] ?? 'Next Ride')),
        'date' => format_display_date($nextRide['event_date'] ?? null, ''),
        'entries' => (int)($nextRide['entry_count'] ?? 0),
        'basket' => $basketCount,
        'waitlist' => $waitlistCount,
        'max_entries' => $maxEntries,
        'classes' => $classCounts,
    ];
}

admin_layout_start('Dashboard', 'dashboard');
?>
<style>
    .dash-section-title {
        font-size: 2rem;
        font-weight: 300;
        color: #6f7b8f;
        margin-bottom: 0.5rem;
    }
    .dash-section-subtitle {
        color: #8f99ab;
        margin-bottom: 1.25rem;
    }
    .dash-stat-card {
        border-radius: 14px;
        overflow: hidden;
        display: flex;
        min-height: 100px;
        box-shadow: 0 12px 30px rgba(15, 47, 31, 0.08);
    }
    .dash-stat-icon {
        width: 102px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 2.5rem;
        flex: 0 0 102px;
    }
    .dash-stat-body {
        background: #fff;
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 0.75rem 1rem;
    }
    .dash-stat-value {
        font-size: 2.25rem;
        line-height: 1;
        font-weight: 300;
        color: #b0b8c8;
        margin-bottom: 0.35rem;
    }
    .dash-stat-label {
        color: #b0b8c8;
        font-size: 0.95rem;
    }
</style>
<?php if ($nextRideSummary): ?>
    <div class="mb-4">
        <div class="dash-section-title">Next Ride</div>
        <div class="dash-section-subtitle">
            <?php echo h($nextRideSummary['title']); ?><?php echo $nextRideSummary['date'] !== '' ? ' · ' . h($nextRideSummary['date']) : ''; ?>
        </div>
        <div class="row g-3 justify-content-start mb-4">
            <?php
            $topCards = [
                ['label' => 'Total Entries', 'value' => $nextRideSummary['entries'], 'icon' => 'fa-horse', 'color' => '#f6a313'],
                ['label' => 'Basket', 'value' => $nextRideSummary['basket'], 'icon' => 'fa-basket-shopping', 'color' => '#1515ff'],
                ['label' => 'Waitlist', 'value' => $nextRideSummary['waitlist'], 'icon' => 'fa-circle-pause', 'color' => '#8c0a92'],
                ['label' => 'Max Entries', 'value' => $nextRideSummary['max_entries'], 'icon' => 'fa-square-full', 'color' => '#028a02'],
            ];
            foreach ($topCards as $card):
            ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="dash-stat-card">
                        <div class="dash-stat-icon" style="background: <?php echo h($card['color']); ?>;">
                            <i class="fa-solid <?php echo h($card['icon']); ?>"></i>
                        </div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-value"><?php echo h((string)$card['value']); ?></div>
                            <div class="dash-stat-label"><?php echo h($card['label']); ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dash-section-title">Entries per Class</div>
        <div class="row g-3 justify-content-start mb-4">
            <?php
            $classCards = [
                ['label' => 'PR Entries', 'value' => $nextRideSummary['classes']['PR'], 'icon' => 'fa-horse-saddle', 'color' => '#bfe3be'],
                ['label' => 'VPR Entries', 'value' => $nextRideSummary['classes']['VPR'], 'icon' => 'fa-heart-pulse', 'color' => '#ff7c6f'],
                ['label' => 'CTR Entries', 'value' => $nextRideSummary['classes']['CTR'], 'icon' => 'fa-stethoscope', 'color' => '#c8b67a'],
                ['label' => 'ER Entries', 'value' => $nextRideSummary['classes']['ER'], 'icon' => 'fa-stopwatch', 'color' => '#f1dd73'],
            ];
            foreach ($classCards as $card):
            ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="dash-stat-card">
                        <div class="dash-stat-icon" style="background: <?php echo h($card['color']); ?>;">
                            <i class="fa-solid <?php echo h($card['icon']); ?>"></i>
                        </div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-value"><?php echo h((string)$card['value']); ?></div>
                            <div class="dash-stat-label"><?php echo h($card['label']); ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card-soft p-3">
            <div class="small text-muted">Pages</div>
            <div class="h4 mb-0"><?php echo h((string)$contentCounts['pages']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-soft p-3">
            <div class="small text-muted">Events</div>
            <div class="h4 mb-0"><?php echo h((string)$contentCounts['events']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-soft p-3">
            <div class="small text-muted">FAQs</div>
            <div class="h4 mb-0"><?php echo h((string)$contentCounts['faqs']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-soft p-3">
            <div class="small text-muted">Users</div>
            <div class="h4 mb-0"><?php echo h((string)count($allUsers)); ?></div>
        </div>
    </div>
</div>

<div class="card-soft p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="fw-bold">Quick links</div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <a class="card-soft p-3 h-100 d-block text-decoration-none text-reset border-0" href="<?php echo h($adminBase); ?>/pages.php">
                <div class="fw-bold mb-1">Manage Pages</div>
                <div class="text-muted small">Edit navigation and body content</div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card-soft p-3 h-100 d-block text-decoration-none text-reset border-0" href="<?php echo h($adminBase); ?>/events.php">
                <div class="fw-bold mb-1">Events</div>
                <div class="text-muted small">Add or update events</div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card-soft p-3 h-100 d-block text-decoration-none text-reset border-0" href="<?php echo h($adminBase); ?>/hero.php">
                <div class="fw-bold mb-1">Hero & Welcome</div>
                <div class="text-muted small">Update homepage messaging</div>
            </a>
        </div>
    </div>
</div>
<?php
admin_layout_end();
