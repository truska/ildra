<?php
declare(strict_types=1);

/**
 * Email subsystem (authenticated SMTP preferred, PHP mail retained only as a fallback).
 *
 * Goals:
 * - Centralised sending + logging (HTML + plain-text)
 * - Configurable via site_settings (key/value)
 * - Do not block checkout if sending fails (always log)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cms.php';

function email_private_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach ([__DIR__ . '/../private/email.php', __DIR__ . '/private/email.php'] as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $cfg = require $path;
        if (is_array($cfg)) {
            $cache = $cfg;
        }
        break;
    }
    return $cache;
}

function email_current_environment(array $privateConfig): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if (PHP_SAPI === 'cli') {
        $host = strtolower((string)($privateConfig['cli_host'] ?? $host));
    }
    $host = preg_replace('/:\d+$/', '', $host) ?: '';
    $liveHosts = array_map('strtolower', (array)($privateConfig['live_hosts'] ?? []));
    if ($host !== '' && in_array($host, $liveHosts, true)) {
        return 'live';
    }
    return (string)($privateConfig['default_environment'] ?? 'dev');
}

function email_environment_config(): array
{
    $private = email_private_config();
    if (!$private) {
        return [];
    }
    $env = email_current_environment($private);
    $envs = is_array($private['environments'] ?? null) ? $private['environments'] : [];
    $base = $private;
    unset($base['environments']);
    $selected = is_array($envs[$env] ?? null) ? $envs[$env] : [];
    $selected['environment'] = $env;
    return array_replace_recursive($base, $selected);
}

function email_site_code(array $settings = []): string
{
    $code = strtolower(trim((string)($settings['site_code'] ?? '')));
    if ($code === '') {
        $code = strtolower(trim((string)(email_environment_config()['site_code'] ?? 'ildra')));
    }
    $code = preg_replace('/[^a-z0-9]+/', '', $code) ?: 'site';
    return substr($code, 0, 24);
}

function email_generate_bounce_token(string $siteCode): string
{
    return $siteCode . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
}

function email_message_id(string $siteCode, string $domain): string
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || !str_contains($domain, '.')) {
        $domain = 'witecanvas.com';
    }
    return sprintf('<%s.%s.%s@%s>', $siteCode, gmdate('YmdHis'), bin2hex(random_bytes(8)), $domain);
}

function email_bounce_sender(string $siteCode, string $token, array $settings): string
{
    $domain = strtolower(trim((string)($settings['bounce_domain'] ?? 'witecanvas.com')));
    return 'bounces+' . $siteCode . '-' . $token . '@' . $domain;
}

function email_safe_debug_error(?Throwable $e): string
{
    return $e ? $e->getMessage() : '';
}

function ensureEmailTables(PDO $pdo): void
{
    ensureSiteSettingsTable($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(20) NOT NULL,
            provider VARCHAR(40) DEFAULT NULL,
            to_email TEXT NOT NULL,
            cc TEXT DEFAULT NULL,
            bcc TEXT DEFAULT NULL,
            subject TEXT NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            meta_json LONGTEXT DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (status),
            INDEX (sent_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");

    // Seed default email settings (kept minimal; admin can override).
    $defaults = defaultEmailSettings();
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1");
    $ins = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())");
    foreach ($defaults as $k => $v) {
        $stmt->execute([':k' => $k]);
        $exists = $stmt->fetchColumn();
        if ($exists === false) {
            $ins->execute([':k' => $k, ':v' => (string)$v]);
        }
    }

    importLegacyEmailSettingsFromCsv($pdo);
}

function defaultEmailSettings(): array
{
    return [
        'email_enabled' => '0',
        'email_provider' => 'smtp', // smtp|php_mail
        'email_test_redirect_enabled' => '0',
        'email_test_redirect_to' => 'clients@truska.com',
        'email_subject_prefix' => 'TEST',
        'email_from_name' => '',
        'email_from_email' => '',
        'email_reply_to' => '',
        'email_return_path' => '',
        'email_cc_default' => '',
        'email_bcc_default' => '',
        'email_smtp_host' => '',
        'email_smtp_port' => '587',
        'email_smtp_secure' => 'tls', // tls|ssl|none
        'email_smtp_username' => '',
        'email_smtp_password' => '',
        'email_site_code' => 'ildra',
    ];
}

function legacyEmailCsvSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $paths = [__DIR__ . '/../private/preferences.csv', __DIR__ . '/private/preferences.csv'];
    $file = null;
    foreach ($paths as $p) {
        if (file_exists($p)) {
            $file = $p;
            break;
        }
    }
    if (!$file) {
        return $cache;
    }

    if (($handle = fopen($file, 'r')) === false) {
        return $cache;
    }

    $header = fgetcsv($handle);
    if (!$header || !is_array($header)) {
        fclose($handle);
        return $cache;
    }
    $idx = array_flip($header);

    $map = [
        'prefEmailSendFrom' => 'email_from_email',
        'prefCompanyName' => 'email_from_name',
        'prefEmailSMTP' => 'email_smtp_host',
        'prefEmailSendPort' => 'email_smtp_port',
        'prefEmailSendSecurity' => 'email_smtp_secure',
        'prefEmailSendPassword' => 'email_smtp_password',
        'prefEmailBCC' => 'email_bcc_default',
        'prefManagerEmail' => 'email_cc_default',
        'prefEmail' => 'email_reply_to',
    ];

    while (($row = fgetcsv($handle)) !== false) {
        $name = isset($idx['name']) ? (string)$row[$idx['name']] : '';
        $value = isset($idx['value']) ? (string)$row[$idx['value']] : '';
        if ($name === '' || $value === '') {
            continue;
        }
        if (!isset($map[$name])) {
            continue;
        }
        $key = $map[$name];
        if ($key === 'email_smtp_secure') {
            $val = strtolower($value);
            if ($val === 'starttls') {
                $value = 'tls';
            } elseif ($val === 'ssl') {
                $value = 'ssl';
            } elseif ($val === 'tls') {
                $value = 'tls';
            } else {
                $value = 'tls';
            }
        }
        $cache[$key] = $value;
        if ($name === 'prefEmailSendFrom') {
            $cache['email_smtp_username'] = $value;
        }
    }
    fclose($handle);
    return $cache;
}

function legacyEmailCsvPath(): ?string
{
    foreach ([__DIR__ . '/../private/preferences.csv', __DIR__ . '/private/preferences.csv'] as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}
function importLegacyEmailSettingsFromCsv(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $csv = legacyEmailCsvSettings();
    if (!$csv) {
        return;
    }

    $existing = [];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'email_%'");
    foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
        $existing[(string)($row['setting_key'] ?? '')] = (string)($row['setting_value'] ?? '');
    }

    $updatableKeys = [
        'email_from_name',
        'email_from_email',
        'email_reply_to',
        'email_cc_default',
        'email_bcc_default',
        'email_smtp_host',
        'email_smtp_port',
        'email_smtp_secure',
    ];

    $save = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())");
    foreach ($updatableKeys as $key) {
        $current = trim((string)($existing[$key] ?? ''));
        $incoming = trim((string)($csv[$key] ?? ''));
        if ($incoming === '' || $current !== '') {
            continue;
        }
        $save->execute([':k' => $key, ':v' => $incoming]);
    }
}

function getEmailSettings(?PDO $pdo): array
{
    $settings = defaultEmailSettings();
    if (!$pdo) {
        return $settings;
    }

    ensureEmailTables($pdo);
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'email_%'");
    foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
        $key = (string)($row['setting_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $settings[$key] = (string)($row['setting_value'] ?? '');
    }

    $csv = legacyEmailCsvSettings();
    foreach (['email_from_name', 'email_from_email', 'email_reply_to', 'email_cc_default', 'email_bcc_default', 'email_smtp_host', 'email_smtp_port', 'email_smtp_secure'] as $k) {
        if (
            trim((string)($settings[$k] ?? '')) === ''
            && isset($csv[$k])
            && trim((string)$csv[$k]) !== ''
        ) {
            $settings[$k] = (string)$csv[$k];
        }
    }

    $privateEmail = email_environment_config();
    $privateOutbound = is_array($privateEmail['outbound'] ?? null) ? $privateEmail['outbound'] : [];
    if ($privateOutbound) {
        $settings['email_enabled'] = '1';
        $settings['email_provider'] = 'smtp';
        $settings['email_from_name'] = trim((string)($privateOutbound['from_name'] ?? $settings['email_from_name'] ?? ''));
        $settings['email_from_email'] = trim((string)($privateOutbound['from_email'] ?? $privateOutbound['username'] ?? $settings['email_from_email'] ?? ''));
        // Reply-to is a public, site-managed contact address. Keep it separate
        // from private SMTP transport credentials and the no-reply From address.
        $settings['email_return_path'] = '';
        $settings['email_cc_default'] = (string)($privateOutbound['cc_default'] ?? '');
        $settings['email_bcc_default'] = (string)($privateOutbound['bcc_default'] ?? '');
        $settings['email_smtp_host'] = trim((string)($privateOutbound['host'] ?? ''));
        $settings['email_smtp_port'] = (string)((int)($privateOutbound['port'] ?? 587));
        $settings['email_smtp_secure'] = strtolower(trim((string)($privateOutbound['secure'] ?? 'tls')));
        $settings['email_smtp_username'] = trim((string)($privateOutbound['username'] ?? $settings['email_from_email'] ?? ''));
        $settings['email_smtp_password'] = (string)($privateOutbound['password'] ?? '');
        $settings['email_site_code'] = email_site_code($privateEmail);
        $settings['bounce_domain'] = trim((string)($privateEmail['bounce_domain'] ?? 'witecanvas.com'));
        $settings['email_config_environment'] = (string)($privateEmail['environment'] ?? 'dev');
    }

    $settings['email_enabled'] = ((string)($settings['email_enabled'] ?? '0')) === '1' ? '1' : '0';
    $settings['email_smtp_port'] = (string)((int)($settings['email_smtp_port'] ?? 587));

    $secure = strtolower((string)($settings['email_smtp_secure'] ?? 'tls'));
    if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
        $secure = 'tls';
    }
    $settings['email_smtp_secure'] = $secure;

    $provider = strtolower((string)($settings['email_provider'] ?? 'php_mail'));
    if (!in_array($provider, ['php_mail', 'smtp'], true)) {
        $provider = 'php_mail';
    }
    $settings['email_provider'] = $provider;

    $settings['email_test_redirect_enabled'] = ((string)($settings['email_test_redirect_enabled'] ?? '0')) === '1' ? '1' : '0';
    $settings['email_test_redirect_to'] = trim((string)($settings['email_test_redirect_to'] ?? ''));

    // Keep "From name" aligned with site hero title unless explicitly set.
    if (trim((string)($settings['email_from_name'] ?? '')) === '') {
        $siteSettings = getSiteSettings($pdo);
        $settings['email_from_name'] = trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));
    }
    if (trim((string)($settings['email_reply_to'] ?? '')) === '' && !empty($settings['email_from_email'])) {
        $settings['email_reply_to'] = $settings['email_from_email'];
    }

    // SMTP credentials are resolved from private config first, then legacy constants/CSV.
    // They are never persisted back into the database, and DB-stored stale secrets are ignored.
    if (!$privateOutbound) {
        $constUser = defined('ILDRA_EMAIL_SMTP_USERNAME') ? trim((string)constant('ILDRA_EMAIL_SMTP_USERNAME')) : '';
        $constPass = defined('ILDRA_EMAIL_SMTP_PASSWORD') ? (string)constant('ILDRA_EMAIL_SMTP_PASSWORD') : '';
        $csvUser = trim((string)($csv['email_smtp_username'] ?? ($settings['email_from_email'] ?? '')));
        $csvPass = (string)($csv['email_smtp_password'] ?? '');
        $settings['email_smtp_username'] = $constUser !== '' ? $constUser : $csvUser;
        $settings['email_smtp_password'] = $constPass !== '' ? $constPass : $csvPass;
    }

    if ($csv && !$privateOutbound) {
        $hasCsvSmtp = trim((string)($settings['email_smtp_host'] ?? '')) !== ''
            && trim((string)($settings['email_smtp_username'] ?? '')) !== ''
            && (string)($settings['email_smtp_password'] ?? '') !== '';
        $settings['email_provider'] = $hasCsvSmtp ? 'smtp' : 'php_mail';
    }

    // Auto-enable if we have enough to attempt sending.
    if ($settings['email_enabled'] === '0'
        && $settings['email_provider'] === 'php_mail'
        && !empty($settings['email_from_email'])) {
        $settings['email_enabled'] = '1';
    }
    if ($settings['email_enabled'] === '0'
        && $settings['email_provider'] === 'smtp'
        && !empty($settings['email_from_email'])
        && !empty($settings['email_smtp_host'])
        && $settings['email_smtp_username'] !== ''
        && $settings['email_smtp_password'] !== '') {
        $settings['email_enabled'] = '1';
    }

    return $settings;
}

function emailDebugSnapshot(array $settings, array $meta = []): array
{
    $csv = legacyEmailCsvSettings();
    $csvPath = legacyEmailCsvPath();
    $constUser = defined('ILDRA_EMAIL_SMTP_USERNAME') ? trim((string) constant('ILDRA_EMAIL_SMTP_USERNAME')) : '';
    $constPass = defined('ILDRA_EMAIL_SMTP_PASSWORD') ? (string) constant('ILDRA_EMAIL_SMTP_PASSWORD') : '';
    $csvUser = trim((string) ($csv['email_smtp_username'] ?? ($settings['email_from_email'] ?? '')));
    $csvPass = (string) ($csv['email_smtp_password'] ?? '');

    return [
        'resolved' => [
            'email_enabled' => (string) ($settings['email_enabled'] ?? '0'),
            'email_provider' => (string) ($settings['email_provider'] ?? ''),
            'email_from_name' => (string) ($settings['email_from_name'] ?? ''),
            'email_from_email' => (string) ($settings['email_from_email'] ?? ''),
            'email_reply_to' => (string) ($settings['email_reply_to'] ?? ''),
            'email_return_path' => (string) ($settings['email_return_path'] ?? ''),
            'email_site_code' => (string) ($settings['email_site_code'] ?? ''),
            'email_config_environment' => (string) ($settings['email_config_environment'] ?? ''),
            'email_cc_default' => (string) ($settings['email_cc_default'] ?? ''),
            'email_bcc_default' => (string) ($settings['email_bcc_default'] ?? ''),
            'email_smtp_host' => (string) ($settings['email_smtp_host'] ?? ''),
            'email_smtp_port' => (string) ($settings['email_smtp_port'] ?? ''),
            'email_smtp_secure' => (string) ($settings['email_smtp_secure'] ?? ''),
            'email_smtp_username' => (string) ($settings['email_smtp_username'] ?? ''),
            'email_smtp_password_present' => ((string) ($settings['email_smtp_password'] ?? '')) !== '',
            'email_test_redirect_enabled' => (string) ($settings['email_test_redirect_enabled'] ?? '0'),
            'email_test_redirect_to' => (string) ($settings['email_test_redirect_to'] ?? ''),
        ],
        'sources' => [
            'private_config_present' => (bool) email_environment_config(),
            'config_username_present' => $constUser !== '',
            'config_password_present' => $constPass !== '',
            'csv_path' => $csvPath,
            'csv_username_present' => $csvUser !== '',
            'csv_password_present' => $csvPass !== '',
            'csv_host_present' => trim((string) ($csv['email_smtp_host'] ?? '')) !== '',
            'resolved_username_source' => $constUser !== '' ? 'config.php' : ($csvUser !== '' ? 'preferences.csv/email_from_email' : 'missing'),
            'resolved_password_source' => $constPass !== '' ? 'config.php' : ($csvPass !== '' ? 'preferences.csv' : 'missing'),
            'resolved_host_source' => trim((string) ($settings['email_smtp_host'] ?? '')) !== ''
                ? (trim((string) ($csv['email_smtp_host'] ?? '')) !== '' ? 'site_settings_or_preferences.csv' : 'site_settings')
                : 'missing',
        ],
        'message' => [
            'kind' => (string) ($meta['kind'] ?? $meta['type'] ?? ''),
        ],
    ];
}

function saveEmailSettings(?PDO $pdo, array $data, array &$alerts): bool
{
    if (!$pdo) {
        $alerts[] = ['type' => 'danger', 'message' => 'Database unavailable.'];
        return false;
    }

    ensureEmailTables($pdo);

    $merged = array_merge(getEmailSettings($pdo), $data);

    $enabled = isset($merged['email_enabled']) && (string)$merged['email_enabled'] === '1' ? '1' : '0';
    $provider = strtolower(trim((string)($merged['email_provider'] ?? 'php_mail')));
    $provider = in_array($provider, ['php_mail', 'smtp'], true) ? $provider : 'php_mail';

    $redirectEnabled = isset($merged['email_test_redirect_enabled']) && (string)$merged['email_test_redirect_enabled'] === '1' ? '1' : '0';
    $redirectTo = trim((string)($merged['email_test_redirect_to'] ?? ''));

    $smtpPort = (string)((int)($merged['email_smtp_port'] ?? 587));
    $smtpSecure = strtolower(trim((string)($merged['email_smtp_secure'] ?? 'tls')));
    $smtpSecure = in_array($smtpSecure, ['tls', 'ssl', 'none'], true) ? $smtpSecure : 'tls';

    $fromName = trim((string)($merged['email_from_name'] ?? ''));
    $fromEmail = trim((string)($merged['email_from_email'] ?? ''));
    $replyTo = trim((string)($merged['email_reply_to'] ?? ''));
    $returnPath = trim((string)($merged['email_return_path'] ?? ''));
    $cc = trim((string)($merged['email_cc_default'] ?? ''));
    $bcc = trim((string)($merged['email_bcc_default'] ?? ''));
    $prefix = trim((string)($merged['email_subject_prefix'] ?? ''));

    $smtpHost = trim((string)($merged['email_smtp_host'] ?? ''));
    $smtpUser = trim((string)($merged['email_smtp_username'] ?? ''));
    $smtpPass = (string)($merged['email_smtp_password'] ?? '');

    // If enabled, enforce minimum viable config.
    if ($enabled === '1') {
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'message' => 'From email is required and must be valid.'];
        }
        if ($redirectEnabled === '1' && ($redirectTo === '' || !filter_var($redirectTo, FILTER_VALIDATE_EMAIL))) {
            $alerts[] = ['type' => 'danger', 'message' => 'Test redirect email must be a valid email address.'];
        }
        if ($provider === 'smtp' && $smtpHost === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'SMTP host is required when SMTP email is enabled.'];
        }
        if ($provider === 'smtp' && ($smtpUser === '' || $smtpPass === '')) {
            $alerts[] = ['type' => 'danger', 'message' => 'SMTP credentials must be available from config.php or preferences.csv when SMTP email is enabled.'];
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Reply-to must be a valid email address.'];
        }
        if ($returnPath !== '' && !filter_var($returnPath, FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'message' => 'Return-path must be a valid email address.'];
        }
    }

    if ($alerts) {
        return false;
    }

    $toSave = [
        'email_enabled' => $enabled,
        'email_provider' => $provider,
        'email_test_redirect_enabled' => $redirectEnabled,
        'email_test_redirect_to' => $redirectTo,
        'email_subject_prefix' => $prefix,
        'email_from_name' => $fromName,
        'email_from_email' => $fromEmail,
        'email_reply_to' => $replyTo,
        'email_return_path' => $returnPath,
        'email_cc_default' => $cc,
        'email_bcc_default' => $bcc,
        'email_smtp_host' => $smtpHost,
        'email_smtp_port' => $smtpPort,
        'email_smtp_secure' => $smtpSecure,
        // Never persist SMTP secrets in the DB.
        'email_smtp_username' => '',
        'email_smtp_password' => '',
        'email_site_code' => 'ildra',
    ];

    $stmt = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())");
    foreach ($toSave as $k => $v) {
        $stmt->execute([':k' => $k, ':v' => (string)$v]);
    }

    return true;
}

function logEmail(PDO $pdo, array $row): int
{
    ensureEmailTables($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO email_log (status, provider, to_email, cc, bcc, subject, body_html, body_text, error_message, meta_json, sent_at)
        VALUES (:status, :provider, :to_email, :cc, :bcc, :subject, :body_html, :body_text, :error_message, :meta_json, :sent_at)
    ");
    $stmt->execute([
        ':status' => (string)($row['status'] ?? 'failed'),
        ':provider' => $row['provider'] ?? null,
        ':to_email' => (string)($row['to_email'] ?? ''),
        ':cc' => $row['cc'] ?? null,
        ':bcc' => $row['bcc'] ?? null,
        ':subject' => (string)($row['subject'] ?? ''),
        ':body_html' => $row['body_html'] ?? null,
        ':body_text' => $row['body_text'] ?? null,
        ':error_message' => $row['error_message'] ?? null,
        ':meta_json' => $row['meta_json'] ?? null,
        ':sent_at' => $row['sent_at'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function updateEmailLog(PDO $pdo, int $id, array $row): void
{
    if ($id <= 0) {
        return;
    }
    ensureEmailTables($pdo);
    $sets = [];
    $params = [':id' => $id];
    foreach (['status', 'provider', 'to_email', 'cc', 'bcc', 'subject', 'body_html', 'body_text', 'error_message', 'meta_json', 'sent_at'] as $col) {
        if (!array_key_exists($col, $row)) {
            continue;
        }
        $sets[] = $col . ' = :' . $col;
        $params[':' . $col] = $row[$col];
    }
    if (!$sets) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE email_log SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
}

function markEmailLogBounced(PDO $pdo, int $id, array $bounceMeta, string $reason): void
{
    if ($id <= 0) {
        return;
    }
    ensureEmailTables($pdo);
    $stmt = $pdo->prepare('SELECT meta_json FROM email_log WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetchColumn();
    $meta = [];
    if (is_string($existing) && $existing !== '') {
        $decoded = json_decode($existing, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    $meta['bounce'] = $bounceMeta;
    updateEmailLog($pdo, $id, [
        'status' => 'bounced',
        'error_message' => $reason,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
}

function findEmailLogByBounceToken(PDO $pdo, string $siteCode, string $token): ?array
{
    ensureEmailTables($pdo);
    $like = '%' . $token . '%';
    $stmt = $pdo->prepare("SELECT * FROM email_log WHERE meta_json LIKE :token ORDER BY id DESC LIMIT 1");
    $stmt->execute([':token' => $like]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $meta = json_decode((string)($row['meta_json'] ?? ''), true);
    if (!is_array($meta)) {
        return null;
    }
    $metaSite = strtolower((string)($meta['site_code'] ?? ''));
    $metaToken = (string)($meta['bounce_token'] ?? '');
    if ($metaToken !== $token || ($siteCode !== '' && $metaSite !== strtolower($siteCode))) {
        return null;
    }
    return $row;
}

function parseEmailList(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[;,\\n]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $e = trim($p);
        if ($e === '') {
            continue;
        }
        $out[] = $e;
    }
    return array_values(array_unique($out));
}

function buildMimeMessage(string $subject, string $fromName, string $fromEmail, string $toEmail, array $ccEmails, array $replyToEmails, string $htmlBody, string $textBody, string $returnPath = '', array $extraHeaders = [], array $attachments = []): array
{
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $mixedBoundary = 'm_' . bin2hex(random_bytes(12));
    $validAttachments = [];
    foreach ($attachments as $attachment) {
        $path = (string)($attachment['path'] ?? '');
        if ($path !== '' && is_file($path) && is_readable($path)) $validAttachments[] = $attachment;
    }
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $fromHeader = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . "?= <{$fromEmail}>"
        : $fromEmail;

    $headers = [];
    $headers[] = "From: {$fromHeader}";
    $headers[] = "To: {$toEmail}";
    if ($ccEmails) {
        $headers[] = 'Cc: ' . implode(', ', $ccEmails);
    }
    if ($replyToEmails) {
        $headers[] = 'Reply-To: ' . implode(', ', $replyToEmails);
    }
    // Return-Path is typically set by the SMTP envelope sender; keep header for debugging only.
    if ($returnPath !== '') {
        $headers[] = "Return-Path: {$returnPath}";
    }
    foreach ($extraHeaders as $name => $value) {
        $name = trim((string)$name);
        $value = trim((string)$value);
        if ($name === '' || $value === '' || preg_match('/[\r\n:]/', $name) || preg_match('/[\r\n]/', $value)) {
            continue;
        }
        $headers[] = $name . ': ' . $value;
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = $validAttachments ? "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"" : "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

    $bodyLines = [];
    $bodyLines[] = "This is a multi-part message in MIME format.";
    $bodyLines[] = "";
    if ($validAttachments) {
        $bodyLines[] = "--{$mixedBoundary}";
        $bodyLines[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $bodyLines[] = "";
    }

    // text/plain
    $bodyLines[] = "--{$boundary}";
    $bodyLines[] = "Content-Type: text/plain; charset=UTF-8";
    $bodyLines[] = "Content-Transfer-Encoding: 8bit";
    $bodyLines[] = "";
    $bodyLines[] = $textBody;
    $bodyLines[] = "";

    // text/html
    $bodyLines[] = "--{$boundary}";
    $bodyLines[] = "Content-Type: text/html; charset=UTF-8";
    $bodyLines[] = "Content-Transfer-Encoding: 8bit";
    $bodyLines[] = "";
    $bodyLines[] = $htmlBody;
    $bodyLines[] = "";

    $bodyLines[] = "--{$boundary}--";
    $bodyLines[] = "";
    if ($validAttachments) {
        foreach ($validAttachments as $attachment) {
            $filename = basename((string)($attachment['filename'] ?? 'attachment.pdf'));
            $filename = preg_replace('/[\r\n\"]/','', $filename) ?: 'attachment.pdf';
            $mime = trim((string)($attachment['mime_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream';
            $contents = @file_get_contents((string)$attachment['path']);
            if ($contents === false) continue;
            $bodyLines[] = "--{$mixedBoundary}";
            $bodyLines[] = "Content-Type: {$mime}; name=\"{$filename}\"";
            $bodyLines[] = 'Content-Transfer-Encoding: base64';
            $bodyLines[] = "Content-Disposition: attachment; filename=\"{$filename}\"";
            $bodyLines[] = '';
            $bodyLines[] = chunk_split(base64_encode($contents), 76, "\r\n");
        }
        $bodyLines[] = "--{$mixedBoundary}--";
        $bodyLines[] = '';
    }

    return [
        'subject' => $encodedSubject,
        'headers' => implode("\r\n", $headers),
        'body' => implode("\r\n", $bodyLines),
    ];
}

function smtp_read_line($fp): string
{
    $line = '';
    while (!feof($fp)) {
        $chunk = fgets($fp, 515);
        if ($chunk === false) {
            break;
        }
        $line .= $chunk;
        // multi-line responses have hyphen after status code
        if (preg_match('/^\\d{3} /', $chunk)) {
            break;
        }
    }
    return $line;
}

function smtp_expect_ok(string $response, array $okCodes, string $context): void
{
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException("SMTP error during {$context}: {$response}");
    }
}

function smtp_send_command($fp, string $cmd, array $okCodes, string $context): string
{
    fwrite($fp, $cmd . "\r\n");
    $resp = smtp_read_line($fp);
    smtp_expect_ok($resp, $okCodes, $context);
    return $resp;
}

function smtp_send_mail(array $settings, string $fromEmail, string $fromName, string $toEmail, array $ccEmails, array $bccEmails, string $subject, string $htmlBody, string $textBody, string $envelopeFrom, array $extraHeaders = [], array $attachments = []): void
{
    $host = (string)($settings['email_smtp_host'] ?? '');
    $port = (int)($settings['email_smtp_port'] ?? 587);
    $secure = (string)($settings['email_smtp_secure'] ?? 'tls'); // tls|ssl|none
    $username = (string)($settings['email_smtp_username'] ?? '');
    $password = (string)($settings['email_smtp_password'] ?? '');

    $transport = $secure === 'ssl' ? 'ssl' : 'tcp';
    $fp = @stream_socket_client("{$transport}://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
    }
    stream_set_timeout($fp, 15);

    $greeting = smtp_read_line($fp);
    smtp_expect_ok($greeting, [220], 'connect');

    $clientHost = 'localhost';
    $ehlo = smtp_send_command($fp, "EHLO {$clientHost}", [250], 'EHLO');

    if ($secure === 'tls' && stripos($ehlo, 'STARTTLS') !== false) {
        smtp_send_command($fp, "STARTTLS", [220], 'STARTTLS');
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new RuntimeException('SMTP STARTTLS failed.');
        }
        smtp_send_command($fp, "EHLO {$clientHost}", [250], 'EHLO (post-STARTTLS)');
    }

    if ($username !== '' && $password !== '') {
        smtp_send_command($fp, "AUTH LOGIN", [334], 'AUTH LOGIN');
        smtp_send_command($fp, base64_encode($username), [334], 'AUTH USER');
        smtp_send_command($fp, base64_encode($password), [235], 'AUTH PASS');
    }

    $returnPath = $envelopeFrom;

    smtp_send_command($fp, "MAIL FROM:<{$envelopeFrom}>", [250], 'MAIL FROM');

    $recipients = array_merge([$toEmail], $ccEmails, $bccEmails);
    $recipients = array_values(array_unique(array_filter(array_map('trim', $recipients), static fn($v) => $v !== '')));
    foreach ($recipients as $rcpt) {
        smtp_send_command($fp, "RCPT TO:<{$rcpt}>", [250, 251], 'RCPT TO');
    }

    smtp_send_command($fp, "DATA", [354], 'DATA');

    $replyTo = trim((string)($settings['email_reply_to'] ?? ''));
    $replyToEmails = $replyTo !== '' ? parseEmailList($replyTo) : [];
    $mime = buildMimeMessage($subject, $fromName, $fromEmail, $toEmail, $ccEmails, $replyToEmails, $htmlBody, $textBody, $returnPath, $extraHeaders, $attachments);

    $data = $mime['headers'] . "\r\n" .
        "Subject: " . $mime['subject'] . "\r\n" .
        "\r\n" .
        $mime['body'];

    // Dot-stuff lines beginning with a dot.
    $data = preg_replace('/\\r\\n\\./', "\r\n..", $data);
    fwrite($fp, $data . "\r\n.\r\n");
    $resp = smtp_read_line($fp);
    smtp_expect_ok($resp, [250], 'DATA end');

    smtp_send_command($fp, "QUIT", [221], 'QUIT');
    fclose($fp);
}

function php_mail_send(array $settings, string $fromEmail, string $fromName, string $toEmail, array $ccEmails, array $bccEmails, string $subject, string $htmlBody, string $textBody, string $envelopeFrom, array $extraHeaders = [], array $attachments = []): void
{
    $replyTo = trim((string)($settings['email_reply_to'] ?? ''));
    $returnPath = $envelopeFrom;
    $replyToEmails = $replyTo !== '' ? parseEmailList($replyTo) : [];
    $mime = buildMimeMessage($subject, $fromName, $fromEmail, $toEmail, $ccEmails, $replyToEmails, $htmlBody, $textBody, $returnPath, $extraHeaders, $attachments);

    $params = '';
    if ($envelopeFrom !== '' && filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL)) {
        $params = '-f ' . $envelopeFrom;
    }

    $ok = $params !== ''
        ? @mail($toEmail, $mime['subject'], $mime['body'], $mime['headers'], $params)
        : @mail($toEmail, $mime['subject'], $mime['body'], $mime['headers']);

    if (!$ok) {
        throw new RuntimeException('PHP mail() failed.');
    }
}

/**
 * Sends and logs an email. Never throws.
 */
