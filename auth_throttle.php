<?php
declare(strict_types=1);

/** Central policy; a future Settings UI can supply these values through this boundary. */
function authThrottlePolicy(string $scope): array
{
    $policies = [
        'login' => ['failures_only'=>true, 'email'=>['limit'=>5,'window'=>900,'cooldown'=>900], 'ip'=>['limit'=>5,'window'=>900,'cooldown'=>900]],
        'password_reset' => ['failures_only'=>false, 'email'=>['limit'=>1,'window'=>60,'cooldown'=>60], 'ip'=>['limit'=>5,'window'=>900,'cooldown'=>900]],
        'magic_link' => ['failures_only'=>false, 'email'=>['limit'=>1,'window'=>60,'cooldown'=>60], 'ip'=>['limit'=>5,'window'=>900,'cooldown'=>900]],
    ];
    if (!isset($policies[$scope])) throw new InvalidArgumentException('Unknown authentication throttle scope.');
    return $policies[$scope];
}

function ensureAuthThrottleTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS auth_throttle (
    scope VARCHAR(32) NOT NULL,
    subject_kind VARCHAR(8) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject_kind, subject_hash),
    INDEX idx_auth_throttle_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );
}

function authThrottleEmail(string $email): string
{
    return strtolower(trim($email));
}

function authThrottleIp(): string
{
    // Do not trust client-supplied forwarding headers. Trusted proxy/real-IP processing belongs at the web server.
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $packed = @inet_pton($address);
    return $packed === false ? 'unknown' : (string)inet_ntop($packed);
}

/** Serialize each email/IP pair through verification or email reservation, across sessions/workers. */
function authThrottleRun(PDO $pdo, string $scope, string $email, string $ip, callable $operation, ?int $now = null): array
{
    $policy = authThrottlePolicy($scope);
    if ($pdo->inTransaction()) throw new LogicException('Authentication throttling requires its own transaction.');
    ensureAuthThrottleTable($pdo); // DDL must precede the transaction.
    $now ??= time();
    // Bounded cleanup keeps expired identities/IPs from accumulating indefinitely.
    $cleanup=$pdo->prepare('DELETE FROM auth_throttle WHERE expires_at <= ? ORDER BY expires_at LIMIT 100');
    $cleanup->execute([$now]);
    $subjects = ['email'=>authThrottleEmail($email),'ip'=>$ip];
    $rows = [];
    $pdo->beginTransaction();
    try {
        $retry = 0;
        foreach ($subjects as $kind=>$subject) {
            $hash = hash('sha256', $kind . ':' . $subject);
            $insert = $pdo->prepare('INSERT INTO auth_throttle(scope,subject_kind,subject_hash,window_start,expires_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE subject_hash=VALUES(subject_hash)');
            $insert->execute([$scope,$kind,$hash,$now,$now+$policy[$kind]['window']]);
            $stmt = $pdo->prepare('SELECT * FROM auth_throttle WHERE scope=? AND subject_kind=? AND subject_hash=? FOR UPDATE');
            $stmt->execute([$scope,$kind,$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)$row['blocked_until'] > $now) {
                $retry = max($retry,(int)$row['blocked_until']-$now);
            } elseif ((int)$row['blocked_until'] > 0 || $now >= (int)$row['window_start']+$policy[$kind]['window']) {
                $row['attempts']=0;$row['blocked_until']=0;$row['window_start']=$now;
            }
            $rows[$kind]=$row;
        }
        if ($retry > 0) {
            $pdo->commit();
            return ['allowed'=>false,'value'=>null,'retry_after'=>$retry];
        }
        $value = $operation();
        $failed = $policy['failures_only'] && !$value;
        foreach ($rows as $kind=>$row) {
            if (!$policy['failures_only'] || $failed) {
                $row['attempts']=(int)$row['attempts']+1;
                if ($row['attempts'] >= $policy[$kind]['limit']) $row['blocked_until']=$now+$policy[$kind]['cooldown'];
            } elseif ($kind === 'email') {
                // A valid credential resets this identity, never the IP's failures against other accounts.
                $row['attempts']=0;$row['blocked_until']=0;$row['window_start']=$now;
            }
            $expiry=max((int)$row['blocked_until'],(int)$row['window_start']+$policy[$kind]['window']);
            $stmt=$pdo->prepare('UPDATE auth_throttle SET attempts=?,window_start=?,blocked_until=?,expires_at=? WHERE scope=? AND subject_kind=? AND subject_hash=?');
            $stmt->execute([$row['attempts'],$row['window_start'],$row['blocked_until'],$expiry,$scope,$kind,$row['subject_hash']]);
            if ($failed) $retry=max($retry,(int)$row['blocked_until']-$now);
        }
        $pdo->commit();
        return ['allowed'=>true,'value'=>$value,'retry_after'=>max(0,$retry)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e; // Callers fail closed; no email/credential bypass on storage failure.
    }
}

function authThrottleRetryMessage(int $seconds): string
{
    $minutes=max(1,(int)ceil($seconds/60));
    return 'Too many attempts. Please wait ' . $minutes . ($minutes===1 ? ' minute' : ' minutes') . ' before trying again. This temporary limit clears automatically.';
}

/** Reserve before user lookup, token creation or email sending, including unknown email addresses. */
function authThrottleEmailRequest(PDO $pdo, string $scope, string $email, array &$alerts): bool
{
    try {
        $result=authThrottleRun($pdo,$scope,$email,authThrottleIp(),static fn()=>true);
        if ($result['allowed']) return true;
        $alerts[]=['type'=>'warning','message'=>authThrottleRetryMessage($result['retry_after'])];
    } catch (Throwable $e) {
        $alerts[]=['type'=>'danger','message'=>'Requests are temporarily unavailable. Please try again in a few minutes.'];
    }
    return false;
}
