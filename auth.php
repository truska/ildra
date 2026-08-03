<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ensureAuthTables(PDO $pdo): void
{
    // Persistent login ("remember me") tokens.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            selector VARCHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            user_agent VARCHAR(255) NULL,
            ip_address VARCHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_selector (selector),
            KEY idx_user (user_id),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // One-time login links ("magic link") tokens.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_magic_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            selector VARCHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            ip_address VARCHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_selector (selector),
            KEY idx_user (user_id),
            KEY idx_expires (expires_at),
            KEY idx_used (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function tidyAuthTokens(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }
    try {
        ensureAuthTables($pdo);
        $now = auth_now_string();
        $magicStmt = $pdo->prepare("DELETE FROM auth_magic_tokens WHERE expires_at < :now OR used_at IS NOT NULL");
        $magicStmt->execute([':now' => $now]);
        $rememberStmt = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE expires_at < :now");
        $rememberStmt->execute([':now' => $now]);
    } catch (PDOException $e) {
        // ignore
    }
}

function auth_base_path(): string
{
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($basePath === '/') {
        $basePath = '';
    }
    if (substr($basePath, -6) === '/admin') {
        $basePath = rtrim(substr($basePath, 0, -6), '/');
    }
    return $basePath === '' ? '/' : $basePath . '/';
}

function auth_cookie_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function auth_now_ts(): int
{
    return time();
}

function auth_now_string(): string
{
    return date('Y-m-d H:i:s', auth_now_ts());
}

function auth_future_string(int $ttlSeconds): string
{
    return date('Y-m-d H:i:s', auth_now_ts() + $ttlSeconds);
}

function auth_is_expired(?string $dateTime, ?int $nowTs = null): bool
{
    $raw = trim((string)$dateTime);
    if ($raw === '') {
        return true;
    }
    $expiresTs = strtotime($raw);
    if ($expiresTs === false) {
        return true;
    }
    return $expiresTs < ($nowTs ?? auth_now_ts());
}

function ensurePasswordResetTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            selector VARCHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            user_agent VARCHAR(255) NULL,
            ip_address VARCHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_selector (selector),
            KEY idx_user (user_id),
            KEY idx_expires (expires_at),
            KEY idx_used (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

function set_remember_me_cookie(string $value, int $ttlSeconds): void
{
    $path = auth_base_path();
    $secure = auth_cookie_secure();
    $ttlSeconds = max(0, $ttlSeconds);
    setcookie('ildra_remember', $value, [
        'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_me_cookie(): void
{
    $path = auth_base_path();
    $secure = auth_cookie_secure();
    setcookie('ildra_remember', '', [
        'expires' => time() - 3600,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function issueRememberMeToken(PDO $pdo, int $userId, int $ttlSeconds): void
{
    ensureAuthTables($pdo);

    // Clamp to sensible range: 1 hour to 1 year.
    $ttlSeconds = max(3600, min(31536000, $ttlSeconds));

    $selector = base64url_encode(random_bytes(9));
    $validator = base64url_encode(random_bytes(24));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = auth_future_string($ttlSeconds);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

    $stmt = $pdo->prepare("
        INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at, user_agent, ip_address)
        VALUES (:uid, :sel, :hash, :exp, :ua, :ip)
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':sel' => $selector,
        ':hash' => $tokenHash,
        ':exp' => $expiresAt,
        ':ua' => $ua ?: null,
        ':ip' => $ip ?: null,
    ]);

    set_remember_me_cookie($selector . ':' . $validator, $ttlSeconds);
}

function rotateRememberMeToken(PDO $pdo, int $tokenId, string $selector, int $ttlSeconds): void
{
    // Clamp to sensible range: 1 hour to 1 year.
    $ttlSeconds = max(3600, min(31536000, $ttlSeconds));

    $newValidator = base64url_encode(random_bytes(24));
    $newHash = hash('sha256', $newValidator);
    $expiresAt = auth_future_string($ttlSeconds);
    $now = auth_now_string();

    $stmt = $pdo->prepare("
        UPDATE auth_remember_tokens
        SET token_hash = :hash, expires_at = :exp, last_used_at = :last_used_at
        WHERE id = :id
    ");
    $stmt->execute([
        ':hash' => $newHash,
        ':exp' => $expiresAt,
        ':last_used_at' => $now,
        ':id' => $tokenId,
    ]);

    set_remember_me_cookie($selector . ':' . $newValidator, $ttlSeconds);
}

function attemptRememberMeLogin(PDO $pdo, array $siteSettings, array &$alerts): ?array
{
    $cookie = (string)($_COOKIE['ildra_remember'] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return null;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    $selector = trim($selector);
    $validator = trim($validator);
    if ($selector === '' || $validator === '') {
        clear_remember_me_cookie();
        return null;
    }

    ensureAuthTables($pdo);
    tidyAuthTokens($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, token_hash, expires_at
            FROM auth_remember_tokens
            WHERE selector = :sel
            LIMIT 1
        ");
        $stmt->execute([':sel' => $selector]);
        $row = $stmt->fetch();
        if (!$row) {
            clear_remember_me_cookie();
            return null;
        }

        if (auth_is_expired((string)($row['expires_at'] ?? null))) {
            $del = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            clear_remember_me_cookie();
            return null;
        }

        $expectedHash = (string)($row['token_hash'] ?? '');
        $actualHash = hash('sha256', $validator);
        if (!hash_equals($expectedHash, $actualHash)) {
            // Potential token theft/guess: remove this token and clear cookie.
            $del = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            clear_remember_me_cookie();
            return null;
        }

        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            clear_remember_me_cookie();
            return null;
        }

        $userStmt = $pdo->prepare("
            SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name, u.last_login_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            $del = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            clear_remember_me_cookie();
            return null;
        }

        $user['level'] = (int)($user['level'] ?? 0);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;

        $ttlSeconds = (int)($siteSettings['remember_me_ttl_seconds'] ?? (30 * 86400));
        rotateRememberMeToken($pdo, (int)$row['id'], $selector, $ttlSeconds);
        return $user;
    } catch (PDOException $e) {
        // Fail closed: don't log the user in from cookie.
        $alerts[] = ['type' => 'danger', 'message' => 'Could not validate remembered login.'];
        clear_remember_me_cookie();
        return null;
    }
}

function createPasswordResetToken(PDO $pdo, int $userId, int $ttlSeconds = 3600): string
{
    ensurePasswordResetTable($pdo);
    $ttlSeconds = max(900, min(86400, $ttlSeconds)); // 15 minutes to 24 hours
    $selector = base64url_encode(random_bytes(9));
    $validator = base64url_encode(random_bytes(24));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = auth_future_string($ttlSeconds);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $now = auth_now_string();

    // Invalidate prior reset tokens for this user.
    $del = $pdo->prepare("DELETE FROM auth_password_resets WHERE user_id = :uid OR expires_at < :now OR used_at IS NOT NULL");
    $del->execute([':uid' => $userId, ':now' => $now]);

    $stmt = $pdo->prepare("
        INSERT INTO auth_password_resets (user_id, selector, token_hash, expires_at, user_agent, ip_address)
        VALUES (:uid, :sel, :hash, :exp, :ua, :ip)
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':sel' => $selector,
        ':hash' => $tokenHash,
        ':exp' => $expiresAt,
        ':ua' => $ua ?: null,
        ':ip' => $ip ?: null,
    ]);
    return $selector . ':' . $validator;
}

function consumePasswordResetToken(PDO $pdo, string $token): ?int
{
    if (strpos($token, ':') === false) {
        return null;
    }
    [$selector, $validator] = explode(':', $token, 2);
    $selector = trim($selector);
    $validator = trim($validator);
    if ($selector === '' || $validator === '') {
        return null;
    }
    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, token_hash, expires_at, used_at
            FROM auth_password_resets
            WHERE selector = :sel
            LIMIT 1
        ");
        $stmt->execute([':sel' => $selector]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!empty($row['used_at']) || auth_is_expired((string)($row['expires_at'] ?? null))) {
            $del = $pdo->prepare("DELETE FROM auth_password_resets WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            return null;
        }
        $expectedHash = (string)($row['token_hash'] ?? '');
        $actualHash = hash('sha256', $validator);
        if (!hash_equals($expectedHash, $actualHash)) {
            $del = $pdo->prepare("DELETE FROM auth_password_resets WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            return null;
        }
        $mark = $pdo->prepare("UPDATE auth_password_resets SET used_at = :used_at WHERE id = :id");
        $mark->execute([
            ':used_at' => auth_now_string(),
            ':id' => (int)$row['id'],
        ]);
        return (int)$row['user_id'];
    } catch (PDOException $e) {
        return null;
    }
}

function inspectPasswordResetToken(PDO $pdo, string $token): ?array
{
    if (strpos($token, ':') === false) {
        return null;
    }
    [$selector, $validator] = explode(':', $token, 2);
    $selector = trim($selector);
    $validator = trim($validator);
    if ($selector === '' || $validator === '') {
        return null;
    }
    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT pr.id, pr.user_id, pr.token_hash, pr.expires_at, pr.used_at, u.email
            FROM auth_password_resets pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.selector = :sel
            LIMIT 1
        ");
        $stmt->execute([':sel' => $selector]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!empty($row['used_at']) || auth_is_expired((string)($row['expires_at'] ?? null))) {
            return null;
        }
        $expectedHash = (string)($row['token_hash'] ?? '');
        $actualHash = hash('sha256', $validator);
        if (!hash_equals($expectedHash, $actualHash)) {
            return null;
        }
        return [
            'user_id' => (int)$row['user_id'],
            'email' => (string)($row['email'] ?? ''),
        ];
    } catch (PDOException $e) {
        return null;
    }
}

function handlePasswordResetRequest(?PDO $pdo, array &$alerts, ?string &$successMessage): void
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return;
    }
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Please provide a valid email address.'];
        return;
    }

    $successMessage = 'If that email is registered, we’ve sent password reset instructions.';

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }
        require_once __DIR__ . '/email.php';
        $token = createPasswordResetToken($pdo, (int)$user['id'], 3600);

        $basePath = rtrim(auth_base_path(), '/');
        $scheme = auth_cookie_secure() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $url = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . '/account?reset=' . rawurlencode($token);
        $siteSettings = getSiteSettings($pdo);
        $emailSettings = getEmailSettings($pdo);
        $brandName = trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));

        $subject = 'Reset your password';
        $html = wrap_user_email_html(
            $siteSettings,
            $emailSettings,
            '<div style="font-size:16px;font-weight:800;color:#0c2a12;">Reset your password</div>'
            . '<div style="margin-top:10px;color:#476146;line-height:1.6;">We received a request to reset your password for ' . h($brandName) . '.</div>'
            . '<div style="margin-top:8px;color:#476146;line-height:1.6;">This secure link expires in 1 hour and can only be used once.</div>'
            . '<div style="margin-top:18px;">' . email_cta_button_html($url, 'Reset password') . '</div>'
            . '<div style="margin-top:18px;color:#476146;font-size:13px;line-height:1.6;">If you didn’t request this, you can ignore this email.</div>'
        );
        $text = wrap_user_email_text(
            $siteSettings,
            $emailSettings,
            "Reset your password\n\nWe received a request to reset your password for {$brandName}.\n\nUse this secure link within 1 hour:\n{$url}\n\nIf you didn’t request this, you can ignore this email."
        );

        send_logged_email($pdo, $email, $subject, $html, $text, ['kind' => 'password_reset']);
    } catch (Throwable $e) {
        // do not block flow
    }
}

