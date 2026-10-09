<?php
declare(strict_types=1);

/** Explicit allowlist: content, configuration, campaigns and credentials are never reset. */
function testingResetTables(bool $keepCalendar): array
{
    $tables = ['coupon_redemptions', 'coupon_audit', 'ride_results', 'booking_items', 'bookings',
        'membership_purchases', 'horse_logbook_purchases', 'external_recognition_applications',
        'misc_payment_requests', 'finance_transactions', 'user_credits', 'loyalty_cards',
        'stripe_customers', 'email_log', 'admin_audit_log', 'baskets', 'share_requests',
        'user_horse_links', 'user_person_links', 'horses'];
    if (!$keepCalendar) $tables = array_merge($tables, ['event_ride_notes', 'event_result_classes', 'event_pricing_rows', 'event_entry_components', 'events']);
    return $tables;
}

function testingResetIdentities(PDO $pdo): array
{
    $users = $pdo->query('SELECT id, email, first_name, last_name FROM users ORDER BY email')->fetchAll();
    $people = $pdo->query('SELECT * FROM people ORDER BY first_name, last_name, id')->fetchAll();
    foreach ($users as &$user) {
        $owned = array_values(array_filter($people, fn($p) => (int)$p['owner_user_id'] === (int)$user['id']));
        $matches = array_values(array_filter($owned, fn($p) =>
            (!empty($p['email']) && strcasecmp(trim($p['email']), trim($user['email'])) === 0)
            || (!empty($user['first_name']) && !empty($user['last_name'])
                && strcasecmp(trim($p['first_name']), trim($user['first_name'])) === 0
                && strcasecmp(trim($p['last_name']), trim($user['last_name'])) === 0)));
        $user['people'] = $owned;
        $user['suggested'] = count($matches) === 1 ? (int)$matches[0]['id'] : null;
    }
    return $users;
}

function testingResetPlan(PDO $pdo, bool $keepCalendar, array $choices): array
{
    $keep = [];
    foreach (testingResetIdentities($pdo) as $user) {
        $choice = $choices[$user['id']] ?? null;
        if ($choice === null || $choice === '') throw new RuntimeException('Choose the person to keep (or no existing person) for every login.');
        $id = (int)$choice;
        if ($id && !in_array($id, array_map(fn($p) => (int)$p['id'], $user['people']), true)) {
            throw new RuntimeException('A selected person no longer belongs to that login. Preview again.');
        }
        if ($id) $keep[] = $id;
    }
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $plan = [];
    foreach (testingResetTables($keepCalendar) as $table) {
        if (in_array($table, $existing, true)) $plan[$table] = '1=1';
    }
    $plan['people'] = $keep ? 'id NOT IN (' . implode(',', $keep) . ')' : '1=1';
    return ['delete' => $plan, 'keep_people' => $keep, 'keep_calendar' => $keepCalendar];
}

function testingResetCounts(PDO $pdo, array $plan): array
{
    $counts = [];
    foreach ($plan['delete'] as $table => $where) $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
    return $counts;
}

/** Called inside the transaction; never disable foreign-key checks or reset IDs. */
function testingResetApply(PDO $pdo, array $plan): void
{
    foreach ($plan['delete'] as $table => $where) $pdo->exec("DELETE FROM `$table` WHERE $where");
    if ($plan['keep_people']) {
        $pdo->exec('UPDATE people SET member_number=NULL, qualification_id=NULL, is_archived=0 WHERE id IN (' . implode(',', $plan['keep_people']) . ')');
    }
    if (isset($plan['delete']['membership_purchases'])) {
        if ((int)$pdo->query('SELECT COUNT(*) FROM membership_purchases')->fetchColumn()
            || (int)$pdo->query('SELECT COUNT(*) FROM people WHERE member_number IS NOT NULL')->fetchColumn()) {
            throw new RuntimeException('Cannot restart membership numbering while memberships or member numbers remain.');
        }
        // Restart test numbering only after all memberships and allocated numbers are cleared.
        // The enclosing transaction and full backup also cover this setting change.
        $pdo->exec("INSERT INTO site_settings (setting_key, setting_value, updated_at)
            VALUES ('next_member_number', '1000', NOW())
            ON DUPLICATE KEY UPDATE setting_value='1000', updated_at=NOW()");
    }
}

function testingResetRun(PDO $pdo, array $plan, int $actorId, array $choices, array $counts): string
{
    $directory = __DIR__ . '/../private/testing-reset-backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create the private backup folder.');
    $path = $directory . '/reset-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.jsonl';
    $file = fopen($path, 'x');
    if (!$file) throw new RuntimeException('Cannot create a backup; nothing was removed.');
    chmod($path, 0600);
    $write = static function(array $data) use ($file): void {
        $line = json_encode($data, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($file, $line) !== strlen($line)) throw new RuntimeException('Backup write failed; reset cancelled.');
    };
    try {
        $pdo->beginTransaction();
        $tables = $pdo->query('SHOW TABLE STATUS')->fetchAll();
        foreach ($tables as $table) {
            if (($table['Engine'] ?? '') !== 'InnoDB') throw new RuntimeException('Reset requires transactional InnoDB tables throughout the database.');
        }
        // Lock rows and gaps in all tables, including preserved configuration, so the backup and reset agree.
        foreach ($tables as $table) {
            $name = str_replace('`', '``', $table['Name']);
            $stmt = $pdo->query("SELECT * FROM `$name` FOR UPDATE");
            while ($stmt->fetch()) { }
        }
        if (testingResetPlan($pdo, $plan['keep_calendar'], $choices) !== $plan || testingResetCounts($pdo, $plan) !== $counts) {
            throw new RuntimeException('Data changed since preview. Review a new preview.');
        }
        $write(['format' => 'ildra-testing-reset-v1', 'created_at' => date(DATE_ATOM), 'actor_id' => $actorId, 'plan' => $plan]);
        foreach ($tables as $table) {
            $name = str_replace('`', '``', $table['Name']);
            $schema = $pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM);
            $write(['table' => $table['Name'], 'schema' => $schema[1]]);
            $stmt = $pdo->query("SELECT * FROM `$name`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $write(['table' => $table['Name'], 'row' => $row]);
        }
        if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new RuntimeException('Could not flush the backup; reset cancelled.');
        fclose($file); $file = null;
        testingResetApply($pdo, $plan);
        $pdo->commit();
        return basename($path);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_resource($file)) fclose($file);
        throw $e;
    }
}
