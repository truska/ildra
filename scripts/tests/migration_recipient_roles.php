<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/auth.php';
require dirname(__DIR__,2).'/cms.php';
require dirname(__DIR__,2).'/account_migration.php';
ini_set('session.save_path','/tmp');session_start();
function recipientCheck(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); }
function recipientReject(callable $fn,string $why):void { try {$fn();} catch (RuntimeException $e) {return;} throw new LogicException($why); }
$alerts=[];$pdo=createPdo(require dirname(__DIR__,2).'/config.php',$alerts);
if (!$pdo) throw new RuntimeException('Database unavailable');
$roles=$pdo->query('SELECT * FROM roles')->fetchAll();
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $q=str_replace('`','``',$table);
    $pdo->exec("CREATE TEMPORARY TABLE recipient_seed LIKE `$q`");
    $pdo->exec("CREATE TEMPORARY TABLE `$q` LIKE recipient_seed");
    $pdo->exec('DROP TEMPORARY TABLE recipient_seed');
}
foreach ($roles as $role) $pdo->prepare('INSERT INTO roles(id,name,level) VALUES(?,?,?)')->execute([$role['id'],$role['name'],$role['level']]);
foreach (['user','organiser','manager','admin','superadmin','developer'] as $name) {
    if (!array_filter($roles,fn($r)=>$r['name']===$name)) $pdo->prepare('INSERT INTO roles(name,level) VALUES(?,3)')->execute([$name]);
}
$roles=$pdo->query('SELECT * FROM roles')->fetchAll();
$sql=file_get_contents(dirname(__DIR__,2).'/migrations/2026-11-17-account-migration-pilot.sql');
foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$statement));
$hash=password_hash('Fixture password only',PASSWORD_DEFAULT);
$eventBefore=null;
foreach (['user','organiser','manager','admin','superadmin','developer'] as $index=>$name) {
    $role=array_values(array_filter($roles,fn($r)=>$r['name']===$name))[0]??null;
    recipientCheck($role!==null,'Missing role fixture: '.$name);
    $uid=100+$index;$email=$name.'@example.invalid';
    $pdo->prepare('INSERT INTO users(id,email,password_hash,role_id,first_name,last_name,is_protected) VALUES(?,?,?,?,?,?,?)')->execute([$uid,$email,$hash,$role['id'],'Fixture',$name,$name==='developer'?1:0]);
    $pdo->prepare('INSERT INTO people(id,owner_user_id,email,first_name,last_name) VALUES(?,?,?,?,?)')->execute([$uid,$uid,$email,'Fixture',$name]);
    $user=migrationRecipient($pdo,$uid);
    recipientCheck($user['role']===$name,'Role recipient rejected or changed');
    $rows=array_column(migrationRecipients($pdo),null,'id');
    recipientCheck(isset($rows[$uid]) && $rows[$uid]['reason']==='','Role missing from eligible list');
    recipientCheck(count(migrationPreviewRecipients($pdo,'selected',[$uid],false))===1,'Selected preview excludes role');
    $pdo->prepare('INSERT INTO migration_enrolments(user_id,person_id) VALUES(?,?)')->execute([$uid,$uid]);
    $token=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO migration_invitations(user_id,token_hash,email,credential_stamp,expires_at,campaign_revision) VALUES(?,?,?,?,?,1)')->execute([$uid,hash('sha256',$token),$email,hash('sha256',$hash),auth_future_string(3600)]);
    $pdo->prepare("UPDATE migration_invitations SET delivery_status='sent',sent_at=NOW() WHERE user_id=?")->execute([$uid]);
    $before=$pdo->query('SELECT * FROM users WHERE id='.$uid)->fetch();
    if ($name==='organiser') {
        $pdo->prepare("INSERT INTO events(title,event_date,organiser_user_id,status,event_type_id) VALUES('Fixture linked event','2030-07-15',?,'draft',1)")->execute([$uid]);
        $eventBefore=$pdo->query('SELECT * FROM events')->fetchAll();
    }
    $_SESSION=[];
    if ($name!=='user') {
        recipientReject(fn()=>migrationAcceptInvitation($pdo,$token,$uid),'Privileged invitation accepted without normal login');
        recipientCheck(migrationInspectInvitation($pdo,$token)!==null,'Rejected acceptance consumed invitation');
        $_SESSION['user']=['id'=>$uid,'role'=>$name];
        recipientReject(fn()=>migrationAcceptInvitation($pdo,$token,$uid),'Session without credential binding accepted');
        $_SESSION['user']=['id'=>$uid,'role'=>$name];
        authBindCredentialSession($uid,$hash);
    }
    migrationAcceptInvitation($pdo,$token,$uid);
    recipientCheck($_SESSION['user']['role']===$name && (int)$_SESSION['user']['level']===(int)$role['level'],'Acceptance altered role/level');
    recipientCheck($pdo->query('SELECT * FROM users WHERE id='.$uid)->fetch()===$before,'Acceptance changed account permissions/credentials');
}
recipientCheck($pdo->query('SELECT * FROM events')->fetchAll()===$eventBefore,'Organiser event links changed');
$pdo->exec('UPDATE users SET is_archived=1 WHERE id=103');
recipientReject(fn()=>migrationRecipient($pdo,103),'Archived account accepted');
recipientCheck(!isset(array_column(migrationRecipients($pdo),null,'id')[103]),'Archived account listed');
$pdo->exec("UPDATE people SET email='mismatch@example.invalid' WHERE id=104");
$rows=array_column(migrationRecipients($pdo),null,'id');
recipientCheck($rows[104]['reason']==='Person match needs review','Person mismatch not blocked');
recipientReject(fn()=>migrationPreviewRecipients($pdo,'selected',[104],false),'Person mismatch preview accepted');
$pdo->exec('DELETE FROM people WHERE id=105');
recipientReject(fn()=>migrationPreviewRecipients($pdo,'selected',[105],false),'Missing Person preview accepted');
recipientCheck($pdo->query('SELECT * FROM roles')->fetchAll()===$roles,'Role definitions changed');
echo "PASS: all recipient roles, active/matching-Person checks, privileged normal-login requirement, retained roles/levels and organiser event links. Temporary tables only; no email sent.\n";