function send_logged_email(?PDO $pdo, string $toEmail, string $subject, string $htmlBody, string $textBody, array $meta = [], array $attachments = []): bool
{
    if (!$pdo) {
        return false;
    }
    ensureEmailTables($pdo);
    $settings = getEmailSettings($pdo);
    $enabled = ((string)($settings['email_enabled'] ?? '0')) === '1';
    $provider = (string)($settings['email_provider'] ?? 'smtp');

    $ccEmails = parseEmailList((string)($settings['email_cc_default'] ?? ''));
    $bccEmails = parseEmailList((string)($settings['email_bcc_default'] ?? ''));
    $fromName = (string)($settings['email_from_name'] ?? '');
    $fromEmail = (string)($settings['email_from_email'] ?? '');

    // Optional: redirect all outgoing mail to a single address (testing).
    // This is designed to prevent accidental delivery to real recipients in staging/sandbox.
    $redirectEnabled = ((string)($settings['email_test_redirect_enabled'] ?? '0')) === '1';
    $redirectTo = trim((string)($settings['email_test_redirect_to'] ?? ''));
    if ($redirectEnabled && $redirectTo !== '' && filter_var($redirectTo, FILTER_VALIDATE_EMAIL)) {
        $originalTo = $toEmail;
        $originalCc = $ccEmails;
        $originalBcc = $bccEmails;

        $toEmail = $redirectTo;
        $ccEmails = [];
        $bccEmails = [];

        $meta['email_test_redirect'] = [
            'enabled' => true,
            'to' => $redirectTo,
            'original_to' => $originalTo,
            'original_cc' => $originalCc,
            'original_bcc' => $originalBcc,
        ];

        $bannerHtml = '<div style="margin:0 0 14px;padding:10px 12px;border-radius:10px;background:#fff7e6;border:1px solid rgba(0,0,0,0.06);">'
            . '<div style="font-weight:700;color:#7a4a00;">Testing redirect enabled</div>'
            . '<div style="color:#7a4a00;font-size:13px;line-height:1.4;">Original recipient: ' . h($originalTo) . '</div>'
            . '</div>';
        $htmlBody = $bannerHtml . $htmlBody;

        $textBody = "TESTING REDIRECT ENABLED\n"
            . "Original recipient: {$originalTo}\n\n"
            . $textBody;
    }

    $siteCode = email_site_code(['site_code' => (string)($settings['email_site_code'] ?? '')]);
    $bounceToken = substr(email_generate_bounce_token($siteCode), strlen($siteCode) + 1);
    $envelopeFrom = email_bounce_sender($siteCode, $bounceToken, $settings);
    $messageId = email_message_id($siteCode, (string)($settings['bounce_domain'] ?? 'witecanvas.com'));

    $meta['provider'] = $provider;
    $meta['smtp_host'] = (string)($settings['email_smtp_host'] ?? '');
    $meta['smtp_port'] = (string)($settings['email_smtp_port'] ?? '');
    $meta['smtp_secure'] = (string)($settings['email_smtp_secure'] ?? '');
    $meta['message_id'] = $messageId;
    $meta['site_code'] = $siteCode;
    $meta['bounce_token'] = $bounceToken;
    $meta['envelope_sender'] = $envelopeFrom;
    $meta['attachments'] = array_values(array_filter(array_map(static fn($a) => basename((string)($a['filename'] ?? '')), $attachments)));
    $meta['delivery_debug'] = emailDebugSnapshot($settings, $meta);

    $logRow = [
        'status' => 'queued',
        'provider' => $provider,
        'to_email' => $toEmail,
        'cc' => $ccEmails ? implode(', ', $ccEmails) : null,
        'bcc' => $bccEmails ? implode(', ', $bccEmails) : null,
        'subject' => $subject,
        'body_html' => $htmlBody,
        'body_text' => $textBody,
        'error_message' => null,
        'meta_json' => $meta ? json_encode($meta) : null,
        'sent_at' => null,
    ];

    $logId = logEmail($pdo, $logRow);
    $meta['log_id'] = $logId;
    $logRow['meta_json'] = json_encode($meta, JSON_UNESCAPED_SLASHES);
    updateEmailLog($pdo, $logId, ['meta_json' => $logRow['meta_json']]);

    if (!$enabled) {
        $logRow['status'] = 'failed';
        $logRow['error_message'] = 'Email disabled.';
        updateEmailLog($pdo, $logId, $logRow);
        return false;
    }

    try {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email is not valid.');
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('From email is not configured.');
        }

        $extraHeaders = [
            'Message-ID' => $messageId,
            'X-CMS-Bounce-Token' => $bounceToken,
            'X-CMS-Site' => $siteCode,
            'X-CMS-Log-ID' => (string)$logId,
        ];

        if ($provider === 'smtp') {
            smtp_send_mail($settings, $fromEmail, $fromName, $toEmail, $ccEmails, $bccEmails, $subject, $htmlBody, $textBody, $envelopeFrom, $extraHeaders, $attachments);
        } else {
            php_mail_send($settings, $fromEmail, $fromName, $toEmail, $ccEmails, $bccEmails, $subject, $htmlBody, $textBody, $envelopeFrom, $extraHeaders, $attachments);
        }

        $logRow['status'] = 'sent';
        $logRow['sent_at'] = date('Y-m-d H:i:s');
        updateEmailLog($pdo, $logId, $logRow);
        return true;
    } catch (Throwable $e) {
        $meta['smtp_error'] = email_safe_debug_error($e);
        $logRow['status'] = 'failed';
        $logRow['error_message'] = $e->getMessage();
        $logRow['meta_json'] = json_encode($meta, JSON_UNESCAPED_SLASHES);
        updateEmailLog($pdo, $logId, $logRow);
        return false;
    }
}