function handlePasswordReset(?PDO $pdo, array &$alerts, ?string &$successMessage): ?array
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return null;
    }
    $token = trim((string)($_POST['token'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($password === '' || strlen($password) < 8) {
        $alerts[] = ['type' => 'danger', 'message' => 'Password must be at least 8 characters.'];
        return null;
    }
    if ($password !== $confirm) {
        $alerts[] = ['type' => 'danger', 'message' => 'Password confirmation does not match.'];
        return null;
    }
    $userId = consumePasswordResetToken($pdo, $token);
    if (!$userId) {
        $alerts[] = ['type' => 'danger', 'message' => 'Reset link is invalid or expired. Request a new one.'];
        return null;
    }
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = :ph, updated_at = NOW() WHERE id = :id LIMIT 1");
        $stmt->execute([':ph' => $hash, ':id' => $userId]);
        // Log the user in
        $userStmt = $pdo->prepare("
            SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch();
        if ($user) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'level' => (int)$user['level'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
            ];
        }
        $successMessage = 'Password updated. You are now signed in.';
        return $_SESSION['user'] ?? null;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not reset password. Please try again.'];
        return null;
    }
}

function createMagicLoginToken(PDO $pdo, int $userId, int $ttlSeconds = 900): string
{
    ensureAuthTables($pdo);
    $ttlSeconds = max(60, min(3600, $ttlSeconds));

    $selector = base64url_encode(random_bytes(9));
    $validator = base64url_encode(random_bytes(24));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = auth_future_string($ttlSeconds);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

    $stmt = $pdo->prepare("
        INSERT INTO auth_magic_tokens (user_id, selector, token_hash, expires_at, ip_address)
        VALUES (:uid, :sel, :hash, :exp, :ip)
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':sel' => $selector,
        ':hash' => $tokenHash,
        ':exp' => $expiresAt,
        ':ip' => $ip ?: null,
    ]);

    return $selector . ':' . $validator;
}

