<?php
declare(strict_types=1);

function screenGuidanceGroups(): array
{
    return ['accounts' => 'Accounts', 'horses-logbooks' => 'Horses & Logbooks', 'email-consent' => 'Email Consent'];
}

/** One catalogue and one stored record per piece of screen guidance. */
function screenGuidanceDefinitions(): array
{
    $definitions = [
        'people' => ['accounts', 'People information', 'Information popup on Account → People; opens automatically when no people are saved.', '/account?view=people', 'popup', 'Adding People', '<p>Add people here so their details can be selected when completing entries and membership forms.</p>'],
        'shares' => ['accounts', 'Shares information', 'Information popup on Account → Shares.', '/account?view=shares', 'popup', 'Managing Shares', '<p>Shares allow approved account holders to select people or horses without changing their private details.</p>'],
        'my-account' => ['accounts', 'My Account information', 'Information popup on Account → My Account.', '/account?view=my-account', 'popup', 'My Account', '<p>Use this page to update your website login details, change your password and manage authenticator app access. These details belong to your user account and are separate from people and membership records.</p>'],
        'horses' => ['horses-logbooks', 'Horses information', 'Information popup on Account → Horses; opens automatically when no horses are saved.', '/account?view=horses', 'popup', 'Adding Horses', '<p>Add horses here to manage their details, registrations and logbooks.</p>'],
        'horse_add_intro' => ['horses-logbooks', 'Add Horse introduction', 'Below the Add Horse heading. Open Add Horse from the Horses page.', '/account?view=horses', 'inline', 'Add Horse introduction', '<p>Save your horse’s name first. You can choose to register for a logbook next.</p>'],
        'horse_edit_intro' => ['horses-logbooks', 'Edit Horse introduction', 'Below the Edit horse heading. Choose Edit beside an existing horse.', '/account?view=horses', 'inline', 'Edit Horse introduction', '<p>Update your horse’s name and logbook details.</p>'],
        'logbook_choice_intro' => ['horses-logbooks', 'Horse saved confirmation', 'Below the Yes/No logbook question after saving a new horse. Requires a horse selected in the registration flow.', '/account?view=horses', 'inline', 'Horse saved confirmation', '<p>Your horse has been saved and can now be selected on entry forms.</p>'],
        'logbook_about' => ['horses-logbooks', 'About logbooks panel', 'Beside the logbook choice and full logbook registration form. Select a horse to see the panel.', '/logbooks', 'panel', 'About logbooks', '<p><strong>Buy Logbook</strong> if you want to take part in competitive rides or registered VPRs.</p><p>A horse saved with just its name is available on entry forms. Without a logbook, the horse cannot claim mileage awards or take part in competitive rides.</p>'],
        'logbook_form_intro' => ['horses-logbooks', 'Logbook form introduction', 'Red explanatory text above the full logbook details form, after selecting a horse.', '/logbooks', 'inline', 'Logbook form introduction', '<p>All fields are required. Save to add the logbook to your basket.</p>'],
        'person_general_email' => ['email-consent', 'General news consent', 'Information popup beside the General news email checkbox in the person editor.', '/account?view=people', 'popup', 'General news and announcements', '<p>Choose this only when you or this person agree to receive general ILDRA news and announcements by email.</p>'],
        'person_ride_notice' => ['email-consent', 'Ride Notice consent', 'Information popup beside the Ride Notice email checkbox in the person editor.', '/account?view=people', 'popup', 'Ride Notice emails', '<p>Choose this only when you or this person agree to receive Ride Notice emails, including automated event-entry opening and closing reminders.</p>'],
        'person_renewal_reminder' => ['email-consent', 'Renewal reminder consent', 'Information popup beside the renewal reminders checkbox in the person editor.', '/account?view=people', 'popup', 'Renewal reminders', '<p>Choose this only when you or this person agree to receive membership and renewal reminder emails.</p>'],
    ];
    $result = [];
    foreach ($definitions as $key => [$group, $label, $location, $url, $type, $heading, $body]) {
        $result[$key] = ['group' => $group, 'label' => $label, 'location' => $location,
            'url' => $url, 'type' => $type, 'heading' => $heading, 'body_html' => $body];
    }
    return $result;
}