function subject_with_prefix(array $settings, string $subject): string
{
    $prefix = trim((string)($settings['email_subject_prefix'] ?? ''));
    if ($prefix === '') {
        return $subject;
    }
    return '[' . $prefix . '] ' . $subject;
}

function email_brand_name(array $siteSettings, array $emailSettings): string
{
    $fromName = trim((string)($emailSettings['email_from_name'] ?? ''));
    if ($fromName !== '') {
        return $fromName;
    }
    return trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));
}

function email_brand_logo_url(array $siteSettings): string
{
    return trim((string)($siteSettings['sponsor_image_url'] ?? ''));
}

function email_cta_button_html(string $url, string $label): string
{
    return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;border-spacing:0;">'
        . '<tr>'
        . '<td width="150" align="center" valign="middle" bgcolor="#146118" style="width:150px;min-width:150px;background:#146118;border:1px solid #146118;border-radius:14px;mso-padding-alt:13px 18px 13px 18px;">'
        . '<a href="' . h($url) . '" style="display:inline-block;width:150px;line-height:20px;padding:13px 0;color:#ffffff !important;text-decoration:none;font-weight:700;font-size:14px;text-align:center;border-radius:14px;background:#146118;box-sizing:border-box;">' . h($label) . '</a>'
        . '</td>'
        . '</tr>'
        . '</table>';
}