function consumeMagicLoginToken(PDO $pdo, string $token, array &$alerts): ?array
{
    if (strpos($token, ':') === false) {
        $alerts[] = ['type' => 'danger', 'message' => 'That sign-in link is not valid.'];
        return null;
    }
    [$selector, $validator] = explode(':', $token, 2);
    $selector = trim($selector);
    $validator = trim($validator);
    if ($selector === '' || $validator === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'That sign-in link is not valid.'];
        return null;
    }

    ensureAuthTables($pdo);
    tidyAuthTokens($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, token_hash, expires_at, used_at
            FROM auth_magic_tokens
            WHERE selector = :sel
            LIMIT 1
        ");
        $stmt->execute([':sel' => $selector]);
        $row = $stmt->fetch();
        if (!$row) {
            $alerts[] = ['type' => 'danger', 'message' => 'That sign-in link has expired.'];
            return null;
        }
        if (!empty($row['used_at']) || auth_is_expired((string)($row['expires_at'] ?? null))) {
            $del = $pdo->prepare("DELETE FROM auth_magic_tokens WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            $alerts[] = ['type' => 'danger', 'message' => 'That sign-in link has expired.'];
            return null;
        }

        $expectedHash = (string)($row['token_hash'] ?? '');
        $actualHash = hash('sha256', $validator);
        if (!hash_equals($expectedHash, $actualHash)) {
            $del = $pdo->prepare("DELETE FROM auth_magic_tokens WHERE id = :id");
            $del->execute([':id' => (int)$row['id']]);
            $alerts[] = ['type' => 'danger', 'message' => 'That sign-in link is not valid.'];
            return null;
        }

        // Mark used (one-time).
        $upd = $pdo->prepare("UPDATE auth_magic_tokens SET used_at = :used_at WHERE id = :id");
        $upd->execute([
            ':used_at' => auth_now_string(),
            ':id' => (int)$row['id'],
        ]);

        $userId = (int)($row['user_id'] ?? 0);
        $userStmt = $pdo->prepare("
            SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name, u.last_login_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            $alerts[] = ['type' => 'danger', 'message' => 'Could not sign you in.'];
            return null;
        }

        $user['level'] = (int)($user['level'] ?? 0);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        return $user;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not sign you in right now.'];
        return null;
    }
}


