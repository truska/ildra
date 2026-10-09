<?php
declare(strict_types=1);

require __DIR__ . '/../cms.php';

// Pure helper tests: no bootstrap, database connection or database writes.
date_default_timezone_set('Europe/London');
$settings = ['membership_next_year_from' => '10-01'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach (['membership_year', 'valid_year'] as $field) {
    $cases = [
        ['Earlier today', 2026, '2026-06-15 10:00:00', '2026-06-15 12:00:00', true],
        ['Explicit midnight before purchase', 2026, '2026-06-15 10:00:00', '2026-06-15', false],
        ['Future purchase today', 2026, '2026-06-15 13:00:00', '2026-06-15 12:00:00', false],
        ['At purchase instant', 2026, '2026-06-15 10:00:00', '2026-06-15 10:00:00', true],
        ['Expired prior year', 2025, '2025-06-15 10:00:00', '2026-06-15 12:00:00', false],
        ['Early renewal before rollover', 2027, '2026-09-15 10:00:00', '2026-09-30 23:59:59', false],
        ['Early renewal at rollover', 2027, '2026-09-15 10:00:00', '2026-10-01 00:00:00', true],
        ['Rollover-day purchase still in future', 2027, '2026-10-01 10:00:00', '2026-10-01 09:59:59', false],
        ['Rollover-day purchase effective immediately', 2027, '2026-10-01 10:00:00', '2026-10-01 10:00:00', true],
        ['Renewal covers remaining old year', 2027, '2026-11-15 10:00:00', '2026-12-31 23:59:59', true],
        ['Renewal covers new year', 2027, '2026-11-15 10:00:00', '2027-01-01 00:00:00', true],
        ['Old year ends at midnight', 2026, '2026-06-15 10:00:00', '2027-01-01 00:00:00', false],
    ];
    foreach ($cases as [$label, $year, $purchasedAt, $evaluation, $expected]) {
        $row = [$field => $year, 'status' => 'active', 'purchased_at' => $purchasedAt];
        $date = new DateTimeImmutable($evaluation);
        $assert(annual_product_covers_year($row, $field, (int)$date->format('Y'), $settings, $date) === $expected, "$field: $label");
        if ($field === 'membership_year') {
            $assert(membership_purchase_is_current($row, $settings, $date) === $expected, "Membership eligibility: $label");
        } else {
            $expectedStatus = $expected ? 'active' : ($year > (int)$date->format('Y') ? 'pending' : 'expired');
            $assert(calc_logbook_status($row, $settings, $date) === $expectedStatus, "Logbook status: $label");
        }
    }

    // Exercise omitted-date paths too: explicit timestamps alone did not expose
    // the regression where wrappers supplied today's midnight instead of now.
    $now = new DateTimeImmutable('now');
    $year = (int)$now->format('Y');
    $row = [$field => $year, 'status' => 'active', 'purchased_at' => $now->modify('-1 second')->format('Y-m-d H:i:s')];
    $assert(annual_product_covers_year($row, $field, $year, $settings), "$field: completed purchase must be active now");
    if ($field === 'membership_year') {
        $assert(membership_purchase_is_current($row, $settings), 'Default membership eligibility must use now');
        $assert(membership_status_for_row($row, $settings) === 'active', 'Same-day membership must display Active');
    } else {
        $assert(calc_logbook_status($row, $settings) === 'active', 'Same-day logbook must display Active');
    }

    $row['purchased_at'] = $now->modify('+1 hour')->format('Y-m-d H:i:s');
    $assert(!annual_product_covers_year($row, $field, $year, $settings), "$field: future timestamp must remain ineffective");
    $row['purchased_at'] = $now->modify('-1 second')->format('Y-m-d H:i:s');
    $row['status'] = 'expired';
    $assert(!annual_product_covers_year($row, $field, $year, $settings), "$field: explicitly expired purchase stays expired");
    $assert(($field === 'membership_year' ? membership_status_for_row($row, $settings) : calc_logbook_status($row, $settings)) === 'expired', "$field: expired status must be preserved");
}

// A custom rollover is shared, while the default remains 1 November.
$assert(membership_purchase_year([], new DateTimeImmutable('2026-10-31 23:59:59')) === 2026, 'Default rollover has not opened in October');
$assert(membership_purchase_year([], new DateTimeImmutable('2026-11-01 00:00:00')) === 2027, 'Default rollover opens in November');
$assert(horse_logbook_purchase_year($settings, new DateTimeImmutable('2026-10-01')) === 2027, 'Logbooks share configured rollover');

echo "Annual product validity tests passed.\n";
