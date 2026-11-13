<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/venue_archives.php';
function ensureVenuesTable(PDO $pdo): void {}
function ensureEventVenueColumns(PDO $pdo): void {}
function table_column_exists(PDO $pdo, string $table, string $column): bool { return true; }
class VenueArchiveTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$pdo = new VenueArchiveTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn() => '2026-11-13 12:00:00');
$pdo->exec("CREATE TABLE venues(id INTEGER PRIMARY KEY, archive_state TEXT DEFAULT 'active', is_draft INTEGER DEFAULT 0, updated_at TEXT)");
$pdo->exec('CREATE TABLE events(id INTEGER PRIMARY KEY, venue_id INTEGER, archive_state TEXT, status TEXT)');
$pdo->exec('INSERT INTO venues(id) VALUES (1),(2),(3),(4)');
$pdo->exec("INSERT INTO events VALUES (1,2,'active','published'),(2,3,'archived','draft'),(3,4,'hidden','draft')");
$alerts = [];
check(changeVenueArchiveState($pdo, 1, 'archive_venue', $alerts) === 'archived', 'Unlinked venue archives');
for ($id=2; $id<=4; $id++) {
    check(changeVenueArchiveState($pdo, $id, 'archive_venue', $alerts) === 'hidden', 'Any linked event causes Hidden');
    check(changeVenueArchiveState($pdo, $id, 'restore_venue', $alerts) === 'active', 'Hidden venue restores');
    check((int)$pdo->query("SELECT is_draft FROM venues WHERE id={$id}")->fetchColumn() === 1, 'Restores as draft');
}
check((int)$pdo->query('SELECT COUNT(*) FROM events WHERE venue_id IN (2,3,4)')->fetchColumn() === 3, 'All event links retained');
check(changeVenueArchiveState($pdo, 1, 'restore_venue', $alerts) === 'active', 'Archived venue restores');
check(changeVenueArchiveState($pdo, 1, 'restore_venue', $alerts) === null, 'Repeated restore rejected');
check(changeVenueArchiveState($pdo, 999, 'archive_venue', $alerts) === null, 'Missing venue rejected');
check(!$pdo->inTransaction(), 'Failures roll back');
echo "Venue archive routing, all event links, draft restoration and invalid actions passed.\n";
