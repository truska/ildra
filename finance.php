<?php
declare(strict_types=1);

/**
 * Ensure the finance tables exist.
 */
function ensure_finance_tables(?PDO $pdo, array &$alerts = []): bool
{
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_credits (
                user_id INT UNSIGNED PRIMARY KEY,
                balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (balance)
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS finance_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                direction VARCHAR(10) NOT NULL DEFAULT 'credit',
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                balance_after DECIMAL(12,2) DEFAULT NULL,
                reference VARCHAR(64) DEFAULT NULL,
                notes VARCHAR(255) DEFAULT NULL,
                metadata TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (type),
                INDEX (created_at)
            )
        ");
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not prepare finance tables.'];
        return false;
    }
}

/**
 * Ensure loyalty card table exists.
 */
function ensure_loyalty_tables(?PDO $pdo, array &$alerts = []): bool
{
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS loyalty_cards (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                card_number VARCHAR(24) NOT NULL,
                points_balance INT UNSIGNED NOT NULL DEFAULT 0,
                tier VARCHAR(20) NOT NULL DEFAULT 'bronze',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_loyalty_user (user_id),
                UNIQUE KEY uniq_loyalty_card_number (card_number),
                INDEX idx_loyalty_points (points_balance)
            )
        ");
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not prepare loyalty tables.'];
        return false;
    }
}

/**
 * Fetch a user's loyalty card, creating one if it doesn't exist.
 */
function fetch_or_create_loyalty_card(?PDO $pdo, int $userId, array &$alerts = []): ?array
{
    if (!$pdo || $userId <= 0 || !ensure_loyalty_tables($pdo, $alerts)) {
        return null;
    }

    $select = $pdo->prepare("
        SELECT id, user_id, card_number, points_balance, tier, created_at, updated_at
        FROM loyalty_cards
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $select->execute([':user_id' => $userId]);
    $row = $select->fetch();
    if ($row) {
        return $row;
    }

    $cardNumber = 'LC-' . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
    try {
        $insert = $pdo->prepare("
            INSERT INTO loyalty_cards (user_id, card_number, points_balance, tier, created_at, updated_at)
            VALUES (:user_id, :card_number, 0, 'bronze', NOW(), NOW())
        ");
        $insert->execute([
            ':user_id' => $userId,
            ':card_number' => $cardNumber,
        ]);
    } catch (PDOException $e) {
        // If another request inserted concurrently, fall through to final fetch.
    }

    $select->execute([':user_id' => $userId]);
    $row = $select->fetch();
    return $row ?: null;
}

function fetch_user_credit_balance(?PDO $pdo, int $userId): float
{
    if (!$pdo || $userId <= 0 || !ensure_finance_tables($pdo)) {
        return 0.0;
    }
    $stmt = $pdo->prepare("SELECT balance FROM user_credits WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $bal = $stmt->fetchColumn();
    return $bal !== false ? (float)$bal : 0.0;
}

function fetch_credit_balances(?PDO $pdo, int $limit = 200): array
{
    if (!$pdo || !ensure_finance_tables($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT uc.user_id, uc.balance, uc.updated_at,
               u.email, u.first_name, u.last_name
        FROM user_credits uc
        LEFT JOIN users u ON uc.user_id = u.id
        ORDER BY uc.balance DESC, uc.updated_at DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_finance_transactions(?PDO $pdo, int $limit = 100, string $sortKey = 'created_at', string $sortDir = 'DESC'): array
{
    if (!$pdo || !ensure_finance_tables($pdo)) {
        return [];
    }

    $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
    $sortMap = [
        'when' => 'ft.created_at',
        'user' => 'u.email',
        'type' => 'ft.type',
        'amount' => 'ft.amount',
        'balance' => 'ft.balance_after',
        'reference' => 'ft.reference',
        'notes' => 'ft.notes',
        'created_at' => 'ft.created_at',
    ];
    $orderBy = $sortMap[$sortKey] ?? 'ft.created_at';

    $stmt = $pdo->prepare("
        SELECT ft.*, u.email, u.first_name, u.last_name
        FROM finance_transactions ft
        LEFT JOIN users u ON ft.user_id = u.id
        ORDER BY $orderBy $sortDir, ft.id $sortDir
        LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $meta = [];
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string)$row['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $row['metadata'] = $meta;
    }
    return $rows;
}

/**
 * Record a finance transaction and update the user's credit balance.
 * Positive amounts credit the user; negative amounts debit the user.
 */
function record_finance_transaction(?PDO $pdo, array $data, array &$alerts = []): bool
{
    if (!$pdo || !ensure_finance_tables($pdo, $alerts)) {
        return false;
    }

    $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
    $affectsCredit = !array_key_exists('affects_credit', $data) || !empty($data['affects_credit']);
    $type = trim((string)($data['type'] ?? 'manual'));
    $amountRaw = $data['amount'] ?? 0;
    $amount = round((float)$amountRaw, 2);
    $reference = substr(trim((string)($data['reference'] ?? '')), 0, 64);
    $notes = substr(trim((string)($data['notes'] ?? '')), 0, 255);
    $metadata = $data['metadata'] ?? [];
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $direction = $amount >= 0 ? 'credit' : 'debit';

    try {
        $pdo->beginTransaction();

        $balanceBefore = 0.0;
        if ($userId && $affectsCredit) {
            $select = $pdo->prepare("SELECT balance FROM user_credits WHERE user_id = :user_id FOR UPDATE");
            $select->execute([':user_id' => $userId]);
            $existing = $select->fetchColumn();
            if ($existing === false) {
                $pdo->prepare("INSERT INTO user_credits (user_id, balance, created_at, updated_at) VALUES (:user_id, 0, NOW(), NOW())")
                    ->execute([':user_id' => $userId]);
            } else {
                $balanceBefore = (float)$existing;
            }
        }

        $balanceAfter = $balanceBefore + $amount;
        if ($userId && $affectsCredit) {
            $update = $pdo->prepare("UPDATE user_credits SET balance = :balance, updated_at = NOW() WHERE user_id = :user_id");
            $update->execute([':balance' => $balanceAfter, ':user_id' => $userId]);
        }

        $insert = $pdo->prepare("
            INSERT INTO finance_transactions (user_id, type, direction, amount, balance_after, reference, notes, metadata, created_at)
            VALUES (:user_id, :type, :direction, :amount, :balance_after, :reference, :notes, :metadata, NOW())
        ");
        $insert->execute([
            ':user_id' => $userId ?: null,
            ':type' => $type,
            ':direction' => $direction,
            ':amount' => $amount,
            ':balance_after' => ($userId && $affectsCredit) ? $balanceAfter : null,
            ':reference' => $reference !== '' ? $reference : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':metadata' => $metadata ? json_encode($metadata) : null,
        ]);

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $alerts[] = ['type' => 'danger', 'message' => 'Could not record finance transaction.'];
        return false;
    }
}
