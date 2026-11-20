<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/auth.php';
require dirname(__DIR__,2).'/migration_rollout.php';
function rolloutCheck(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
function rolloutReject(callable $fn,string $why):void { try{$fn();}catch(RuntimeException $e){return;}throw new LogicException($why); }
$config=['enabled'=>true,'web_root'=>'/fixture/web','database'=>'fixture_db','host'=>'enduranceridingireland.com','public_origin'=>'https://enduranceridingireland.com','delivery_mode'=>'direct'];
rolloutCheck(migrationProductionEnvironmentAllowed($config,'/fixture/web','fixture_db','enduranceridingireland.com'),'Final live origin rejected');
foreach(['enabled'=>false,'web_root'=>'/another/web','database'=>'another_db','host'=>'evil.test','public_origin'=>'http://enduranceridingireland.com','delivery_mode'=>'unknown'] as $key=>$value)rolloutCheck(!migrationProductionEnvironmentAllowed(array_replace($config,[$key=>$value]),'/fixture/web','fixture_db','enduranceridingireland.com'),'Invalid config accepted: '.$key);
foreach(['dev-ildra.witecanvas.com','enduranceridingireland.com.evil.test','enduranceridingireland.com:443','www.enduranceridingireland.com'] as $host)rolloutCheck(!migrationProductionEnvironmentAllowed($config,'/fixture/web','fixture_db',$host),'Wrong authority accepted');
$stage=array_replace($config,['host'=>'ildra.witecanvas.com','public_origin'=>'https://ildra.witecanvas.com']);rolloutCheck(migrationProductionEnvironmentAllowed($stage,'/fixture/web','fixture_db','ildra.witecanvas.com'),'Population/test origin rejected');
$direct=['delivery_mode'=>'direct'];$redirect=['delivery_mode'=>'redirect'];$off=['email_test_redirect_enabled'=>'0'];$on=['email_test_redirect_enabled'=>'1','email_test_redirect_to'=>'fixture@example.test'];
rolloutCheck(migrationDeliveryRedirect($direct,$off)==='','Direct mode did not select intended recipients');
rolloutCheck(migrationDeliveryRedirect($redirect,$on)==='fixture@example.test','Rehearsal redirect lost');
rolloutReject(fn()=>migrationDeliveryRedirect($direct,$on),'Direct mode silently redirected');
rolloutReject(fn()=>migrationDeliveryRedirect($redirect,$off),'Rehearsal bypassed redirect');
class RolloutPDO extends PDO { public string|false $role='superadmin';public function __construct(){}public function prepare(string $query,array $options=[]):PDOStatement|false{return new RolloutStatement($this);} }
class RolloutStatement extends PDOStatement { public function __construct(private RolloutPDO $fixture){}public function execute(?array $params=null):bool{return true;}public function fetchColumn(int $column=0):mixed{return $this->fixture->role;} }
$pdo=new RolloutPDO();$_SESSION=[];migrationRequireProductionOperator($pdo,['id'=>10,'role'=>'superadmin']);
$pdo->role='user';rolloutReject(fn()=>migrationRequireProductionOperator($pdo,['id'=>10,'role'=>'superadmin']),'Forged session role accepted');
$pdo->role=false;rolloutReject(fn()=>migrationRequireProductionOperator($pdo,['id'=>10,'role'=>'superadmin']),'Missing/archived operator accepted');
$pdo->role='developer';$_SESSION['act_as_original_user']=['id'=>1];rolloutReject(fn()=>migrationRequireProductionOperator($pdo,['id'=>10,'role'=>'developer']),'Impersonation accepted');
// Exercise the actual sender's pre-transport settings guard; no email implementation runs.
$source=file_get_contents(dirname(__DIR__,2).'/email.php');$start=strpos($source,"    if (array_key_exists('required_migration_redirect',\$meta))");$end=strpos($source,'    $deliverySystem =',$start);$guard=substr($source,$start,$end-$start);
foreach([['', $off,true],['',$on,false],['fixture@example.test',$on,true],['other@example.test',$on,false],['fixture@example.test',$off,false]] as [$required,$settings,$expected]) { $meta=['required_migration_redirect'=>$required];rolloutCheck(eval($guard.'return true;')===$expected,'Changed delivery mode/address not blocked'); }
echo "PASS: explicit destination opt-in, approved origins, root/database binding, direct/redirect policy, operator roles, impersonation denial and send-time delivery pinning. Offline fixtures only.\n";