function email_signature_html(array $siteSettings, array $emailSettings): string
{
    $brandName = trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));
    $logoUrl = email_brand_logo_url($siteSettings);
    $contactEmail = trim((string)($emailSettings['email_reply_to'] ?? ''));

    $contactHtml = $contactEmail !== ''
        ? '<div style="margin-top:6px;color:#476146;font-size:13px;">' . h($contactEmail) . '</div>'
        : '';

    $logoHtml = '';
    if ($logoUrl !== '') {
        $logoHtml = '<div style="margin-top:18px;"><img src="' . h($logoUrl) . '" alt="' . h($brandName) . '" style="max-width:100px;max-height:100px;width:auto;height:auto;display:block;"></div>';
    }

    return '<div style="margin-top:24px;padding-top:18px;border-top:1px solid rgba(20,97,24,0.12);background:#ffffff;">'
        . '<div style="font-weight:800;font-size:14px;color:#0c2a12;">' . h($brandName) . '</div>'
        . $contactHtml
        . $logoHtml
        . '</div>';
}

function email_signature_text(array $siteSettings, array $emailSettings): string
{
    $brandName = trim((string)($siteSettings['hero_title'] ?? defaultSiteSettings()['hero_title']));
    $contactEmail = trim((string)($emailSettings['email_reply_to'] ?? ''));

    $lines = [$brandName];
    if ($contactEmail !== '') {
        $lines[] = $contactEmail;
    }
    return implode("\n", $lines);
}

