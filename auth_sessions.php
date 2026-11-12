<?php
declare(strict_types=1);

/** Private session binding: never put password hashes into public user arrays. */
function authBindCredentialSession(int $userId, string $passwordHash): void
{
    $_SESSION['auth_credential']=['user_id'=>$userId,'stamp'=>hash('sha256','password-v1:'.$passwordHash)];
    unset($_SESSION['act_as_original_user'],$_SESSION['act_as_started_at'],$_SESSION['pending_auth_app_secret']);
}

function authInvalidateSession(): void
{
    $_SESSION=[];
    unset($_COOKIE['ildra_remember']);
    clear_remember_me_cookie();
    if (session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

/** Validate the authenticating principal, including the original administrator while acting-as. */
function authValidateSession(?PDO $pdo, array &$alerts): bool
{
    $user=$_SESSION['act_as_original_user'] ?? $_SESSION['user'] ?? null;
    if (!$user) return true;
    $binding=$_SESSION['auth_credential'] ?? [];
    $valid=false;
    try {
        if ($pdo && (int)($binding['user_id']??0)===(int)($user['id']??0) && !empty($binding['stamp'])) {
            $stmt=$pdo->prepare('SELECT password_hash FROM users WHERE id=? AND is_archived=0');
            $stmt->execute([(int)$user['id']]);$hash=$stmt->fetchColumn();
            $valid=$hash!==false && hash_equals((string)$binding['stamp'],hash('sha256','password-v1:'.(string)$hash));
        }
    } catch (Throwable $e) { $valid=false; }
    if (!$valid) {
        authInvalidateSession();
        $alerts[]=['type'=>'warning','message'=>'Your sign-in has expired. Please log in again.'];
    }
    return $valid;
}

/** Atomically replace the credential and revoke all persistent ways back into this account. */
function authReplacePassword(PDO $pdo, int $userId, string $hash, ?string $expectedHash=null): void
{
    $ownsTransaction=!$pdo->inTransaction();
    if ($ownsTransaction) {
        ensureAuthTables($pdo);ensurePasswordResetTable($pdo); // DDL before transaction.
        $pdo->beginTransaction();
    }
    try {
        $sql='UPDATE users SET password_hash=:hash,updated_at=NOW() WHERE id=:id AND is_archived=0';
        $params=[':hash'=>$hash,':id'=>$userId];
        if ($expectedHash!==null) { $sql.=' AND BINARY password_hash=:previous';$params[':previous']=$expectedHash; }
        $stmt=$pdo->prepare($sql);$stmt->execute($params);
        if ($stmt->rowCount()!==1) throw new RuntimeException('The account changed. Please log in again before changing its password.');
        foreach(['auth_remember_tokens','auth_magic_tokens','auth_password_resets'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE user_id=?")->execute([$userId]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
