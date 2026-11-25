<?php
declare(strict_types=1);
require_once __DIR__ . '/import_workspace.php';

/** A separate destination-local opt-in; never enable the development import tools. */
function prelaunchTestingResetConfig(): array
{
    $directory = __DIR__ . '/../private';
    clearstatcache();
    if (file_exists($directory . '/prelaunch-testing-reset.disabled')) return [];
    $path = $directory . '/prelaunch-testing-reset.php';
    if (!is_readable($path)) return [];
    try {
        $config = require $path;
        return is_array($config) ? $config : [];
    } catch (Throwable $e) {
        error_log('Pre-launch reset configuration could not be loaded.');
        return [];
    }
}

/** Exact physical root/database pins are independent of the untrusted Host header. */
function prelaunchTestingResetEnvironmentAllowed(array $config, string $root, string $database, string $host): bool
{
    return ($config['enabled'] ?? false) === true
        && $root !== '' && $database !== ''
        && ($config['web_root'] ?? null) === $root
        && ($config['database'] ?? null) === $database
        && ($config['host'] ?? null) === 'ildra.witecanvas.com'
        && strtolower($host) === 'ildra.witecanvas.com';
}

function testingResetEnvironmentEnabled(?PDO $pdo, string $host): bool
{
    if (!$pdo) return false;
    try {
        $root = (string)realpath(__DIR__);
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        return prelaunchTestingResetEnvironmentAllowed(prelaunchTestingResetConfig(), $root, $database, $host)
            || importWorkspaceEnvironmentAllowed(importWorkspaceConfig(), $root, $database, $host);
    } catch (Throwable $e) {
        return false;
    }
}

function testingResetEnvironmentFingerprint(PDO $pdo, string $host): string
{
    return hash('sha256', (string)realpath(__DIR__) . "\0" . (string)$pdo->query('SELECT DATABASE()')->fetchColumn() . "\0" . strtolower($host));
}
