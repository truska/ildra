<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/testing_reset_access.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
function prelaunchCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$config=['enabled'=>true,'web_root'=>'/fixture/staging/web','database'=>'fixture_staging','host'=>'ildra.witecanvas.com'];
prelaunchCheck(prelaunchTestingResetEnvironmentAllowed($config,$config['web_root'],$config['database'],$config['host']),'Exact destination rejected');
foreach (['','ildra.witecanvas.com:443','ildra.witecanvas.com:28086','ildra.witecanvas.com.','ildra.witecanvas.com.evil.invalid','evil@ildra.witecanvas.com','https://ildra.witecanvas.com',' ildra.witecanvas.com','ildra.witecanvas.com/','enduranceridingireland.com','dev-ildra.witecanvas.com'] as $host) {
    prelaunchCheck(!prelaunchTestingResetEnvironmentAllowed($config,$config['web_root'],$config['database'],$host),'Unsafe host accepted: '.$host);
}
foreach ([[],array_replace($config,['enabled'=>false]),array_replace($config,['enabled'=>'true']),array_replace($config,['web_root'=>'/different/web']),array_replace($config,['database'=>'other']),array_replace($config,['host'=>'enduranceridingireland.com'])] as $bad) {
    prelaunchCheck(!prelaunchTestingResetEnvironmentAllowed($bad,$config['web_root'],$config['database'],$config['host']),'Bad/missing opt-in accepted');
}
prelaunchCheck(!prelaunchTestingResetEnvironmentAllowed($config,'',$config['database'],$config['host']),'Empty root accepted');
prelaunchCheck(!prelaunchTestingResetEnvironmentAllowed($config,$config['web_root'],'',$config['host']),'Empty database accepted');
prelaunchCheck(!importWorkspaceEnvironmentAllowed($config,$config['web_root'],$config['database'],$config['host']),'New opt-in enabled import workspace');
$dev=['enabled'=>true,'web_root'=>'/fixture/dev/web','database'=>'fixture_dev','host'=>'dev-ildra.witecanvas.com'];
prelaunchCheck(importWorkspaceEnvironmentAllowed($dev,$dev['web_root'],$dev['database'],'dev-ildra.witecanvas.com:28086'),'Existing dev proxy access changed');
prelaunchCheck(!importWorkspaceEnvironmentAllowed($dev,$dev['web_root'],$dev['database'],'ildra.witecanvas.com'),'Dev guard broadened to staging');

