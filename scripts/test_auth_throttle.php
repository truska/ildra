<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/auth.php';
$config=require dirname(__DIR__).'/config.php';
$db=$config['db'];
class ThrottleTestPDO extends PDO {
    public bool $failStorage=false;
    public function __construct(array $db, public string $fixtureTable) {
        parent::__construct('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    private function fixtureSql(string $sql): string {
        if ($this->failStorage && preg_match('/\bauth_throttle\b/',$sql)) throw new PDOException('Synthetic storage failure');
        return preg_replace('/\bauth_throttle\b/',$this->fixtureTable,$sql);
    }
    public function exec(string $statement): int|false { return parent::exec($this->fixtureSql($statement)); }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare($this->fixtureSql($query),$options); }
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false { return $fetchMode===null ? parent::query($this->fixtureSql($query)) : parent::query($this->fixtureSql($query),$fetchMode,...$args); }
}
function throttleCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
if (($argv[1]??'')==='--worker') {
    $table=$argv[2]??'';
    if (!preg_match('/^auth_throttle_test_[a-f0-9]{12}$/D',$table)) exit(1);
    $pdo=new ThrottleTestPDO($db,$table);
    $result=authThrottleRun($pdo,'login','parallel@example.invalid','192.0.2.55',static function(){usleep(100000);return null;});
    echo json_encode($result);exit;
}
ini_set('session.save_path','/tmp');session_start();
$table='auth_throttle_test_'.bin2hex(random_bytes(6));
$pdo=new ThrottleTestPDO($db,$table);$pdo2=new ThrottleTestPDO($db,$table);
try {
    ensureAuthThrottleTable($pdo);
    $schema=file_get_contents(dirname(__DIR__).'/migrations/2026-11-12-auth-throttle.sql');
    $helper=file_get_contents(dirname(__DIR__).'/auth_throttle.php');
    throttleCheck(str_contains($helper,trim($schema)),'Migration and application schema differ');
    $now=time();
    $failed=static fn()=>null;
    for($i=1;$i<=5;$i++) {
        session_regenerate_id(true);
        $_SESSION=['browser'=>$i%2?'one':'two'];
        $result=authThrottleRun($i%2?$pdo:$pdo2,'login',$i%2?' Person@Example.Invalid ':'person@example.invalid','192.0.2.1',$failed,$now);
        throttleCheck($result['allowed'] && ($result['retry_after']===($i===5?900:0)),'Fifth failed-attempt boundary incorrect');
    }
    $ran=false;
    $blocked=authThrottleRun($pdo2,'login','person@example.invalid','192.0.2.2',function()use(&$ran){$ran=true;return ['id'=>1];},$now+1);
    throttleCheck(!$blocked['allowed'] && !$ran,'Email limit bypassed with a different connection/browser/IP');
    $blocked=authThrottleRun($pdo2,'login','another@example.invalid','192.0.2.1',$failed,$now+1);
    throttleCheck(!$blocked['allowed'],'IP limit bypassed with another email');
    throttleCheck(!authThrottleRun($pdo,'login','person@example.invalid','192.0.2.2',$failed,$now+899)['allowed'],'Cooldown expired early');
    throttleCheck(authThrottleRun($pdo2,'login','person@example.invalid','192.0.2.2',static fn()=>['id'=>1],$now+900)['value']['id']===1,'Exact cooldown expiry did not recover');
    // Blocked retries do not prolong the fixed cooldown.
    $pdo->exec('DELETE FROM auth_throttle');
    for($i=0;$i<4;$i++) authThrottleRun($pdo,'login','success@example.invalid','192.0.2.3',$failed,$now);
    $ok=authThrottleRun($pdo2,'login','success@example.invalid','192.0.2.3',static fn()=>['id'=>1],$now+1);
    throttleCheck($ok['value']['id']===1 && $ok['retry_after']===0,'Valid fifth attempt blocked');
    $counts=$pdo->query("SELECT subject_kind,attempts FROM auth_throttle WHERE scope='login'")->fetchAll(PDO::FETCH_KEY_PAIR);
    throttleCheck((int)$counts['email']===0 && (int)$counts['ip']===4,'Success did not reset email, or erased IP failures');
    // Independent mail budgets; reserve before lookup even when the address does not exist.
    $pdo->exec('DELETE FROM auth_throttle');
    foreach(['password_reset','magic_link'] as $scope) {
        throttleCheck(authThrottleRun($pdo,$scope,'mail@example.invalid','192.0.2.4',static fn()=>true,$now)['allowed'],'First email request blocked');
        throttleCheck(!authThrottleRun($pdo2,$scope,'MAIL@example.invalid','192.0.2.5',static fn()=>true,$now+1)['allowed'],'Repeated email escaped recipient limit');
        throttleCheck(authThrottleRun($pdo2,$scope,'mail@example.invalid','192.0.2.5',static fn()=>true,$now+60)['allowed'],'Recipient cooldown did not expire');
    }
    foreach(['password_reset','magic_link'] as $scope) {
        for($i=0;$i<5;$i++) throttleCheck(authThrottleRun($pdo,$scope,'mail'.$i.'@example.invalid','192.0.2.7',static fn()=>true,$now)['allowed'],'Allowed email IP request blocked');
        throttleCheck(!authThrottleRun($pdo2,$scope,'mail6@example.invalid','192.0.2.7',static fn()=>true,$now+1)['allowed'],'Sixth email IP request allowed');
        throttleCheck(authThrottleRun($pdo,$scope,'mail7@example.invalid','192.0.2.7',static fn()=>true,$now+900)['allowed'],'Email IP cooldown did not expire');
    }
    // Real handlers, shadowing account data with connection-local temporary tables.
    $hash=password_hash('correct test password',PASSWORD_BCRYPT,['cost'=>12]);
    $secret='JBSWY3DPEHPK3PXP';
    foreach([$pdo,$pdo2] as $connection) {
        $connection->exec("CREATE TEMPORARY TABLE roles(id INT PRIMARY KEY,name VARCHAR(30),level INT)");
        $connection->exec("INSERT INTO roles VALUES(1,'user',1)");
        $connection->exec("CREATE TEMPORARY TABLE users(id INT PRIMARY KEY,email VARCHAR(255),password_hash VARCHAR(255),role_id INT,first_name VARCHAR(80),last_name VARCHAR(80),is_archived TINYINT DEFAULT 0,auth_app_secret VARCHAR(64),auth_app_enabled TINYINT DEFAULT 1,auth_app_confirmed_at DATETIME NULL,last_login_at DATETIME NULL,updated_at DATETIME NULL)");
        $connection->prepare('INSERT INTO users(id,email,password_hash,role_id,first_name,last_name,auth_app_secret) VALUES(1,?,?,1,?,?,?)')->execute(['known@example.invalid',$hash,'Test','User',$secret]);
    }
    $settings=['auth_app_login_enabled'=>1,'remember_me_ttl_seconds'=>0];
    $pdo->exec('DELETE FROM auth_throttle');
    $_SERVER['REMOTE_ADDR']='192.0.2.20';$_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.99';
    throttleCheck(authThrottleIp()==='192.0.2.20','Untrusted forwarding header accepted');
    $_POST=['email'=>'known@example.invalid','password'=>'wrong'];$alerts=[];$success=null;
    throttleCheck(handleLogin($pdo,$settings,$alerts,$success)===null,'Wrong password signed in');
    $knownMessage=$alerts[0]['message'];
    $_POST=['email'=>'unknown@example.invalid','password'=>'wrong'];$alerts=[];
    throttleCheck(handleLogin($pdo,$settings,$alerts,$success)===null && $alerts[0]['message']===$knownMessage,'Password error exposes account existence');
    $pdo->exec('DELETE FROM auth_throttle');
    $badCode='000000';while(auth_app_verify_code($secret,$badCode))$badCode=str_pad((string)((int)$badCode+1),6,'0',STR_PAD_LEFT);
    for($i=1;$i<=5;$i++) {
        session_regenerate_id(true);$_SESSION=['browser'=>$i%2?'one':'two'];$alerts=[];$success=null;
        $_POST=['email'=>'known@example.invalid','password'=>'wrong','code'=>$badCode];
        $user=$i%2 ? handleLogin($pdo,$settings,$alerts,$success) : handleAuthAppLogin($pdo2,$settings,$alerts,$success);
        throttleCheck($user===null,'Invalid mixed-method login succeeded');
        throttleCheck(($i===5)===str_contains($alerts[0]['message'],'wait 15'),'Mixed login methods did not share fifth-attempt cooldown');
    }
    $_POST=['email'=>'known@example.invalid','password'=>'correct test password'];$alerts=[];$success=null;
    throttleCheck(handleLogin($pdo2,$settings,$alerts,$success)===null && empty($_SESSION['user']),'Correct password bypassed cooldown');
    $_POST=['email'=>'known@example.invalid','code'=>auth_app_totp_code($secret)];$alerts=[];
    throttleCheck(handleAuthAppLogin($pdo2,$settings,$alerts,$success)===null,'Valid authenticator bypassed password cooldown');
    $pdo->exec('UPDATE auth_throttle SET blocked_until=1,expires_at=1');
    $_POST=['email'=>'known@example.invalid','password'=>'correct test password'];$alerts=[];
    throttleCheck(handleLogin($pdo2,$settings,$alerts,$success)['id']===1 && !$alerts,'Password login did not recover after cooldown');
    unset($_SESSION['user']);$_POST=['email'=>'known@example.invalid','code'=>auth_app_totp_code($secret)];$alerts=[];
    throttleCheck(handleAuthAppLogin($pdo,$settings,$alerts,$success)['id']===1 && !$alerts,'Successful authenticator login failed');
    $pdo->exec('DELETE FROM auth_throttle');
    for($i=1;$i<=5;$i++) {
        session_regenerate_id(true);$_SESSION=[];$_POST=['email'=>'known@example.invalid','code'=>$badCode];$alerts=[];$success=null;
        throttleCheck(handleAuthAppLogin($i%2?$pdo:$pdo2,$settings,$alerts,$success)===null,'Invalid authenticator signed in');
        throttleCheck(($i===5)===str_contains($alerts[0]['message'],'wait 15'),'Authenticator-only fifth-failure boundary incorrect');
    }
    // Uniform method choices and email-request feedback. These requests send NO emails.
    $known=loginMethodState($pdo,'known@example.invalid',$settings);$unknown=loginMethodState($pdo,'unknown@example.invalid',$settings);unset($known['email'],$unknown['email']);
    throttleCheck($known===$unknown,'Method choices reveal account existence');
    foreach(['password_reset','magic_link'] as $scope) {
        $pdo->exec('DELETE FROM auth_throttle');
        $_POST=['email'=>'unknown@example.invalid'];$alerts=[];$success=null;
        if($scope==='password_reset')handlePasswordResetRequest($pdo,$alerts,$success);else handleMagicLinkRequest($pdo,$settings,$alerts,$success);
        throttleCheck(!$alerts && str_starts_with($success,'If that email is registered'),'Unknown email response differs');
        $responses=[];
        foreach(['unknown@example.invalid','known@example.invalid'] as $address) {
            // Pre-reserve known request, so its handler cannot send a real message/token.
            if($address==='known@example.invalid')authThrottleRun($pdo,$scope,$address,authThrottleIp(),static fn()=>true);
            $_SESSION=[];$_POST=['email'=>$address];$alerts=[];$success=null;
            if($scope==='password_reset')handlePasswordResetRequest($pdo2,$alerts,$success);else handleMagicLinkRequest($pdo2,$settings,$alerts,$success);
            throttleCheck($success===null && count($alerts)===1,'Repeated email request not stopped before sending');
            $responses[]=$alerts[0]['message'];
        }
        throttleCheck($responses[0]===$responses[1],'Request cooldown reveals account existence');
    }
    unset($_SESSION['user']);$pdo->failStorage=true;
    $_POST=['email'=>'known@example.invalid','password'=>'correct test password'];$alerts=[];$success=null;
    throttleCheck(handleLogin($pdo,$settings,$alerts,$success)===null && empty($_SESSION['user']),'Storage failure bypassed login throttling');
    $alerts=[];throttleCheck(!authThrottleEmailRequest($pdo,'magic_link','known@example.invalid',$alerts),'Storage failure bypassed email limit');
    $pdo->failStorage=false;
    // Real parallel processes/connections must never all verify before recording failures.
    $pdo->exec('DELETE FROM auth_throttle');$workers=[];
    for($i=0;$i<10;$i++) {
        $process=proc_open([PHP_BINARY,__FILE__,'--worker',$table],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$workers[]=[$process,$pipes];
    }
    $allowed=0;
    foreach($workers as [$process,$pipes]) {
        $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        throttleCheck(proc_close($process)===0,'Concurrent limiter worker failed: '.$error);
        $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);$allowed+=(int)$result['allowed'];
    }
    throttleCheck($allowed===5,'Concurrent fifth-attempt boundary bypassed: '.$allowed.' verifications');
    echo "Authentication throttling passed: five-failure/fifth-success boundaries, fixed cooldown/expiry, email+IP limits, independent connections/sessions, password/TOTP handlers, separate email budgets, generic responses, fail-closed storage and ten parallel workers. No emails sent.\n";
} finally {
    $pdo->failStorage=false;
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP TABLE IF EXISTS auth_throttle');
}
