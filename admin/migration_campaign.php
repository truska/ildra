<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/campaign_navigation.php';
require_once __DIR__.'/../import_workspace.php';
require_once __DIR__.'/../views/migration_fields.php';
header('Cache-Control: no-store, private');
try {
    if (!$pdo) throw new RuntimeException('Database unavailable.');
    $migrationEnvironment=migrationAuthorizeAdmin($pdo,$currentUser);
    $canResetOnboarding=$migrationEnvironment['mode']==='development';
    ensureMigrationTables($pdo);

} catch (RuntimeException $e) { http_response_code(403);exit(h($e->getMessage())); }
$errors=[];$campaign=migrationCampaign($pdo);$pageCopy=migrationPageCopy($pdo);
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        $action=(string)($_POST['action']??'');
        if ($action==='reset_onboarding') {
            if(!$canResetOnboarding)throw new RuntimeException('Testing resets are available only on development.');
            if (($_POST['delete_confirmation']??'')!=='RESET ONBOARDING') throw new RuntimeException('Type RESET ONBOARDING to confirm.');
            migrationResetOnboarding($pdo,$currentUser,(int)($_POST['user_id']??0),(int)($_POST['person_id']??0));
            unset($_SESSION['migration_campaign_preview']);
            $_SESSION['flash_success']='Onboarding reset. This account will restart at its next login, or through a fresh invitation. Data has been retained. For an account already emailed, select Allow resends when sending its new invitation.';
            header('Location: migration_campaign.php',true,303);exit;
        } elseif ($action==='save_page_copy') {
            $pdo->prepare('UPDATE migration_page_copy SET introduction=?,closure_explanation=?,revision=revision+1 WHERE id=1')->execute([trim((string)($_POST['introduction']??'')),trim((string)($_POST['closure_explanation']??''))]);
            $_SESSION['flash_success']='Welcome and account closure wording saved.';
            header('Location: migration_campaign.php',true,303);exit;
        } elseif ($action==='save') {
            $errors=migrationCampaignErrors($_POST);
            // Empty copy can be saved as a draft; sending still requires both fields.
            if (trim((string)($_POST['subject']??''))==='' && trim(strip_tags((string)($_POST['body_html']??'')))==='') $errors=[];
            if (!$errors) {
                $pdo->prepare('UPDATE migration_campaigns SET subject=?,body_html=?,revision=revision+1 WHERE id=1')->execute([trim((string)($_POST['subject']??'')),(string)($_POST['body_html']??'')]);
                unset($_SESSION['migration_campaign_preview']);
                $_SESSION['flash_success']='Migration campaign draft saved. No email sent.';
                header('Location: migration_campaign.php',true,303);exit;
            }
        } elseif ($action==='preview') {
            unset($_SESSION['migration_campaign_preview']);
            $errors=migrationCampaignErrors($campaign);
            if(!$errors){
                $redirect=migrationRequiredRedirect($pdo);
                $recipients=migrationPreviewRecipients($pdo,(string)($_POST['recipient_mode']??'selected'),(array)($_POST['user_ids']??[]),!empty($_POST['allow_resend']));
                $_SESSION['migration_campaign_preview']=['revision'=>(int)$campaign['revision'],'actor_id'=>(int)$currentUser['id'],'recipients'=>$recipients,'redirect'=>$redirect,'time'=>time(),'token'=>bin2hex(random_bytes(24))];
            }
        } elseif ($action==='send') {
            $preview=$_SESSION['migration_campaign_preview']??[];unset($_SESSION['migration_campaign_preview']);
            if(!$preview || (int)$preview['actor_id']!==(int)$currentUser['id'] || time()-(int)$preview['time']>900 || !hash_equals((string)$preview['token'],(string)($_POST['preview_token']??'')) || !isset($preview['recipients'],$preview['redirect']))throw new RuntimeException('Preview expired. Preview the recipients again before sending.');
            if(migrationRequiredRedirect($pdo)!==$preview['redirect'])throw new RuntimeException('The redirect changed. Preview again.');
            $report=[];
            foreach($preview['recipients'] as $recipient){
                try{
                    $sent=migrationSendRecipient($pdo,(int)$preview['revision'],(int)$recipient['id'],static function(array $user,array $payload,int $iid,string $redirect)use($pdo):bool{
                        $settings=getSiteSettings($pdo);$emailSettings=getEmailSettings($pdo);
                        return send_logged_email($pdo,$user['email'],subject_with_prefix($emailSettings,$payload['subject']),wrap_user_email_html($settings,$emailSettings,$payload['html']),wrap_user_email_text($settings,$emailSettings,$payload['text']),['kind'=>'account_migration','migration_invitation_id'=>$iid,'required_migration_redirect'=>$redirect]);
                    },$recipient['operation']==='Resend',$recipient['snapshot'],$preview['redirect']);
                    $report[]=['email'=>$recipient['email'],'result'=>$sent?'Sent (redirected)':'Delivery blocked or failed; check redirect and Email history'];
                }catch(Throwable $e){$report[]=['email'=>$recipient['email'],'result'=>$e instanceof PDOException?'Delivery uncertain; database failure. Review Email history before retrying.':$e->getMessage()];}
            }
            $_SESSION['migration_send_report']=$report;
            $_SESSION['flash_success']='Reviewed migration batch processed. All accepted deliveries used '.$preview['redirect'].'. See the per-account results below.';
            header('Location: migration_campaign.php',true,303);exit;
        }

    } catch (Throwable $e) {
        error_log('Migration campaign: '.$e->getMessage());
        $alerts[]=['type'=>'danger','message'=>$e instanceof PDOException?'The campaign could not be saved or sent. Check the server log.':$e->getMessage()];
    }
}
$preview=$_SESSION['migration_campaign_preview']??null;
if ($preview && (!isset($preview['recipients']) || time()-(int)$preview['time']>900 || (int)$preview['revision']!==(int)$campaign['revision'])) $preview=null;
$values=$errors && ($_POST['action']??'')==='save'?$_POST:$campaign;
$recipientRows=migrationRecipients($pdo);$redirectTo='';$redirectError='';
try{$redirectTo=migrationRequiredRedirect($pdo);}catch(RuntimeException $e){$redirectError=$e->getMessage();}
$report=$_SESSION['migration_send_report']??[];unset($_SESSION['migration_send_report']);
$history=$pdo->query('SELECT email,user_id,delivery_status,sent_at,expires_at,used_at,revoked_at FROM migration_invitations ORDER BY id DESC LIMIT 100');
admin_layout_start('Account migration','email_campaigns');
render_campaign_navigation('migration',$currentRole);
?>
<div class="card-soft p-4 mb-4"><h1 class="h4">Account migration campaign</h1><p>Choose the accounts to invite below, then review the recipients and send the batch. Each email has a personal link for its intended account.</p><p>Password &amp; user settings → Person settings → enabled Horses/Shares pages → Finish.</p><p class="small text-muted">Ordinary users open their personal link and choose Continue to set a password. Protected accounts sign in normally first. Preparing or sending an invitation does not change the password, role, protection or authenticator. Progress becomes mandatory after Continue is selected.</p>
<form method="post" novalidate><?= csrf_input() ?><input type="hidden" name="action" value="save"><div class="row">
<?php migrationField('subject','Email subject',$values,$errors); ?>
<div class="col-12 mb-3">
    <label class="form-label" for="migration_body_html">Campaign text <span aria-hidden="true">*</span></label>
    <?php if(isset($errors['body_html'])): ?><div class="text-danger small mb-1" id="migration_body_html_error"><?= h($errors['body_html']) ?></div><?php endif; ?>
    <textarea class="form-control wysiwyg-field <?= isset($errors['body_html'])?'is-invalid':'' ?>" id="migration_body_html" name="body_html" rows="12" required <?= isset($errors['body_html'])?'aria-invalid="true" aria-describedby="migration_body_html_error"':'' ?>><?= h($values['body_html']??'') ?></textarea>
