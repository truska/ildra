<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/import_workspace.php';
require dirname(__DIR__, 2) . '/auth.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$config = require dirname(__DIR__, 3) . '/private/config.php';
$alerts=[]; $pdo=createPdo($config,$alerts);
check($pdo instanceof PDO, 'Database unavailable');
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
// Shadow EVERY installed table before exercising application mutation functions.
foreach ($tables as $table) {
    $name=str_replace('`','``',$table);
    $pdo->exec("CREATE TEMPORARY TABLE import_fixture_seed LIKE `$name`");
    $pdo->exec("INSERT INTO import_fixture_seed SELECT * FROM `$name`");
    $pdo->exec("CREATE TEMPORARY TABLE `$name` LIKE import_fixture_seed");
    $pdo->exec("INSERT INTO `$name` SELECT * FROM import_fixture_seed");
    $pdo->exec('DROP TEMPORARY TABLE import_fixture_seed');
}
foreach ($tables as $table) {
    $name=str_replace('`','``',$table);
    check(str_contains($pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1], 'CREATE TEMPORARY TABLE'), 'Refusing to mutate non-temporary table');
}
$workspace=importWorkspaceConfig(); $keep=(int)$workspace['protected_user_id'];
$actor=['id'=>$keep,'role'=>'developer'];$host='dev-ildra.witecanvas.com';
check(importWorkspaceAuthorize($pdo,$actor,$workspace,$host.':28085')===$keep, 'Development proxy port authorized');
foreach (['dev-ildra.witecanvas.com.evil.test:28085', 'dev-ildra.witecanvas.com:0', 'dev-ildra.witecanvas.com:65536', 'dev-ildra.witecanvas.com:28085/path'] as $invalidHost) {
    check(!importWorkspaceEnvironmentAllowed($workspace,realpath(dirname(__DIR__,2)),(string)$workspace['database'],$invalidHost), 'Invalid host rejected: '.$invalidHost);
}
check(importWorkspaceAuthorize($pdo,$actor,$workspace,$host)===$keep, 'Protected developer authorized');
check(!importWorkspaceEnvironmentAllowed([],realpath(dirname(__DIR__,2)),(string)$workspace['database'],$host), 'Private opt-in required');
check(!importWorkspaceEnvironmentAllowed($workspace,realpath(dirname(__DIR__,2)),(string)$workspace['database'],'enduranceridingireland.com'), 'Live host rejected');
check(!importWorkspaceEnvironmentAllowed($workspace,'/another/checkout',(string)$workspace['database'],$host), 'Copied source rejected');
check(!importWorkspaceEnvironmentAllowed($workspace,realpath(dirname(__DIR__,2)),'other_database',$host), 'Wrong DB rejected');
try { importWorkspaceAuthorize($pdo,['id'=>$keep,'role'=>'superadmin'],$workspace,$host); throw new LogicException('Superadmin accepted'); }
catch (RuntimeException $e) {}
$plan=importWorkspacePlan($pdo,$keep);
$original=importWorkspaceSnapshot($pdo,$plan);
$protected=$pdo->query('SELECT * FROM users WHERE id='.$keep)->fetch();
$systemHorse=$pdo->query('SELECT * FROM horses WHERE id=1')->fetch();
$eventCount=(int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
$pdo->beginTransaction();
importWorkspaceApply($pdo,$plan);
check($pdo->query('SELECT * FROM users WHERE id='.$keep)->fetch()===$protected,'Protected login unchanged');
check((int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn()===0,'All people removed');
check((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn()===$eventCount,'Calendar preserved');
$afterHorse=$pdo->query('SELECT * FROM horses WHERE id=1')->fetch();
check((!$systemHorse && !$afterHorse) || ($systemHorse['name']===$afterHorse['name']), 'System horse preserved');
foreach (testingResetCounts($pdo,$plan) as $table=>$count) check($count===0,'Data remains in '.$table);
foreach ($plan['updates'] as $table=>$fields) foreach ($fields as $field=>$value) {
    check((int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE `$field` IS NOT NULL AND `$field` NOT IN (0,$keep)")->fetchColumn()===0,'Orphan reference '.$table.'.'.$field);
}
check($pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='next_member_number'")->fetchColumn()==='1000','Membership numbering restarted');
check($pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='campaign_live_sending_enabled'")->fetchColumn()==='0','Campaign sending disabled');
// Repeat within the fixture, then prove rollback restores every affected row.
importWorkspaceApply($pdo,importWorkspacePlan($pdo,$keep));
$pdo->rollBack();
check(importWorkspaceSnapshot($pdo,$plan)===$original,'Rollback restores all affected records');
$alerts=[];
check(!updateUserRoleAndLevel($pdo,$keep,'user',1,$alerts),'Protected role change rejected');
$alerts=[];
check(!resetUserPassword($pdo,$keep,'fixture-password-only',$alerts),'Protected administrative password reset rejected');
$pdo->beginTransaction();
$alerts=[];
check(updateOwnUserDetails($pdo,$keep,['first_name'=>'Fixture','last_name'=>'Developer','email'=>$protected['email']],$alerts)!==null,'Protected owner can edit personal details');
$pdo->rollBack();
$alerts=[];
check(updateOwnUserDetails($pdo,$keep,['first_name'=>'Fixture','last_name'=>'Developer','email'=>'changed@example.test'],$alerts)===null,'Protected email change rejected');
$other=(int)$pdo->query('SELECT id FROM users WHERE id<>'.$keep.' LIMIT 1')->fetchColumn();
try { deleteOrArchiveUser($pdo,$keep,['id'=>$other ?: $keep+1,'role'=>'developer']); throw new LogicException('Protected deletion accepted'); }
catch (RuntimeException $e) {}
check(importWorkspaceSnapshot($pdo,$plan)===$original,'Protection checks changed no data');
$preview=['actor_id'=>$keep,'time'=>time(),'plan'=>$plan,'snapshot'=>$original];
$pdo->exec("UPDATE users SET first_name='Changed fixture' WHERE id=$keep");
try { importWorkspaceRun($pdo,$actor,$workspace,$host,$preview); throw new LogicException('Stale preview accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(),'Data changed'),'Unexpected stale-preview rejection: '.$e->getMessage()); }
$pdo->prepare('UPDATE users SET first_name=?,updated_at=? WHERE id=?')->execute([$protected['first_name'],$protected['updated_at'],$keep]);
check(importWorkspaceSnapshot($pdo,$plan)===$original,'Restored stale-preview fixture');
$backup=importWorkspaceRun($pdo,$actor,$workspace,$host,$preview);
$backupPath=dirname(__DIR__,3).'/private/import-workspace-backups/'.$backup;
try {
    check((fileperms($backupPath) & 0777)===0600,'Backup permissions must be private');
    $lines=file($backupPath, FILE_IGNORE_NEW_LINES);
    check(json_decode($lines[count($lines)-1],true)['backup_complete']===true,'Backup completion marker');
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'Complete backed-up fixture reset');
} finally { unlink($backupPath); }
$pdo->exec('ALTER TABLE pages ADD COLUMN future_user_id INT NULL');
try { importWorkspacePlan($pdo,$keep); throw new LogicException('Unreviewed identity link accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(),'Unreviewed identity reference'),'Unknown reference rejection'); }
// Render the actual form template without invoking its authenticated controller.
function admin_layout_end(): void {}
$currentUser=['id'=>$keep,'email'=>'fixture@example.test'];
$preview=['token'=>'fixture-preview-token','snapshot'=>$original];
$source=file_get_contents(dirname(__DIR__,2).'/admin/import_workspace.php');
$start=strpos($source,"admin_layout_start('Import workspace', 'import_workspace');");
$start=strpos($source,'?>',$start);
ob_start(); eval(substr($source,$start)); $html=ob_get_clean();
$dom=new DOMDocument(); @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
check($xpath->query('//form//input[@name="csrf"]')->length===2,'Both workspace forms carry CSRF tokens');
check($xpath->query('//input[@name="confirmation" and @pattern="RESET DEV IMPORT DATA"]')->length===1,'Typed confirmation field');
check($xpath->query('//input[@name="preview_token" and @value="fixture-preview-token"]')->length===1,'Preview binding field');
check(str_contains($html,'CSV delivery'),'CSV delivery guidance');
echo "PASS: temporary-table reset, repeat runs, rollback, preserved login/calendar/system horse, reference cleanup, protected identity, dev-only authorization stale preview, private full backup and unknown-reference rejection. No real records deleted or emails sent.\n";
