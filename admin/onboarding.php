<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../onboarding_copy.php';
require_once __DIR__ . '/../onboarding_reset.php';
if (!in_array($currentRole, ['developer', 'superadmin', 'admin', 'manager'], true)) {
    http_response_code(403);exit('Access denied.');
}
$definitions=onboardingCopyDefinitions();
$definitions['password'][0]='Password & user settings';
$tabs=array_diff_key($definitions,['user'=>true,'welcome'=>true]);
$tabs['settings']=['Settings',''];
$tabs=['welcome'=>$definitions['welcome']]+$tabs;
$tab=is_string($_POST['tab']??$_GET['tab']??null)?($_POST['tab']??$_GET['tab']):'password';
if($tab==='user')$tab='password';
if(!isset($tabs[$tab]))$tab='password';
$settings=getSiteSettings($pdo);
$flowOptions=onboardingFlowSettings($pdo);
$flowLabels=['onboarding_horses_enabled'=>'Enable Horses page','onboarding_shares_enabled'=>'Enable Shares page','onboarding_show_blank_pages'=>'Show Blank Pages'];
$flowErrors=[];
$body=(string)($settings['onboarding_copy_'.$tab]??$tabs[$tab][1]);
$userBody=(string)($settings['onboarding_copy_user']??$definitions['user'][1]);
$setupHeading=(string)($settings['onboarding_heading']??'Complete your account setup');
if($tab==='welcome' && !array_key_exists('onboarding_copy_welcome',$settings) && $pdo && migrationAvailable($pdo)) {
    $welcomeCopy=migrationPageCopy($pdo);$body=nl2br(h((string)($welcomeCopy['introduction']??'')));
}
$resetErrors=[];$resetFormValues=['confirmation'=>'','quiet'=>false];
$resetPreview=null;$resetAvailable=false;$resetConfig=importWorkspaceConfig();$resetHost=strtolower((string)($_SERVER['HTTP_HOST']??''));
try {if($pdo){$resetProtectedId=importWorkspaceAuthorize($pdo,$currentUser,$resetConfig,$resetHost);$resetAvailable=true;}} catch(RuntimeException $e) { }
$resetAction=(string)($_POST['action']??'');
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && in_array($resetAction,['preview_onboarding_reset','run_onboarding_reset'],true)) {
    $tab='settings';
    try {
        if(!$pdo || !$resetAvailable) {http_response_code(403);throw new RuntimeException('Only the protected Developer can reset this development workspace.');}
        if($resetAction==='preview_onboarding_reset') {
            $mode=is_string($_POST['reset_mode']??null)?$_POST['reset_mode']:'';
            $plan=onboardingResetPlan($pdo,$resetProtectedId,$mode);
            $resetPreview=['actor_id'=>$resetProtectedId,'time'=>time(),'token'=>bin2hex(random_bytes(24)),'plan'=>$plan,'snapshot'=>importWorkspaceSnapshot($pdo,$plan)];
            $_SESSION['onboarding_reset_preview']=$resetPreview;
        } else {
            $saved=$_SESSION['onboarding_reset_preview']??null;unset($_SESSION['onboarding_reset_preview']);
            if(!is_array($saved) || !is_string($_POST['preview_token']??null) || !hash_equals((string)$saved['token'],$_POST['preview_token']))throw new RuntimeException('Reset preview expired. Choose the reset option again.');
            $mode=$saved['plan']['onboarding_mode']??'';$definition=onboardingResetModes()[$mode]??null;
            if(!$definition)throw new RuntimeException('Invalid reset preview. Choose the reset option again.');
            $resetFormValues=['confirmation'=>is_string($_POST['reset_confirmation']??null)?$_POST['reset_confirmation']:'','quiet'=>($_POST['reset_quiet']??'')==='1'];
            if($resetFormValues['confirmation']!==$definition['phrase'])$resetErrors['reset_confirmation']='Type '.$definition['phrase'].' exactly as shown.';
            if(!$resetFormValues['quiet'])$resetErrors['reset_quiet']='Confirm testing, scheduled sends and payment processing have stopped.';
            if($resetErrors) {
                $saved['token']=bin2hex(random_bytes(24));
                $_SESSION['onboarding_reset_preview']=$saved;$resetPreview=$saved;
            } else {
            $backup=onboardingResetRun($pdo,$currentUser,$resetConfig,$resetHost,$saved);
            foreach(['basket','basket_last_added','migration_invite','migration_completed_modal','migration_account_closed','legacy_balance_confirmation'] as $key)unset($_SESSION[$key]);
            $_SESSION['flash_success']=($mode==='keep_users'?'Onboarding data reset. Users retained and fresh Person records created.':'Onboarding data reset. Only the protected user remains, ready for a new import.').' A full private backup was created: '.$backup;
            header('Location: onboarding.php?tab=settings',true,303);exit;
            }
        }
    } catch(Throwable $e) {
        error_log('Onboarding reset failed ('.get_class($e).', code '.(string)$e->getCode().').');
        $alerts[]=['type'=>'danger','message'=>($e instanceof RuntimeException && !($e instanceof PDOException))?$e->getMessage():'Reset failed. Database changes were rolled back.'];
    }
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !in_array($resetAction,['preview_onboarding_reset','run_onboarding_reset'],true)) {
    $payload=[];
    if($tab==='settings') {
        foreach($flowLabels as $key=>$label) {
            $flowOptions[$key]=is_string($_POST[$key]??null)?$_POST[$key]:'';
            if(!in_array($flowOptions[$key],['0','1'],true))$flowErrors[$key]='Choose Yes or No.';
            $payload[$key]=$flowOptions[$key];
        }
    } else {
        $body=is_string($_POST['body_html']??null)?$_POST['body_html']:'';
        $payload['onboarding_copy_'.$tab]=sanitize_rich_html($body);
        if($tab==='welcome') {
            $setupHeading=is_string($_POST['setup_heading']??null)?trim($_POST['setup_heading']):'';
            if($setupHeading==='' || mb_strlen($setupHeading)>180)$flowErrors['setup_heading']='Enter a heading of up to 180 characters.';
            $payload['onboarding_heading']=$setupHeading;
        }
        if($tab==='password') {
            $userBody=is_string($_POST['user_body_html']??null)?$_POST['user_body_html']:'';
            $payload['onboarding_copy_user']=sanitize_rich_html($userBody);
        }
    }
    if(!$flowErrors)try {
        if(!$pdo)throw new RuntimeException('Database unavailable.');
        $pdo->beginTransaction();
        $statement=$pdo->prepare('INSERT INTO site_settings (setting_key,setting_value,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
        foreach($payload as $key=>$value)$statement->execute([$key,$value]);
        $pdo->commit();
        $_SESSION['flash_success']=$tab==='settings'?'Onboarding settings saved.':$tabs[$tab][0].' wording saved.';
        header('Location: onboarding.php?tab='.rawurlencode($tab));exit;
    } catch(Throwable $e) {
        if($pdo && $pdo->inTransaction())$pdo->rollBack();
        $alerts[]=['type'=>'danger','message'=>'The onboarding changes could not be saved. Please try again.'];
    }
}
admin_layout_start('Onboarding','onboarding');
?>
<h1 class="h4">Onboarding</h1>
<p class="text-muted">Edit the message displayed on each account setup page. Save each tab before moving to another. Leave an editor empty to show no message. Thank you wording also appears in the completion popup.</p>
<ul class="nav nav-tabs admin-tabs mb-3" aria-label="Onboarding pages">
<?php foreach($tabs as $key=>[$label]): ?>
<li class="nav-item"><a class="nav-link <?= $tab===$key?'active':'' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="onboarding.php?tab=<?= h($key) ?>"><?= h($label) ?></a></li>
<?php endforeach; ?>
</ul>
<form method="post" class="card-soft p-4">
<?= csrf_input() ?><input type="hidden" name="tab" value="<?= h($tab) ?>">
<?php if($tab==='settings'): ?>
<p>These settings apply to unfinished onboarding journeys. Disabling a page skips it even when records exist. Completed accounts are not reopened.</p>
<?php foreach($flowLabels as $key=>$label): ?>
<div class="mb-3">
    <label class="form-label fw-semibold" for="<?= h($key) ?>"><?= h($label) ?></label>
    <?php if(isset($flowErrors[$key])): ?><div class="text-danger small mb-1" id="<?= h($key) ?>-error"><?= h($flowErrors[$key]) ?></div><?php endif; ?>
    <select id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-select <?= isset($flowErrors[$key])?'is-invalid':'' ?>" <?= isset($flowErrors[$key])?'aria-invalid="true" aria-describedby="'.h($key).'-error"':'' ?>>
        <?php foreach(['1'=>'Yes','0'=>'No'] as $value=>$text): ?><option value="<?= $value ?>" <?= $flowOptions[$key]===(string)$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?>
    </select>
    <?php if($key==='onboarding_show_blank_pages'): ?><div class="form-text">One setting for all optional pages. Yes shows enabled Horses/Shares pages with a “none found” message when empty. No skips empty pages.</div><?php endif; ?>
</div>
<?php endforeach; ?>
<button type="submit" class="btn btn-success">Save Onboarding Settings</button>
<?php else: ?>
<?php if($tab==='welcome'): ?>
<label class="form-label fw-semibold" for="onboarding-heading">Account setup heading</label>
<?php if(isset($flowErrors['setup_heading'])): ?><div class="text-danger mb-1" id="onboarding-heading-error"><?= h($flowErrors['setup_heading']) ?></div><?php endif; ?>
<input type="text" name="setup_heading" id="onboarding-heading" class="form-control mb-2 <?= isset($flowErrors['setup_heading'])?'is-invalid':'' ?>" maxlength="180" required value="<?= h($setupHeading) ?>" <?= isset($flowErrors['setup_heading'])?'aria-invalid="true" aria-describedby="onboarding-heading-error"':'' ?>>
<p class="form-text">The heading appears throughout account setup. The Welcome message below appears on the first page above the account choices.</p>
<?php endif; ?>
<label for="onboarding-body" class="form-label fw-semibold"><?= h($tab==='password'?'Password':$tabs[$tab][0]) ?> message</label>
<textarea id="onboarding-body" class="form-control wysiwyg-field" name="body_html" rows="12"><?= h($body) ?></textarea>
<?php if($tab==='password'): ?>
<label for="onboarding-user-body" class="form-label fw-semibold mt-3">User confirmation message on the same page</label>
<textarea id="onboarding-user-body" class="form-control wysiwyg-field" name="user_body_html" rows="6"><?= h($userBody) ?></textarea>
<?php endif; ?>
<button type="submit" class="btn btn-success mt-3">Save <?= h($tabs[$tab][0]) ?></button>
<?php endif; ?>
</form>
<?php if ($tab==='settings') require __DIR__.'/onboarding_reset_panel.php'; ?>
<?php render_tinymce_bootstrap(); ?>
<script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'textarea.wysiwyg-field'}));</script>
<?php admin_layout_end(); ?>
