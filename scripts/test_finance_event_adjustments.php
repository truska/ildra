<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance.php';

// Use an isolated database and the reports' actual SELECT statements.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value);
$pdo->sqliteCreateFunction('CONCAT_WS', static fn($separator, ...$values) => implode($separator, array_filter($values, static fn($value) => $value !== null)));
$pdo->exec('CREATE TABLE users (id INTEGER, first_name TEXT, last_name TEXT, email TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'Test', 'Member', 'test@example.invalid')");
$pdo->exec('CREATE TABLE booking_items (id INTEGER, event_id INTEGER)');
$pdo->exec('CREATE TABLE finance_transactions (id INTEGER, user_id INTEGER, type TEXT, amount REAL, metadata TEXT, created_at TEXT)');
$insert = $pdo->prepare('INSERT INTO finance_transactions VALUES (?, 1, ?, ?, ?, ?)');
foreach ([
    [77, 'manual_debit', 2, 36], // Legacy credit carrying the wrong type label.
    [78, 'manual_credit', 5, 36],
    [79, 'manual_debit', -1, 36], // Reversal restores revenue.
    [80, 'refund', -3, 36],
    [81, 'manual_credit', 100, 37],
    [82, 'manual_credit', 100, null],
] as [$id, $type, $amount, $eventId]) {
    $insert->execute([$id, $type, $amount, json_encode(['event_id' => $eventId]), '2026-10-19 14:47:30']);
}

$source = file_get_contents(dirname(__DIR__) . '/admin/finance_event.php');
if (!preg_match('/SELECT ft\.\*, COALESCE\(NULLIF\(TRIM\(CONCAT_WS.*?ORDER BY ft\.created_at DESC, ft\.id DESC/s', $source, $match)) {
    throw new RuntimeException('Event adjustment query not found.');
}
$stmt = $pdo->prepare($match[0]);
$stmt->execute([':event_id' => 36]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (array_map('intval', array_column($rows, 'id')) !== [80, 79, 78, 77]) {
    throw new RuntimeException('Missing adjustment or incorrect event scope.');
}
$total = 0.0;
foreach ($rows as $row) {
    $total += finance_event_adjustment_amount($row);
    if ($row['person_name'] !== 'Test Member') throw new RuntimeException('Account holder missing.');
}
if ($total !== 9.0) throw new RuntimeException('Event credit/reversal total incorrect.');
if (finance_event_adjustment_amount(['type' => 'manual_debit', 'amount' => '2.00']) !== 2.0) {
    throw new RuntimeException('T-77 must reduce event revenue by £2.');
}

$source = file_get_contents(dirname(__DIR__) . '/admin/finance.php');
if (!preg_match('/SELECT event_id, SUM\(refund_amount\).*?GROUP BY event_id/s', $source, $match)) {
    throw new RuntimeException('Event summary adjustment query not found.');
}
$totals = $pdo->query($match[0])->fetchAll(PDO::FETCH_KEY_PAIR);
if ((float)($totals[36] ?? 0) !== $total || (float)($totals[37] ?? 0) !== 100.0) {
    throw new RuntimeException('Event list and detail adjustment totals disagree.');
}
echo "Event-linked credits, legacy T-77 type, reversals, refunds, account holders and report totals: passed.\n";
