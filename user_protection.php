<?php
declare(strict_types=1);

/** Protected accounts remain usable; protection is not a sign-in lock. */
function ensureUserProtectionColumn(PDO $pdo): void
{
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'is_protected'")->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_protected TINYINT(1) NOT NULL DEFAULT 0');
    }
}

function userIsProtected(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT is_protected FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    return (bool)$stmt->fetchColumn();
}
