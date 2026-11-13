<?php
declare(strict_types=1);

function entryHubEnabled(array $settings): bool
{
    return (string)($settings['entry_hub_enabled'] ?? '0') === '1';
}

function requireEntryHubEnabled(array $settings): void
{
    // Stop before looking up either a GUID or a numeric booking item ID.
    if (entryHubEnabled($settings)) return;
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Entry Hub is unavailable.';
    exit;
}
