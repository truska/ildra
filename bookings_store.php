<?php
declare(strict_types=1);

function ensure_bookings_tables(?PDO $pdo, array &$alerts = []): bool
{
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bookings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                new_id INT UNSIGNED DEFAULT NULL,
                booking_ref VARCHAR(64) NOT NULL UNIQUE,
                user_id INT UNSIGNED DEFAULT NULL,
                contact_name VARCHAR(255) NOT NULL DEFAULT '',
                contact_email VARCHAR(255) NOT NULL DEFAULT '',
                contact_phone VARCHAR(64) DEFAULT NULL,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (new_id),
                INDEX (created_at)
            )
        ");
        if (!table_column_exists($pdo, 'bookings', 'new_id')) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN new_id INT UNSIGNED DEFAULT NULL");
        }
        if (!table_index_on_column_exists($pdo, 'bookings', 'new_id')) {
            if (table_index_count($pdo, 'bookings') < 64) {
                $pdo->exec("ALTER TABLE bookings ADD INDEX idx_bookings_new_id (new_id)");
            }
        }
        try {
            $pdo->exec("UPDATE bookings SET new_id = id WHERE new_id IS NULL OR new_id = 0");
        } catch (PDOException $e) {
            // ignore
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                booking_id INT UNSIGNED NOT NULL,
                guid VARCHAR(64) DEFAULT NULL,
                event_id INT UNSIGNED DEFAULT NULL,
                event_title VARCHAR(255) DEFAULT NULL,
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                booking_type VARCHAR(50) NOT NULL DEFAULT 'ride',
                booking_type_id INT UNSIGNED DEFAULT NULL,
                booking_type_label VARCHAR(100) DEFAULT NULL,
                metadata TEXT DEFAULT NULL,
                is_withdrawn TINYINT(1) NOT NULL DEFAULT 0,
                withdrawn_at DATETIME DEFAULT NULL,
                withdrawn_by_user_id INT UNSIGNED DEFAULT NULL,
                withdrawal_reason TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (booking_id),
                UNIQUE KEY uniq_booking_item_guid (guid),
                INDEX (event_id),
                INDEX (is_withdrawn)
            )
        ");
        ensure_booking_items_withdrawal_columns($pdo);
        ensure_booking_items_guid_column($pdo);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not verify bookings tables.'];
        return false;
    }
}

function ensure_booking_items_withdrawal_columns(PDO $pdo): void
{
    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM booking_items")->fetchAll() as $row) {
            $cols[(string)($row['Field'] ?? '')] = true;
        }
    } catch (PDOException $e) {
        return;
    }

    $alter = [];
    if (!isset($cols['is_withdrawn'])) {
        $alter[] = "ADD COLUMN is_withdrawn TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!isset($cols['withdrawn_at'])) {
        $alter[] = "ADD COLUMN withdrawn_at DATETIME DEFAULT NULL";
    }
    if (!isset($cols['withdrawn_by_user_id'])) {
        $alter[] = "ADD COLUMN withdrawn_by_user_id INT UNSIGNED DEFAULT NULL";
    }
    if (!isset($cols['withdrawal_reason'])) {
        $alter[] = "ADD COLUMN withdrawal_reason TEXT DEFAULT NULL";
    }
    if ($alter) {
        $pdo->exec("ALTER TABLE booking_items " . implode(', ', $alter));
    }
    if (!table_index_on_column_exists($pdo, 'booking_items', 'is_withdrawn')) {
        try {
            if (table_index_count($pdo, 'booking_items') < 64) {
                $pdo->exec("ALTER TABLE booking_items ADD INDEX idx_booking_items_is_withdrawn (is_withdrawn)");
            }
        } catch (PDOException $e) {
            // ignore if exists or index limit reached
        }
    }
}

