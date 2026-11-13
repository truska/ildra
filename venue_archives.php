<?php
declare(strict_types=1);

function ensureVenueArchiveColumns(?PDO $pdo): void
{
    if (!$pdo) return;
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) return;
    ensureVenuesTable($pdo);
    foreach (['archive_state'=>"ENUM('active','archived','hidden') NOT NULL DEFAULT 'active'", 'is_draft'=>'TINYINT(1) NOT NULL DEFAULT 0'] as $column=>$definition) {
        if (!table_column_exists($pdo, 'venues', $column)) $pdo->exec("ALTER TABLE venues ADD COLUMN {$column} {$definition}");
    }
    $ready[$key] = true;
}

function changeVenueArchiveState(PDO $pdo, int $id, string $action, array &$alerts): ?string
{
    ensureVenueArchiveColumns($pdo);
    ensureEventVenueColumns($pdo);
    if ($id <= 0 || !in_array($action, ['archive_venue','restore_venue'], true)) {
        $alerts[] = ['type'=>'danger', 'message'=>'Invalid venue archive action.'];
        return null;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT archive_state FROM venues WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        if ($current === false) throw new RuntimeException('Venue not found.');
        if ($action === 'restore_venue') {
            if (!in_array($current, ['hidden','archived'], true)) throw new RuntimeException('This venue is no longer hidden or archived.');
            $state = 'active';
        } else {
            if ($current !== 'active') throw new RuntimeException('This venue is already hidden or archived.');
            // Include past, draft, hidden and archived events. Preserve every relationship.
            $stmt = $pdo->prepare('SELECT id FROM events WHERE venue_id=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$id]);
            $state = $stmt->fetchColumn() !== false ? 'hidden' : 'archived';
        }
        $pdo->prepare('UPDATE venues SET archive_state=?, is_draft=?, updated_at=NOW() WHERE id=?')->execute([$state, $state === 'active' ? 1 : 0, $id]);
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[] = ['type'=>'danger', 'message'=>$e instanceof PDOException ? 'The venue could not be changed. Nothing was changed.' : $e->getMessage()];
        return null;
    }
}