</div>
</div><p class="small text-muted">This is the email body. Personalisation tags: {{first_name}}, {{last_name}}, {{email}}. The personal setup button and seven-day expiry notice are added automatically.</p><button class="btn btn-success">Save draft</button></form>
</div>
<div class="card-soft p-4 mb-4"><h2 class="h5">Welcome and closure wording</h2><p>These optional text blocks appear above the first-page choices and in the closure confirmation. The system always explains that closure archives the records and stops migration.</p><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save_page_copy"><div class="row"><?php migrationField('introduction','Welcome explanation',$pageCopy,[],'textarea',false);migrationField('closure_explanation','Account closure explanation',$pageCopy,[],'textarea',false); ?></div><button class="btn btn-success">Save page wording</button></form><a class="btn btn-link mt-2" href="record_notes.php?return=migration_campaign.php">View internal record notes</a></div>
<div class="card-soft p-4 mb-4"><h2 class="h5">Choose recipients</h2>
<?php if($redirectError): ?><div class="alert alert-warning"><?= h($redirectError) ?></div><?php elseif($redirectTo===''): ?><div class="alert alert-warning">Direct delivery is enabled. Invitations will go to each intended recipient shown in the preview.</div><?php else: ?><div class="alert alert-info">All outgoing invitations will be delivered to <strong><?= h($redirectTo) ?></strong>. The original recipient is shown in each redirected email, so you can forward it with their personal link.</div><?php endif; ?>
<form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="preview">
<div class="form-check"><input class="form-check-input" type="radio" id="migration-selected" name="recipient_mode" value="selected" checked><label class="form-check-label" for="migration-selected">Selected accounts</label></div>
<div class="form-check mb-3"><input class="form-check-input" type="radio" id="migration-unsent" name="recipient_mode" value="not_sent"><label class="form-check-label" for="migration-unsent">All who have not yet been emailed</label></div>
<p class="small text-muted">Completed or closed accounts and uncertain deliveries are excluded. Failed deliveries with no successful send can be retried. Up to 100 recipients per reviewed batch.</p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Select</th><th>Account</th><th>Person ID</th><th>Setup status</th><th>Previous sends</th><th>Onboarding</th></tr></thead><tbody>
<?php foreach($recipientRows as $row): ?><tr><td><input class="form-check-input" type="checkbox" name="user_ids[]" value="<?= (int)$row['id'] ?>" aria-label="Select <?= h($row['email']) ?>" <?= $row['reason']!==''?'disabled':'' ?>></td><td><?= h($row['name']) ?><div class="small text-muted"><?= h($row['email']) ?></div></td><td><?= (int)$row['person_id'] ?></td><td><?= h($row['status']) ?><?php if($row['reason']!==''): ?><div class="small text-muted"><?= h($row['reason']) ?></div><?php endif; ?></td><td><?= (int)$row['sent'] ?></td><td><?php if($canResetOnboarding): ?><button class="btn btn-sm btn-outline-warning" type="submit" form="reset-onboarding-<?= (int)$row['id'] ?>" <?= empty($row['person_id'])?'disabled title="Review the Person match first"':'' ?>>Reset Onboarding</button><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<label class="d-block mb-3"><input type="checkbox" name="allow_resend" value="1"> Allow resends for selected accounts already emailed (older unused links will stop working).</label>
<button class="btn btn-outline-success" <?= $redirectError?'disabled':'' ?>>Preview recipients and saved campaign</button></form>
<?php foreach($recipientRows as $resetRow): if(!$canResetOnboarding || empty($resetRow['person_id']))continue; ?>
<form method="post" id="reset-onboarding-<?= (int)$resetRow['id'] ?>" data-confirm-title="Reset Onboarding?" data-confirm-message="<?= h('Restart onboarding for '.$resetRow['email'].' at their next login or via a fresh invitation? People, horses, shares and financial history are retained. Old onboarding links and progress are reset. No email is sent now.') ?>" data-confirm-style="danger" data-confirm-button="Reset Onboarding" data-confirm-require-text="RESET ONBOARDING">
    <?= csrf_input() ?><input type="hidden" name="action" value="reset_onboarding"><input type="hidden" name="user_id" value="<?= (int)$resetRow['id'] ?>"><input type="hidden" name="person_id" value="<?= (int)$resetRow['person_id'] ?>">