function ensure_booking_items_guid_column(PDO $pdo): void
{
    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM booking_items")->fetchAll() as $row) {
            $cols[(string)($row['Field'] ?? '')] = true;
        }
    } catch (PDOException $e) {
        return;
    }
    if (!isset($cols['guid'])) {
        try {
            $pdo->exec("ALTER TABLE booking_items ADD COLUMN guid VARCHAR(64) DEFAULT NULL");
        } catch (PDOException $e) {
            return;
        }
    }
    if (!table_index_exists($pdo, 'booking_items', 'uniq_booking_item_guid')) {
        try {
            if (table_index_count($pdo, 'booking_items') < 64) {
                $pdo->exec("ALTER TABLE booking_items ADD UNIQUE KEY uniq_booking_item_guid (guid)");
            }
        } catch (PDOException $e) {
            // ignore if exists or index limit reached
        }
    }
}

function generate_booking_item_guid(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $guid = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("SELECT 1 FROM booking_items WHERE guid = :g LIMIT 1");
        $stmt->execute([':g' => $guid]);
        if (!$stmt->fetchColumn()) {
            return $guid;
        }
    }
    return bin2hex(random_bytes(16));
}

function ensure_booking_item_guid(PDO $pdo, int $itemId): ?string
{
    if ($itemId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT guid FROM booking_items WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $itemId]);
        $guid = (string)($stmt->fetchColumn() ?: '');
        if ($guid !== '') {
            return $guid;
        }
        $guid = generate_booking_item_guid($pdo);
        $upd = $pdo->prepare("UPDATE booking_items SET guid = :g WHERE id = :id LIMIT 1");
        $upd->execute([':g' => $guid, ':id' => $itemId]);
        return $guid;
    } catch (PDOException $e) {
        return null;
    }
}

function seed_bookings_from_session(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    if (empty($_SESSION['orders']) || !is_array($_SESSION['orders'])) {
        return;
    }
    $alerts = [];
    foreach ($_SESSION['orders'] as $order) {
        append_booking_record($order, $alerts, $pdo, false);
    }
}

function load_all_bookings(?PDO $pdo = null, bool $seedFromSession = true, array &$alerts = []): array
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if (!$pdo || !ensure_bookings_tables($pdo, $alerts)) {
        return [];
    }
    try {
        $stmt = $pdo->query("SELECT * FROM bookings ORDER BY created_at DESC");
        $bookings = $stmt->fetchAll() ?: [];

        if (!$bookings && $seedFromSession) {
            seed_bookings_from_session($pdo);
            $stmt = $pdo->query("SELECT * FROM bookings ORDER BY created_at DESC");
            $bookings = $stmt->fetchAll() ?: [];
        }

        $ids = [];
        foreach ($bookings as $b) {
            $maybeId = $b['id'] ?? ($b['new_id'] ?? null);
            if ($maybeId !== null && $maybeId !== '') {
                $ids[] = $maybeId;
            }
        }
        $itemsByBooking = [];
        $items = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $itemStmt = $pdo->prepare("SELECT * FROM booking_items WHERE booking_id IN ($placeholders) ORDER BY id ASC");
            $itemStmt->execute($ids);
            $items = array_map('hydrate_booking_item', $itemStmt->fetchAll());
        }

        // Map events and event type quick view fields to items
        $eventIds = [];
        foreach ($items as $item) {
            if (!empty($item['event_id'])) {
                $eventIds[] = (int)$item['event_id'];
            }
        }
        $eventMeta = [];
        if ($eventIds) {
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $evStmt = $pdo->prepare("
                SELECT e.id, e.title, e.event_type_id, et.name AS event_type_name, et.quick_view_fields
                FROM events e
                LEFT JOIN event_types et ON e.event_type_id = et.id
                WHERE e.id IN ($placeholders)
            ");
            $evStmt->execute($eventIds);
            foreach ($evStmt->fetchAll() as $row) {
                $quickFields = [];
                if (!empty($row['quick_view_fields'])) {
                    $decoded = json_decode((string)$row['quick_view_fields'], true);
                    if (is_array($decoded)) {
                        $quickFields = $decoded;
                    }
                }
                $eventMeta[(int)$row['id']] = [
                    'title' => $row['title'] ?? '',
                    'event_type_id' => $row['event_type_id'] ?? null,
                    'event_type_name' => $row['event_type_name'] ?? '',
                    'quick_view_fields' => $quickFields,
                ];
            }
        }

        foreach ($items as $item) {
            $bid = (string)($item['booking_id'] ?? '');
            if (!isset($itemsByBooking[$bid])) {
                $itemsByBooking[$bid] = [];
            }
            $evId = (int)($item['event_id'] ?? 0);
            if ($evId && isset($eventMeta[$evId])) {
                $item['event_title'] = $item['event_title'] ?? $eventMeta[$evId]['title'];
                $item['event_type_id'] = $eventMeta[$evId]['event_type_id'];
                $item['event_type_name'] = $eventMeta[$evId]['event_type_name'];
                $item['quick_view_fields'] = $eventMeta[$evId]['quick_view_fields'];
            } else {
                $item['quick_view_fields'] = [];
            }
            $itemsByBooking[$bid][] = $item;
        }

        foreach ($bookings as &$booking) {
            $bid = (string)($booking['id'] ?? ($booking['new_id'] ?? ($booking['booking_ref'] ?? '')));
            $booking['items'] = $bid !== '' && isset($itemsByBooking[$bid]) ? $itemsByBooking[$bid] : [];
            $booking['booking_ref'] = $booking['booking_ref'] ?? '';
        }
        return $bookings;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not load bookings from the database.'];
        return [];
    }
}

