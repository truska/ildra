<?php
declare(strict_types=1);

function ensureEventArchiveColumn(?PDO $pdo): void
{
    if (!$pdo) return;
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) return;
    if (!table_column_exists($pdo, 'events', 'archive_state')) {
        $pdo->exec("ALTER TABLE events ADD COLUMN archive_state ENUM('active','archived','hidden') NOT NULL DEFAULT 'active'");
    }
    $ready[$key] = true;
}

/** Count every entry, including withdrawn entries, and unpublished notes/reports. */
function eventHasArchiveDependencies(PDO $pdo, int $id): bool
{
    foreach (['booking_items', 'event_ride_notes', 'news_articles'] as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE event_id=? LIMIT 1");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== false) return true;
    }
    return false;
}

function changeEventArchiveState(PDO $pdo, int $id, string $action, array &$alerts): ?string
{
    // Schema setup must finish before starting the transaction.
    ensureEventArchiveColumn($pdo);
    ensure_bookings_tables($pdo);
    ensureRideNotesTables($pdo);
    ensureNewsTables($pdo);
    if ($id <= 0 || !in_array($action, ['archive_event', 'restore_event'], true)) {
        $alerts[] = ['type'=>'danger', 'message'=>'Invalid event archive action.'];
        return null;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT archive_state FROM events WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        if ($current === false) throw new RuntimeException('Event not found.');
        if ($action === 'restore_event') {
            if (!in_array($current, ['archived', 'hidden'], true)) throw new RuntimeException('This event is no longer archived or hidden.');
            $state = 'active';
        } else {
            if ($current !== 'active') throw new RuntimeException('This event is already archived or hidden.');
            $state = eventHasArchiveDependencies($pdo, $id) ? 'hidden' : 'archived';
        }
        // Never republish automatically when restoring.
        $pdo->prepare("UPDATE events SET archive_state=?, status='draft', updated_at=NOW() WHERE id=?")->execute([$state, $id]);
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[] = ['type'=>'danger', 'message'=>$e instanceof PDOException ? 'The event could not be changed. Nothing was changed.' : $e->getMessage()];
        return null;
    }
}

/** Public linked content follows its parent event's visibility. */
function eventIsArchived(?PDO $pdo, int $id): bool
{
    if (!$pdo || $id <= 0) return false;
    ensureEventArchiveColumn($pdo);
    $stmt = $pdo->prepare('SELECT archive_state FROM events WHERE id=?');
    $stmt->execute([$id]);
    $state = $stmt->fetchColumn();
    return $state !== false && $state !== 'active';
}