/** Execute actual controller/view code with offline gate/DB doubles. Never invokes a real reset. */
function prelaunchPageFixture(string $page, array $case): array
{
    $root=dirname(__DIR__,2);
    $source=file_get_contents($root.'/admin/'.$page);
    $source=str_replace("require __DIR__ . '/_bootstrap.php';",'', $source);
    $source=str_replace('__DIR__',var_export($root.'/admin',true),$source);
    $source='namespace PrelaunchFixture; use \RuntimeException; use \Throwable; '.str_replace('declare(strict_types=1);','',substr($source,5));
    $fixture=<<<'FIXTURE'
<?php
namespace PrelaunchFixture;
require ROOT_HELPERS;
class FixturePDO extends \PDO { public function __construct() {} }
$case=CASE_DATA;
$pdo=new FixturePDO();$currentRole=$case['role']??'superadmin';$currentUser=['id'=>42,'role'=>$currentRole];
$alerts=[];$successMessage='';$calls=0;$runs=0;
$_SERVER['HTTP_HOST']='ildra.witecanvas.com';$_SERVER['REQUEST_METHOD']=$case['method']??'GET';
$_POST=$case['post']??[];$_SESSION=$case['session']??[];
function testingResetEnvironmentEnabled($pdo,$host):bool { global $case,$calls; $calls++;return ($case['enabled']??true) && !(($case['revoke']??false) && $calls>1); }
function testingResetEnvironmentFingerprint($pdo,$host):string { return 'fixture-environment'; }
function testingResetPlan($pdo,$calendar,$choices):array { return ['delete'=>[],'keep_people'=>[],'keep_calendar'=>$calendar]; }
function testingResetCounts($pdo,$plan):array { return ['people'=>0]; }
function testingResetRun($pdo,$plan,$actor,$choices,$counts):string { global $runs;$runs++;return 'offline-double-only.jsonl'; }
function testingResetIdentities($pdo):array { return []; }
function admin_layout_start($title,$active):void {}
function admin_layout_end():void {}
ob_start();
register_shutdown_function(function () { global $alerts,$runs; $html=ob_get_clean();echo json_encode(['status'=>http_response_code()?:200,'alerts'=>$alerts,'session'=>$_SESSION,'runs'=>$runs,'has_card'=>str_contains($html,'Open Test Data Reset')]); });
eval(PAGE_SOURCE);
FIXTURE;
    $fixture=str_replace(['ROOT_HELPERS','CASE_DATA','PAGE_SOURCE'],[var_export($root.'/helpers.php',true),var_export($case,true),var_export($source,true)],$fixture);
    $path=tempnam('/tmp','prelaunch-reset-fixture-');
    try {
        file_put_contents($path,$fixture);
        $output=shell_exec('php '.escapeshellarg($path));
        $result=json_decode((string)$output,true);
        if (!is_array($result)) throw new RuntimeException('Invalid fixture output');
        return $result;
    } finally { unlink($path); }
}
foreach (['GET','POST'] as $method) {
    $result=prelaunchPageFixture('testing_reset.php',['enabled'=>false,'method'=>$method,'post'=>['action'=>'reset']]);
    prelaunchCheck($result['status']===404 && !$result['runs'],'Disabled endpoint did not reject '.$method);
}
foreach (['admin','manager','organiser','user'] as $role) {
    $result=prelaunchPageFixture('testing_reset.php',['role'=>$role]);
    prelaunchCheck($result['status']===403 && !$result['runs'],'Wrong role allowed: '.$role);
}
foreach (['superadmin','developer'] as $role) {
    prelaunchCheck(prelaunchPageFixture('testing_reset.php',['role'=>$role])['status']===200,'Privileged role rejected');
}
prelaunchCheck(!prelaunchPageFixture('tech.php',['enabled'=>false])['has_card'],'Disabled Tech card visible');
prelaunchCheck(prelaunchPageFixture('tech.php',['enabled'=>true])['has_card'],'Enabled Tech card missing');
$previewResult=prelaunchPageFixture('testing_reset.php',['method'=>'POST','post'=>['action'=>'preview','keep_calendar'=>1]]);
$preview=$previewResult['session']['testing_reset_preview'];
prelaunchCheck($preview['actor_id']===42 && $preview['environment']==='fixture-environment','Preview not bound to actor/environment');
$post=['action'=>'reset','preview_token'=>$preview['token'],'confirmation'=>'RESET TEST DATA','quiet'=>1];
$case=['method'=>'POST','post'=>$post,'session'=>['testing_reset_preview'=>$preview]];
foreach (['confirmation'=>'RESET','quiet'=>0,'preview_token'=>'wrong'] as $key=>$value) {
    $bad=$case;$bad['post'][$key]=$value;$result=prelaunchPageFixture('testing_reset.php',$bad);
    prelaunchCheck(!$result['runs'] && count($result['alerts'])===1,'Invalid confirmation/quiet/token accepted');
}
foreach (['time'=>time()-901,'actor_id'=>999,'environment'=>'other','counts'=>['people'=>99],'plan'=>[]] as $key=>$value) {
    $bad=$case;$bad['session']['testing_reset_preview'][$key]=$value;$result=prelaunchPageFixture('testing_reset.php',$bad);
    prelaunchCheck(!$result['runs'] && count($result['alerts'])===1,'Stale/mismatched preview accepted: '.$key);
}
$result=prelaunchPageFixture('testing_reset.php',array_replace($case,['revoke'=>true]));
prelaunchCheck(!$result['runs'] && count($result['alerts'])===1,'Opt-in revocation before runner ignored');
$result=prelaunchPageFixture('testing_reset.php',$case);
prelaunchCheck($result['runs']===1 && !$result['alerts'],'Valid preview did not reach offline runner double');
// Check private loader and retirement marker without touching the actual private directory.
$directory=sys_get_temp_dir().'/prelaunch-config-'.bin2hex(random_bytes(6));mkdir($directory,0700);
$source=file_get_contents(dirname(__DIR__,2).'/testing_reset_access.php');
$start=strpos($source,'function prelaunchTestingResetConfig');$end=strpos($source,'/** Exact physical', $start);
$loader=substr($source,$start,$end-$start);
$loader=str_replace("__DIR__ . '/../private'",var_export($directory,true),$loader);
eval('namespace PrelaunchLoaderFixture; '.$loader);
try {
    prelaunchCheck(\PrelaunchLoaderFixture\prelaunchTestingResetConfig()===[],'Missing config allowed');
    file_put_contents($directory.'/prelaunch-testing-reset.php',"<?php return ".var_export($config,true).';');
    prelaunchCheck(\PrelaunchLoaderFixture\prelaunchTestingResetConfig()===$config,'Private opt-in not loaded');
    touch($directory.'/prelaunch-testing-reset.disabled');
    prelaunchCheck(\PrelaunchLoaderFixture\prelaunchTestingResetConfig()===[],'Retirement marker ignored');
} finally {
    unlink($directory.'/prelaunch-testing-reset.php');unlink($directory.'/prelaunch-testing-reset.disabled');rmdir($directory);
}
echo "PASS: exact destination/host pins, default denial, unchanged dev guard, roles, Tech visibility, actor/environment-bound previews, stale-data/token/confirmation checks and private retirement. Offline doubles only; no database connection or reset.\n";
