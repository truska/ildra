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
$errors=[];$general=$basketSyncError;$completedNow=false;
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
        } elseif ($action==='back') migrationBack($pdo,$uid);
        elseif (in_array($action,['account','password','user'],true)) $errors=migrationSaveAccount($pdo,$uid,$_POST);
        elseif ($action==='person') {
            $errors=migrationSaveDetails($pdo,$uid,$action,$_POST);
            if(!$errors && $action==='person' && !empty($_POST['add_membership']))$_SESSION['flash_success']='Membership added to basket. Continue with Next when ready.';
        }
        elseif ($action==='horse_rename') {
            $errors=migrationRenameHorse($pdo,$uid,(int)($_POST['horse_id']??0),$_POST);
            if(!$errors){$_SESSION['flash_success']='Horse name updated. Continue with Next when ready.';header('Location: /migrate.php',true,303);exit;}
        }
        elseif ($action==='horse_choice') {
            $hid=(int)($_POST['horse_id']??0);$choice=(string)($_POST['horse_choice']??'');
            $input=$choice==='logbook' && is_array($_POST['horses'][$hid]??null)?$_POST['horses'][$hid]:$_POST;
            $input['logbook_quote']=(string)($_POST['logbook_quote']??'');
            $errors=migrationChooseHorse($pdo,$uid,$hid,$choice,$input);
            if(!$errors){
                $currentOffer=$choice==='logbook'?migrationLogbookOffer($pdo):null;
                $alreadyHas=$currentOffer && migrationHasLogbook($pdo,$hid,(int)$currentOffer['valid_year']);
                $_SESSION['flash_success']=match($choice){'logbook'=>$alreadyHas?'Horse details confirmed. The '.$currentOffer['valid_year'].' logbook is already on your account.':'Logbook added to basket. Choose an option for the next horse, or select Next.','keep'=>'No logbook selected for this horse. Continue with Next when ready.','archive'=>'Horse archived and an internal note recorded.'};
                header('Location: /migrate.php',true,303);exit;
            }
        }
        elseif ($action==='horses') $errors=migrationSaveHorses($pdo,$uid,$_POST);
        elseif ($action==='shares') migrationSaveShares($pdo,$uid);
        elseif ($action==='finish') { migrationFinish($pdo,$uid); $_SESSION['migration_completed_modal']=true; }
        else throw new RuntimeException('Choose an available setup action.');
        if (!$errors) { header('Location: /migrate.php',true,303);exit; }
    } catch (Throwable $e) {
        $general=$e instanceof PDOException?'Your changes could not be saved. Please try again.':$e->getMessage();
    }
    $state=$uid && empty($_SESSION['act_as_original_user'])?migrationRefreshStages($pdo,$uid):($uid?migrationState($pdo,$uid):null);
}
$siteSettings=$siteSettingsBootstrap;$basePath='';
$step=migrationPending($state)?$state['current_step']:null;
if($invitation && $currentUser && (int)$invitation['user_id']!==$uid){$step=null;$state=null;}
$values=[];
if (in_array($step,['password','user'],true)) {
    $s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([$uid]);$values=$s->fetch()?:[];
} elseif ($step==='person') {
    $s=$pdo->prepare('SELECT * FROM people WHERE id=? AND owner_user_id=? AND is_archived=0');$s->execute([(int)$state['person_id'],$uid]);$values=$s->fetch()?:[];
}
// Default the first preference review to On; preserve a previously saved choice.
if ($step==='person' && empty($state['person_preferences_json'])) $values['ride_notice_opt_in']=1;
if ($errors) {
    $values=array_merge($values,$_POST);
    $values=array_merge($values,migrationPreferences($_POST));
}
$horseRows=[];$horseQualifications=[];$horseAction='';$selectedHorse=null;$horseChoiceStates=[];$logbookOffer=null;$horseAlreadyHasLogbook=false;
if($step==='horses'){
    $horseRows=migrationHorses($pdo,$uid);$horseQualifications=migrationHorseQualifications($pdo);$logbookOffer=migrationLogbookOffer($pdo);$choices=migrationHorseChoices($pdo,$uid);
    foreach($horseRows as $horse){
        $choice=$choices[(int)$horse['id']]??null;$valid=migrationHorseChoiceValid($pdo,$horse,$choice,$logbookOffer);
        $label=$valid?($choice['choice']==='keep'?'Not purchasing a logbook at this time':(migrationHasLogbook($pdo,(int)$horse['id'],(int)$choice['logbook_year'])?'Already has a logbook for '.$choice['logbook_year']:'Logbook added to basket for '.$choice['logbook_year'])):($choice?'Choice needs review':'Please choose an option');
        $horseChoiceStates[(int)$horse['id']]=['valid'=>$valid,'label'=>$label];
    }
    $horseAction=(string)(($_SERVER['REQUEST_METHOD']??'GET')==='POST'?($_POST['horse_choice']??''):($_GET['horse_action']??''));
    $selectedId=(int)(($_SERVER['REQUEST_METHOD']??'GET')==='POST'?($_POST['horse_id']??0):($_GET['horse_id']??0));
    if(in_array($horseAction,['logbook','keep','archive'],true))foreach($horseRows as $horse)if((int)$horse['id']===$selectedId)$selectedHorse=$horse;
    if($selectedHorse && $logbookOffer)$horseAlreadyHasLogbook=migrationHasLogbook($pdo,(int)$selectedHorse['id'],(int)$logbookOffer['valid_year']);
    if(!$selectedHorse){if($horseAction!==''&&$general==='')$general='Choose a horse from your list.';$horseAction='';}
    elseif($errors || $general!==''){
        $posted=$horseAction==='logbook'?($_POST['horses'][$selectedId]??[]):$_POST;
        if(is_array($posted))foreach(migrationHorseFields() as $field=>$label)if(isset($posted[$field])&&is_scalar($posted[$field]))$selectedHorse[$field]=(string)$posted[$field];
    }
}
$setupSteps=$uid?migrationVisibleSteps($pdo,$uid):[];
$shareCount=$step==='shares'?migrationShareCount($pdo,$uid):0;
$membershipOffers=$step==='person'?migrationMembershipOffers($pdo):[];
$membershipYear=membership_purchase_year(migrationCommerceSettings($pdo));
$completedNow=!empty($_SESSION['migration_completed_modal']);unset($_SESSION['migration_completed_modal']);
require __DIR__.'/views/migration_setup.php';
