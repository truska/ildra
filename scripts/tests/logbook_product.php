<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/helpers.php';
require dirname(__DIR__, 2) . '/cms.php';
$config = require dirname(__DIR__, 2) . '/config.php';
$alerts = [];
$pdo = createPdo($config, $alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');

// Shadow production tables: neither product configuration nor purchases change.
foreach (['horse_logbook_types', 'horse_logbook_purchases', 'site_settings'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$pdo->exec("INSERT INTO horse_logbook_types (id,name,cost,status,valid_year)
    VALUES (1,'Horse Logbook',7.50,'published',2020)");
$settings = ['membership_next_year_from' => '11-01'];
foreach (['2026-10-31' => 2026, '2026-11-01' => 2027, '2027-11-01' => 2028] as $date => $year) {
    $type = fetchPurchasableHorseLogbookType($pdo, $settings, new DateTimeImmutable($date));
    if (!$type || (int)$type['id'] !== 1 || (int)$type['valid_year'] !== $year) {
        throw new RuntimeException('Product rollover failed on ' . $date);
    }
}
if ((int)$pdo->query('SELECT valid_year FROM horse_logbook_types WHERE id=1')->fetchColumn() !== 2020) {
    throw new RuntimeException('Stored product year changed.');
}
echo "One unchanged product covers successive renewal years: passed\n";

$year = horse_logbook_purchase_year(getSiteSettings($pdo));
$purchase = ['horse_id' => 999999, 'logbook_type_id' => 1, 'valid_year' => $year, 'amount' => '7.50'];
if (!saveHorseLogbookPurchase($pdo, $purchase, $alerts)) {
    throw new RuntimeException('Purchase failed: ' . json_encode($alerts));
}
if ((int)$pdo->query('SELECT valid_year FROM horse_logbook_purchases')->fetchColumn() !== $year) {
    throw new RuntimeException('Incorrect purchase year.');
}
$alerts = [];
if (saveHorseLogbookPurchase($pdo, $purchase, $alerts)) throw new RuntimeException('Duplicate accepted.');
$alerts = [];
if (saveHorseLogbookPurchase($pdo, array_replace($purchase, ['valid_year' => $year - 1]), $alerts)) {
    throw new RuntimeException('Wrong purchase year accepted.');
}
$pdo->exec("UPDATE horse_logbook_types SET status='draft' WHERE id=1");
if (fetchPurchasableHorseLogbookType($pdo, $settings)) throw new RuntimeException('Draft offered for sale.');
echo "Purchase year, duplicate protection and draft availability: passed\n";
