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
