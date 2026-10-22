<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/cms.php';
require_once dirname(__DIR__, 2) . '/bookings_store.php';
require_once dirname(__DIR__, 2) . '/announcements.php';
$alerts = [];
$pdo = createPdo(require dirname(__DIR__, 2) . '/config.php', $alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');
// Shadow the event data so no actual calendar entries are created or changed.
$types = $pdo->query('SELECT * FROM event_types')->fetchAll(PDO::FETCH_ASSOC);
foreach (['events', 'event_types', 'booking_items'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
foreach ($types as $type) {
    $keys = array_keys($type);
    $stmt = $pdo->prepare('INSERT INTO event_types (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')');
    $stmt->execute(array_values($type));
}
$migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/2026-10-22-third-party-events.sql');
$pdo->exec($migration);
$pdo->exec($migration);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$fixture = ['title' => 'External ride', 'event_date' => date('Y-m-d', strtotime('+30 days')),
    'venue' => 'Test venue', 'description' => '<p>Organiser information</p>',
    'external_entry_url' => 'https://example.org/entries?event=1&ride=2', 'status' => 'published'];
$id = saveThirdPartyEvent($pdo, $fixture, $alerts);
$assert($id !== null && !$alerts, 'Simple event must save: ' . json_encode($alerts));
$event = fetchEventById($pdo, $id);
$assert($event !== null && isThirdPartyEvent($event), 'Public event lookup must retain the external entry URL.');
$assert($event['external_entry_url'] === $fixture['external_entry_url'], 'Entry URL must persist.');
$assert(empty($event['capacity_enabled']) && empty($event['entry_open_at']) && empty($event['send_reminder_emails']), 'No internal entry schedule, pricing or reminders.');
$assert(($event['classes_offered'] ?? '') === '[]', 'No classes required.');
$assert(event_announcement([$event]) === null, 'No invented ILDRA entry announcements for third-party events.');
$second = saveThirdPartyEvent($pdo, array_replace($fixture, ['title' => 'Earlier external event', 'event_date' => date('Y-m-d', strtotime('+10 days'))]), $alerts);
$events = fetchEvents($pdo, true);
$assert(count($events) === 2 && (int)$events[0]['id'] === $second && (int)$events[1]['id'] === $id, 'Third-party events must appear in calendar date order.');
$assert(saveThirdPartyEvent($pdo, array_replace($fixture, ['title' => 'Updated external event']), $alerts, $id) === $id, 'Existing external event must update.');
foreach (['javascript:alert(1)', 'ftp://example.org/entries', 'not-a-url', ''] as $url) {
    $errors = [];
    $assert(saveThirdPartyEvent($pdo, array_replace($fixture, ['external_entry_url' => $url]), $errors) === null && count($errors) > 0, 'Invalid URL must be rejected.');
}
$errors = [];
$assert(saveThirdPartyEvent($pdo, array_replace($fixture, ['event_date' => '2026-02-30']), $errors) === null, 'Invalid date must fail.');
$errors = [];
$assert(saveThirdPartyEvent($pdo, array_replace($fixture, ['description' => '<p><br></p>']), $errors) === null, 'Empty rich text must fail.');
$pdo->exec('UPDATE events SET external_entry_url=NULL WHERE id=' . (int)$id);
$errors = [];
$assert(saveThirdPartyEvent($pdo, $fixture, $errors, $id) === null, 'Simple editor must not overwrite an ILDRA event.');
echo "Third-party create/edit, calendar ordering, entry isolation and validation: passed\n";
