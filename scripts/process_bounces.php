#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../cms.php';
require_once __DIR__ . '/../email.php';

function bounce_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function bounce_mailbox_string(array $mailbox, string $folder = 'INBOX'): string
{
    $host = trim((string)($mailbox['host'] ?? ''));
    $port = (int)($mailbox['port'] ?? 993);
    $secure = strtolower(trim((string)($mailbox['secure'] ?? 'ssl')));
    $flags = '/imap';
    if ($secure === 'ssl') {
        $flags .= '/ssl/novalidate-cert';
    } elseif ($secure === 'tls') {
        $flags .= '/tls/novalidate-cert';
    } else {
        $flags .= '/notls';
    }
    return sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);
}

function bounce_extract_token(string $headers, string $body): array
{
    $text = $headers . "\n" . $body;
    if (preg_match('/bounces\+([a-z0-9]+)-(\d{14}-[a-f0-9]{16})@[-a-z0-9.]+/i', $text, $m)) {
        return [strtolower($m[1]), $m[2], 'alias'];
    }
    $site = '';
    if (preg_match('/^X-CMS-Site:\s*([a-z0-9_-]+)/mi', $text, $m)) {
        $site = strtolower($m[1]);
    }
    if (preg_match('/^X-CMS-Bounce-Token:\s*([a-z0-9-]+)/mi', $text, $m)) {
        return [$site, trim($m[1]), 'header'];
    }
    return ['', '', 'none'];
}

function bounce_excerpt(string $headers, string $body): string
{
    $text = $headers . "\n" . $body;
    foreach ([
        '/^Diagnostic-Code:\s*(.+)$/mi',
        '/^Status:\s*(.+)$/mi',
        '/^Action:\s*(.+)$/mi',
        '/^Subject:\s*(.+)$/mi',
    ] as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }
    }
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?: '');
    if (function_exists('mb_substr')) {
        return mb_substr($plain, 0, 500);
    }
    return substr($plain, 0, 500);
}

function bounce_create_folder($imap, array $mailbox, string $folder): void
{
    $full = bounce_mailbox_string($mailbox, $folder);
    $encoded = function_exists('imap_utf7_encode') ? imap_utf7_encode($full) : $full;
    @imap_createmailbox($imap, $encoded);
}

$config = require __DIR__ . '/../config.php';
$alerts = [];
$pdo = createPdo($config, $alerts);
if (!$pdo) {
    bounce_log('Database connection failed.');
    exit(1);
}

ensureEmailTables($pdo);
$emailConfig = email_environment_config();
$mailbox = is_array($emailConfig['bounce_mailbox'] ?? null) ? $emailConfig['bounce_mailbox'] : [];
$siteCodeDefault = email_site_code($emailConfig);
if (!$mailbox) {
    bounce_log('No bounce mailbox configured.');
    exit(1);
}

$user = (string)($mailbox['username'] ?? '');
$pass = (string)($mailbox['password'] ?? '');
$inbox = bounce_mailbox_string($mailbox, (string)($mailbox['mailbox'] ?? 'INBOX'));
$imap = @imap_open($inbox, $user, $pass, 0, 1);
if (!$imap) {
    bounce_log('IMAP open failed: ' . implode(' | ', imap_errors() ?: []));
    exit(1);
}

$count = imap_num_msg($imap);
$matched = 0;
$unmatched = 0;
$moved = 0;
bounce_log('Scanning ' . $count . ' mailbox message(s).');

for ($i = 1; $i <= $count; $i++) {
    $headers = (string)imap_fetchheader($imap, $i, FT_PEEK);
    $body = (string)imap_body($imap, $i, FT_PEEK);
    [$siteCode, $token, $source] = bounce_extract_token($headers, $body);
    if ($siteCode === '') {
        $siteCode = $siteCodeDefault;
    }
    if ($token === '') {
        $unmatched++;
        continue;
    }
    $row = findEmailLogByBounceToken($pdo, $siteCode, $token);
    if (!$row) {
        $unmatched++;
        continue;
    }

    $reason = bounce_excerpt($headers, $body);
    $bounceMeta = [
        'processed_at' => date('Y-m-d H:i:s'),
        'site_code' => $siteCode,
        'bounce_token' => $token,
        'source' => $source,
        'mailbox_subject' => preg_match('/^Subject:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '',
        'reason_excerpt' => $reason,
    ];
    markEmailLogBounced($pdo, (int)$row['id'], $bounceMeta, $reason !== '' ? $reason : 'Delivery bounced.');
    $matched++;

    $folder = 'INBOX.Processed' . ($siteCode !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', strtolower($siteCode)) : '');
    if ($folder === 'INBOX.Processed.') {
        $folder = 'INBOX.Processed';
    }
    bounce_create_folder($imap, $mailbox, $folder);
    if (@imap_mail_move($imap, (string)$i, $folder)) {
        $moved++;
    } else {
        bounce_log('Matched log #' . (int)$row['id'] . ' but move failed: ' . implode(' | ', imap_errors() ?: []));
    }
}

imap_expunge($imap);
imap_close($imap);
imap_errors();
imap_alerts();
bounce_log('Done. matched=' . $matched . ' moved=' . $moved . ' unmatched_left=' . $unmatched . '.');
