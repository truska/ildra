<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
ob_start();require __DIR__.'/account_migration.php'; // Existing all-table temporary fixture harness.
require_once dirname(__DIR__,2).'/archives.php';
require_once dirname(__DIR__,2).'/admin/table_sort.php';
$actor=['id'=>2,'role'=>'developer'];
foreach (['baskets','booking_items','finance_transactions','coupon_audit','email_campaign_recipients'] as $table) $pdo->exec('DELETE FROM '.$table);
$pdo->exec("INSERT INTO people(id,owner_user_id,first_name,last_name,email,is_archived) VALUES (1980001,2,'Archived','Fixture','archive@example.invalid',1),(1980002,2,'Active','Fixture','active@example.invalid',0)");
$pdo->exec("INSERT INTO horses(id,owner_user_id,name,is_archived) VALUES (1980001,2,'Archived fixture horse',1),(1980002,2,'Active fixture horse',0)");
$apply=static function(string $type,int $id,string $action,string $confirmation='DELETE',?array $who=null) use ($pdo,$actor):bool {
    $alerts=[];return manageArchivedSubject($pdo,$type,$id,$action,$who??$actor,$confirmation,$alerts);
};
foreach (['people','horses'] as $type) {
    migrationCheck(count(fetchArchivedSubjects($pdo,$type))>=1,'Archived list missing '.$type);
    migrationCheck(!$apply($type,1980001,'purge','delete'),'Lowercase confirmation accepted');
    migrationCheck(!$apply($type,1980001,'purge','DELETE',['id'=>2,'role'=>'manager']),'Manager could purge');
    migrationCheck(!$apply($type,1980002,'purge'),'Active record could be purged');
    migrationCheck($apply($type,1980001,'restore',''),'Archived record did not restore');
    migrationCheck((int)$pdo->query("SELECT is_archived FROM `$type` WHERE id=1980001")->fetchColumn()===0,'Restore failed');
    $pdo->exec("UPDATE `$type` SET is_archived=1 WHERE id=1980001");
}
$pdo->exec('UPDATE people SET owner_user_id=3 WHERE id=1980001');
migrationCheck(!$apply('people',1980001,'restore',''),'Archived login owner allowed restore');
$pdo->exec('UPDATE people SET owner_user_id=2 WHERE id=1980001');
migrationCheck(!$apply('users',2,'purge'),'Arbitrary archive type accepted');
migrationCheck(!$apply('horses',1,'purge'),'System horse could be deleted');
$pdo->exec('UPDATE people SET is_archived=1 WHERE id=123');
migrationCheck(!$apply('people',123,'purge'),'Protected account holder could be deleted');
// Completed migrations still carry a Person reference and must not become dangling.
$pdo->exec('UPDATE migration_enrolments SET person_id=1980001 WHERE user_id=2');
migrationCheck(!$apply('people',1980001,'purge'),'Migration reference ignored');
$pdo->exec('UPDATE migration_enrolments SET person_id=123 WHERE user_id=2');
$pdo->exec("INSERT INTO membership_purchases(member_id,membership_type_id,membership_year) VALUES (1980001,1,2027)");
migrationCheck(!$apply('people',1980001,'purge'),'Membership history ignored');
$pdo->exec('DELETE FROM membership_purchases WHERE member_id=1980001');
$pdo->exec("INSERT INTO horse_logbook_purchases(horse_id,logbook_type_id,valid_year) VALUES (1980001,1,2027)");
migrationCheck(!$apply('horses',1980001,'purge'),'Logbook history ignored');
$pdo->exec('DELETE FROM horse_logbook_purchases WHERE horse_id=1980001');
foreach (['people'=>['user_person_links','person_id'],'horses'=>['user_horse_links','horse_id']] as $type=>[$table,$column]) {
    $pdo->exec("INSERT INTO `$table`(user_id,`$column`,status,revoked_at) VALUES (2,1980001,'approved',NOW())");
    migrationCheck(!$apply($type,1980001,'purge'),'Revoked share ignored');
    $pdo->exec("DELETE FROM `$table` WHERE `$column`=1980001");
    $entity=$type==='people'?'person':'horse';
    $pdo->prepare("INSERT INTO share_requests(entity_type,entity_id,created_by_user_id,target_email,status) VALUES (?,1980001,2,'share@example.invalid','pending')")->execute([$entity]);
    migrationCheck(!$apply($type,1980001,'purge'),'Sharing request ignored');
    $pdo->exec('DELETE FROM share_requests WHERE entity_id=1980001');
}
$pdo->exec("INSERT INTO booking_items(booking_id,metadata,is_withdrawn) VALUES (1,'{\"nested\":{\"person_id\":1980001,\"horse_id\":\"1980001\"}}',1)");
foreach (['people','horses'] as $type) migrationCheck(!$apply($type,1980001,'purge'),'Withdrawn booking JSON ignored');
$pdo->exec('DELETE FROM booking_items');
$pdo->prepare('INSERT INTO baskets(session_id,user_id,basket_json) VALUES (?,2,?)')->execute(['archive-fixture-basket',json_encode([['member_id'=>1980001],['horse_id'=>1980001]])]);
foreach (['people','horses'] as $type) migrationCheck(!$apply($type,1980001,'purge'),'Basket reference ignored');
$pdo->exec('DELETE FROM baskets');
$pdo->exec("INSERT INTO booking_items(booking_id,metadata) VALUES (1,'not valid JSON')");
migrationCheck(!$apply('people',1980001,'purge'),'Uncheckable metadata allowed purge');
$pdo->exec('DELETE FROM booking_items');
// A substring or a numeric ID under a different key is not a reference.
$pdo->exec("INSERT INTO booking_items(booking_id,metadata) VALUES (1,'{\"notes\":\"1980001\",\"person_id\":19800010,\"horse_id\":19800010}')");
foreach (['people','horses'] as $type) migrationCheck(archivedSubjectPurgeBlockers($pdo,$type,1980001)===[],'False-positive JSON match');
$ownerBefore=$pdo->query('SELECT * FROM users WHERE id=2')->fetch();
if (!empty($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')) {
    $currentUser=$actor;$_GET=[];
    foreach (['people','horses'] as $activeTab) {
        ob_start();require dirname(__DIR__,2).'/admin/archive_subjects.php';$html=ob_get_clean();
        file_put_contents($argv[1].'/archive-'.$activeTab.'.html',$html);chmod($argv[1].'/archive-'.$activeTab.'.html',0600);
    }
}
foreach (['people','horses'] as $type) {
    migrationCheck($apply($type,1980001,'purge'),'Unreferenced archived record could not be purged');
    migrationCheck(!$apply($type,1980001,'purge'),'Repeated purge reported success');
    migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM `$type` WHERE id=1980001")->fetchColumn()===0,'Purged record retained');
    migrationCheck((int)$pdo->query("SELECT COUNT(*) FROM `$type` WHERE id=1980002")->fetchColumn()===1,'Unrelated record removed');
}
migrationCheck($pdo->query('SELECT * FROM users WHERE id=2')->fetch()===$ownerBefore,'Owning login changed');
ob_end_clean();echo "PASS: People/Horses archive lists, restore, typed purge, role/active/system/protected guards, migration/history/sharing/basket/JSON blockers, stale requests and retained logins. Temporary tables only; no real deletion.\n";
