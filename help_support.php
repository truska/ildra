<?php
declare(strict_types=1);

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
        min_user_level INT NOT NULL DEFAULT 0,
        max_user_level INT DEFAULT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_help_group (group_id, is_published, display_order),
        CONSTRAINT fk_help_article_group FOREIGN KEY (group_id) REFERENCES help_groups(id) ON DELETE SET NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $globalColumn = $pdo->query("SHOW COLUMNS FROM help_articles LIKE 'is_global'")->fetch();
    if (!$globalColumn) {
        $pdo->exec("ALTER TABLE help_articles ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER group_id");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_intro_modals (
        view_key VARCHAR(30) PRIMARY KEY,
        heading VARCHAR(200) NOT NULL,
        body_html MEDIUMTEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $seed = $pdo->prepare("INSERT IGNORE INTO account_intro_modals (view_key, heading, body_html, is_active) VALUES (:view_key, :heading, :body_html, 1)");
    $defaults = [
        'people' => ['Adding People', '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Add people here so their details can be selected when completing entries and membership forms.</p>'],
        'horses' => ['Adding Horses', '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Add horses here to manage their details, registrations and logbooks.</p>'],
        'shares' => ['Managing Shares', '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Shares allow approved account holders to select people or horses without changing their private details.</p>'],
        'security' => ['Account Security', '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Use this area to manage your password and additional sign-in security.</p>'],
        'my-account' => ['My Account', '<p>Use this page to update your website login details, change your password and manage authenticator app access. These details belong to your user account and are separate from people and membership records.</p>'],
    ];
    foreach ($defaults as $viewKey => [$heading, $bodyHtml]) {
        $seed->execute([':view_key' => $viewKey, ':heading' => $heading, ':body_html' => $bodyHtml]);
    }
}

function fetchAccountIntroModal(?PDO $pdo, string $viewKey, bool $activeOnly = true): ?array
{
    if (!$pdo || !in_array($viewKey, ['people', 'horses', 'shares', 'security', 'my-account'], true)) return null;
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
    if (!$pdo) return false;
    ensureHelpTables($pdo);
    $posted = isset($data['intros']) && is_array($data['intros']) ? $data['intros'] : [];
    try {
        $stmt = $pdo->prepare("UPDATE account_intro_modals SET heading = :heading, body_html = :body_html, is_active = :active WHERE view_key = :view_key");
        foreach (['people', 'horses', 'shares', 'my-account'] as $viewKey) {
            $row = isset($posted[$viewKey]) && is_array($posted[$viewKey]) ? $posted[$viewKey] : [];
            $heading = trim((string)($row['heading'] ?? ''));
            $bodyHtml = trim((string)($row['body_html'] ?? ''));
            if ($heading === '' || $bodyHtml === '') {
                $alerts[] = ['type' => 'danger', 'message' => 'Every active account introduction needs a heading and text.'];
                return false;
            }
            $stmt->execute([':heading' => $heading, ':body_html' => $bodyHtml, ':active' => !empty($row['is_active']) ? 1 : 0, ':view_key' => $viewKey]);
        }
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not save account introduction modals.'];
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
    $id=(int)($data['article_id']??0); $title=trim((string)($data['title']??'')); $body=trim((string)($data['body_html']??''));
    if ($title==='' || $body==='') { $alerts[]=['type'=>'danger','message'=>'Title and instructions are required.']; return false; }
    $max=trim((string)($data['max_user_level']??''));
    $groupId=((int)($data['group_id']??0))?:null;
    $params=[':title'=>$title,':summary'=>trim((string)($data['summary']??'')),':body'=>$body,':keywords'=>trim((string)($data['keywords']??'')),':group_id'=>$groupId,':global'=>($groupId===null||isset($data['is_global']))?1:0,':min'=>(int)($data['min_user_level']??0),':max'=>$max===''?null:(int)$max,':display_order'=>(int)($data['display_order']??0),':published'=>isset($data['is_published'])?1:0];
    if ($id) { $params[':id']=$id; $sql='UPDATE help_articles SET title=:title,summary=:summary,body_html=:body,keywords=:keywords,group_id=:group_id,is_global=:global,min_user_level=:min,max_user_level=:max,display_order=:display_order,is_published=:published WHERE id=:id'; }
    else $sql='INSERT INTO help_articles (title,summary,body_html,keywords,group_id,is_global,min_user_level,max_user_level,display_order,is_published) VALUES (:title,:summary,:body,:keywords,:group_id,:global,:min,:max,:display_order,:published)';
    return $pdo->prepare($sql)->execute($params);
}
