<?php
declare(strict_types=1);

function authAppSecurityPolicy(): array { return ['setup_verification_seconds'=>600]; }

function authAppSecurityState(array $user): string
{
    return hash('sha256',json_encode([(string)$user['password_hash'],!empty($user['auth_app_enabled']),(string)($user['auth_app_secret']??'')],JSON_THROW_ON_ERROR));
}

function authAppClearPendingSetup(): void
{
    unset($_SESSION['pending_auth_app_secret'],$_SESSION['auth_app_setup_verification']);
}

/** Signed-in identity, shared brute-force limits and row lock apply to every account-authenticator mutation. */
function authAppSecurityRun(?PDO $pdo, int $userId, callable $operation, array &$alerts, string $failureMessage = 'Verification failed. Enter your current password or a valid code from your existing authenticator, then try again.'): mixed
{
    if (!$pdo || $userId<=0 || (int)($_SESSION['user']['id']??0)!==$userId || !empty($_SESSION['act_as_original_user'])) {
        $alerts[]=['type'=>'danger','message'=>'Log in to your own account before changing its authenticator.'];return null;
    }
    if (!authValidateSession($pdo,$alerts)) return null;
    try {
        ensureAuthAppColumns($pdo);
        $stmt=$pdo->prepare('SELECT email FROM users WHERE id=? AND is_archived=0');$stmt->execute([$userId]);$email=$stmt->fetchColumn();
        if (!$email) throw new RuntimeException('Account unavailable.');
        $result=authThrottleRun($pdo,'login',(string)$email,authThrottleIp(),static function()use($pdo,$userId,$operation) {
            $stmt=$pdo->prepare('SELECT id,email,password_hash,auth_app_enabled,auth_app_secret FROM users WHERE id=? AND is_archived=0 FOR UPDATE');
            $stmt->execute([$userId]);$user=$stmt->fetch();
            // Recheck under lock in case the password changed after request/session validation.
            if (!$user || !hash_equals((string)($_SESSION['auth_credential']['stamp']??''),hash('sha256','password-v1:'.(string)$user['password_hash']))) return null;
            return $operation($pdo,$user);
        });
        if ($result['allowed'] && $result['value']) return $result['value'];
        $alerts[]=['type'=>'danger','message'=>$result['retry_after']>0 ? authThrottleRetryMessage($result['retry_after']) : $failureMessage];
    } catch (Throwable $e) {
        $alerts[]=['type'=>'danger','message'=>'Could not verify this security change. Please try again in a few minutes.'];
    }
    return null;
}

function authAppFreshProofValid(array $user): bool
{
    $password=is_string($_POST['auth_password']??null)?$_POST['auth_password']:'';
    $code=is_string($_POST['auth_existing_code']??null)?$_POST['auth_existing_code']:'';
    if ($password!=='' && !empty($user['password_hash']) && password_verify($password,(string)$user['password_hash'])) return true;
    return $code!=='' && !empty($user['auth_app_enabled']) && !empty($user['auth_app_secret']) && auth_app_verify_code((string)$user['auth_app_secret'],$code);
}

function beginAuthAppSetup(?PDO $pdo, array $currentUser, array $siteSettings, array &$alerts): array
{
    authAppClearPendingSetup();
    $proof=authAppSecurityRun($pdo,(int)($currentUser['id']??0),static function(PDO $pdo,array $user) {
        return authAppFreshProofValid($user) ? ['state'=>authAppSecurityState($user)] : null;
    },$alerts);
    if (!$proof) return [];
    session_regenerate_id(true);
    $_SESSION['pending_auth_app_secret']=auth_app_generate_secret();
    $_SESSION['auth_app_setup_verification']=[
        'user_id'=>(int)$currentUser['id'],'state'=>$proof['state'],
        'session_stamp'=>hash('sha256',session_id()),
        'expires_at'=>time()+authAppSecurityPolicy()['setup_verification_seconds'],
    ];
    return pendingAuthAppSetup($currentUser,$siteSettings) ?: [];
}

function authAppSetupVerificationValid(int $userId): bool
{
    $proof=$_SESSION['auth_app_setup_verification']??[];
    return (int)($proof['user_id']??0)===$userId && (int)($proof['expires_at']??0)>time()
        && hash_equals((string)($proof['session_stamp']??''),hash('sha256',session_id()))
        && !empty($proof['state']) && !empty($_SESSION['pending_auth_app_secret']);
}

function pendingAuthAppSetup(array $currentUser, array $siteSettings): ?array
{
    if (!authAppSetupVerificationValid((int)($currentUser['id']??0))) { authAppClearPendingSetup();return null; }
    $secret=(string)$_SESSION['pending_auth_app_secret'];
    $uri=auth_app_otpauth_uri($currentUser,$secret,$siteSettings);
    return ['secret'=>$secret,'formatted_secret'=>auth_app_format_secret($secret),'otpauth_uri'=>$uri,'qr_data_uri'=>auth_app_qr_data_uri($uri)];
}

function confirmAuthAppSetup(?PDO $pdo, int $userId, array &$alerts): bool
{
    if (!authAppSetupVerificationValid($userId)) {
        authAppClearPendingSetup();
        $alerts[]=['type'=>'danger','message'=>'Setup verification expired. Verify your current password or existing authenticator and start setup again.'];return false;
    }
    $secret=(string)$_SESSION['pending_auth_app_secret'];$proof=$_SESSION['auth_app_setup_verification'];
    $code=is_string($_POST['code']??null)?$_POST['code']:'';
    $result=authAppSecurityRun($pdo,$userId,static function(PDO $pdo,array $user)use($secret,$proof,$code) {
        if (!authAppSetupVerificationValid((int)$user['id']) || !hash_equals((string)$proof['state'],authAppSecurityState($user))) { authAppClearPendingSetup();return null; }
        if (!auth_app_verify_code($secret,$code)) return null;
        $stmt=$pdo->prepare('UPDATE users SET auth_app_secret=?,auth_app_enabled=1,auth_app_confirmed_at=NOW(),updated_at=NOW() WHERE id=? AND is_archived=0');
        $stmt->execute([$secret,(int)$user['id']]);return ['confirmed'=>true];
    },$alerts,'That code or setup verification is no longer valid. Check the new authenticator code, or start setup again.');
    if (!$result) return false;
    authAppClearPendingSetup();session_regenerate_id(true);return true;
}

function disableAuthApp(?PDO $pdo, int $userId, array &$alerts): bool
{
    $result=authAppSecurityRun($pdo,$userId,static function(PDO $pdo,array $user) {
        if (!authAppFreshProofValid($user)) return null;
        $pdo->prepare('UPDATE users SET auth_app_secret=NULL,auth_app_enabled=0,auth_app_confirmed_at=NULL,updated_at=NOW() WHERE id=? AND is_archived=0')->execute([(int)$user['id']]);
        return ['disabled'=>true];
    },$alerts);
    if (!$result) return false;
    authAppClearPendingSetup();session_regenerate_id(true);return true;
}
