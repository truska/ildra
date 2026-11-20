<?php
declare(strict_types=1);
require_once __DIR__.'/record_notes.php';
require_once __DIR__.'/onboarding_copy.php';

/** Pilot scope is intentionally enforced server-side, independent of email redirects. */
function migrationPilotUser(PDO $pdo): array
{
    $s = $pdo->prepare('SELECT u.*,r.name AS role,r.level FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.is_archived=0');
    $s->execute(['david@truska.com']); $u = $s->fetch();
    if (!$u || empty($u['is_protected']) || $u['role'] !== 'developer') throw new RuntimeException('Protected pilot account unavailable.');
    return $u;
}

function ensureMigrationTables(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Migration schema must be prepared outside a transaction.');
    $sql = file_get_contents(__DIR__.'/migrations/2026-11-17-account-migration-pilot.sql');
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
    $horseSql=file_get_contents(__DIR__.'/migrations/2026-11-17-migration-horse-reviews.sql');
    foreach(explode(';',$horseSql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    $choiceSql=file_get_contents(__DIR__.'/migrations/2026-11-17-migration-horse-choices.sql');
    foreach(explode(';',$choiceSql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    ensureHorseLogbookTables($pdo);
    ensureRecordNotesTables($pdo);
    $pdo->exec("INSERT IGNORE INTO migration_campaigns (id,body_html) VALUES (1,'')");
}

function migrationAvailable(PDO $pdo): bool
{
    return (bool)$pdo->query("SHOW TABLES LIKE 'migration_enrolments'")->fetchColumn();
}

function migrationState(PDO $pdo, int $uid, bool $lock = false): ?array
{
    $s = $pdo->prepare('SELECT * FROM migration_enrolments WHERE user_id=?'.($lock?' FOR UPDATE':''));
    $s->execute([$uid]); return $s->fetch() ?: null;
}

function migrationPending(?array $state): bool
{
    return $state && ($state['current_step']??'')!=='declined' && !empty($state['started_at']) && empty($state['completed_at']);
}

function migrationCampaign(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM migration_campaigns WHERE id=1')->fetch();
}

function migrationCampaignErrors(array $data): array
{
    $errors = [];
    if (trim((string)($data['subject']??'')) === '' || mb_strlen((string)($data['subject']??'')) > 255 || preg_match('/[\r\n]/', (string)($data['subject']??''))) $errors['subject']='Enter a subject of up to 255 characters.';
    if (trim(strip_tags((string)($data['body_html']??''))) === '') $errors['body_html']='Enter the campaign text before sending.';
    return $errors;
}

function migrationPilotPerson(PDO $pdo, array $user): array
{
    $s = $pdo->prepare('SELECT * FROM people WHERE owner_user_id=? AND is_archived=0'); $s->execute([(int)$user['id']]);
    $rows = $s->fetchAll();
    if (count($rows) !== 1 || strcasecmp(trim((string)$rows[0]['email']), $user['email']) !== 0) throw new RuntimeException('Review the account/person match before sending.');
    return $rows[0];
}

function migrationEmailPayload(array $campaign, array $user, string $url): array
{
    $merge = ['first_name'=>(string)$user['first_name'],'last_name'=>(string)$user['last_name'],'email'=>(string)$user['email']];
    $html = emailCampaignMerge((string)$campaign['body_html'], array_map('h',$merge));
    $html .= '<p>'.email_cta_button_html($url,'Complete your account setup').'</p><p>This personal link expires in seven days. You will set a password, confirm your user and person details, and review your horses. A Shares step is included for later setup.</p>';
    $text = trim(html_entity_decode(strip_tags(preg_replace('/<\/(?:p|div)>|<br\s*\/?>/i',"\n",$html)??$html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    return ['subject'=>(string)$campaign['subject'],'html'=>$html,'text'=>$text."\n\nComplete your account setup: ".$url];
}

/** Send only after an explicit reviewed POST. Resends revoke older links, preserving progress. */
function migrationSendPilot(PDO $pdo, int $revision, callable $transport): bool
{
    return migrationSendRecipient($pdo,$revision,(int)migrationPilotUser($pdo)['id'],$transport,true);
}

function migrationRecipient(PDO $pdo, int $uid): array
{
    $s=$pdo->prepare('SELECT u.*,r.name AS role,r.level FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_archived=0');$s->execute([$uid]);$user=$s->fetch();
    if (!$user || ($user['role']!=='user' && ($user['role']!=='developer' || empty($user['is_protected']) || $user['email']!=='david@truska.com'))) throw new RuntimeException('Account is not eligible for migration.');
    return $user;
}

function migrationRequiredRedirect(PDO $pdo): string
{
    $settings=getEmailSettings($pdo);$to=trim((string)($settings['email_test_redirect_to']??''));
    if (($settings['email_test_redirect_enabled']??'0')!=='1' || !filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enable Redirect all outgoing emails to a valid test address before sending migration invitations.');
    return $to;
}

function migrationDeliveryCounts(PDO $pdo, int $uid): array
{
    $s=$pdo->prepare("SELECT COALESCE(SUM(sent_at IS NOT NULL),0) AS sent,COALESCE(SUM(delivery_status IN ('pending','uncertain')),0) AS uncertain,COALESCE(SUM(delivery_status='failed'),0) AS failed FROM migration_invitations WHERE user_id=?");$s->execute([$uid]);return $s->fetch();
}

function migrationRecipientSnapshot(PDO $pdo, int $uid): string
{
    $u=migrationRecipient($pdo,$uid);$p=migrationPilotPerson($pdo,$u);$state=migrationState($pdo,$uid);$counts=migrationDeliveryCounts($pdo,$uid);
    return hash('sha256',json_encode([$u['id'],$u['email'],$u['first_name'],$u['last_name'],$u['role'],$u['is_protected'],$u['password_hash'],$p,$state,$counts],JSON_THROW_ON_ERROR));
}

function migrationRecipients(PDO $pdo): array
{
    $ids=$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.is_archived=0 AND (r.name='user' OR (r.name='developer' AND u.is_protected=1 AND u.email='david@truska.com')) ORDER BY u.email,u.id")->fetchAll(PDO::FETCH_COLUMN);$rows=[];
    foreach($ids as $id) {
        $u=migrationRecipient($pdo,(int)$id);$state=migrationState($pdo,(int)$id);$counts=migrationDeliveryCounts($pdo,(int)$id);$reason='';$person=null;
        try{$person=migrationPilotPerson($pdo,$u);}catch(RuntimeException $e){$reason='Person match needs review';}
        $status=($state['current_step']??'')==='restart'?'Restart on next login':(!empty($state['completed_at'])?'Complete':(($state['current_step']??'')==='declined'?'Closed':(!empty($state['started_at'])?'In progress: '.$state['current_step']:((int)$counts['sent']>0?'Invited':((int)$counts['failed']>0?'Delivery failed':'Not emailed')))));
        if($state && $person && (int)$state['person_id']!==(int)$person['id'])$reason='Person mapping needs review';
        if(!empty($state['completed_at']) || ($state['current_step']??'')==='declined')$reason=$status;
        if((int)$counts['uncertain']>0)$reason='Delivery pending or uncertain; review before retrying';
        $rows[]=['id'=>(int)$id,'email'=>$u['email'],'name'=>trim($u['first_name'].' '.$u['last_name']),'person_id'=>$person?(int)$person['id']:null,'status'=>$status,'sent'=>(int)$counts['sent'],'failed'=>(int)$counts['failed'],'reason'=>$reason];
    }
    return $rows;
}

function migrationPreviewRecipients(PDO $pdo, string $mode, array $selected, bool $resend): array
{
    foreach($selected as $id)if((!is_int($id)&&!is_string($id)) || !ctype_digit((string)$id) || (int)$id<=0)throw new RuntimeException('Invalid account selection.');
    $chosen=array_fill_keys(array_map('intval',$selected),true);$result=[];
    foreach(migrationRecipients($pdo) as $row) {
        if($mode==='not_sent') {if($row['sent']>0 || $row['reason']!=='')continue;}
        elseif($mode==='selected') {if(!isset($chosen[$row['id']]))continue;unset($chosen[$row['id']]);if($row['reason']!=='')throw new RuntimeException($row['email'].': '.$row['reason']);if($row['sent']>0&&!$resend)throw new RuntimeException('Tick Allow resends to invite previously emailed accounts again.');}
        else throw new RuntimeException('Choose a recipient selection method.');
        $row['snapshot']=migrationRecipientSnapshot($pdo,$row['id']);$row['operation']=$row['sent']>0?'Resend':($row['failed']>0?'Retry':'New invitation');$result[]=$row;
    }
    if($mode==='selected' && $chosen)throw new RuntimeException('Some selected accounts are no longer eligible. Review the list again.');
    if(!$result)throw new RuntimeException('No eligible recipients selected.');
    if(count($result)>100)throw new RuntimeException('Select up to 100 accounts per reviewed batch.');
    return $result;
}

/** Rechecks selection under locks. Any change since preview requires another preview. */
function migrationSendRecipient(PDO $pdo, int $revision, int $uid, callable $transport, bool $resend=false, ?string $snapshot=null, ?string $requiredRedirect=null): bool
{
    require_once __DIR__.'/import_workspace.php';
    importWorkspaceAuthorize($pdo,$_SESSION['user']??[],importWorkspaceConfig(),(string)($_SERVER['HTTP_HOST']??''));
    $redirect=migrationRequiredRedirect($pdo);
    if($requiredRedirect!==null && $redirect!==$requiredRedirect)throw new RuntimeException('The redirect changed. Preview the recipients again.');
    $campaign=migrationCampaign($pdo);
    if((int)$campaign['revision']!==$revision || migrationCampaignErrors($campaign))throw new RuntimeException('Save and review the current campaign first.');
    $user=migrationRecipient($pdo,$uid);$person=migrationPilotPerson($pdo,$user);$token=bin2hex(random_bytes(32));
    $payload=migrationEmailPayload($campaign,$user,authEmailOrigins()['dev-ildra.witecanvas.com'].'/migrate.php?invite='.rawurlencode($token));
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$uid]);
        $lockedCampaign=$pdo->query('SELECT * FROM migration_campaigns WHERE id=1 FOR UPDATE')->fetch();
        if($lockedCampaign!==$campaign || migrationRecipient($pdo,$uid)!==$user)throw new RuntimeException('Campaign or account changed; preview again.');
        $s=$pdo->prepare('SELECT id FROM people WHERE id=? AND owner_user_id=? AND is_archived=0 FOR UPDATE');$s->execute([(int)$person['id'],$uid]);if(!$s->fetch())throw new RuntimeException('Person match changed.');
        $state=migrationState($pdo,$uid,true);$counts=migrationDeliveryCounts($pdo,$uid);
        if($snapshot!==null && !hash_equals($snapshot,migrationRecipientSnapshot($pdo,$uid)))throw new RuntimeException('Recipient changed since preview. Preview again.');
        if((int)$counts['uncertain']>0)throw new RuntimeException('An earlier delivery is pending or uncertain; review before retrying.');
        if((int)$counts['sent']>0 && !$resend)throw new RuntimeException('This account has already been emailed.');
        if(($state['current_step']??'')==='declined' || !empty($state['completed_at']))throw new RuntimeException('This account has finished or declined migration.');
        if($state && (int)$state['person_id']!==(int)$person['id'])throw new RuntimeException('Person mapping changed; review required.');
        $pdo->prepare('INSERT IGNORE INTO migration_enrolments (user_id,person_id) VALUES (?,?)')->execute([$uid,(int)$person['id']]);
        $pdo->prepare('UPDATE migration_invitations SET revoked_at=NOW() WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL')->execute([$uid]);
        $s=$pdo->prepare('INSERT INTO migration_invitations (user_id,token_hash,email,credential_stamp,expires_at,campaign_revision) VALUES (?,?,?,?,?,?)');$s->execute([$uid,hash('sha256',$token),$user['email'],hash('sha256',$user['password_hash']),auth_future_string(7*86400),$revision]);$iid=(int)$pdo->lastInsertId();$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    try{$sent=(bool)$transport($user,$payload,$iid,$redirect);}catch(Throwable $e){$pdo->prepare("UPDATE migration_invitations SET delivery_status='uncertain',revoked_at=NOW() WHERE id=?")->execute([$iid]);throw $e;}
    // Never un-revoke a link if the recipient finished/closed while delivery was in progress.
    $pdo->prepare("UPDATE migration_invitations SET delivery_status=?,sent_at=IF(?=1,NOW(),NULL),revoked_at=IF(?=0,NOW(),revoked_at) WHERE id=?")->execute([$sent?'sent':'failed',(int)$sent,(int)$sent,$iid]);
    return $sent;
}

function migrationInspectInvitation(PDO $pdo, string $token, bool $lock=false): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$token)) return null;
    $s=$pdo->prepare('SELECT i.*,u.is_archived,u.password_hash,u.email AS current_email FROM migration_invitations i JOIN users u ON u.id=i.user_id WHERE i.token_hash=?'.($lock?' FOR UPDATE':''));
    $s->execute([hash('sha256',$token)]); $row=$s->fetch();
    if (!$row || !empty($row['is_archived']) || $row['used_at'] || $row['revoked_at'] || auth_is_expired($row['expires_at'])
        || $row['email'] !== $row['current_email'] || !hash_equals($row['credential_stamp'],hash('sha256',$row['password_hash']))) return null;
    return $row;
}

/** Invitation proof signs ordinary users into the gated setup only, on a CSRF-protected POST. */
function migrationAcceptInvitation(PDO $pdo, string $token, int $uid): void
{
    if(!empty($_SESSION['act_as_original_user']))throw new RuntimeException('Exit impersonation before opening an invitation.');
    $sessionUid=(int)($_SESSION['user']['id']??0);
    if($sessionUid && $sessionUid!==$uid)throw new RuntimeException('Sign out of the other account before continuing.');
    if($sessionUid)migrationAssertOwnSession($pdo,$uid);
    $pdo->beginTransaction();$user=null;
    try {
        $row=migrationInspectInvitation($pdo,$token,true);
        if(!$row || (int)$row['user_id']!==$uid)throw new RuntimeException('This invitation is invalid, expired or belongs to another account.');
        $user=migrationRecipient($pdo,$uid);
        if(($user['role']!=='user' || !empty($user['is_protected'])) && $sessionUid!==$uid)throw new RuntimeException('Sign in normally to this protected account before continuing.');
        $state=migrationState($pdo,$uid,true);
        if(!$state || $state['current_step']==='declined' || !empty($state['completed_at']))throw new RuntimeException('Account setup is unavailable or already complete.');
        $pdo->prepare('UPDATE migration_invitations SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        $pdo->prepare("UPDATE migration_enrolments SET current_step=IF(current_step='restart','password',current_step),started_at=COALESCE(started_at,NOW()) WHERE user_id=?")->execute([$uid]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    session_regenerate_id(true);authBindCredentialSession($uid,$user['password_hash']);
    $_SESSION['user']=['id'=>$uid,'email'=>$user['email'],'role'=>$user['role'],'level'=>(int)$user['level'],'first_name'=>$user['first_name'],'last_name'=>$user['last_name']];
    unset($_SESSION['migration_invite']);
}

function migrationPreferences(array $data): array
{
    $choice=(int)!empty($data['ride_notice_opt_in']);
    return array_fill_keys(['general_email_opt_in','ride_notice_opt_in','renewal_reminder_opt_in'],$choice);
}

function migrationDetailErrors(array $data, bool $person): array
{
    $errors=[];
    $required=['first_name'=>'First name','last_name'=>'Surname','email'=>'Email address'];
    if ($person) $required+=['phone'=>'Phone number','address'=>'Full Postal Address','postcode'=>'Postcode','junior_or_senior'=>'Junior or Senior','emergency_contact_name'=>'Emergency contact name','emergency_contact_phone'=>'Emergency contact phone'];
    foreach ($required as $field=>$label) if (trim((string)($data[$field]??''))==='') $errors[$field]=$label.' is required.';
    foreach (['first_name'=>120,'last_name'=>120,'email'=>255,'phone'=>60,'postcode'=>40,'emergency_contact_name'=>255,'emergency_contact_phone'=>60] as $field=>$limit) if (mb_strlen((string)($data[$field]??''))>$limit) $errors[$field]='Please use no more than '.$limit.' characters.';
    if (!filter_var(trim((string)($data['email']??'')),FILTER_VALIDATE_EMAIL)) $errors['email']='Enter a valid email address.';
    if ($person) {
        if (!in_array($data['junior_or_senior']??'',['Junior','Senior'],true)) $errors['junior_or_senior']='Select Junior or Senior.';
        $dob=trim((string)($data['dob']??''));
        if ($dob!=='' && !birth_date_is_valid($dob)) $errors['dob']='Enter a valid date of birth that is not in the future.';
        if (($data['junior_or_senior']??'')==='Junior' && $dob==='') $errors['dob']='Date of birth is required for juniors.';
    }
    return $errors;
}

/** Fields and progress are committed together; client-supplied step and person IDs are never trusted. */
function migrationSaveDetails(PDO $pdo, int $uid, string $step, array $data): array
{
    migrationAssertOwnSession($pdo,$uid);
    if (!in_array($step,['user','person'],true)) throw new RuntimeException('Invalid setup step.');
    $errors=migrationDetailErrors($data,$step==='person');
    if ($errors) return $errors;
    $pdo->beginTransaction();
    try {
        $state=migrationState($pdo,$uid,true);
        if (!migrationPending($state) || $state['current_step']!==$step) throw new RuntimeException('Your setup step changed. Reload this page.');
        $s=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([$uid]);$user=$s->fetch();
        if (!$user || !hash_equals((string)($_SESSION['auth_credential']['stamp']??''),hash('sha256','password-v1:'.(string)$user['password_hash']))) throw new RuntimeException('Sign in again before saving these details.');
        // Login email remains the verified invitation address; changes use normal verified account support later.
        if ($step==='user' && trim((string)$data['email'])!==$user['email']) { $pdo->rollBack(); return ['email'=>'Keep your existing login email during account setup.']; }
        $prefs=migrationPreferences($data);
        if ($step==='user') {
            // Preferences are reviewed once, on the Person step; ignore any hidden/forged User-step choices.
            $s=$pdo->prepare('UPDATE users SET first_name=?,last_name=? WHERE id=?');
            $s->execute([trim($data['first_name']),trim($data['last_name']),$uid]);
            $pdo->prepare("UPDATE migration_enrolments SET current_step='person',user_confirmed_at=NOW() WHERE user_id=?")->execute([$uid]);
        } else {
            $s=$pdo->prepare('SELECT * FROM people WHERE id=? AND owner_user_id=? AND is_archived=0 FOR UPDATE');$s->execute([(int)$state['person_id'],$uid]);$person=$s->fetch();
            if (!$person) throw new RuntimeException('Your person record needs administrator review.');
            $classification=$data['junior_or_senior'];$dob=trim((string)($data['dob']??''));
            // Match existing person age classification: under 18 on 1 January is Junior.
            if ($dob!=='' && (new DateTimeImmutable($dob))->diff(new DateTimeImmutable(date('Y').'-01-01'))->y<18) $classification='Junior';
            $fields=['first_name','last_name','email','phone','address','postcode','emergency_contact_name','emergency_contact_phone'];
            $values=array_map(static fn($f)=>trim((string)$data[$f]),$fields);
            $s=$pdo->prepare('UPDATE people SET first_name=?,last_name=?,email=?,phone=?,address=?,postcode=?,emergency_contact_name=?,emergency_contact_phone=?,junior_or_senior=?,dob=?,general_email_opt_in=?,ride_notice_opt_in=?,renewal_reminder_opt_in=? WHERE id=? AND owner_user_id=?');
            $s->execute([...$values,$classification,$dob?:null,...array_values($prefs),(int)$person['id'],$uid]);
            // The mapped imported person is this account holder. One explicit choice serves either campaign address strategy.
            $pdo->prepare('UPDATE users SET general_email_opt_in=?,ride_notice_opt_in=?,renewal_reminder_opt_in=? WHERE id=?')->execute([...array_values($prefs),$uid]);
            $membershipBasket=null;$membershipClock=null;
            if(!empty($data['add_membership'])) {
                $membershipErrors=migrationAddMembership($pdo,$uid,$person,array_merge($person,$data),$data,$membershipBasket,$membershipClock);
                if($membershipErrors){$pdo->rollBack();return $membershipErrors;}
            }
            $snapshot=json_encode($prefs,JSON_THROW_ON_ERROR);
            $pdo->prepare("UPDATE migration_enrolments SET current_step='horses',horses_status='pending',shares_status='deferred',person_confirmed_at=NOW(),person_preferences_json=?,user_preferences_json=? WHERE user_id=?")->execute([$snapshot,$snapshot,$uid]);
        }
        if($step==='person' && !empty($data['add_membership']))$pdo->prepare("UPDATE migration_enrolments SET current_step='person' WHERE user_id=?")->execute([$uid]);
        if ($step==='person' && empty($data['add_membership'])) migrationApplyStageOptions($pdo,$uid,migrationState($pdo,$uid));
        $pdo->commit();
        if(isset($membershipBasket)){$_SESSION['basket']=$membershipBasket;$_SESSION['basket_last_added']=$membershipClock;}
        return [];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function migrationPasswordErrors(array $data): array
{
    $errors=[];
    if (strlen((string)($data['new_password']??''))<8 || strlen((string)($data['new_password']??''))>72) $errors['new_password']='Use between 8 and 72 characters.';
    if (($data['new_password']??'')!==($data['confirm_password']??'')) $errors['confirm_password']='The passwords do not match.';
    return $errors;
}

function migrationSavePassword(PDO $pdo, int $uid, array $data, bool $confirmUser = false): array
{
    migrationAssertOwnSession($pdo,$uid);
    $errors=migrationPasswordErrors($data);
    if ($confirmUser) $errors += migrationDetailErrors($data, false);
    if ($errors) return $errors;
    ensureAuthTables($pdo); ensurePasswordResetTable($pdo);
    $user=migrationRecipient($pdo,$uid);
    if ($confirmUser && trim((string)$data['email']) !== $user['email']) return ['email'=>'Keep your existing login email during account setup.'];
    $hash=password_hash($data['new_password'],PASSWORD_DEFAULT);
    $result=authThrottleRun($pdo,'login',$user['email'],authThrottleIp(),static function() use($pdo,$uid,$hash,$confirmUser,$data) {
        $state=migrationState($pdo,$uid,true);
        if (!migrationPending($state) || !in_array($state['current_step'], $confirmUser ? ['password','user'] : ['password'], true)) throw new RuntimeException('Your setup step changed. Reload this page.');
        $s=$pdo->prepare('SELECT password_hash,email FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([$uid]);$lockedUser=$s->fetch();$previous=$lockedUser['password_hash']??null;
        if ($confirmUser && ($lockedUser['email']??'') !== trim((string)$data['email'])) throw new RuntimeException('Your login email changed. Reload this page.');
        if (!$previous || !hash_equals((string)($_SESSION['auth_credential']['stamp']??''),hash('sha256','password-v1:'.$previous))) return false;
        authReplacePassword($pdo,$uid,$hash,$previous);
        if ($confirmUser) {
            $pdo->prepare('UPDATE users SET first_name=?,last_name=? WHERE id=?')->execute([trim($data['first_name']),trim($data['last_name']),$uid]);
            $pdo->prepare("UPDATE migration_enrolments SET current_step='person',password_setup_required=0,password_set_at=NOW(),user_confirmed_at=NOW() WHERE user_id=?")->execute([$uid]);
        } else {
            $pdo->prepare("UPDATE migration_enrolments SET current_step='user',password_setup_required=0,password_set_at=NOW() WHERE user_id=?")->execute([$uid]);
        }
        return true;
    });
    if (!$result['allowed'] || !$result['value']) return ['new_password'=>$result['retry_after']>0?authThrottleRetryMessage($result['retry_after']):'Your sign-in has expired. Sign in again before setting your password.'];
    session_regenerate_id(true); authBindCredentialSession($uid,$hash); clear_remember_me_cookie(); unset($_COOKIE['ildra_remember']);
    return [];
}

/** The public combined form commits identity, password and progress atomically. */
function migrationSaveAccount(PDO $pdo, int $uid, array $data): array
{
    return migrationSavePassword($pdo,$uid,$data,true);
}

function migrationFinish(PDO $pdo, int $uid): void
{
    migrationAssertOwnSession($pdo,$uid);
    migrationRestoreMembershipBasket($pdo,$uid);
    migrationSyncLogbookBasket($pdo,$uid);
    $pdo->beginTransaction();
    try {
        $state=migrationState($pdo,$uid,true);
        if (!migrationPending($state) || $state['current_step']!=='finish' || !$state['password_set_at'] || !$state['user_confirmed_at'] || !$state['person_confirmed_at']) throw new RuntimeException('Complete each setup step before finishing.');
        // Revalidate current records so an intervening edit cannot bypass required details.
        $s=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([$uid]);$user=$s->fetch();
        $s=$pdo->prepare('SELECT * FROM people WHERE id=? AND owner_user_id=? AND is_archived=0 FOR UPDATE');$s->execute([(int)$state['person_id'],$uid]);$person=$s->fetch();
        if (!$user || !$person || migrationDetailErrors($user,false) || migrationDetailErrors($person,true)) throw new RuntimeException('Your saved details need review before finishing.');
        $steps=migrationVisibleSteps($pdo,$uid);
        if (isset($steps['horses']) && !migrationHorseReviewValid($pdo,$uid,$state,true)) throw new RuntimeException('Review your horses before finishing.');
        if (isset($steps['shares']) && $state['shares_status']!=='placeholder_seen') throw new RuntimeException('Continue through Shares before finishing.');
        $pdo->prepare("UPDATE migration_enrolments SET current_step='complete',completed_at=NOW() WHERE user_id=?")->execute([$uid]);
        $pdo->prepare('UPDATE migration_invitations SET revoked_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$uid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function migrationGuardAllows(string $script, array $get, string $method, array $post): bool
{
    if (in_array($script,['migrate.php','help.php','help_manual.php'],true)) return true;
    return $script==='account.php' && $method==='GET' && isset($get['logout']);
}

function migrationAssertOwnSession(PDO $pdo, int $uid): void
{
    $alerts=[];
    if ($uid<=0 || (int)($_SESSION['user']['id']??0)!==$uid || !empty($_SESSION['act_as_original_user']) || !authValidateSession($pdo,$alerts)) throw new RuntimeException('Sign in to your own account before continuing.');
}

function migrationBack(PDO $pdo, int $uid): void
{
    migrationAssertOwnSession($pdo,$uid);
    $pdo->beginTransaction();
    try {
        $state=migrationState($pdo,$uid,true);
        $steps=array_keys(migrationVisibleSteps($pdo,$uid));
        $current=$state['current_step']==='user'?'password':$state['current_step'];
        $index=array_search($current,$steps,true);
        if (!migrationPending($state) || $index===false || $index===0) throw new RuntimeException('No earlier step is available.');
        $pdo->prepare('UPDATE migration_enrolments SET current_step=? WHERE user_id=?')->execute([$steps[$index-1],$uid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function migrationPageCopy(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM migration_page_copy WHERE id=1')->fetch();
}

function migrationClosureMessage(array $copy): string
{
    $extra=trim((string)($copy['closure_explanation']??''));
    return ($extra!==''?$extra."\n\n":'').'Confirming will archive your login and the person record imported with it, sign you out and stop this migration. Your records will be retained in the archive.';
}

/** Self-service decline is separate from administrative deletion; records are never deleted. */
function migrationDecline(PDO $pdo, int $uid, string $token, bool $confirmed, int $copyRevision): void
{
    if(!empty($_SESSION['act_as_original_user']) || (!empty($_SESSION['user']) && (int)$_SESSION['user']['id']!==$uid))throw new RuntimeException('Sign out of the other account before continuing.');
    if(!empty($_SESSION['user']))migrationAssertOwnSession($pdo,$uid);
    if (!$confirmed) throw new RuntimeException('Confirm account closure before continuing.');
    ensureAuthTables($pdo);ensurePasswordResetTable($pdo);
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT u.*,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_archived=0 FOR UPDATE');$s->execute([$uid]);$user=$s->fetch();
        if (!$user || (!empty($_SESSION['user']) && !hash_equals((string)($_SESSION['auth_credential']['stamp']??''),hash('sha256','password-v1:'.$user['password_hash'])))) throw new RuntimeException('Sign in again before closing your account.');
        if (!empty($user['is_protected']) || $user['role']!=='user') throw new RuntimeException('This protected or administrative account cannot be closed here.');
        $invitation=migrationInspectInvitation($pdo,$token,true);
        if (!$invitation || (int)$invitation['user_id']!==$uid) throw new RuntimeException('Open your valid personal invitation before closing your account.');
        $state=migrationState($pdo,$uid,true);
        if (!$state || $state['started_at'] || $state['completed_at'] || $state['current_step']!=='password') throw new RuntimeException('This choice is only available before starting migration.');
        $copy=$pdo->query('SELECT * FROM migration_page_copy WHERE id=1 FOR UPDATE')->fetch();
        if (!$copy || (int)$copy['revision']!==$copyRevision) throw new RuntimeException('The confirmation wording changed. Reload and review it before confirming.');
        $s=$pdo->prepare('SELECT * FROM people WHERE owner_user_id=? AND is_archived=0 FOR UPDATE');$s->execute([$uid]);$people=$s->fetchAll();
        if (count($people)!==1 || (int)$people[0]['id']!==(int)$state['person_id']) throw new RuntimeException('Your person records need review. Please contact us before closing your account.');
        $person=$people[0];$event=bin2hex(random_bytes(16));
        $meta=['confirmation_version'=>'migration-close-v1','copy_revision'=>$copyRevision,'confirmation_text'=>migrationClosureMessage($copy),'invitation_id'=>(int)$invitation['id'],'user_id'=>$uid,'person_id'=>(int)$person['id'],'requester_email'=>$user['email']];
        $note='Account holder confirmed Close My Account on the migration welcome page. Migration declined; the user and imported person were archived.';
        appendRecordNote($pdo,'user',$uid,$user['email'],'migration_declined',$note,$uid,$event,$meta);
        appendRecordNote($pdo,'person',(int)$person['id'],(string)($person['email']??$user['email']),'migration_declined',$note,$uid,$event,$meta);
        $pdo->prepare('UPDATE people SET is_archived=1,general_email_opt_in=0,ride_notice_opt_in=0,renewal_reminder_opt_in=0 WHERE id=? AND owner_user_id=?')->execute([(int)$person['id'],$uid]);
        $pdo->prepare('UPDATE users SET is_archived=1,general_email_opt_in=0,ride_notice_opt_in=0,renewal_reminder_opt_in=0 WHERE id=? AND is_protected=0')->execute([$uid]);
        $pdo->prepare("UPDATE migration_enrolments SET current_step='declined',password_setup_required=0 WHERE user_id=?")->execute([$uid]);
        $pdo->prepare('UPDATE migration_invitations SET revoked_at=NOW() WHERE user_id=?')->execute([$uid]);
        foreach(['auth_remember_tokens','auth_magic_tokens','auth_password_resets'] as $table) $pdo->prepare("DELETE FROM `$table` WHERE user_id=?")->execute([$uid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
    authInvalidateSession();
}

function migrationHorseFields():array
{
    return ['name'=>'Horse name','owner_name'=>'Owner name','breed'=>'Breed','colour'=>'Colour','sex'=>'Sex','height_cm'=>'Height','qualification_id'=>'Qualification','year_of_birth'=>'Year of birth','passport_number'=>'Passport number'];
}

function migrationHorses(PDO $pdo,int $uid,bool $lock=false):array
{
    $s=$pdo->prepare('SELECT * FROM horses WHERE owner_user_id=? AND id<>1 AND is_archived=0 ORDER BY id'.($lock?' FOR UPDATE':''));$s->execute([$uid]);return $s->fetchAll();
}

function migrationHorseQualifications(PDO $pdo):array
{
    $choices=[];
    foreach($pdo->query("SELECT id,name FROM horse_qualifications WHERE LOWER(TRIM(name)) NOT IN ('platinum','diamond') ORDER BY name") as $row)$choices[(int)$row['id']]=$row['name'];
    return $choices;
}

function migrationHorseHeights():array
{
    $choices=[];
    foreach([102=>'10.0',112=>'11.0',122=>'12.0',127=>'12.2',132=>'13.0',137=>'13.2',142=>'14.0',145=>'14.1',147=>'14.2',150=>'14.3',152=>'15.0',155=>'15.1',157=>'15.2',160=>'15.3',163=>'16.0',165=>'16.1',168=>'16.2',170=>'16.3',173=>'17.0',175=>'17.1',178=>'17.2',180=>'17.3',183=>'18.0'] as $cm=>$hands)$choices[$cm]=$cm.' cm ['.$hands.' hh]';
    return $choices;
}

/** Same required details as horse registration, with field-specific migration warnings. */
function migrationHorseErrors(array $data,array $qualifications):array
{
    $errors=[];$fields=migrationHorseFields();
    foreach($fields as $field=>$label)if(!isset($data[$field]) || !is_scalar($data[$field]) || trim((string)$data[$field])==='')$errors[$field]=$label.' is required.';
    foreach(['name'=>255,'owner_name'=>255,'breed'=>120,'colour'=>120,'passport_number'=>32] as $field=>$limit)if(isset($data[$field]) && is_scalar($data[$field]) && mb_strlen((string)$data[$field])>$limit)$errors[$field]='Use no more than '.$limit.' characters.';
    if(!in_array($data['sex']??null,['Mare','Gelding','Stallion'],true))$errors['sex']='Select Mare, Gelding or Stallion.';
    $height=$data['height_cm']??'';
    if(!is_scalar($height) || !ctype_digit((string)$height) || (int)$height<=0 || (float)$height>4294967295)$errors['height_cm']='Enter a valid positive height in centimetres.';
    $qualification=$data['qualification_id']??'';
    if(!is_scalar($qualification) || !ctype_digit((string)$qualification) || !array_key_exists((int)$qualification,$qualifications))$errors['qualification_id']='Select a valid qualification.';
    $year=$data['year_of_birth']??'';
    if(!is_scalar($year) || !birth_year_is_valid((string)$year))$errors['year_of_birth']='Enter a valid year of birth that is not in the future.';
    return $errors;
}

function migrationHorseHash(array $horse):string
{
    $values=['id'=>(int)$horse['id'],'owner_user_id'=>(int)$horse['owner_user_id']];
    foreach(migrationHorseFields() as $field=>$label)$values[$field]=(string)($horse[$field]??'');
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}

function migrationHorseReviewValid(PDO $pdo,int $uid,array $state,bool $lock=false):bool
{
    return migrationHorseChoicesValid($pdo,$uid,$state,$lock);
}

/** The list's Next button requires a saved choice for each active owned horse. */
function migrationSaveHorses(PDO $pdo,int $uid,array $data):array
{
    migrationAssertOwnSession($pdo,$uid);$pdo->beginTransaction();
    try{
        $state=migrationState($pdo,$uid,true);migrationLockOwner($pdo,$uid);
        if(!migrationPending($state) || $state['current_step']!=='horses')throw new RuntimeException('Your setup step changed. Return to the horse list.');
        $horses=migrationHorses($pdo,$uid,true);$choices=migrationHorseChoices($pdo,$uid,true);$offer=migrationLogbookOffer($pdo,true);$errors=[];
        foreach($horses as $horse)if(!migrationHorseChoiceValid($pdo,$horse,$choices[(int)$horse['id']]??null,$offer))$errors['horse_choice_'.$horse['id']]='Choose an option for this horse before continuing.';
        if($errors){$pdo->rollBack();return $errors;}
        $pdo->prepare("UPDATE migration_enrolments SET current_step='shares',horses_status=?,shares_status='deferred' WHERE user_id=?")->execute([$horses?'reviewed':'none',$uid]);migrationApplyStageOptions($pdo,$uid,migrationState($pdo,$uid));$pdo->commit();return [];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Placeholder progress only; no existing share request or permission is changed. */
function migrationSaveShares(PDO $pdo,int $uid):void
{
    migrationAssertOwnSession($pdo,$uid);$pdo->beginTransaction();
    try{
        $state=migrationState($pdo,$uid,true);migrationLockOwner($pdo,$uid);
        if(!migrationPending($state) || $state['current_step']!=='shares' || (isset(migrationVisibleSteps($pdo,$uid)['horses']) && !migrationHorseReviewValid($pdo,$uid,$state,true)))throw new RuntimeException('Review your horse details before continuing.');
        $pdo->prepare("UPDATE migration_enrolments SET current_step='finish',shares_status='placeholder_seen' WHERE user_id=?")->execute([$uid]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Existing active links and pending requests count as Shares; no permission is changed. */
function migrationShareCount(PDO $pdo, int $uid): int
{
    $count=0;
    foreach (['user_person_links'=>'people','user_horse_links'=>'horses'] as $links=>$records) {
        $idColumn=$records==='people'?'person_id':'horse_id';
        $statement=$pdo->prepare("SELECT COUNT(*) FROM `$links` l JOIN `$records` r ON r.id=l.`$idColumn` JOIN users u ON u.id=l.user_id WHERE (l.user_id=? OR r.owner_user_id=?) AND l.status='approved' AND l.revoked_at IS NULL AND r.is_archived=0 AND u.is_archived=0");
        $statement->execute([$uid,$uid]);$count+=(int)$statement->fetchColumn();
    }
    $statement=$pdo->prepare("SELECT COUNT(*) FROM share_requests WHERE (created_by_user_id=? OR target_user_id=? OR LOWER(target_email)=(SELECT LOWER(email) FROM users WHERE id=?)) AND status='pending' AND (expires_at IS NULL OR expires_at>=NOW())");
    $statement->execute([$uid,$uid,$uid]);return $count+(int)$statement->fetchColumn();
}

function migrationVisibleSteps(PDO $pdo, int $uid): array
{
    $options=onboardingFlowSettings($pdo);$blank=$options['onboarding_show_blank_pages']==='1';
    $steps=['password'=>'Password & user settings','person'=>'Person settings'];
    if ($options['onboarding_horses_enabled']==='1' && ($blank || migrationHorses($pdo,$uid))) $steps['horses']='Horses';
    if ($options['onboarding_shares_enabled']==='1' && ($blank || migrationShareCount($pdo,$uid)>0)) $steps['shares']='Shares';
    $steps['finish']='Finish';return $steps;
}

/** Called with the enrolment locked; hidden stages never fake record reviews. */
function migrationApplyStageOptions(PDO $pdo, int $uid, ?array $state): ?array
{
    if (!migrationPending($state) || !in_array($state['current_step'],['horses','shares','finish'],true)) return $state;
    $steps=migrationVisibleSteps($pdo,$uid);$horseVisible=isset($steps['horses']);$shareVisible=isset($steps['shares']);
    $current=$state['current_step'];$horseStatus=$state['horses_status'];$shareStatus=$state['shares_status'];
    if (!$horseVisible) {
        $horseStatus='skipped';
        if ($current==='horses') $current=$shareVisible?'shares':'finish';
    } elseif ($current!=='horses' && !migrationHorseReviewValid($pdo,$uid,$state,true)) {
        $current='horses';$horseStatus='pending';$shareStatus='deferred';
    }
    if (!$shareVisible) {
        $shareStatus='skipped';
        if ($current==='shares') $current='finish';
    } elseif ($current==='finish' && $shareStatus!=='placeholder_seen') {
        $current='shares';
    }
    $pdo->prepare('UPDATE migration_enrolments SET current_step=?,horses_status=?,shares_status=? WHERE user_id=?')->execute([$current,$horseStatus,$shareStatus,$uid]);
    return array_replace($state,['current_step'=>$current,'horses_status'=>$horseStatus,'shares_status'=>$shareStatus]);
}

/** Refresh active journeys only; completed accounts are never reopened. */
function migrationRefreshStages(PDO $pdo,int $uid):?array
{
    migrationAssertOwnSession($pdo,$uid);$pdo->beginTransaction();
    try {
        $state=migrationApplyStageOptions($pdo,$uid,migrationState($pdo,$uid,true));
        $pdo->commit();return $state;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function migrationLockOwner(PDO $pdo,int $uid):void
{
    $s=$pdo->prepare('SELECT password_hash FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([$uid]);$hash=$s->fetchColumn();
    if(!$hash || !hash_equals((string)($_SESSION['auth_credential']['stamp']??''),hash('sha256','password-v1:'.$hash)))throw new RuntimeException('Sign in again before saving your setup.');
}

require_once __DIR__.'/migration_horse_choices.php';

/** Schedule a repeat journey without changing the account's operational records. */
function migrationResetOnboarding(PDO $pdo, array $actor, int $uid, int $expectedPersonId): void
{
    require_once __DIR__.'/import_workspace.php';
    if (!empty($_SESSION['act_as_original_user'])) throw new RuntimeException('Exit impersonation before resetting onboarding.');
    importWorkspaceAuthorize($pdo,$actor,importWorkspaceConfig(),strtolower((string)($_SERVER['HTTP_HOST']??'')));
    ensureMigrationTables($pdo);
    $pdo->beginTransaction();
    try {
        $statement=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$statement->execute([$uid]);
        if (!$statement->fetchColumn()) throw new RuntimeException('Account unavailable.');
        $user=migrationRecipient($pdo,$uid);$person=migrationPilotPerson($pdo,$user);
        if ((int)$person['id']!==$expectedPersonId) throw new RuntimeException('The Person mapping changed. Reload before resetting onboarding.');
        $before=migrationState($pdo,$uid,true);$delivery=migrationDeliveryCounts($pdo,$uid);
        $pdo->prepare('INSERT IGNORE INTO migration_enrolments(user_id,person_id) VALUES (?,?)')->execute([$uid,(int)$person['id']]);
        $pdo->prepare("UPDATE migration_enrolments SET person_id=?,current_step='restart',password_setup_required=1,started_at=NULL,password_set_at=NULL,user_confirmed_at=NULL,person_confirmed_at=NULL,user_preferences_json=NULL,person_preferences_json=NULL,horses_status='pending',shares_status='deferred',completed_at=NULL WHERE user_id=?")->execute([(int)$person['id'],$uid]);
        foreach(['migration_horse_reviews','migration_horse_choices'] as $table)$pdo->prepare("DELETE FROM `$table` WHERE user_id=?")->execute([$uid]);
        // Keep historical sends. Old uncertain/pending links are explicitly retired
        // by this confirmed rehearsal reset; delivery details remain in Email history.
        $pdo->prepare("UPDATE migration_invitations SET revoked_at=COALESCE(revoked_at,NOW()),delivery_status=CASE WHEN delivery_status IN ('pending','uncertain') THEN 'reset' ELSE delivery_status END WHERE user_id=?")->execute([$uid]);
        appendRecordNote($pdo,'user',$uid,$user['email'],'migration_onboarding_reset','Onboarding reset for training/testing; restart at next sign-in. Account, People, Horses, sharing permissions and financial records retained.',(int)$actor['id'],bin2hex(random_bytes(16)),['previous_step'=>$before['current_step']??null,'previous_completed_at'=>$before['completed_at']??null,'person_id'=>(int)$person['id'],'previous_delivery_counts'=>$delivery]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** A verified sign-in (including a remembered login) activates only scheduled resets. */
function migrationActivateScheduledRestart(PDO $pdo, int $uid): void
{
    $pdo->prepare("UPDATE migration_enrolments SET current_step='password',started_at=NOW() WHERE user_id=? AND current_step='restart' AND completed_at IS NULL")->execute([$uid]);
}