function append_booking_record(array $booking, array &$alerts = [], ?PDO $pdo = null, bool $seedFromSession = true): bool
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if (!$pdo || !ensure_bookings_tables($pdo, $alerts)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable for bookings.'];
        return false;
    }

    $bookingRef = $booking['booking_ref'] ?? $booking['id'] ?? null;
    if (!$bookingRef) {
        $bookingRef = 'BK-' . strtoupper(bin2hex(random_bytes(4)));
    }
    $total = price_to_number($booking['total'] ?? 0);
    try {
        // Replace any existing record for this booking id
        $pdo->prepare("DELETE FROM booking_items WHERE booking_id IN (SELECT id FROM bookings WHERE booking_ref = :booking_ref)")->execute([':booking_ref' => $bookingRef]);

        $stmt = $pdo->prepare("
            INSERT INTO bookings (booking_ref, user_id, contact_name, contact_email, contact_phone, total, created_at, updated_at)
            VALUES (:booking_ref, :user_id, :contact_name, :contact_email, :contact_phone, :total, :created_at, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                contact_name = VALUES(contact_name),
                contact_email = VALUES(contact_email),
                contact_phone = VALUES(contact_phone),
                total = VALUES(total),
                created_at = VALUES(created_at),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':booking_ref' => $bookingRef,
            ':user_id' => $booking['user_id'] ?? null,
            ':contact_name' => $booking['contact_name'] ?? '',
            ':contact_email' => $booking['contact_email'] ?? '',
            ':contact_phone' => $booking['contact_phone'] ?? null,
            ':total' => $total,
            ':created_at' => $booking['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $bookingId = (int)$pdo->lastInsertId();
        if ($bookingId === 0) {
            $stmtExisting = $pdo->prepare("SELECT id FROM bookings WHERE booking_ref = :booking_ref LIMIT 1");
            $stmtExisting->execute([':booking_ref' => $bookingRef]);
            $bookingId = (int)($stmtExisting->fetchColumn() ?: 0);
        }
        if ($bookingId > 0 && table_column_exists($pdo, 'bookings', 'new_id')) {
            try {
                $stmtNewId = $pdo->prepare("UPDATE bookings SET new_id = :nid WHERE id = :id AND (new_id IS NULL OR new_id = 0)");
                $stmtNewId->execute([':nid' => $bookingId, ':id' => $bookingId]);
            } catch (PDOException $e) {
                // ignore
            }
        }

        if (!empty($booking['items']) && is_array($booking['items'])) {
            $itemStmt = $pdo->prepare("
                INSERT INTO booking_items (
                    booking_id, guid, event_id, event_title, price,
                    booking_type, booking_type_id, booking_type_label, metadata, created_at
                ) VALUES (
                    :booking_id, :guid, :event_id, :event_title, :price,
                    :booking_type, :booking_type_id, :booking_type_label, :metadata, :created_at
                )
            ");
            foreach ($booking['items'] as $item) {
                $itemPrice = price_to_number($item['price'] ?? 0);
                $guid = generate_booking_item_guid($pdo);
                $meta = $item['metadata'] ?? [];
                if (!is_array($meta)) {
                    $meta = [];
                }
                $legacyFields = ['class_code', 'class_label', 'rider_name', 'contact_email', 'contact_phone', 'horse_name', 'meal_choice', 'notes'];
                foreach ($legacyFields as $key) {
                    if (isset($item[$key]) && !isset($meta[$key])) {
                        $meta[$key] = $item[$key];
                    }
                }
                $coreKeys = ['id', 'event_id', 'event_title', 'price', 'booking_type', 'booking_type_id', 'booking_type_label', 'metadata'];
                foreach ($item as $key => $val) {
                    if (!in_array($key, $coreKeys, true) && !isset($meta[$key])) {
                        $meta[$key] = $val;
                    }
                }
                $itemStmt->execute([
                    ':booking_id' => $bookingId,
                    ':guid' => $guid,
                    ':event_id' => $item['event_id'] ?? null,
                    ':event_title' => $item['event_title'] ?? null,
                    ':price' => $itemPrice,
                    ':booking_type' => $item['booking_type'] ?? 'ride',
                    ':booking_type_id' => $item['booking_type_id'] ?? null,
                    ':booking_type_label' => $item['booking_type_label'] ?? null,
                    ':metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    ':created_at' => $booking['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save booking.'];
        return false;
    }
}

function find_booking_by_id(string $bookingId, ?PDO $pdo = null, array &$alerts = []): ?array
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    $bookingId = trim($bookingId);
    if ($bookingId === '') {
        return null;
    }
    if ($pdo && ensure_bookings_tables($pdo, $alerts)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_ref = :ref OR id = :id LIMIT 1");
            $stmt->execute([':ref' => $bookingId, ':id' => (int)$bookingId]);
            $booking = $stmt->fetch();
            if ($booking) {
                $itemStmt = $pdo->prepare("SELECT * FROM booking_items WHERE booking_id = :booking_id ORDER BY id ASC");
                $itemStmt->execute([':booking_id' => (int)$booking['id']]);
                $booking['items'] = array_map('hydrate_booking_item', $itemStmt->fetchAll() ?: []);
                return $booking;
            }
        } catch (PDOException $e) {
            $alerts[] = ['type' => 'danger', 'message' => 'Could not load booking.'];
        }
    }
    // Fallback to recent bookings list (e.g. if DB lookup failed but data is in session)
    $fallbacks = load_all_bookings($pdo, true, $alerts);
    foreach ($fallbacks as $b) {
        $ref = (string)($b['booking_ref'] ?? $b['id'] ?? '');
        if ($ref === $bookingId) {
            return $b;
        }
    }
    return null;
}

function hydrate_booking_item(array $item): array
{
    $decoded = [];
    if (isset($item['metadata'])) {
        $meta = json_decode((string)$item['metadata'], true);
        if (is_array($meta)) {
            $decoded = $meta;
        }
    }
    $item['metadata'] = $decoded;
    return $item;
}
