<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/cms.php';
function ageCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$today = new DateTimeImmutable('2026-11-25', new DateTimeZone('Europe/London'));
$message = 'All riders must be at least 5 years old.';
foreach (['' => null, '2021-11-25' => null, '2021-11-24' => null, '1980-01-01' => null, '2021-11-26' => $message, '2022-11-25' => $message, '2026-11-25' => $message] as $dob => $expected) {
    ageCheck(rider_birth_date_error($dob, $today) === $expected, 'Age boundary failed for ' . $dob);
}
foreach (['2026-11-26', '2021-02-29', 'invalid'] as $dob) ageCheck(rider_birth_date_error($dob, $today) !== null, 'Invalid date accepted');
ageCheck(rider_birth_date_error('2020-02-29', new DateTimeImmutable('2025-02-28')) === $message, 'Leap birthday prematurely accepted');
ageCheck(rider_birth_date_error('2020-02-29', new DateTimeImmutable('2025-03-01')) === null, 'Leap birthday rejected');
$alerts = [];
$pdo = createPdo(require dirname(__DIR__, 2) . '/config.php', $alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');
// All application tables are shadowed with empty connection-local fixtures.
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $quoted = str_replace('`', '``', $table);
    $pdo->exec("CREATE TEMPORARY TABLE age_seed LIKE `$quoted`");
    $pdo->exec("CREATE TEMPORARY TABLE `$quoted` LIKE age_seed");
    $pdo->exec('DROP TEMPORARY TABLE age_seed');
}
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/London'));
$young = $today->modify('-4 years')->format('Y-m-d');
$five = $today->modify('-5 years')->format('Y-m-d');
$data = ['first_name'=>'Age', 'last_name'=>'Fixture', 'dob'=>$young];
$alerts = [];
ageCheck(savePersonForUser($pdo, 1, $data, $alerts) === null, 'Under-five creation succeeded');
ageCheck(($alerts[0]['message'] ?? '') === $message && ($alerts[0]['field'] ?? '') === 'dob', 'Field warning missing');
ageCheck((int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn() === 0, 'Rejected creation wrote a record');
$alerts = [];
$id = savePersonForUser($pdo, 1, array_replace($data, ['dob'=>$five]), $alerts);
ageCheck((int)$id > 0, 'Fifth birthday rejected: ' . json_encode($alerts));
$alerts = [];
ageCheck(savePersonForUser($pdo, 1, $data, $alerts, $id) === null, 'Under-five edit succeeded');
ageCheck($pdo->query('SELECT dob FROM people LIMIT 1')->fetchColumn() === $five, 'Rejected edit changed DOB');
$alerts = [];
ageCheck((int)savePersonForUser($pdo, 1, array_replace($data, ['dob'=>'']), $alerts) > 0, 'Optional blank DOB rejected');
$alerts = [];
ageCheck(createMemberForUser($pdo, 1, 'Age', 'Fixture', $young, $alerts) === null, 'Membership new-person route bypassed minimum');
echo "PASS: fifth-birthday boundaries, optional/invalid dates, leap birthday, rejected create/edit without writes and membership creation guard. Temporary tables only.\n";
