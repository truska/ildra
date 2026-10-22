<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/helpers.php';
require dirname(__DIR__, 2) . '/cms.php';
$config = require dirname(__DIR__, 2) . '/config.php';
$alerts = [];
$pdo = createPdo($config, $alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');
foreach (['horses', 'horse_qualifications'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
// Apply the live migration twice to verify additive, repeatable upgrades.
$migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/2026-10-22-horse-registration.sql');
$pdo->exec($migration);
$pdo->exec($migration);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$pdo->exec('ALTER TABLE horses AUTO_INCREMENT = 2');
$id = saveHorseForUser($pdo, 999999, ['name' => 'Flow test horse'], $alerts);
$assert($id !== null && !$alerts, 'Name-only horse must save.');
$horse = $pdo->query('SELECT * FROM horses WHERE id=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
$assert($horse['breed'] === null, 'Minimal record must not require breed.');
$assert(!horseLogbookDetailsAreValid($pdo, $horse, $alerts), 'Incomplete logbook details must fail.');
$alerts = [];
$qualification = (int)$pdo->query('SELECT id FROM horse_qualifications LIMIT 1')->fetchColumn();
$details = array_replace($horse, ['owner_name' => 'Test Owner', 'breed' => 'Arab', 'colour' => 'Bay',
    'sex' => 'Mare', 'height_cm' => '152', 'qualification_id' => $qualification,
    'year_of_birth' => '2015', 'passport_number' => 'TEST123']);
$assert(horseLogbookDetailsAreValid($pdo, $details, $alerts), 'Complete logbook details must pass.');
$assert(saveHorseForUser($pdo, 999999, $details, $alerts, $id) === $id && !$alerts, 'Details must save on the existing horse.');
$stored = $pdo->query('SELECT * FROM horses WHERE id=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
$assert($stored['owner_name'] === 'Test Owner', 'Owner name must persist.');
$loadedHorse = fetchHorseForUserById($pdo, 999999, $id);
$assert(($loadedHorse['owner_name'] ?? '') === 'Test Owner', 'Edit lookup must return saved owner name.');
$horseList = fetchHorsesForUser($pdo, 999999);
$assert(($horseList[0]['owner_name'] ?? '') === 'Test Owner', 'Logbook selection must return saved owner name.');
foreach (['name', 'owner_name', 'breed', 'colour', 'sex', 'height_cm', 'qualification_id', 'year_of_birth', 'passport_number'] as $field) {
    $missingAlerts = [];
    $assert(!horseLogbookDetailsAreValid($pdo, array_replace($details, [$field => '']), $missingAlerts), 'Edit must reject missing ' . $field);
}

$renamed = array_replace($stored, ['name' => 'Renamed horse']);
$assert(saveHorseForUser($pdo, 999999, $renamed, $alerts, $id) === $id, 'Name edit must save.');
$assert($pdo->query('SELECT passport_number FROM horses WHERE id=' . (int)$id)->fetchColumn() === 'TEST123', 'Name edits must preserve logbook details.');
$alerts = [];
foreach (['None', 'Other'] as $label) {
    $option = $pdo->prepare('SELECT id FROM horse_qualifications WHERE name = ?');
    $option->execute([$label]);
    $optionId = (int)$option->fetchColumn();
    $assert($optionId > 0, $label . ' qualification must exist.');
    $assert(horseLogbookDetailsAreValid($pdo, array_replace($details, ['qualification_id' => $optionId]), $alerts), $label . ' must be accepted without date of birth.');
    $assert(saveHorseForUser($pdo, 999999, array_replace($details, ['qualification_id' => $optionId, 'breed' => 'Updated breed']), $alerts, $id) === $id, 'Edited details must save.');
}
$assert($pdo->query('SELECT breed FROM horses WHERE id=' . (int)$id)->fetchColumn() === 'Updated breed', 'Edited breed must persist.');
echo "Name-only registration, required logbook details, owner persistence and name edits: passed\n";
