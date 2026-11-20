<?php
declare(strict_types=1);
require_once __DIR__ . '/testing_reset.php';
require_once __DIR__ . '/user_protection.php';

function importWorkspaceConfig(): array
{
    $path = __DIR__ . '/../private/import-workspace.php';
    return is_file($path) ? (array)require $path : [];
}

/** Independent of the Host header: the destination needs an explicit private opt-in. */
function importWorkspaceEnvironmentAllowed(array $config, string $root, string $database, string $host): bool
{
    // HTTP_HOST includes the port when accessed through the development proxy.
    if (!preg_match('/\Adev-ildra\.witecanvas\.com(?::([0-9]{1,5}))?\z/D', strtolower($host), $match)
        || (isset($match[1]) && ((int)$match[1] < 1 || (int)$match[1] > 65535))) {
        return false;
    }
    $host = 'dev-ildra.witecanvas.com';
    return ($config['enabled'] ?? false) === true
        && ($config['web_root'] ?? '') === $root
        && ($config['database'] ?? '') === $database
        && ($config['host'] ?? '') === $host
        && $host === 'dev-ildra.witecanvas.com';
}

function importWorkspaceAuthorize(PDO $pdo, array $actor, array $config, string $host): int
{
    if (!importWorkspaceEnvironmentAllowed($config, (string)realpath(__DIR__), (string)$pdo->query('SELECT DATABASE()')->fetchColumn(), $host)) {
        throw new RuntimeException('Import workspace is disabled in this environment.');
    }
    $id = (int)($config['protected_user_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT u.id,u.email,u.is_protected,u.is_archived,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
    $stmt->execute([$id]); $user = $stmt->fetch();
    if (!$user || empty($user['is_protected']) || !empty($user['is_archived']) || $user['role'] !== 'developer'
        || $user['email'] !== ($config['protected_email'] ?? '')
        || (int)($actor['id'] ?? 0) !== $id || ($actor['role'] ?? '') !== 'developer') {
        throw new RuntimeException('Only the protected Developer account can use this workspace.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_protected=1')->fetchColumn() !== 1) {
        throw new RuntimeException('Review the reset policy: more than one protected account exists.');
    }
    return $id;
}

function importWorkspaceReferenceRules(int $keepId): array
{
    // Preserve content and task discussions; transfer mandatory authorship and
    // clear optional account links, leaving visible author/organiser text intact.
    return [
        'events' => ['organiser_user_id'=>null],
        'news_articles' => ['created_by_user_id'=>null],
        'dev_tasks' => ['created_by'=>$keepId, 'updated_by'=>null, 'next_action_by'=>null, 'closed_by'=>null],
        'dev_task_messages' => ['user_id'=>$keepId],
        'email_campaigns' => ['created_by_user_id'=>null, 'approved_by_user_id'=>null],
        'horses' => ['owner_user_id'=>0],
    ];
}

function importWorkspacePlan(PDO $pdo, int $keepId): array
{
    if ($keepId <= 0) throw new RuntimeException('Protected account is missing.');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $delete = [];
    $requested = array_merge(testingResetTables(true), ['coupons','email_campaign_recipients','email_campaign_limited_tests','auth_throttle',
        'auth_magic_tokens','auth_password_resets','auth_remember_tokens','migration_enrolments','migration_invitations','record_notes','migration_horse_reviews','migration_horse_choices']);
    foreach ($requested as $table) {
        if (in_array($table, $tables, true)) $delete[$table] = '1=1';
    }
    if (isset($delete['horses'])) $delete['horses'] = 'id <> 1'; // Required global "Not Registered" option.
    foreach (['auth_magic_tokens','auth_password_resets','auth_remember_tokens'] as $table) {
        if (isset($delete[$table])) $delete[$table] = 'user_id <> ' . $keepId;
    }
    $delete['people'] = '1=1';
    $delete['users'] = 'id <> ' . $keepId . ' AND is_protected=0';
    $rules = importWorkspaceReferenceRules($keepId);
    $updates = [];
    foreach ($tables as $table) {
        // Historical migration snapshots and source tables are retained, outside active app state.
        if (str_contains($table, '_backup_') || str_starts_with($table, 'x-source-')) continue;
        $quoted = str_replace('`', '``', $table);
        foreach ($pdo->query("SHOW COLUMNS FROM `$quoted`") as $column) {
            $field = $column['Field'];
            if (array_key_exists($field, $rules[$table] ?? [])) {
                $updates[$table][$field] = $rules[$table][$field];
            }
            if (isset($delete[$table])) continue;
            if (preg_match('/(?:^|_)(?:user|person|horse|member|booking|booking_item|finance_transaction|membership_purchase|horse_logbook_purchase)_id$/D', $field)
                && !array_key_exists($field, $rules[$table] ?? [])) {
                throw new RuntimeException('Unreviewed identity reference: ' . $table . '.' . $field . '. Reset cancelled.');
            }
        }
    }
    // Refuse unreviewed future foreign keys into records this reset removes.
    $references = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
    foreach ($references as $ref) {
        if (isset($delete[$ref['REFERENCED_TABLE_NAME']]) && !isset($delete[$ref['TABLE_NAME']])
            && !array_key_exists($ref['COLUMN_NAME'], $updates[$ref['TABLE_NAME']] ?? [])) {
            throw new RuntimeException('Unreviewed foreign key from ' . $ref['TABLE_NAME'] . '. Reset cancelled.');
        }
    }
    return ['delete'=>$delete, 'updates'=>$updates, 'keep_user'=>$keepId, 'keep_people'=>[], 'keep_calendar'=>true];
}

function importWorkspaceSnapshot(PDO $pdo, array $plan): array
{
    $hashes = [];
    foreach (array_unique(array_merge(array_keys($plan['delete']), array_keys($plan['updates']), ['site_settings'], $plan['snapshot_tables'] ?? [])) as $table) {
        $rows = [];
        foreach ($pdo->query("SELECT * FROM `$table`") as $row) $rows[] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        sort($rows, SORT_STRING);
        $hashes[$table] = hash('sha256', implode('', $rows));
    }
    return ['counts'=>testingResetCounts($pdo, $plan), 'hashes'=>$hashes];
}

function importWorkspaceApply(PDO $pdo, array $plan): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Reset requires a transaction.');
    $keepId = (int)$plan['keep_user'];
    $before = $pdo->query('SELECT * FROM users WHERE id=' . $keepId)->fetch();
    if (!$before || empty($before['is_protected'])) throw new RuntimeException('Protected account is missing.');
    foreach ($plan['updates'] as $table => $fields) {
        foreach ($fields as $field => $replacement) {
            $value = $replacement === null ? 'NULL' : (string)(int)$replacement;
            $pdo->exec("UPDATE `$table` SET `$field`=$value WHERE `$field` IS NOT NULL AND `$field` NOT IN (0,$keepId)");
        }
    }
    // References are detached before deleting the accounts they point to.
    testingResetApply($pdo, $plan);
    importWorkspacePauseCampaigns($pdo,isset($plan['updates']['email_campaigns']));
    if ($pdo->query('SELECT * FROM users WHERE id=' . $keepId)->fetch() !== $before
        || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1) {
        throw new RuntimeException('Protected account verification failed.');
    }
    foreach (testingResetCounts($pdo, $plan) as $count) {
        if ($count !== 0) throw new RuntimeException('Reset left operational records behind.');
    }
}

function importWorkspacePauseCampaigns(PDO $pdo, bool $hasCampaigns): void
{
    if ($hasCampaigns) {
        $pdo->exec("UPDATE email_campaigns SET status='draft', scheduled_at=NULL, live_send_approved=0, approved_by_user_id=NULL,
            approved_at=NULL, started_at=NULL, completed_at=NULL, recipient_count=0, sent_count=0, failed_count=0, opened_count=0");
    }
    $pdo->exec("INSERT INTO site_settings (setting_key,setting_value,updated_at) VALUES ('campaign_live_sending_enabled','0',NOW())
        ON DUPLICATE KEY UPDATE setting_value='0',updated_at=NOW()");
}

/** Full private schema/data backup, locked preview recheck and atomic deletion. No DDL or external calls. */
function importWorkspaceRun(PDO $pdo, array $actor, array $config, string $host, array $preview, ?string $onboardingMode = null): string
{
    if ($onboardingMode !== null) {
        require_once __DIR__.'/onboarding_reset.php';
        if (!isset(onboardingResetModes()[$onboardingMode])) throw new RuntimeException('Invalid onboarding reset mode.');
    }
    importWorkspaceAuthorize($pdo, $actor, $config, $host);
    if (($preview['actor_id'] ?? 0) !== (int)$actor['id'] || time() - ($preview['time'] ?? 0) > 900) {
        throw new RuntimeException('Preview expired. Create a new preview.');
    }
    $directory = __DIR__ . '/../private/import-workspace-backups';
    if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('Private backup directory is not writable. Nothing removed.');
    if ((int)$pdo->query("SELECT GET_LOCK('ildra-import-reset',0)")->fetchColumn() !== 1) throw new RuntimeException('Another reset is in progress.');
    $file = null;
    $path = $directory . '/reset-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.jsonl';
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $tables = $pdo->query('SHOW TABLE STATUS')->fetchAll();
        foreach ($tables as $table) {
            if (($table['Engine'] ?? '') !== 'InnoDB') throw new RuntimeException('All tables must use InnoDB.');
            $name = str_replace('`', '``', $table['Name']);
            $stmt = $pdo->query("SELECT * FROM `$name` FOR UPDATE");
            while ($stmt->fetch()) { }
        }
        $keepId = importWorkspaceAuthorize($pdo, $actor, $config, $host);
        $plan = $onboardingMode === null ? importWorkspacePlan($pdo, $keepId) : onboardingResetPlan($pdo, $keepId, $onboardingMode);
        if ($plan !== $preview['plan'] || importWorkspaceSnapshot($pdo, $plan) !== $preview['snapshot']) {
            throw new RuntimeException('Data changed since preview. Review a new preview.');
        }
        $file = fopen($path, 'x');
        if (!$file || !chmod($path, 0600)) throw new RuntimeException('Cannot create a private backup. Nothing removed.');
        $write = static function(array $data) use ($file): void {
            $line = json_encode($data, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($file, $line) !== strlen($line)) throw new RuntimeException('Backup write failed.');
        };
        $write(['format'=>'ildra-import-reset-v1','created_at'=>date(DATE_ATOM),'actor_id'=>$keepId,'plan'=>$plan]);
        foreach ($tables as $table) {
            $name = str_replace('`', '``', $table['Name']);
            $schema = $pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM);
            $write(['table'=>$table['Name'],'schema'=>$schema[1]]);
            foreach ($pdo->query("SELECT * FROM `$name`") as $row) $write(['table'=>$table['Name'],'row'=>$row]);
        }
        $write(['backup_complete'=>true]);
        if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new RuntimeException('Could not flush backup.');
        fclose($file); $file = null;
        if ($onboardingMode === null) importWorkspaceApply($pdo, $plan);
        else onboardingResetApply($pdo, $plan);
        $pdo->commit();
        return basename($path);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_resource($file)) fclose($file);
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ildra-import-reset')");
    }
}
