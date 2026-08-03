<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

try {
    $pdo->exec("ALTER TABLE events ADD COLUMN non_member_entry_open_at DATETIME NULL DEFAULT NULL AFTER entry_open_at");
    echo "Added non_member_entry_open_at column.\n";
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'Duplicate column name') === false) {
        throw $e;
    }
    echo "non_member_entry_open_at column already exists.\n";
}

$stmt = $pdo->prepare("
    UPDATE events
    SET non_member_entry_open_at = DATE_ADD(entry_open_at, INTERVAL 7 DAY),
        updated_at = NOW()
    WHERE entry_open_at IS NOT NULL
      AND (non_member_entry_open_at IS NULL OR non_member_entry_open_at = '0000-00-00 00:00:00')
");
$stmt->execute();

echo "Backfilled events: " . (int)$stmt->rowCount() . "\n";