function ensureAuthAppColumns(PDO $pdo): void
{
    $columns = [];
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
    } catch (PDOException $e) {
        return;
    }
    if (empty($columns['auth_app_secret'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN auth_app_secret VARCHAR(64) NULL AFTER password_hash");
    }
    if (empty($columns['auth_app_enabled'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN auth_app_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER auth_app_secret");
    }
    if (empty($columns['auth_app_confirmed_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN auth_app_confirmed_at DATETIME NULL AFTER auth_app_enabled");
    }
}

function auth_app_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $out = '';
    for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    for ($i = 0, $len = strlen($bits); $i < $len; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function auth_app_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper((string)preg_replace('/[^A-Z2-7]/i', '', $secret));
    $bits = '';
    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $pos = strpos($alphabet, $secret[$i]);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $len = strlen($bits) - 7; $i < $len; $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function auth_app_generate_secret(): string
{
    return auth_app_base32_encode(random_bytes(20));
}

function auth_app_format_secret(string $secret): string
{
    return trim(chunk_split(strtoupper($secret), 4, ' '));
}

function auth_app_totp_code(string $secret, ?int $time = null): string
{
    $time = $time ?? time();
    $counter = intdiv($time, 30);
    $key = auth_app_base32_decode($secret);
    if ($key === '') {
        return '';
    }
    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function auth_app_verify_code(string $secret, string $code): bool
{
    $code = (string)preg_replace('/\D+/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    $now = time();
    for ($step = -1; $step <= 1; $step++) {
        if (hash_equals(auth_app_totp_code($secret, $now + ($step * 30)), $code)) {
            return true;
        }
    }
    return false;
}

function auth_app_otpauth_uri(array $user, string $secret, array $siteSettings): string
{
    $issuer = trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));
    $issuer = $issuer !== '' ? $issuer : 'ILDRA';
    $email = (string)($user['email'] ?? 'account');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

function auth_app_qr_data_uri(string $otpauthUri): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $png = @shell_exec('qrencode -o - -t PNG ' . escapeshellarg($otpauthUri));
    if (!is_string($png) || $png === '') {
        return '';
    }
    return 'data:image/png;base64,' . base64_encode($png);
}

function fetchLoginUserByEmail(?PDO $pdo, string $email): ?array
{
    if (!$pdo || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    try {
        ensureAuthAppColumns($pdo);
        $stmt = $pdo->prepare("SELECT id, email, password_hash, auth_app_secret, auth_app_enabled FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function loginMethodState(?PDO $pdo, string $email, array $siteSettings): array
{
    $user = fetchLoginUserByEmail($pdo, $email);
    $authAppSiteEnabled = !empty($siteSettings['auth_app_login_enabled']) && (string)$siteSettings['auth_app_login_enabled'] !== '0';
    return [
        'email' => $email,
        'user_exists' => (bool)$user,
        'password' => $user && (string)($user['password_hash'] ?? '') !== '',
        'auth_app' => $authAppSiteEnabled && $user && !empty($user['auth_app_enabled']) && (string)($user['auth_app_secret'] ?? '') !== '',
        'email_link' => (bool)$user,
    ];
}

function rememberLoginEmail(string $email): void
{
    $_SESSION['login_email'] = trim($email);
}

function currentLoginEmail(): string
{
    return trim((string)($_POST['email'] ?? $_GET['email'] ?? $_SESSION['login_email'] ?? ''));
}

function finishUserLogin(PDO $pdo, array $user, array $siteSettings, bool $rememberMe, ?string &$successMessage): array
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'level' => (int)$user['level'],
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
    ];
    unset($_SESSION['login_email']);
    $updateStmt = $pdo->prepare("UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id");
    $updateStmt->execute([':id' => $_SESSION['user']['id']]);
    if ($rememberMe) {
        $ttlSeconds = (int)($siteSettings['remember_me_ttl_seconds'] ?? (30 * 86400));
        issueRememberMeToken($pdo, (int)$_SESSION['user']['id'], $ttlSeconds);
    } else {
        clear_remember_me_cookie();
    }
    $successMessage = 'Welcome back!';
    return $_SESSION['user'];
}

function handleLoginLookup(?PDO $pdo, array $siteSettings, array &$alerts): ?array
{
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Please provide a valid email address.'];
        return null;
    }
    rememberLoginEmail($email);
    $methods = loginMethodState($pdo, $email, $siteSettings);
    if (!$methods['user_exists']) {
        $alerts[] = ['type' => 'danger', 'message' => 'No account was found for that email address.'];
        return null;
    }
    return $methods;
}

function handleAuthAppLogin(?PDO $pdo, array $siteSettings, array &$alerts, ?string &$successMessage): ?array
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return null;
    }
    if (empty($siteSettings['auth_app_login_enabled']) || (string)$siteSettings['auth_app_login_enabled'] === '0') {
        $alerts[] = ['type' => 'danger', 'message' => 'Authenticator app login is not enabled for this site.'];
        return null;
    }
    $email = currentLoginEmail();
    $code = (string)($_POST['code'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Please start again with your email address.'];
        return null;
    }
    try {
        ensureAuthAppColumns($pdo);
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.password_hash, u.auth_app_secret, u.auth_app_enabled, r.name AS role, r.level AS level, u.first_name, u.last_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if (!$user || empty($user['auth_app_enabled']) || (string)($user['auth_app_secret'] ?? '') === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'Authenticator app login is not available for this account.'];
            return null;
        }
        if (!auth_app_verify_code((string)$user['auth_app_secret'], $code)) {
            $alerts[] = ['type' => 'danger', 'message' => 'That authenticator code is not valid.'];
            return null;
        }
        return finishUserLogin($pdo, $user, $siteSettings, !empty($_POST['remember_me']), $successMessage);
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not sign you in right now.'];
        return null;
    }
}

function currentUserAuthAppStatus(?PDO $pdo, int $userId): array
{
    if (!$pdo || $userId <= 0) {
        return ['enabled' => false, 'confirmed_at' => null];
    }
    try {
        ensureAuthAppColumns($pdo);
        $stmt = $pdo->prepare("SELECT auth_app_enabled, auth_app_confirmed_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'enabled' => !empty($row['auth_app_enabled']),
            'confirmed_at' => $row['auth_app_confirmed_at'] ?? null,
        ];
    } catch (PDOException $e) {
        return ['enabled' => false, 'confirmed_at' => null];
    }
}

function beginAuthAppSetup(array $currentUser, array $siteSettings): array
{
    $secret = auth_app_generate_secret();
    $_SESSION['pending_auth_app_secret'] = $secret;
    return pendingAuthAppSetup($currentUser, $siteSettings) ?: [];
}

function pendingAuthAppSetup(array $currentUser, array $siteSettings): ?array
{
    $secret = (string)($_SESSION['pending_auth_app_secret'] ?? '');
    if ($secret === '') {
        return null;
    }
    $uri = auth_app_otpauth_uri($currentUser, $secret, $siteSettings);
    return [
        'secret' => $secret,
        'formatted_secret' => auth_app_format_secret($secret),
        'otpauth_uri' => $uri,
        'qr_data_uri' => auth_app_qr_data_uri($uri),
    ];
}

function confirmAuthAppSetup(?PDO $pdo, int $userId, array &$alerts): bool
{
    if (!$pdo || $userId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    $secret = (string)($_SESSION['pending_auth_app_secret'] ?? '');
    $code = (string)($_POST['code'] ?? '');
    if ($secret === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Start authenticator setup again.'];
        return false;
    }
    if (!auth_app_verify_code($secret, $code)) {
        $alerts[] = ['type' => 'danger', 'message' => 'That authenticator code is not valid.'];
        return false;
    }
    try {
        ensureAuthAppColumns($pdo);
        $stmt = $pdo->prepare("UPDATE users SET auth_app_secret = :secret, auth_app_enabled = 1, auth_app_confirmed_at = NOW(), updated_at = NOW() WHERE id = :id LIMIT 1");
        $stmt->execute([':secret' => $secret, ':id' => $userId]);
        unset($_SESSION['pending_auth_app_secret']);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not enable authenticator app login.'];
        return false;
    }
}

function disableAuthApp(?PDO $pdo, int $userId, array &$alerts): bool
{
    if (!$pdo || $userId <= 0) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    try {
        ensureAuthAppColumns($pdo);
        $stmt = $pdo->prepare("UPDATE users SET auth_app_secret = NULL, auth_app_enabled = 0, auth_app_confirmed_at = NULL, updated_at = NOW() WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        unset($_SESSION['pending_auth_app_secret']);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not disable authenticator app login.'];
        return false;
    }
}

function fetchRoleByName(PDO $pdo, string $role): ?array
{
    $stmt = $pdo->prepare("SELECT id, name, level FROM roles WHERE name = :role LIMIT 1");
    $stmt->execute([':role' => $role]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function handleLogout(): void
{
    if (!empty($_SESSION['user']['id']) && isset($_COOKIE['ildra_remember'])) {
        // Best-effort: invalidate the current remember token on logout.
        try {
            $pdo = $GLOBALS['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                ensureAuthTables($pdo);
                $cookie = (string)($_COOKIE['ildra_remember'] ?? '');
                if (strpos($cookie, ':') !== false) {
                    [$selector] = explode(':', $cookie, 2);
                    $del = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = :uid OR selector = :sel");
                    $del->execute([':uid' => (int)$_SESSION['user']['id'], ':sel' => (string)$selector]);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    clear_remember_me_cookie();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($basePath === '/') {
        $basePath = '';
    }
    if (substr($basePath, -6) === '/admin') {
        $basePath = rtrim(substr($basePath, 0, -6), '/');
    }
    $redirect = ($basePath ?: '') . '/';
    header('Location: ' . $redirect);
    exit;
}

function handleRegister(?PDO $pdo, array &$alerts, ?string &$successMessage): ?array
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return null;
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = 'user';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Please provide a valid email address.'];
    }
    if (strlen($password) < 8) {
        $alerts[] = ['type' => 'danger', 'message' => 'Password must be at least 8 characters.'];
    }
    if ($password !== $confirmPassword) {
        $alerts[] = ['type' => 'danger', 'message' => 'Password confirmation does not match.'];
    }

    if ($alerts) {
        return null;
    }

    try {
        $roleRow = fetchRoleByName($pdo, $role);
        if (!$roleRow) {
            $alerts[] = ['type' => 'danger', 'message' => 'Role configuration missing.'];
            return null;
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, role_id, first_name, last_name, created_at, updated_at)
            VALUES (:email, :password_hash, :role_id, :first_name, :last_name, NOW(), NOW())
        ");
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role_id' => (int)$roleRow['id'],
            ':first_name' => $firstName ?: null,
            ':last_name' => $lastName ?: null,
        ]);
        $userId = (int)$pdo->lastInsertId();

        // Auto-create an initial Person record for this account owner.
        // People can exist without a membership number; allocation happens when a membership is purchased.
        $newPersonId = null;
        try {
            ensureMembershipTables($pdo);
            $safeFirst = $firstName !== '' ? $firstName : '—';
            $safeLast = $lastName !== '' ? $lastName : '—';
            $ins = $pdo->prepare("
                INSERT INTO people (owner_user_id, member_number, first_name, last_name, dob, email, is_archived, created_at, updated_at)
                VALUES (:uid, NULL, :first_name, :last_name, NULL, :email, 0, NOW(), NOW())
            ");
            $ins->execute([
                ':uid' => $userId,
                ':first_name' => $safeFirst,
                ':last_name' => $safeLast,
                    ':email' => $email,
                ]);
                $newPersonId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            // Non-fatal: the account can exist even if the Person bootstrap fails.
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'email' => $email,
            'role' => $role,
            'level' => (int)$roleRow['level'],
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
        if ($newPersonId) {
            $_SESSION['prompt_person_completion'] = $newPersonId;
        }
        $successMessage = 'Account created and logged in.';
        return $_SESSION['user'];
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) {
            $alerts[] = ['type' => 'danger', 'message' => 'That email is already registered.'];
        } else {
            $alerts[] = ['type' => 'danger', 'message' => 'Registration failed. Please try again.'];
        }
        return null;
    }
}

function handleLogin(?PDO $pdo, array $siteSettings, array &$alerts, ?string &$successMessage): ?array
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return null;
    }

    $email = currentLoginEmail();
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $alerts[] = ['type' => 'danger', 'message' => 'Invalid credentials.'];
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.password_hash, r.name AS role, r.level AS level, u.first_name, u.last_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && (string)($user['password_hash'] ?? '') !== '' && password_verify($password, $user['password_hash'])) {
        return finishUserLogin($pdo, $user, $siteSettings, !empty($_POST['remember_me']), $successMessage);
    }

    $alerts[] = ['type' => 'danger', 'message' => 'Invalid credentials.'];
    return null;
}

function handleMagicLinkRequest(?PDO $pdo, array $siteSettings, array &$alerts, ?string &$successMessage): void
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return;
    }
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Please provide a valid email address.'];
        return;
    }

    // Do not reveal whether an account exists.
    $successMessage = 'If that email is registered, we’ve sent a sign-in link.';

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        require_once __DIR__ . '/email.php';
        $token = createMagicLoginToken($pdo, (int)$user['id'], 15 * 60);

        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($basePath === '/') {
            $basePath = '';
        }
        if (substr($basePath, -6) === '/admin') {
            $basePath = rtrim(substr($basePath, 0, -6), '/');
        }
        $scheme = auth_cookie_secure() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $url = $scheme . '://' . $host . ($basePath ?: '') . '/account?magic=' . rawurlencode($token);
        $url = preg_replace('/^(https?)\s+:\/\//i', '$1://', $url) ?: $url;
        $siteSettings = getSiteSettings($pdo);
        $emailSettings = getEmailSettings($pdo);
        $brandName = trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));

        $subject = 'Your sign-in link';
        $html = wrap_user_email_html(
            $siteSettings,
            $emailSettings,
            '<div style="font-size:16px;font-weight:800;color:#0c2a12;">Sign in to ' . h($brandName) . '</div>'
            . '<div style="margin-top:10px;color:#476146;line-height:1.6;">Use the secure button below to sign in without a password.</div>'
            . '<div style="margin-top:8px;color:#476146;line-height:1.6;">This sign-in link expires in 15 minutes and can only be used once.</div>'
            . '<div style="margin-top:18px;">' . email_cta_button_html($url, 'Sign in') . '</div>'
            . '<div style="margin-top:18px;color:#476146;font-size:13px;line-height:1.6;">If you didn’t request this email, you can ignore it.</div>'
        );
        $text = wrap_user_email_text(
            $siteSettings,
            $emailSettings,
            "Sign in to {$brandName}\n\nUse this secure link to sign in without a password. It expires in 15 minutes and can only be used once:\n<{$url}>\n\nIf you didn’t request this email, you can ignore it."
        );

        send_logged_email($pdo, $email, $subject, $html, $text, ['kind' => 'magic_login']);
    } catch (Throwable $e) {
        // Ignore failures; do not block user flow.
    }
}

function fetchAllUsersForAdmin(?PDO $pdo, array &$alerts): array
{
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->query("
            SELECT u.id, u.email, r.name AS role, r.level AS level, u.first_name, u.last_name, u.last_login_at, u.created_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            ORDER BY u.created_at DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not load users list.'];
        return [];
    }
}

function updateUserRoleAndLevel(?PDO $pdo, int $userId, string $role, int $level, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    $allowedRoles = ['superadmin', 'admin', 'organiser', 'user'];
    if (!in_array($role, $allowedRoles, true)) {
        $alerts[] = ['type' => 'danger', 'message' => 'Invalid role selected.'];
        return false;
    }
    try {
        $roleRow = fetchRoleByName($pdo, $role);
        if (!$roleRow) {
            $alerts[] = ['type' => 'danger', 'message' => 'Role configuration missing.'];
            return false;
        }
        $stmt = $pdo->prepare("UPDATE users SET role_id = :role_id, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':role_id' => (int)$roleRow['id'], ':id' => $userId]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not update user.'];
        return false;
    }
}

function resetUserPassword(?PDO $pdo, int $userId, string $password, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }
    if (strlen($password) < 8) {
        $alerts[] = ['type' => 'danger', 'message' => 'Password must be at least 8 characters.'];
        return false;
    }
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':hash' => $hash, ':id' => $userId]);
        return true;
    } catch (PDOException $e) {
        $alerts[] = ['type' => 'danger', 'message' => 'Could not reset password.'];
        return false;
    }
}
