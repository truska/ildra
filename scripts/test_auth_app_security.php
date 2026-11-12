<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/db.php';require dirname(__DIR__).'/auth.php';
$config=require dirname(__DIR__).'/config.php';$db=$config['db'];
class AuthAppSecurityTestPDO extends PDO {
    public bool $failMutation=false;
    public function __construct(array $db){parent::__construct('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    public function exec(string $sql):int|false{return parent::exec(preg_replace('/CREATE TABLE IF NOT EXISTS (auth_(?:throttle|remember_tokens|magic_tokens|password_resets))\b/i','CREATE TEMPORARY TABLE IF NOT EXISTS $1',$sql));}
    public function prepare(string $sql,array $options=[]):PDOStatement|false{if($this->failMutation && str_contains($sql,'UPDATE users SET auth_app'))throw new PDOException('Synthetic mutation failure');return parent::prepare($sql,$options);}
}
function appCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function appRow(PDO $pdo):array{return $pdo->query('SELECT * FROM users WHERE id=1')->fetch();}
function appLogin(PDO $pdo):array{
    $_SESSION=[];$row=appRow($pdo);$user=['id'=>1,'email'=>$row['email'],'role'=>'user','level'=>1];$_SESSION['user']=$user;authBindCredentialSession(1,$row['password_hash']);return $user;
}
function appBegin(PDO $pdo,array $user,array $settings,array $proof):array{$_POST=$proof;$alerts=[];$setup=beginAuthAppSetup($pdo,$user,$settings,$alerts);appCheck((bool)$setup && !$alerts,'Fresh setup proof rejected: '.json_encode($alerts));return $setup;}
ini_set('session.save_path','/tmp');session_start();
$pdo=new AuthAppSecurityTestPDO($db);
$pdo->exec('CREATE TEMPORARY TABLE roles(id INT PRIMARY KEY,name VARCHAR(30),level INT)');$pdo->exec("INSERT INTO roles VALUES(1,'user',1)");
$pdo->exec('CREATE TEMPORARY TABLE users(id INT PRIMARY KEY,email VARCHAR(255),password_hash VARCHAR(255),is_archived TINYINT DEFAULT 0,role_id INT,first_name VARCHAR(60),last_name VARCHAR(60),last_login_at DATETIME NULL,updated_at DATETIME NULL,auth_app_enabled TINYINT DEFAULT 0,auth_app_secret VARCHAR(64) NULL,auth_app_confirmed_at DATETIME NULL) ENGINE=InnoDB');
$password='known security test password';$hash=password_hash($password,PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users(id,email,password_hash,role_id)VALUES(1,'security@example.invalid',?,1),(2,'other@example.invalid',?,1)")->execute([$hash,$hash]);
ensureAuthThrottleTable($pdo);
foreach(['users','roles','auth_throttle']as$table){$schema=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];appCheck(str_contains(strtoupper($schema),'CREATE TEMPORARY TABLE'),'Refusing persistent fixture table');}
$_SERVER['REMOTE_ADDR']='192.0.2.66';$settings=['auth_app_login_enabled'=>1,'hero_title'=>'Fixture'];$user=appLogin($pdo);
$_POST=[];$alerts=[];appCheck(beginAuthAppSetup($pdo,$user,$settings,$alerts)===[] && empty($_SESSION['pending_auth_app_secret']),'Unattended session can start setup');
$rogue=auth_app_generate_secret();$_SESSION['pending_auth_app_secret']=$rogue;$_POST=['code'=>auth_app_totp_code($rogue)];$alerts=[];
appCheck(!confirmAuthAppSetup($pdo,1,$alerts) && empty(appRow($pdo)['auth_app_enabled']),'Direct setup confirmation bypasses fresh proof');
$setup=appBegin($pdo,$user,$settings,['auth_password'=>$password]);
appCheck(empty(appRow($pdo)['auth_app_enabled']),'Starting setup enables authenticator before code confirmation');
appCheck(authAppSecurityPolicy()['setup_verification_seconds']===600 && $_SESSION['auth_app_setup_verification']['expires_at']>=time()+599,'Setup proof lifetime incorrect');
appCheck(!str_contains(serialize($_SESSION),$password),'Fresh password stored in session');
$_POST=['code'=>auth_app_totp_code($setup['secret'])];$alerts=[];appCheck(confirmAuthAppSetup($pdo,1,$alerts),'New authenticator code rejected');
$original=appRow($pdo)['auth_app_secret'];appCheck($original===$setup['secret'] && empty($_SESSION['auth_app_setup_verification']),'Setup proof not consumed');
$alerts=[];appCheck(!confirmAuthAppSetup($pdo,1,$alerts),'Consumed setup verification reused');
$_POST=[];$alerts=[];appCheck(!disableAuthApp($pdo,1,$alerts) && appRow($pdo)['auth_app_secret']===$original,'Unattended session can remove authenticator');
$pdo->exec('DELETE FROM auth_throttle');
$setup=appBegin($pdo,$user,$settings,['auth_existing_code'=>auth_app_totp_code($original)]);
appCheck(appRow($pdo)['auth_app_secret']===$original,'Replacement changed old authenticator before new confirmation');
$_SESSION['auth_app_setup_verification']['expires_at']=time();$alerts=[];$_POST=['code'=>auth_app_totp_code($setup['secret'])];
appCheck(!confirmAuthAppSetup($pdo,1,$alerts) && appRow($pdo)['auth_app_secret']===$original && empty($_SESSION['pending_auth_app_secret']),'Expired proof enabled replacement');
$setup=appBegin($pdo,$user,$settings,['auth_password'=>$password]);session_regenerate_id(true);
appCheck(pendingAuthAppSetup($user,$settings)===null,'Proof can move between session IDs');
$setup=appBegin($pdo,$user,$settings,['auth_password'=>$password]);$_POST=['code'=>auth_app_totp_code($setup['secret'])];$alerts=[];
appCheck(!confirmAuthAppSetup($pdo,2,$alerts),'Setup verification can target another account');
$setup=appBegin($pdo,$user,$settings,['auth_password'=>$password]);
$newOtherDeviceSecret=auth_app_generate_secret();$pdo->prepare('UPDATE users SET auth_app_secret=? WHERE id=1')->execute([$newOtherDeviceSecret]);
$_POST=['code'=>auth_app_totp_code($setup['secret'])];$alerts=[];
appCheck(!confirmAuthAppSetup($pdo,1,$alerts) && appRow($pdo)['auth_app_secret']===$newOtherDeviceSecret,'Stale replacement proof overwrote authenticator changed elsewhere');
$pdo->exec('DELETE FROM auth_throttle');
$setup=appBegin($pdo,$user,$settings,['auth_existing_code'=>auth_app_totp_code($newOtherDeviceSecret)]);
$_POST=['code'=>auth_app_totp_code($setup['secret'])];$pdo->failMutation=true;$alerts=[];
appCheck(!confirmAuthAppSetup($pdo,1,$alerts) && appRow($pdo)['auth_app_secret']===$newOtherDeviceSecret,'Mutation failure partially replaced authenticator');
$pdo->failMutation=false;$alerts=[];appCheck(confirmAuthAppSetup($pdo,1,$alerts),'Verified replacement could not retry after rollback');
$replacement=appRow($pdo)['auth_app_secret'];appCheck($replacement===$setup['secret'],'Replacement secret not persisted');
$_POST=['auth_existing_code'=>auth_app_totp_code($replacement)];$alerts=[];appCheck(disableAuthApp($pdo,1,$alerts) && empty(appRow($pdo)['auth_app_enabled']) && appRow($pdo)['auth_app_secret']===null,'Existing authenticator proof cannot remove authenticator');
// A verified new code is not fresh identity proof: both proof stages must be satisfied.
$setup=appBegin($pdo,$user,$settings,['auth_password'=>$password]);
$bad='000000';while(auth_app_verify_code($setup['secret'],$bad))$bad=str_pad((string)((int)$bad+1),6,'0',STR_PAD_LEFT);
$pdo->exec('DELETE FROM auth_throttle');
for($i=1;$i<=5;$i++){$_POST=['code'=>$bad];$alerts=[];appCheck(!confirmAuthAppSetup($pdo,1,$alerts),'Invalid new code accepted');appCheck(($i===5)===str_contains($alerts[0]['message'],'wait 15'),'New-code attempts not throttled');}
$_POST=['code'=>auth_app_totp_code($setup['secret'])];$alerts=[];appCheck(!confirmAuthAppSetup($pdo,1,$alerts),'Correct new code bypassed active cooldown');
$pdo->exec('DELETE FROM auth_throttle');$alerts=[];appCheck(confirmAuthAppSetup($pdo,1,$alerts),'New authenticator not enabled after cooldown cleared in fixture');
// Every disable action needs a newly supplied proof; a recent setup alone is insufficient.
$_POST=[];$alerts=[];appCheck(!disableAuthApp($pdo,1,$alerts),'Recent setup implicitly authorizes removal');
$_POST=['auth_password'=>$password];$alerts=[];appCheck(disableAuthApp($pdo,1,$alerts),'Current password cannot remove authenticator');
$pdo->exec('DELETE FROM auth_throttle');
for($i=1;$i<=5;$i++){$_POST=['auth_password'=>'incorrect'];$alerts=[];appCheck(beginAuthAppSetup($pdo,$user,$settings,$alerts)===[],'Wrong security password accepted');appCheck(($i===5)===str_contains($alerts[0]['message'],'wait 15'),'Fresh-auth attempts not throttled');}
$_POST=['auth_password'=>$password];$alerts=[];appCheck(beginAuthAppSetup($pdo,$user,$settings,$alerts)===[],'Correct proof bypassed cooldown');
$pdo->exec('DELETE FROM auth_throttle');
$setup=appBegin($pdo,$user,$settings,['auth_password'=>$password]);
$alerts=[];appCheck(changeOwnPassword($pdo,1,['current_password'=>$password,'new_password'=>'changed security password','confirm_password'=>'changed security password'],$alerts),'Password change failed');
appCheck(empty($_SESSION['auth_app_setup_verification']) && empty($_SESSION['pending_auth_app_secret']),'Password change retained authenticator setup approval');
$alerts=[];$_POST=['code'=>auth_app_totp_code($setup['secret'])];appCheck(!confirmAuthAppSetup($pdo,1,$alerts),'Old setup survived password change');
$user=appLogin($pdo);$_SESSION['act_as_original_user']=['id'=>2];$_POST=['auth_password'=>'changed security password'];$alerts=[];
appCheck(beginAuthAppSetup($pdo,$user,$settings,$alerts)===[],'Impersonation can modify authenticator');
$user=appLogin($pdo);$setup=appBegin($pdo,$user,['auth_app_login_enabled'=>0,'hero_title'=>'Fixture'],['auth_password'=>'changed security password']);
appCheck((bool)$setup,'Site-disabled login incorrectly prevents secure setup');
echo "Authenticator fresh authentication passed: no-proof rejection, password/existing-code proofs, new-code confirmation, protected replacement/removal, ten-minute expiry, session/account/state binding, rollback, five-attempt throttles, password-change invalidation and impersonation rejection. Temporary tables only; no emails sent.\n";
