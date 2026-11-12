<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/cms.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
// Session-only fixtures shadow development pages: no persistent page is changed.
class PageArchiveTestPDO extends PDO {
    public function exec(string $statement): int|false {
        if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS (?:pages|announcements)\b/i', $statement)) return 0;
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

require dirname(__DIR__, 2) . '/archives.php';
$pdo->exec('CREATE TEMPORARY TABLE page_content_elements (id INT PRIMARY KEY, page_id INT, body_html TEXT)');
$pdo->exec('CREATE TEMPORARY TABLE media_batches (id INT PRIMARY KEY, owner_type VARCHAR(80), owner_id INT)');
$pdo->exec('CREATE TEMPORARY TABLE media_batch_images (id INT PRIMARY KEY, batch_id INT)');
check(canManageArchives('admin') && canManageArchives('developer') && canManageArchives('superadmin'), 'Archive admin access');
check(!canManageArchives('manager') && !canManageArchives('organiser') && !canManageArchives('user'), 'Archive access too broad');
check(count(fetchArchivedPages($pdo))===1, 'Archive list must include only archived pages');
$pdo->exec("INSERT INTO page_content_elements VALUES (20,2,'Section content')");
$pdo->exec("INSERT INTO media_batches VALUES (30,'page',2),(31,'page_content_element',20),(32,'page',4)");
$pdo->exec('INSERT INTO media_batch_images VALUES (40,30),(41,31),(42,32)');
$alerts=[];
check(!manageArchivedPage($pdo,2,'purge',$alerts), 'Purge ignored destination/image dependencies');
check(count(archivedPagePurgeBlockers($pdo,2))===2, 'Both dependency types must be reported');
check((int)$pdo->query('SELECT COUNT(*) FROM page_content_elements WHERE page_id=2')->fetchColumn()===1,'Blocked purge removed content');
$alerts=[];check(manageArchivedPage($pdo,2,'restore',$alerts),'Restore failed');
$restored=fetchPageById($pdo,2);
check($restored!==null && (int)$restored['is_published']===0,'Restore must be draft');
check($pdo->query('SELECT body_html FROM page_content_elements WHERE id=20')->fetchColumn()==='Section content','Restore lost section');
check((int)$pdo->query('SELECT COUNT(*) FROM media_batch_images')->fetchColumn()===3,'Restore lost images');
$alerts=[];check(!manageArchivedPage($pdo,2,'purge',$alerts),'Active page purged');
$alerts=[];check(!manageArchivedPage($pdo,2,'restore',$alerts),'Repeated restore should fail');
$alerts=[];check(deletePage($pdo,2,$alerts),'Re-archive failed');
$pdo->exec('UPDATE pages SET destination_page_id=NULL WHERE id=3');
$alerts=[];check(!manageArchivedPage($pdo,2,'purge',$alerts),'Purge ignored page images');
$pdo->exec('DELETE FROM media_batch_images WHERE batch_id=30');
$alerts=[];check(!manageArchivedPage($pdo,2,'purge',$alerts),'Purge ignored section images');
$pdo->exec('DELETE FROM media_batch_images WHERE batch_id=31');
$alerts=[];check(manageArchivedPage($pdo,2,'purge',$alerts),'Eligible purge failed');
check(!$pdo->query('SELECT id FROM pages WHERE id=2')->fetchColumn(),'Purged page retained');
check(!$pdo->query('SELECT id FROM page_content_elements WHERE page_id=2')->fetchColumn(),'Purged sections retained');
check((int)$pdo->query('SELECT COUNT(*) FROM media_batches')->fetchColumn()===1,'Owned empty batches retained or other batches removed');
check((int)$pdo->query('SELECT COUNT(*) FROM media_batch_images')->fetchColumn()===1,'Unrelated media removed');
$alerts=[];check(!manageArchivedPage($pdo,2,'purge',$alerts),'Repeated purge should fail');
$alerts=[];check(!manageArchivedPage($pdo,1,'purge',$alerts),'Home purged');
$alerts=[];check(!manageArchivedPage($pdo,4,'unknown',$alerts),'Unknown action accepted');
$pdo->exec('UPDATE pages SET is_archived=1,destination_page_id=999 WHERE id=3');
$alerts=[];check(!manageArchivedPage($pdo,3,'restore',$alerts),'Invalid destination restored');
check(!fetchPageById($pdo,3),'Failed restore changed archive state');
echo "Archives: draft restore, permanent purge, state/dependency guards, rollback, media preservation and role checks passed.\n";

// Announcement lifecycle uses independent temporary tables; shared library assets are untouched.
$schema = file_get_contents(dirname(__DIR__, 2) . '/announcements.php');
preg_match('/CREATE TABLE IF NOT EXISTS announcements \([\s\S]+?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/', $schema, $matches);
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS announcements', 'CREATE TEMPORARY TABLE announcements', $matches[0]));
$pdo->exec("CREATE TEMPORARY TABLE site_settings (setting_key VARCHAR(191) PRIMARY KEY,setting_value TEXT,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$legacySettings = defaultSiteSettings();
$legacySettings['announcement_title'] = 'Imported manual';
ensure_announcements_table($pdo, $legacySettings);
$manualId = (int)$pdo->query("SELECT id FROM announcements WHERE legacy_key='manual'")->fetchColumn();
$superId = (int)$pdo->query("SELECT id FROM announcements WHERE legacy_key='super'")->fetchColumn();
$pdo->exec("UPDATE announcements SET enabled=1,show_from='2026-10-01',show_to='2026-12-01'");
foreach ([$manualId=>'manual',$superId=>'super'] as $id=>$type) {
    $alerts=[];
    check(archiveAnnouncement($pdo,$id,$type,$alerts),'Announcement archive failed');
    check(select_announcement($pdo->query('SELECT * FROM announcements')->fetchAll(),$type,atForArchives('2026-11-12'))===null,'Archived announcement selected for public display');
    $public=announcement_public_settings($pdo,$legacySettings);
    $prefix=$type==='super'?'super_announcement_':'announcement_';
    check($public[$prefix.'enabled']==='0','Archived notice exposed publicly');
    check(count(fetchArchivedAnnouncements($pdo))===1,'Announcement archive list incorrect');
    $alerts=[];check(!archiveAnnouncement($pdo,$id,$type,$alerts),'Repeated announcement archive succeeded');
    $alerts=[];check(manageArchivedAnnouncement($pdo,$id,'restore',$alerts),'Announcement restore failed');
    $row=$pdo->query('SELECT * FROM announcements WHERE id='.(int)$id)->fetch();
    check((int)$row['is_archived']===0 && (int)$row['enabled']===0,'Announcement restored enabled');
    check($row['show_from']==='2026-10-01','Announcement schedule lost');
    $alerts=[];check(!manageArchivedAnnouncement($pdo,$id,'purge',$alerts),'Active announcement purged');
    $alerts=[];check(archiveAnnouncement($pdo,$id,$type,$alerts),'Announcement re-archive failed');
    $alerts=[];check(manageArchivedAnnouncement($pdo,$id,'purge',$alerts),'Announcement purge failed');
    ensure_announcements_table($pdo,$legacySettings); // Even stale legacy settings must not recreate it.
    check(!$pdo->query("SELECT id FROM announcements WHERE legacy_key=".$pdo->quote($type))->fetchColumn(),'Purged legacy announcement recreated');
    $alerts=[];check(!manageArchivedAnnouncement($pdo,$id,'restore',$alerts),'Purged announcement restored');
}
$alerts=[];check(!manageArchivedAnnouncement($pdo,999,'unknown',$alerts),'Unknown announcement archive action accepted');
echo "Announcement archives: both types, public exclusion, disabled restore, purge and legacy re-import prevention passed.\n";
function atForArchives(string $value): DateTimeImmutable { return new DateTimeImmutable($value,new DateTimeZone('Europe/London')); }
