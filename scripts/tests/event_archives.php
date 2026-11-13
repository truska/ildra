<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/event_archives.php';
// Exercise real SQL/state changes in an isolated in-memory database.
function table_column_exists(PDO $pdo, string $table, string $column): bool { return true; }
function ensure_bookings_tables(PDO $pdo): void {}
function ensureRideNotesTables(PDO $pdo): void {}
function ensureNewsTables(PDO $pdo): void {}
class ArchiveTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$pdo = new ArchiveTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn() => '2026-11-13 12:00:00');
$pdo->exec("CREATE TABLE events(id INTEGER PRIMARY KEY, archive_state TEXT DEFAULT 'active', status TEXT DEFAULT 'published', updated_at TEXT)");
foreach (['booking_items', 'event_ride_notes', 'news_articles'] as $table) $pdo->exec("CREATE TABLE {$table}(event_id INTEGER, content TEXT)");
for ($id = 1; $id <= 4; $id++) $pdo->exec("INSERT INTO events(id) VALUES ({$id})");
$alerts = [];
check(changeEventArchiveState($pdo, 1, 'archive_event', $alerts) === 'archived', 'Empty event archives');
check(eventIsArchived($pdo, 1), 'Archived parent hides public content');
foreach (['booking_items', 'event_ride_notes', 'news_articles'] as $index => $table) {
    $id = $index + 2;
    $pdo->exec("INSERT INTO {$table} VALUES ({$id}, 'retained')");
    check(changeEventArchiveState($pdo, $id, 'archive_event', $alerts) === 'hidden', "{$table} causes Hidden");
    check($pdo->query("SELECT content FROM {$table}")->fetchColumn() === 'retained', 'Related content retained');
    check(changeEventArchiveState($pdo, $id, 'restore_event', $alerts) === 'active', 'Hidden event restores');
    check($pdo->query("SELECT status FROM events WHERE id={$id}")->fetchColumn() === 'draft', 'Restores only as draft');
}
check(changeEventArchiveState($pdo, 1, 'restore_event', $alerts) === 'active', 'Archived event restores');
check(changeEventArchiveState($pdo, 1, 'restore_event', $alerts) === null, 'Duplicate restore rejected');
check(changeEventArchiveState($pdo, 999, 'archive_event', $alerts) === null, 'Missing event rejected');
check(!$pdo->inTransaction(), 'Failed actions roll back');
echo "Event archive routing, dependency retention, draft restoration and invalid actions passed.\n";