function wrap_user_email_html(array $siteSettings, array $emailSettings, string $innerHtml): string
{
    return '<!doctype html><html><body style="margin:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#0c2a12;">'
        . '<div style="max-width:680px;margin:0 auto;padding:24px;">'
        . '<div style="padding:20px 20px 18px;border-radius:16px;background:#ffffff;border:0;box-shadow:none;">'
        . $innerHtml
        . email_signature_html($siteSettings, $emailSettings)
        . '</div>'
        . '</div>'
        . '</body></html>';
}

function wrap_user_email_text(array $siteSettings, array $emailSettings, string $innerText): string
{
    $innerText = rtrim($innerText);
    return $innerText . "\n\n" . email_signature_text($siteSettings, $emailSettings) . "\n";
}

function render_booking_confirmation_email(array $order, array $siteSettings, array $emailSettings): array
{
    $bookingRef = (string)($order['booking_ref'] ?? '');
    $placed = format_display_datetime($order['created_at'] ?? null, '');
    $contactName = (string)($order['contact_name'] ?? '');
    $contactEmail = (string)($order['contact_email'] ?? '');
    $total = format_price($order['total'] ?? 0);
    $items = $order['items'] ?? [];

    $subject = subject_with_prefix($emailSettings, "Booking confirmation {$bookingRef}");

    $rowsHtml = '';
    $rowsText = [];
    foreach ($items as $item) {
        $title = (string)($item['event_title'] ?? '');
        $price = format_price($item['price'] ?? 0);
        $type = ucfirst((string)($item['booking_type'] ?? 'item'));
        $meta = $item['metadata'] ?? [];
        $details = [];
        if (!empty($meta['class_label'])) {
            $details[] = 'Class: ' . $meta['class_label'];
        }
        if (!empty($meta['rider_name'])) {
            $details[] = 'Rider: ' . $meta['rider_name'];
        }
        if (!empty($meta['horse_name'])) {
            $details[] = 'Horse: ' . $meta['horse_name'];
        }
        if (!empty($item['member_number'])) {
            $details[] = 'Member no: ' . $item['member_number'];
        }
        $extras = entry_components_summary($meta);
        if ($extras !== '') {
            $details[] = 'Extras: ' . $extras;
        }
        $detailsStr = $details ? implode(' · ', $details) : '';

        $rowsHtml .= '<tr>'
            . '<td style="padding:10px 0;"><div style="font-weight:700;color:#0c2a12;">' . h($title) . '</div>'
            . ($detailsStr !== '' ? '<div style="color:#476146;font-size:13px;line-height:1.4;">' . h($detailsStr) . '</div>' : '')
            . '<div style="color:#476146;font-size:13px;">Type: ' . h($type) . '</div>'
            . '</td>'
            . '<td style="padding:10px 0;text-align:right;white-space:nowrap;font-weight:700;color:#0c2a12;">' . h($price) . '</td>'
            . '</tr>';

        $rowsText[] = "- {$title} ({$type}) — {$price}" . ($detailsStr !== '' ? " — {$detailsStr}" : '');
    }

    $htmlInner = '<div style="font-size:16px;font-weight:800;color:#0c2a12;">Booking confirmation</div>'
        . '<div style="margin-top:6px;color:#476146;">Reference: <strong>' . h($bookingRef) . '</strong></div>'
        . '<div style="margin-top:4px;color:#476146;">Placed: ' . h($placed) . '</div>'
        . '<div style="margin-top:4px;color:#476146;">Contact: ' . h($contactName) . ' · ' . h($contactEmail) . '</div>'
        . '<div style="margin-top:16px;border-top:1px solid rgba(0,0,0,0.06);padding-top:14px;">'
        . '<table style="width:100%;border-collapse:collapse;">'
        . $rowsHtml
        . '<tr><td style="padding-top:12px;border-top:1px solid rgba(0,0,0,0.06);font-weight:800;">Total</td>'
        . '<td style="padding-top:12px;border-top:1px solid rgba(0,0,0,0.06);text-align:right;font-weight:800;">' . h($total) . '</td></tr>'
        . '</table>'
        . '</div>'
        . '<div style="margin-top:18px;color:#476146;font-size:13px;">Thank you for your booking.</div>';

    $html = wrap_user_email_html($siteSettings, $emailSettings, $htmlInner);

    $text = wrap_user_email_text($siteSettings, $emailSettings, "Booking confirmation\n"
        . "Reference: {$bookingRef}\n"
        . "Placed: {$placed}\n"
        . "Contact: {$contactName} · {$contactEmail}\n\n"
        . "Items:\n"
        . implode("\n", $rowsText) . "\n\n"
        . "Total: {$total}");

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
