<?php
declare(strict_types=1);

/** Storage and lookup helpers for the organiser's post-ride results entry. */
function ensureRideResultsTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_result_classes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        class_code VARCHAR(100) NOT NULL,
        class_label VARCHAR(255) NOT NULL,
        class_group VARCHAR(16) NOT NULL DEFAULT 'OTHER',
        actual_distance_km DECIMAL(7,2) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event_result_class (event_id,class_code), INDEX (event_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ride_results (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        booking_item_id INT UNSIGNED NOT NULL,
        person_id INT UNSIGNED DEFAULT NULL,
        horse_id INT UNSIGNED DEFAULT NULL,
        class_code VARCHAR(100) NOT NULL,
        class_group VARCHAR(16) NOT NULL DEFAULT 'OTHER',
        status ENUM('pending','completed','retired','eliminated','non_starter','withdrawn') NOT NULL DEFAULT 'pending',
        distance_km DECIMAL(7,2) DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
        ready_for_vet_at DATETIME DEFAULT NULL,
        ride_minutes INT UNSIGNED DEFAULT NULL,
        vet_minutes INT UNSIGNED DEFAULT NULL,
        final_heart_rate SMALLINT UNSIGNED DEFAULT NULL,
        vet_result ENUM('pass','fail') DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        rider_mileage_eligible TINYINT(1) NOT NULL DEFAULT 0,
        horse_mileage_eligible TINYINT(1) NOT NULL DEFAULT 0,
        created_by_user_id INT UNSIGNED DEFAULT NULL,
        updated_by_user_id INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ride_result_entry (event_id,booking_item_id), INDEX (event_id), INDEX (person_id), INDEX (horse_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function rideResultTime(string $date, string $time): ?string
{
    $time = trim($time);
    if ($time === '') return null;
    if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $time)) return null;
    return $date . ' ' . str_pad(explode(':', $time)[0], 2, '0', STR_PAD_LEFT) . ':' . substr($time, -2) . ':00';
}

function rideResultMinutes(?string $from, ?string $to): ?int
{
    if (!$from || !$to) return null;
    $a = strtotime($from); $b = strtotime($to);
    return ($a === false || $b === false || $b < $a) ? null : (int)round(($b - $a) / 60);
}

function rideResultEntries(PDO $pdo, array $event): array
{
    ensureRideResultsTables($pdo);
    ensureMembershipTables($pdo);
    ensureHorseLogbookTables($pdo);
    $eventId = (int)$event['id'];
    $year = (int)substr((string)$event['event_date'], 0, 4);
    $pricing = [];
    foreach (fetchEventPricingRows($pdo, $eventId) as $row) {
        $code = (string)($row['class_code'] ?: $row['class_name']);
        if ($code !== '') $pricing[$code] = $row;
    }
    $stmt = $pdo->prepare("SELECT bi.*,b.contact_name,b.contact_email,r.status AS result_status,r.distance_km,r.started_at,r.finished_at,r.ready_for_vet_at,r.final_heart_rate,r.vet_result,r.notes,r.rider_mileage_eligible,r.horse_mileage_eligible
        FROM booking_items bi JOIN bookings b ON b.new_id=bi.booking_id
        LEFT JOIN ride_results r ON r.event_id=bi.event_id AND r.booking_item_id=bi.id
        WHERE bi.event_id=:event AND bi.booking_type NOT IN ('membership','horse_logbook') ORDER BY bi.id");
    $stmt->execute([':event'=>$eventId]);
    $entries = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $meta = json_decode((string)($row['metadata'] ?? ''), true); if (!is_array($meta)) $meta=[];
        $code = (string)($meta['class_code'] ?? ''); $class = $pricing[$code] ?? [];
        $group = strtoupper((string)($class['class_group'] ?? 'OTHER'));
        $personId=(int)($meta['person_id'] ?? 0); $horseId=(int)($meta['horse_id'] ?? 0);
        $member = false; $logbook = false;
        if ($personId && $year) { $q=$pdo->prepare("SELECT 1 FROM membership_purchases WHERE member_id=:id AND membership_year=:year AND status<>'expired' LIMIT 1"); $q->execute([':id'=>$personId,':year'=>$year]); $member=(bool)$q->fetchColumn(); }
        if ($horseId && $year) { $q=$pdo->prepare("SELECT 1 FROM horse_logbook_purchases WHERE horse_id=:id AND valid_year=:year AND status<>'expired' LIMIT 1"); $q->execute([':id'=>$horseId,':year'=>$year]); $logbook=(bool)$q->fetchColumn(); }
        $classLabel = (string)($class['class_name'] ?? $meta['class_label'] ?? $code);
        if ($classLabel === '') $classLabel = 'Unclassified';
        $entries[] = $row + ['meta'=>$meta,'class_code'=>$code,'class_label'=>$classLabel,'class_group'=>$group,'person_id'=>$personId,'horse_id'=>$horseId,'rider_name'=>(string)($meta['rider_name'] ?? $row['contact_name'] ?? ''),'horse_name'=>(string)($meta['horse_name'] ?? ''),'member_eligible'=>$member,'logbook_eligible'=>$logbook];
    }
    usort($entries, static fn($a,$b) => [array_search($a['class_group'],['PR','VPR','CTR','ER'],true) ?: 99,$a['class_label'],$a['rider_name']] <=> [array_search($b['class_group'],['PR','VPR','CTR','ER'],true) ?: 99,$b['class_label'],$b['rider_name']]);
    return $entries;
}