/** Inline text uses the same records as the existing account information popups. */
function fetchScreenGuidance(?PDO $pdo, string $key): ?array
{
    $definition = screenGuidanceDefinitions()[$key] ?? null;
    if (!$definition) return null;
    $fallback = ['view_key' => $key, 'heading' => $definition['heading'], 'body_html' => $definition['body_html'], 'is_active' => 1];
    if (!$pdo) return $fallback;
    try {
        ensureHelpTables($pdo);
        $stmt = $pdo->prepare('SELECT view_key, heading, body_html, is_active FROM account_intro_modals WHERE view_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $fallback;
        if (empty($row['is_active'])) return null;
        $row['body_html'] = sanitize_rich_html((string)$row['body_html']);
        return $row;
    } catch (PDOException $e) { return $fallback; }
}

function screenGuidanceHtml(?PDO $pdo, string $key): string
{
    return (string)(fetchScreenGuidance($pdo, $key)['body_html'] ?? '');
}

function ensureHelpTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS help_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        path_patterns TEXT NOT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS help_articles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        summary TEXT DEFAULT NULL,
        body_html MEDIUMTEXT NOT NULL,
        keywords VARCHAR(500) DEFAULT NULL,
        group_id INT UNSIGNED DEFAULT NULL,
        is_global TINYINT(1) NOT NULL DEFAULT 0,
        include_in_user_manual TINYINT(1) NOT NULL DEFAULT 0,
        include_in_admin_manual TINYINT(1) NOT NULL DEFAULT 0,
        min_user_level INT NOT NULL DEFAULT 0,
        max_user_level INT DEFAULT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        to_be_created TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_help_group (group_id, is_published, display_order),
        CONSTRAINT fk_help_article_group FOREIGN KEY (group_id) REFERENCES help_groups(id) ON DELETE SET NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $globalColumn = $pdo->query("SHOW COLUMNS FROM help_articles LIKE 'is_global'")->fetch();
    if (!$globalColumn) {
        $pdo->exec("ALTER TABLE help_articles ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER group_id");
    }
    $userManualColumn = $pdo->query("SHOW COLUMNS FROM help_articles LIKE 'include_in_user_manual'")->fetch();
    if (!$userManualColumn) {
        $pdo->exec("ALTER TABLE help_articles ADD COLUMN include_in_user_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER is_global");
    }
    $adminManualColumn = $pdo->query("SHOW COLUMNS FROM help_articles LIKE 'include_in_admin_manual'")->fetch();
    if (!$adminManualColumn) {
        $pdo->exec("ALTER TABLE help_articles ADD COLUMN include_in_admin_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER include_in_user_manual");
    }
    $toBeCreatedColumn = $pdo->query("SHOW COLUMNS FROM help_articles LIKE 'to_be_created'")->fetch();
    if (!$toBeCreatedColumn) {
        $pdo->exec("ALTER TABLE help_articles ADD COLUMN to_be_created TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published");
        $pdo->exec("ALTER TABLE help_articles ADD INDEX idx_help_to_be_created (to_be_created)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_intro_modals (
        view_key VARCHAR(30) PRIMARY KEY,
        heading VARCHAR(200) NOT NULL,
        body_html MEDIUMTEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $seed = $pdo->prepare("INSERT IGNORE INTO account_intro_modals (view_key, heading, body_html, is_active) VALUES (:view_key, :heading, :body_html, 1)");
    foreach (screenGuidanceDefinitions() as $viewKey => $definition) {
        $seed->execute([':view_key' => $viewKey, ':heading' => $definition['heading'], ':body_html' => $definition['body_html']]);
    }
}

function fetchAccountIntroModal(?PDO $pdo, string $viewKey, bool $activeOnly = true): ?array
{
    if (!$pdo || !in_array($viewKey, ['people', 'horses', 'shares', 'security', 'my-account', 'person_general_email', 'person_ride_notice', 'person_renewal_reminder'], true)) return null;
    try {
        ensureHelpTables($pdo);
        $sql = 'SELECT view_key, heading, body_html, is_active FROM account_intro_modals WHERE view_key = :view_key';
        if ($activeOnly) $sql .= ' AND is_active = 1';
        $stmt = $pdo->prepare($sql); $stmt->execute([':view_key' => $viewKey]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}

function fetchAccountIntroModals(?PDO $pdo): array
{
    if (!$pdo) return [];
    try {
        ensureHelpTables($pdo);
        $rows = $pdo->query("SELECT view_key, heading, body_html, is_active FROM account_intro_modals ORDER BY FIELD(view_key, 'people', 'horses', 'shares', 'my-account', 'security')")->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) $result[(string)$row['view_key']] = $row;
        return $result;
    } catch (PDOException $e) { return []; }
}

function saveAccountIntroModals(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    $definitions = screenGuidanceDefinitions();
    $posted = isset($data['intros']) && is_array($data['intros']) ? $data['intros'] : [];
    if (!$posted) {
        $alerts[] = ['type' => 'danger', 'message' => 'No screen guidance was submitted.'];
        return false;
    }
    $updates = [];
    foreach ($posted as $key => $row) {
        if (!isset($definitions[$key]) || !is_array($row)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Unknown screen guidance item.'];
            return false;
        }
        $definition = $definitions[$key];
        $heading = $definition['type'] === 'inline' ? $definition['heading'] : trim((string)($row['heading'] ?? ''));
        $body = sanitize_rich_html((string)($row['body_html'] ?? ''));
        if ($heading === '' || mb_strlen($heading) > 200 || rich_html_plain_text($body) === '') {
            $alerts[] = ['type' => 'danger', 'message' => $definition['label'] . ' needs a heading (maximum 200 characters) and text.'];
            return false;
        }
        $updates[$key] = [':heading' => $heading, ':body_html' => $body, ':active' => !empty($row['is_active']) ? 1 : 0, ':view_key' => $key];
    }
    $ownsTransaction = false;
    try {
        ensureHelpTables($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE account_intro_modals SET heading = :heading, body_html = :body_html, is_active = :active WHERE view_key = :view_key');
        foreach ($updates as $values) $stmt->execute($values);
        if ($ownsTransaction) $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save screen guidance.'];
        return false;
    }
}

function fetchHelpGroups(?PDO $pdo, bool $activeOnly = true): array
{
    if (!$pdo) return [];
    try {
        ensureHelpTables($pdo);
        return $pdo->query('SELECT * FROM help_groups' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY display_order, name')->fetchAll() ?: [];
    } catch (PDOException $e) { return []; }
}

function helpNormalisePath(string $uri): string
{
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    return '/' . trim($path, '/');
}

function helpMatchingGroupIds(array $groups, string $uri): array
{
    $path = helpNormalisePath($uri);
    $matches = [];
    foreach ($groups as $group) {
        foreach (preg_split('/[\r\n,]+/', (string)$group['path_patterns']) ?: [] as $pattern) {
            $pattern = '/' . trim(trim($pattern), '/');
            if ($pattern === '/') $pattern = '/';
            $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $path)) { $matches[] = (int)$group['id']; break; }
        }
    }
    return array_values(array_unique($matches));
}

function fetchHelpArticles(?PDO $pdo, int $level, bool $publishedOnly = true): array
{
    if (!$pdo) return [];
    try {
        ensureHelpTables($pdo);
        $sql = 'SELECT a.*, g.name AS group_name FROM help_articles a LEFT JOIN help_groups g ON g.id=a.group_id WHERE a.min_user_level <= :level AND (a.max_user_level IS NULL OR a.max_user_level >= :level)';
        if ($publishedOnly) $sql .= ' AND a.is_published=1 AND (a.group_id IS NULL OR g.is_active=1)';
        $sql .= ' ORDER BY a.display_order, a.title';
        $stmt = $pdo->prepare($sql); $stmt->execute([':level' => $level]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) { return []; }
}

function fetchHelpArticle(?PDO $pdo, int $id): ?array
{
    if (!$pdo || $id < 1) return null;
    ensureHelpTables($pdo);
    $stmt=$pdo->prepare('SELECT * FROM help_articles WHERE id=:id'); $stmt->execute([':id'=>$id]);
    return $stmt->fetch() ?: null;
}

function helpUserLevelOptions(?PDO $pdo): array
{
    $grouped = [0 => ['Visitor']];
    if ($pdo) {
        try {
            $roles = $pdo->query('SELECT name, level FROM roles ORDER BY level, name')->fetchAll() ?: [];
            foreach ($roles as $role) {
                $level = (int)$role['level'];
                $name = strtolower(trim((string)$role['name']));
                $label = $name === 'superadmin' ? 'SuperAdmin' : ucfirst($name);
                if ($label !== '') $grouped[$level][] = $label;
            }
        } catch (PDOException $e) {
            // The Help editor remains usable with the established role levels.
            $grouped += [1 => ['User'], 3 => ['Organiser'], 4 => ['Manager', 'Admin'], 5 => ['SuperAdmin']];
        }
    }
    ksort($grouped);
    $options = [];
    foreach ($grouped as $level => $labels) {
        $options[(int)$level] = implode(' / ', array_values(array_unique($labels)));
    }
    return $options;
}

function helpUserLevelLabel(array $options, ?int $level, string $emptyLabel = 'No maximum'): string
{
    if ($level === null) return $emptyLabel;
    return $options[$level] ?? ('Legacy level ' . $level);
}

function saveHelpGroup(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) return false;
    ensureHelpTables($pdo);
    $id=(int)($data['group_id']??0); $name=trim((string)($data['name']??'')); $patterns=trim((string)($data['path_patterns']??''));
    if ($name==='' || $patterns==='') { $alerts[]=['type'=>'danger','message'=>'Group name and page patterns are required.']; return false; }
    $params=[':name'=>$name,':description'=>trim((string)($data['description']??'')),':patterns'=>$patterns,':display_order'=>(int)($data['display_order']??0),':active'=>isset($data['is_active'])?1:0];
    if ($id) { $params[':id']=$id; $sql='UPDATE help_groups SET name=:name,description=:description,path_patterns=:patterns,display_order=:display_order,is_active=:active WHERE id=:id'; }
    else $sql='INSERT INTO help_groups (name,description,path_patterns,display_order,is_active) VALUES (:name,:description,:patterns,:display_order,:active)';
    return $pdo->prepare($sql)->execute($params);
}

function saveHelpArticle(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) return false;
    ensureHelpTables($pdo);
    $id=(int)($data['article_id']??0); $title=trim((string)($data['title']??'')); $body=sanitize_rich_html((string)($data['body_html']??'')); $toBeCreated=!empty($data['to_be_created'])?1:0;
    if ($title==='' || ($body==='' && !$toBeCreated)) { $alerts[]=['type'=>'danger','message'=>$toBeCreated?'Title is required.':'Title and instructions are required.']; return false; }
    $max=trim((string)($data['max_user_level']??''));
    $minLevel=(int)($data['min_user_level']??0); $maxLevel=$max===''?null:(int)$max;
    if ($maxLevel !== null && $maxLevel < $minLevel) { $alerts[]=['type'=>'danger','message'=>'The maximum audience must not be below the minimum audience.']; return false; }
    $groupId=((int)($data['group_id']??0))?:null;
    $params=[':title'=>$title,':summary'=>trim((string)($data['summary']??'')),':body'=>$body,':keywords'=>trim((string)($data['keywords']??'')),':group_id'=>$groupId,':global'=>($groupId===null||isset($data['is_global']))?1:0,':user_manual'=>isset($data['include_in_user_manual'])?1:0,':admin_manual'=>isset($data['include_in_admin_manual'])?1:0,':min'=>$minLevel,':max'=>$maxLevel,':display_order'=>(int)($data['display_order']??0),':to_be_created'=>$toBeCreated,':published'=>$toBeCreated?0:(isset($data['is_published'])?1:0)];
    if ($id) { $params[':id']=$id; $sql='UPDATE help_articles SET title=:title,summary=:summary,body_html=:body,keywords=:keywords,group_id=:group_id,is_global=:global,include_in_user_manual=:user_manual,include_in_admin_manual=:admin_manual,min_user_level=:min,max_user_level=:max,display_order=:display_order,is_published=:published,to_be_created=:to_be_created WHERE id=:id'; }
    else $sql='INSERT INTO help_articles (title,summary,body_html,keywords,group_id,is_global,include_in_user_manual,include_in_admin_manual,min_user_level,max_user_level,display_order,is_published,to_be_created) VALUES (:title,:summary,:body,:keywords,:group_id,:global,:user_manual,:admin_manual,:min,:max,:display_order,:published,:to_be_created)';
    return $pdo->prepare($sql)->execute($params);
}
