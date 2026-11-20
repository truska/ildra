<?php
declare(strict_types=1);
require_once __DIR__.'/import_workspace.php';

function migrationRolloutConfig():array
{
    $path=__DIR__.'/../private/account-migration.php';
    return is_file($path)?(array)require $path:[];
}

/** Pin rollout to an explicit installed root, database and approved HTTPS origin. */
function migrationProductionEnvironmentAllowed(array $config,string $root,string $database,string $host):bool
{
    $origins=['ildra.witecanvas.com'=>'https://ildra.witecanvas.com','enduranceridingireland.com'=>'https://enduranceridingireland.com'];
    return ($config['enabled']??false)===true && isset($origins[$host])
        && ($config['host']??'')===$host && ($config['web_root']??'')===$root
        && ($config['database']??'')===$database && ($config['public_origin']??'')===$origins[$host]
        && in_array($config['delivery_mode']??'',['redirect','direct'],true);
}

function migrationEnvironment(PDO $pdo):array
{
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));$root=(string)realpath(__DIR__);$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if(importWorkspaceEnvironmentAllowed(importWorkspaceConfig(),$root,$database,$host))return ['mode'=>'development','origin'=>authEmailOrigins()['dev-ildra.witecanvas.com'],'delivery_mode'=>'redirect'];
    $config=migrationRolloutConfig();
    if(!migrationProductionEnvironmentAllowed($config,$root,$database,$host))throw new RuntimeException('Account migration is disabled in this environment.');
    return ['mode'=>'production','origin'=>$config['public_origin'],'delivery_mode'=>$config['delivery_mode']];
}

function migrationAuthorizeAdmin(PDO $pdo,array $actor):array
{
    $environment=migrationEnvironment($pdo);
    if($environment['mode']==='development'){
        importWorkspaceAuthorize($pdo,$actor,importWorkspaceConfig(),(string)($_SERVER['HTTP_HOST']??''));
    }else{
        migrationRequireProductionOperator($pdo,$actor);
    }
    return $environment;
}

function migrationRequireProductionOperator(PDO $pdo,array $actor):void
{
    $s=$pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_archived=0');$s->execute([(int)($actor['id']??0)]);$role=$s->fetchColumn();
    if(!in_array($role,['developer','superadmin'],true) || $role!==($actor['role']??null) || !empty($_SESSION['act_as_original_user']))throw new RuntimeException('Developer or Superadmin access required for account migration.');
}

/** An empty return value means explicitly approved direct delivery. */
function migrationDeliveryRedirect(array $environment,array $settings):string
{
    if($environment['delivery_mode']==='direct'){
        if(($settings['email_test_redirect_enabled']??'0')==='1')throw new RuntimeException('Direct invitation delivery is configured, but the email test redirect is still enabled. Review delivery settings before sending.');
        return '';
    }
    $to=trim((string)($settings['email_test_redirect_to']??''));
    if(($settings['email_test_redirect_enabled']??'0')!=='1' || !filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enable Redirect all outgoing emails to a valid test address before sending migration invitations.');
    return $to;
}

/** CLI imports are a separate opt-in: they never enable HTTP invitations or resets. */
function migrationAuthorizeImport(PDO $pdo):array
{
    if(PHP_SAPI!=='cli')throw new RuntimeException('Imports require PHP CLI.');
    $workspace=importWorkspaceConfig();$root=(string)realpath(__DIR__);$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if(importWorkspaceEnvironmentAllowed($workspace,$root,$database,'dev-ildra.witecanvas.com')){
        importWorkspaceAuthorize($pdo,['id'=>$workspace['protected_user_id']??0,'role'=>'developer'],$workspace,'dev-ildra.witecanvas.com');return $workspace;
    }
    $config=migrationRolloutConfig();
    if(($config['allow_imports']??false)!==true || !migrationProductionEnvironmentAllowed(array_replace($config,['enabled'=>true]),$root,$database,(string)($config['host']??'')))throw new RuntimeException('Imports are disabled or the destination configuration does not match.');
    $id=(int)($config['operator_user_id']??0);
    $s=$pdo->prepare('SELECT u.email,r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_archived=0');$s->execute([$id]);$operator=$s->fetch();
    if(!$operator || !in_array($operator['name'],['developer','superadmin'],true))throw new RuntimeException('Configure an active Developer or Superadmin import operator.');
    return $config+['protected_user_id'=>$id,'protected_email'=>$operator['email']];
}
