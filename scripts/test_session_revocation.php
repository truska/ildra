<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/auth.php';
$config=require dirname(__DIR__).'/config.php';$db=$config['db'];
class RevocationTestPDO extends PDO {
    public bool $failRevocation=false;
    public function __construct(array $db) {
        parent::__construct('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    public function exec(string $statement): int|false {
        $statement=preg_replace('/CREATE TABLE IF NOT EXISTS (auth_(?:remember_tokens|magic_tokens|password_resets))\b/i','CREATE TEMPORARY TABLE IF NOT EXISTS $1',$statement);
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if($this->failRevocation && str_contains($query,'DELETE FROM auth_magic_tokens WHERE user_id'))throw new PDOException('Synthetic token revocation failure');
        return parent::prepare($query,$options);
    }
}
function revokeCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function revokeHash(PDO $pdo,int $id=1): string { return (string)$pdo->query('SELECT password_hash FROM users WHERE id='.(int)$id)->fetchColumn(); }
function revokeLogin(PDO $pdo,int $id): array {
    $_SESSION=[];$success=null;
    finishUserLogin($pdo,['id'=>$id,'email'=>'user'.$id.'@example.invalid','role'=>$id===2?'admin':'user','level'=>$id===2?5:1,'password_hash'=>revokeHash($pdo,$id)],[],false,$success);
    return $_SESSION;
}
function revokeRemember(PDO $pdo,int $userId=1): string {
    $selector=base64url_encode(random_bytes(9));$validator=base64url_encode(random_bytes(24));
    $pdo->prepare('INSERT INTO auth_remember_tokens(user_id,selector,token_hash,expires_at) VALUES(?,?,?,?)')->execute([$userId,$selector,hash('sha256',$validator),auth_future_string(3600)]);
    return $selector.':'.$validator;
}
function revokeCount(PDO $pdo,string $table,int $id=1): int { return (int)$pdo->query("SELECT COUNT(*) FROM $table WHERE user_id=".(int)$id)->fetchColumn(); }
ini_set('session.save_path','/tmp');session_start();
$pdo=new RevocationTestPDO($db);
$pdo->exec('CREATE TEMPORARY TABLE roles(id INT PRIMARY KEY,name VARCHAR(30),level INT)');
$pdo->exec("INSERT INTO roles VALUES(1,'user',1),(2,'admin',5)");
$pdo->exec('CREATE TEMPORARY TABLE users(id INT PRIMARY KEY,email VARCHAR(255),password_hash VARCHAR(255),is_archived TINYINT DEFAULT 0,role_id INT,first_name VARCHAR(60),last_name VARCHAR(60),last_login_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB');
$oldHash=password_hash('old password 123',PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users(id,email,password_hash,role_id) VALUES(1,'user1@example.invalid',?,1),(2,'user2@example.invalid',?,2)")->execute([$oldHash,password_hash('admin password 123',PASSWORD_DEFAULT)]);
ensureAuthTables($pdo);ensurePasswordResetTable($pdo);
foreach(['users','roles','auth_remember_tokens','auth_magic_tokens','auth_password_resets'] as $table) {
    $definition=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    revokeCheck(str_contains(strtoupper($definition),'CREATE TEMPORARY TABLE'),'Refusing to test against persistent account/token tables');
}
$browserA=revokeLogin($pdo,1);$browserB=revokeLogin($pdo,1);$otherBrowser=revokeLogin($pdo,2);
$remember=revokeRemember($pdo);$otherRemember=revokeRemember($pdo,2);
$oldMagic=createMagicLoginToken($pdo,1);$oldReset=createPasswordResetToken($pdo,1);
$alerts=[];revokeCheck(authValidateSession($pdo,$alerts),'Fresh session refused');
$_SESSION=$browserA;$sessionId=session_id();
$alerts=[];revokeCheck(!changeOwnPassword($pdo,1,['current_password'=>'wrong','new_password'=>'new password 456','confirm_password'=>'new password 456'],$alerts),'Wrong current password changed credential');
revokeCheck(revokeHash($pdo)===$oldHash && revokeCount($pdo,'auth_remember_tokens')===1,'Rejected change revoked/altered credentials');
$alerts=[];$_COOKIE['ildra_remember']=$remember;
revokeCheck(changeOwnPassword($pdo,1,['current_password'=>'old password 123','new_password'=>'new password 456','confirm_password'=>'new password 456'],$alerts),'Self password change failed');
revokeCheck(session_id()!==$sessionId,'Password-change session ID not rotated');
$currentBrowser=$_SESSION;$alerts=[];revokeCheck(authValidateSession($pdo,$alerts),'Changing browser was logged out');
revokeCheck(!isset($_COOKIE['ildra_remember']),'Changing browser retained remembered cookie');
foreach(['auth_remember_tokens','auth_magic_tokens','auth_password_resets'] as $table)revokeCheck(revokeCount($pdo,$table)===0,'Old tokens retained: '.$table);
revokeCheck(revokeCount($pdo,'auth_remember_tokens',2)===1,'Other account remembered token revoked');
$_SESSION=$browserB;$_COOKIE['ildra_remember']=$remember;$alerts=[];
revokeCheck(!authValidateSession($pdo,$alerts) && empty($_SESSION['user']) && !isset($_COOKIE['ildra_remember']),'Other session retained access or could auto-restore');
$_SESSION=[];$_COOKIE['ildra_remember']=$remember;$alerts=[];revokeCheck(attemptRememberMeLogin($pdo,[],$alerts)===null,'Revoked remembered login restored');
$alerts=[];revokeCheck(consumeMagicLoginToken($pdo,$oldMagic,$alerts)===null,'Old magic link restored access');
revokeCheck(consumePasswordResetToken($pdo,$oldReset)===null,'Old recovery link still accepted');
$_SESSION=$otherBrowser;$alerts=[];revokeCheck(authValidateSession($pdo,$alerts),'Unrelated account session invalidated');
// Recovery keeps the newly recovered browser, invalidating all older sessions/tokens again.
$recovery=createPasswordResetToken($pdo,1);$oldMagic=createMagicLoginToken($pdo,1);$remember=revokeRemember($pdo);
$_SESSION=[];$_COOKIE['ildra_remember']=$remember;$_POST=['token'=>$recovery,'password'=>'recovered password 789','confirm_password'=>'recovered password 789'];$alerts=[];$success=null;
revokeCheck(handlePasswordReset($pdo,$alerts,$success)['id']===1 && !$alerts,'Password recovery failed');
$recoveredBrowser=$_SESSION;$alerts=[];revokeCheck(authValidateSession($pdo,$alerts),'Recovery browser was invalidated');
foreach(['auth_remember_tokens','auth_magic_tokens','auth_password_resets'] as $table)revokeCheck(revokeCount($pdo,$table)===0,'Recovery retained old tokens');
$_SESSION=$currentBrowser;$alerts=[];revokeCheck(!authValidateSession($pdo,$alerts),'Pre-recovery browser retained access');
$_SESSION=[];$alerts=[];$success=null;revokeCheck(handlePasswordReset($pdo,$alerts,$success)===null,'Recovery link reusable');
// Administrative reset also invalidates the target, never the unrelated administrator.
$_SESSION=$otherBrowser;$alerts=[];revokeCheck(resetUserPassword($pdo,1,'admin reset password 123',$alerts),'Administrative reset failed');
$alerts=[];revokeCheck(authValidateSession($pdo,$alerts),'Administrator resetting another user was invalidated');
$_SESSION=$recoveredBrowser;$alerts=[];revokeCheck(!authValidateSession($pdo,$alerts),'Admin reset did not invalidate target session');
// Missing/legacy stamps and an original administrator invalidated while acting-as fail closed.
$_SESSION=['user'=>$browserA['user']];$alerts=[];revokeCheck(!authValidateSession($pdo,$alerts),'Legacy unstamped session trusted');
$_SESSION=$otherBrowser;$_SESSION['act_as_original_user']=$otherBrowser['user'];$_SESSION['user']=$browserA['user'];$alerts=[];
revokeCheck(authValidateSession($pdo,$alerts),'Valid administrator impersonation invalidated by target password change');
$alerts=[];revokeCheck(resetUserPassword($pdo,2,'changed admin password 456',$alerts),'Administrator password reset failed');
$alerts=[];revokeCheck(!authValidateSession($pdo,$alerts) && empty($_SESSION['act_as_original_user']),'Revoked administrator retained impersonation');
// Revocation failure rolls back password, token deletion and consumption of the recovery token.
$remember=revokeRemember($pdo);$recovery=createPasswordResetToken($pdo,1);$magic=createMagicLoginToken($pdo,1);$before=revokeHash($pdo);
$pdo->failRevocation=true;$_SESSION=[];$_POST=['token'=>$recovery,'password'=>'failed recovery password','confirm_password'=>'failed recovery password'];$alerts=[];$success=null;
revokeCheck(handlePasswordReset($pdo,$alerts,$success)===null,'Recovery succeeded despite failed token revocation');
$pdo->failRevocation=false;
revokeCheck(revokeHash($pdo)===$before && revokeCount($pdo,'auth_remember_tokens')===1 && revokeCount($pdo,'auth_magic_tokens')===1,'Failed recovery partially committed');
$alerts=[];$success=null;revokeCheck(handlePasswordReset($pdo,$alerts,$success)['id']===1,'Recovery token consumed despite rolled-back failure');
// A login verified before a concurrent reset must not mint a new remembered token afterwards.
$stale=$before;$rejected=false;
try { issueRememberMeToken($pdo,1,3600,$stale); } catch(RuntimeException $e) { $rejected=true; }
revokeCheck($rejected && revokeCount($pdo,'auth_remember_tokens')===0,'Stale credential minted remembered login');
// Fresh magic and remember logins establish bindings and continue to work normally.
$_SESSION=[];$freshMagic=createMagicLoginToken($pdo,1);$alerts=[];revokeCheck(consumeMagicLoginToken($pdo,$freshMagic,$alerts)['id']===1,'Fresh magic login failed');
revokeCheck(authValidateSession($pdo,$alerts),'Magic login not bound to credential');
$_SESSION=[];$_COOKIE['ildra_remember']=revokeRemember($pdo);$alerts=[];revokeCheck(attemptRememberMeLogin($pdo,[],$alerts)['id']===1,'Fresh remembered login failed');
revokeCheck(authValidateSession($pdo,$alerts),'Remember login not bound to credential');
$alerts=[];revokeCheck(!authValidateSession(null,$alerts),'Storage failure retained privileged session');
$bootstrap=file_get_contents(dirname(__DIR__).'/bootstrap.php');
revokeCheck(strpos($bootstrap,'authValidateSession($pdo, $alerts)')<strpos($bootstrap,'attemptRememberMeLogin('),'Revocation checked after automatic login restore');
echo "Password/session revocation passed: other sessions and remembered logins rejected after change/recovery/admin reset, current/unrelated browsers preserved, impersonation/legacy/storage guards, token cleanup, rollback, stale issuance and fresh magic/remember login. Temporary tables only; no emails sent.\n";
