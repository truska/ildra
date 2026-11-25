<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/cms.php';
require dirname(__DIR__,2).'/admin_settings_audit.php';
require dirname(__DIR__,2).'/admin_menu.php';
require dirname(__DIR__,2).'/email.php';
function auditCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$alerts=[];$pdo=createPdo(require dirname(__DIR__,2).'/config.php',$alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $quoted=str_replace('`','``',$table);
    $pdo->exec("CREATE TEMPORARY TABLE audit_seed LIKE `$quoted`");
    $pdo->exec("CREATE TEMPORARY TABLE `$quoted` LIKE audit_seed");
    $pdo->exec('DROP TEMPORARY TABLE audit_seed');
}
// DDL from the audit service must also stay connection-local if the table is new.
$pdo->exec('CREATE TEMPORARY TABLE IF NOT EXISTS admin_audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_user_id INT UNSIGNED NULL,action_key VARCHAR(100),target_type VARCHAR(64),target_id VARCHAR(100),detail_json JSON,ip_address VARCHAR(64),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$actor=['id'=>987,'first_name'=>'Audit','last_name'=>'Fixture','email'=>'audit@example.invalid','role'=>'admin'];
$_SERVER['REMOTE_ADDR']='192.0.2.1';
getSiteSettings($pdo);
$alerts=[];
auditCheck(adminSaveSiteSettings($pdo,['event_default_start_time'=>'08:15'],$alerts,$actor,'events'),'Save failed');
$row=$pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
$detail=json_decode($row['detail_json'],true);
auditCheck($row['action_key']==='settings.update' && (int)$row['actor_user_id']===987 && $row['target_id']==='events','Actor/tab incorrect');
auditCheck($detail['actor_email']===$actor['email'] && $row['ip_address']==='192.0.2.1','Identity snapshot/IP absent');
auditCheck($detail['changes']['event_default_start_time']['after']==='08:15','Changed value absent');
$beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
$alerts=[];adminSaveSiteSettings($pdo,['event_default_start_time'=>'08:15'],$alerts,$actor,'events');
auditCheck((int)$pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===$beforeCount,'Unchanged save logged');
$diff=adminAuditSettingsDiff(['smtp_password'=>'old-secret','nested'=>['api_key'=>'old-key'],'bool'=>1],['smtp_password'=>'new-secret','nested'=>['api_key'=>'new-key'],'bool'=>'1']);
$encoded=json_encode(adminAuditSafeValue($diff));
auditCheck(!str_contains($encoded,'old-secret') && !str_contains($encoded,'new-secret') && !str_contains($encoded,'old-key') && !isset($diff['bool']),'Secret/type normalization failed');
auditCheck($diff['smtp_password']===['before'=>'[redacted]','after'=>'[redacted]'],'Secret diff structure lost');
$alerts=[];
adminAuditSettingsOperation($pdo,$actor,'purchasing',static fn()=>getSiteSettings($pdo),static function () use ($pdo) { savePurchasingPause($pdo,true,'Fixture pause');return true; });
$row=$pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
auditCheck($row['target_id']==='purchasing' && isset(json_decode($row['detail_json'],true)['changes']['purchasing_paused_at']),'Purchasing change missing');
$alerts=[];
adminAuditSettingsOperation($pdo,$actor,'footer.socials',static fn()=>['socials'=>adminAuditFooterRows(fetchCompanySocials($pdo))],static function () use ($pdo,&$alerts) { return saveCompanySocials($pdo,[['name'=>'Fixture','url'=>'https://example.invalid','is_active'=>1]],$alerts); });
$row=$pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
auditCheck($row['target_id']==='footer.socials','Footer link change missing');
$alerts=[];
adminAuditSettingsOperation($pdo,$actor,'global',static fn()=>getSiteSettings($pdo),static function () use ($pdo,&$alerts) { saveSiteSettings($pdo,['hero_title'=>'<script>fixture</script>'],$alerts);return false; });
$row=$pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
auditCheck(json_decode($row['detail_json'],true)['outcome']==='partial','Partial save not labelled');
ensureAdminMenuTable($pdo);
$menu=$pdo->query("SELECT child.parent_id,child.required_roles,tech.id AS tech_id FROM admin_menu_items child JOIN admin_menu_items tech ON tech.menu_key='tech' WHERE child.menu_key='audit_log'")->fetch();
auditCheck($menu && (int)$menu['parent_id']===(int)$menu['tech_id'],'Audit menu not under Tech');
$pdo->exec("UPDATE admin_menu_items SET parent_id=NULL WHERE menu_key='audit_log'");
ensureAdminMenuTable($pdo);
auditCheck($pdo->query("SELECT parent_id FROM admin_menu_items WHERE menu_key='audit_log'")->fetchColumn()===null,'Menu location forcibly reset');
$alerts=[];
$emailData=['email_enabled'=>'0','email_subject_prefix'=>'[Audit Fixture]','email_from_email'=>'fixture@example.invalid'];
adminAuditSettingsOperation($pdo,$actor,'live_email',static fn()=>array_intersect_key(getSiteSettings($pdo),$emailData),static function () use ($pdo,$emailData,&$alerts) { return saveEmailSettings($pdo,$emailData,$alerts); });
$row=$pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
auditCheck($row['target_id']==='live_email' && isset(json_decode($row['detail_json'],true)['changes']['email_subject_prefix']),'Live email settings change missing');
// Older audit events remain available, including actors with no surviving account.
adminAuditLog($pdo,'users.change_role',['id'=>99999],'user',111,['new_role'=>'admin']);
// Render the actual read-only page with fixture records and shared table filters.
function admin_layout_start(string $title,string $key):void { echo '<html><body>'; }
function admin_layout_end():void { echo '</body></html>'; }
$currentUser=['role'=>'superadmin'];
$_GET=['section'=>'events','sort'=>'day','dir'=>'desc'];
$source=file_get_contents(dirname(__DIR__,2).'/admin/audit_log.php');
$source=str_replace("require __DIR__ . '/_bootstrap.php';",'', $source);
$source=str_replace('__DIR__',var_export(dirname(__DIR__,2).'/admin',true),$source);
ob_start();eval(substr($source,5));$html=ob_get_clean();
auditCheck(str_contains($html,'08:15') && str_contains($html,'Audit Fixture') && str_contains($html,'Before'),'Viewer missing readable changes/actor');
auditCheck(!str_contains($html,'&lt;script&gt;fixture') && !str_contains($html,'Fixture pause'),'Section filter failed');
$_GET=['sort'=>'day','dir'=>'asc'];
ob_start();eval(substr($source,5));$html=ob_get_clean();
auditCheck(!str_contains($html,'<script>fixture</script>') && str_contains($html,'&lt;script&gt;fixture&lt;/script&gt;'),'Stored HTML not escaped');
auditCheck(str_contains($html,'name="actor"') && str_contains($html,'name="day"') && str_contains($html,'name="search_details"'),'Header search/select/date tools missing');
$_GET=['search_details'=>'08:15'];
ob_start();eval(substr($source,5));$filtered=ob_get_clean();
auditCheck(str_contains($filtered,'Audit Fixture') && !str_contains($filtered,'Fixture pause'),'Details search failed');
$_GET=['action_key'=>'users.change_role'];
ob_start();eval(substr($source,5));$legacy=ob_get_clean();
auditCheck(str_contains($legacy,'User #99999') && str_contains($legacy,'new_role'),'Existing audit actions/deleted actors missing');
foreach (['admin','manager','organiser'] as $role) {
    $path=tempnam('/tmp','audit-role-');
    file_put_contents($path,"<?php require ".var_export(dirname(__DIR__,2).'/helpers.php',true)."; \$currentUser=['role'=>".var_export($role,true)."]; eval(".var_export(substr($source,5),true).");");
    $output=shell_exec('php '.escapeshellarg($path));
    unlink($path);
    auditCheck($output==='Access denied.','Audit viewer allowed '.$role);
}
if (!empty($argv[1])) file_put_contents($argv[1],$html);
echo "PASS: settings change/no-op/partial saves, identity, secrets, purchasing/footer coverage, Tech menu placement, actual viewer filtering and escaped details. Temporary tables only.\n";
