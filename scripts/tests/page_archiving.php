<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/cms.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
// Session-only fixtures shadow development pages: no persistent page is changed.
class PageArchiveTestPDO extends PDO {
    public function exec(string $statement): int|false {
        if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS pages\b/i', $statement)) return 0;
        return parent::exec($statement);
    }
}
$config = require dirname(__DIR__, 2) . '/config.php';
$db = $config['db'];
$pdo = new PageArchiveTestPDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4', $db['user'], $db['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TEMPORARY TABLE pages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), slug VARCHAR(180) UNIQUE,
 nav_group VARCHAR(80) DEFAULT 'home', destination_page_id INT UNSIGNED NULL,
 excerpt TEXT, body_html TEXT, is_published TINYINT DEFAULT 0, display_order INT DEFAULT 0,
 button_name VARCHAR(150), button_title VARCHAR(255), button_url VARCHAR(1000), button_asset_id INT,
 button_target VARCHAR(16) DEFAULT '_self', show_in_footer TINYINT DEFAULT 0, menu_divider_below TINYINT DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO pages (id,title,slug,body_html,is_published,destination_page_id) VALUES
 (1,'Home','home','Home copy',1,NULL), (2,'Fixture','archive-fixture','Preserved copy',1,NULL),
 (3,'Alias','alias-fixture','Alias copy',1,2), (4,'Draft','draft-fixture','Draft copy',0,NULL)");
ensurePageArchiveColumn($pdo);
ensurePageArchiveColumn($pdo);
$alerts=[];
check(deletePage($pdo,2,$alerts), 'Delete must succeed');
check(!$alerts,'Unexpected error');
$row=$pdo->query('SELECT * FROM pages WHERE id=2')->fetch();
check((int)$row['is_archived']===1 && $row['body_html']==='Preserved copy' && (int)$row['is_published']===1,'Archive must preserve original record/content');
check(fetchPageById($pdo,2)===null, 'Archived page exposed to admin editor');
check(fetchPageBySlug($pdo,'archive-fixture')===null, 'Archived page exposed publicly');
check(fetchPageBySlug($pdo,'archive-fixture',false)===null, 'Archived page exposed to admin preview');
check(!in_array(2,array_column(fetchPages($pdo),'id')), 'Archived page appears in admin list');
$pages=fetchPages($pdo,true);
check(!in_array(2,array_column($pages,'id')), 'Archived page appears in navigation');
$alias=array_values(array_filter($pages,fn($p)=>(int)$p['id']===3))[0];
check($alias['destination_slug']===null, 'Alias exposes archived destination');
check((int)$pdo->query('SELECT destination_page_id FROM pages WHERE id=3')->fetchColumn()===2, 'Recovery reference lost');
check(!savePage($pdo,['page_id'=>2,'title'=>'Overwrite','slug'=>'archive-fixture'],$alerts),'Stale editor can save archived record');
$alerts=[];check(!deletePage($pdo,1,$alerts), 'Home deleted');
$alerts=[];check(!deletePage($pdo,2,$alerts), 'Repeated delete incorrectly succeeds');
$alerts=[];check(!deletePage($pdo,999,$alerts), 'Missing page incorrectly succeeds');
$pdo->exec('UPDATE pages SET is_published=0');
check(fetchPages($pdo,true)===[], 'Empty published list must stay empty');
check((int)$pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn()===4,'Default pages re-seeded');
check($pdo->query('SELECT body_html FROM pages WHERE id=2')->fetchColumn()==='Preserved copy','Archived content overwritten');
check(fetchPageById($pdo,4)!==null,'Unpublished active page must remain editable');
echo "Page archive: content preservation, admin/public exclusion, aliases, stale saves, home protection and empty-list seeding passed.\n";