</form>
<?php endforeach; ?>
<?php if($preview): $example=migrationRecipient($pdo,(int)$preview['recipients'][0]['id']);$payload=migrationEmailPayload($campaign,$example,$migrationEnvironment['origin'].'/migrate.php?preview=1'); ?>
<hr><h3 class="h6">Review <?= count($preview['recipients']) ?> invitations</h3><p>Delivery address for all: <strong><?= h($preview['redirect']!==''?$preview['redirect']:'each intended recipient') ?></strong></p>
<table class="table"><thead><tr><th>Intended recipient</th><th>Action</th></tr></thead><tbody><?php foreach($preview['recipients'] as $recipient): ?><tr><td><?= h($recipient['email']) ?></td><td><?= h($recipient['operation']) ?></td></tr><?php endforeach; ?></tbody></table>
<h3 class="h6"><?= h($payload['subject']) ?></h3><p class="small text-muted">Example for <?= h($example['email']) ?>. Preview button is inactive. Each sent email will contain its recipient's unique personal link.</p><iframe title="Migration email preview" sandbox="" style="width:100%;min-height:320px;border:1px solid #ddd" srcdoc="<?= h($payload['html']) ?>"></iframe>
<form method="post" class="mt-3"><?= csrf_input() ?><input type="hidden" name="action" value="send"><input type="hidden" name="preview_token" value="<?= h($preview['token']) ?>"><button class="btn btn-success">Send <?= count($preview['recipients']) ?> reviewed invitations to <?= h($preview['redirect']!==''?$preview['redirect']:'each intended recipient') ?></button></form>
<?php endif; ?></div>
<?php if($report): ?><div class="card-soft p-4 mb-4"><h2 class="h5">Batch results</h2><table class="table"><thead><tr><th>Intended recipient</th><th>Result</th></tr></thead><tbody><?php foreach($report as $row): ?><tr><td><?= h($row['email']) ?></td><td><?= h($row['result']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<div class="card-soft p-4"><h2 class="h5">Invitation history</h2><table class="table"><thead><tr><th>Intended recipient</th><th>Delivery</th><th>Sent</th><th>Expires</th><th>Link state</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><td><?= h($row['email']) ?></td><td><?= h($row['delivery_status']) ?></td><td><?= h(admin_display_datetime($row['sent_at'])) ?></td><td><?= h(admin_display_datetime($row['expires_at'])) ?></td><td><?= $row['used_at']?'Used':($row['revoked_at']?'Revoked':(auth_is_expired($row['expires_at'])?'Expired':'Available until expiry')) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php render_tinymce_bootstrap(); ?><script>if(window.tinymce)tinymce.init(window.ildraTinyMceConfig({selector:'#migration_body_html'}));</script><?php admin_layout_end(); ?>
