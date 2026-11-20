<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();require __DIR__.'/account_migration.php';ob_end_clean();
// The required base fixture shadows every table; recheck all mutation targets here.
foreach(['users','people','migration_enrolments','membership_purchases','baskets','membership_types'] as $table) {
    migrationCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Refusing non-temporary fixture mutation: '.$table);
}
$u=$pdo->query('SELECT * FROM users WHERE id=2')->fetch();
$_SESSION['user']=['id'=>2,'role'=>'developer','email'=>$u['email']];authBindCredentialSession(2,$u['password_hash']);
$pdo->exec("UPDATE migration_enrolments SET current_step='person',completed_at=NULL WHERE user_id=2");
$pdo->exec('DELETE FROM membership_purchases');$pdo->exec('DELETE FROM baskets');
$pdo->exec("INSERT INTO membership_types (id,name,cost,status,membership_year) VALUES (99001,'Fixture membership',35,'published',2027)");
$pdo->exec("INSERT INTO membership_types (id,name,cost,status,membership_year,display_order) VALUES (99002,'A second fixture',35,'published',2027,900), (99003,'Z first fixture',35,'published',2027,1)");
$ids=array_column(migrationMembershipOffers($pdo),'id');
migrationCheck(array_search(99003,$ids)<array_search(99002,$ids),'Onboarding ignores membership display order');
$offer=$pdo->query('SELECT * FROM membership_types WHERE id=99001')->fetch();$year=membership_purchase_year(migrationCommerceSettings($pdo));
$data=$person;$data['add_membership']=1;$data['membership_type_id']=99001;$data['membership_quotes']=[99001=>migrationMembershipQuote($offer,$year)];
$pdo->exec("UPDATE membership_types SET admin_allocation_only=1 WHERE id=99001");
migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$data)['membership_type_id']),'Admin membership accepted');
$pdo->exec("UPDATE membership_types SET admin_allocation_only=0,status='draft' WHERE id=99001");
migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$data)['membership_type_id']),'Unpublished membership accepted');
$pdo->exec("UPDATE membership_types SET status='published' WHERE id=99001");
$missing=$data;$missing['phone']='';
migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$missing)['phone']),'Missing person details accepted');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM baskets')->fetchColumn()===0,'Invalid person created basket');
$bad=$data;$bad['membership_type_id']=0;
migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$bad)['membership_type_id']),'Invalid type accepted');
migrationCheck(migrationSaveDetails($pdo,2,'person',$data)===[],'Add membership failed');
migrationCheck(migrationState($pdo,2)['current_step']==='person','Add membership advanced stage');
migrationCheck(count($_SESSION['basket'])===1 && (float)$_SESSION['basket'][0]['price']===35.0,'Incorrect canonical basket');
migrationCheck((int)$_SESSION['basket'][0]['member_id']===123,'Wrong person');
migrationCheck(migrationSaveDetails($pdo,2,'person',$data)===[] && count($_SESSION['basket'])===1,'Duplicate membership');
$pdo->exec('UPDATE membership_types SET cost=40 WHERE id=99001');
migrationCheck(isset(migrationSaveDetails($pdo,2,'person',$data)['membership_type_id']),'Stale price accepted');
migrationCheck((float)$_SESSION['basket'][0]['price']===35.0,'Rejected price changed basket');
$pdo->prepare('UPDATE baskets SET session_id=? WHERE session_id=?')->execute(['fixture-previous-session',session_id()]);$_SESSION['basket']=[];
migrationRestoreMembershipBasket($pdo,2);
migrationCheck(count($_SESSION['basket'])===1,'Cross-session membership lost');
migrationCheck((int)$pdo->query('SELECT COUNT(*) FROM membership_purchases')->fetchColumn()===0,'Added paid entitlement');
unset($data['add_membership']);migrationCheck(migrationSaveDetails($pdo,2,'person',$data)===[],'Next failed');
migrationCheck(migrationState($pdo,2)['current_step']==='horses','Next did not advance');
echo "PASS: membership selection, canonical quote, required type, duplicate prevention, stage return, resume and no paid entitlement. Temporary tables only.\n";
