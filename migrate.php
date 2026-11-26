<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/views/migration_fields.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (!$pdo || !migrationAvailable($pdo)) { http_response_code(404); exit('Account setup is unavailable.'); }
// Keep bearer tokens out of subsequent URLs and form actions. Merely opening never consumes one.
if (isset($_GET['invite'])) {
    $token=is_string($_GET['invite'])?$_GET['invite']:'';
    $_SESSION['migration_invite']=$token;
    header('Location: /migrate.php',true,303);exit;
}
$uid=(int)($currentUser['id']??0);
$basketSyncError='';
if($uid && empty($_SESSION['act_as_original_user'])){
    try{migrationRestoreMembershipBasket($pdo,$uid);migrationSyncLogbookBasket($pdo,$uid);}catch(RuntimeException $e){$basketSyncError=$e instanceof PDOException?'Your saved logbook selections could not be loaded. Please try again.':$e->getMessage();}
}
$state=$uid && empty($_SESSION['act_as_original_user'])?migrationRefreshStages($pdo,$uid):($uid?migrationState($pdo,$uid):null);
$errors=[];$general=$basketSyncError;
$closureComplete=!empty($_SESSION['migration_account_closed']);unset($_SESSION['migration_account_closed']);
$pageCopy=migrationPageCopy($pdo);
$closureProtected=$currentUser && (($currentUser['role']??'')!=='user' || userIsProtected($pdo,$uid));
$token=(string)($_SESSION['migration_invite']??'');
$invitation=$token!==''?migrationInspectInvitation($pdo,$token):null;
$invitedUser=$invitation?migrationRecipient($pdo,(int)$invitation['user_id']):null;
$canInvitationStart=$invitedUser && $invitedUser['role']==='user' && empty($invitedUser['is_protected']);
$invitationState=$invitation?migrationState($pdo,(int)$invitation['user_id']):null;
$allowInvitationClosure=$invitationState && empty($invitationState['started_at']) && empty($invitationState['completed_at']) && $invitationState['current_step']==='password';
if($invitedUser)$closureProtected=!$canInvitationStart;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        $action=(string)($_POST['action']??'');
        if ((!$currentUser && !in_array($action,['accept','close_account'],true)) || !empty($_SESSION['act_as_original_user'])) throw new RuntimeException('Open your personal invitation or sign in to your own account before continuing.');
        $invitedUid=$invitation?(int)$invitation['user_id']:$uid;
        if ($action==='close_account') {
            migrationDecline($pdo,$invitedUid,$token,!empty($_POST['closure_confirmed']),(int)($_POST['copy_revision']??0));
            $_SESSION['migration_account_closed']=true;
            header('Location: /migrate.php',true,303);exit;
        } elseif ($action==='accept') {
            migrationAcceptInvitation($pdo,$token,$invitedUid);unset($_SESSION['migration_invite']);
        } elseif (in_array($action,['account','password','user'],true)) {
            $errors=migrationSaveAccount($pdo,$uid,$_POST);
            if (!$errors) { header('Location: /account',true,303);exit; }
        }
        elseif ($action==='finish') {
            migrationFinishToAccount($pdo,$uid);
            header('Location: /account',true,303);exit;
        }
        else throw new RuntimeException('Choose an available setup action.');
        if (!$errors) { header('Location: /migrate.php',true,303);exit; }
    } catch (Throwable $e) {
        $general=$e instanceof PDOException?'Your changes could not be saved. Please try again.':$e->getMessage();
    }
    $state=$uid && empty($_SESSION['act_as_original_user'])?migrationRefreshStages($pdo,$uid):($uid?migrationState($pdo,$uid):null);
}
if (!empty($state['completed_at'])) { header('Location: /account',true,303);exit; }
$siteSettings=$siteSettingsBootstrap;$basePath='';
$step=migrationPending($state)?$state['current_step']:null;
if($invitation && $currentUser && (int)$invitation['user_id']!==$uid){$step=null;$state=null;}
$values=[];
if (in_array($step,['password','user'],true)) {
    $s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([$uid]);$values=$s->fetch()?:[];
}
if ($errors) {
    $values=array_merge($values,$_POST);
    $values=array_merge($values,migrationPreferences($_POST));
}

require __DIR__.'/views/migration_setup.php';
