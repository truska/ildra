<?php
declare(strict_types=1);

require __DIR__ . '/../cms.php';

$settings = ['membership_next_year_from' => '10-01'];
$beforeRollover = new DateTimeImmutable('2026-09-30');
$afterRollover = new DateTimeImmutable('2026-10-01');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(horse_logbook_purchase_year($settings, $beforeRollover) === 2026, 'Before rollover, 2026 must be the purchasable logbook year.');
$assert(horse_logbook_purchase_year($settings, $afterRollover) === 2027, 'From rollover, 2027 must be the purchasable logbook year.');
$assert(calc_logbook_status(['valid_year'=>2027, 'status'=>'active'], $settings, $beforeRollover) === 'pending', 'A future-year logbook must be pending before rollover.');
$assert(calc_logbook_status(['valid_year'=>2026, 'status'=>'active'], $settings, $beforeRollover) === 'active', 'The current-year logbook must be active before rollover.');

$futureOnly = horse_logbook_renewal_state([['valid_year'=>2027, 'status'=>'pending']], $settings, $beforeRollover);
$assert($futureOnly['label'] === '2027 renewal purchased', 'A future-only purchase must not be presented as current.');
$assert($futureOnly['action_enabled'] === true, 'A future-only purchase must still allow purchase of the current year.');

echo "Horse logbook rollover tests passed.\n";
