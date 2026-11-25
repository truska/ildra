<?php
declare(strict_types=1);
require_once __DIR__ . '/admin_actions.php';

/** Never expose credential values, including nested keys, in audit details. */
function adminAuditSafeValue(mixed $value, string $key = ''): mixed
{
    if (preg_match('/password|passwd|secret|token|credential|cookie|authorization|(?:^|_)api_key|access_key|private_key/i', $key)) {
        return is_array($value) && array_key_exists('before', $value) && array_key_exists('after', $value)
            ? ['before'=>'[redacted]', 'after'=>'[redacted]'] : '[redacted]';
    }
    if (is_array($value)) {
        foreach ($value as $name => $item) $value[$name] = adminAuditSafeValue($item, (string)$name);
    }
    return $value;
}

function adminAuditSettingsDiff(array $before, array $after): array
{
    $changes = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;
        if ((is_scalar($old) && is_scalar($new)) ? (string)$old === (string)$new : $old === $new) continue;
        $changes[$key] = ['before'=>adminAuditSafeValue($old, (string)$key), 'after'=>adminAuditSafeValue($new, (string)$key)];
    }
    return $changes;
}

/** Capture persisted changes even when a multi-step settings save partly fails. */
function adminAuditSettingsOperation(PDO $pdo, array $actor, string $section, callable $snapshot, callable $save): mixed
{
    $before = $snapshot();
    $result = false;
    try {
        $result = $save();
        return $result;
    } finally {
        try {
            $changes = adminAuditSettingsDiff($before, $snapshot());
            if ($changes) adminAuditLog($pdo, 'settings.update', $actor, 'settings', $section, [
                'section'=>$section, 'outcome'=>$result === false ? 'partial' : 'success',
                'actor_name'=>trim((string)($actor['first_name'] ?? '') . ' ' . (string)($actor['last_name'] ?? '')),
                'actor_email'=>(string)($actor['email'] ?? ''), 'actor_role'=>(string)($actor['role'] ?? ''),
                'changes'=>$changes,
            ]);
        } catch (Throwable $e) {
            error_log('Settings audit capture failed: ' . get_class($e));
        }
    }
}

function adminSaveSiteSettings(PDO $pdo, array $data, array &$alerts, array $actor, string $section): bool
{
    return adminAuditSettingsOperation($pdo, $actor, $section,
        static fn() => array_intersect_key(getSiteSettings($pdo), $data),
        static function () use ($pdo, $data, &$alerts) { return saveSiteSettings($pdo, $data, $alerts); });
}

/** Ignore replacement row IDs/timestamps when comparing footer links. */
function adminAuditFooterRows(array $rows): array
{
    return array_map(static function (array $row): array {
        unset($row['id'], $row['created_at'], $row['updated_at']);
        return $row;
    }, $rows);
}
