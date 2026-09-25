<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$decoded = is_string($raw) ? json_decode($raw, true) : null;
$report = is_array($decoded) ? ($decoded['csp-report'] ?? ($decoded[0]['body'] ?? null)) : null;
if (!is_array($report)) {
    http_response_code(400);
    exit;
}

$entry = [
    'received_at' => gmdate('c'),
    'document_uri' => substr((string)($report['document-uri'] ?? $report['documentURL'] ?? ''), 0, 2048),
    'blocked_uri' => substr((string)($report['blocked-uri'] ?? $report['blockedURL'] ?? ''), 0, 2048),
    'effective_directive' => substr((string)($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? ''), 0, 255),
    'violated_directive' => substr((string)($report['violated-directive'] ?? ''), 0, 255),
];
// Use the configured PHP error log: it is already writable by the web worker
// and retained with the deployment logs. Review entries prefixed CSP_REPORT
// before changing the policy from report-only to enforced.
error_log('CSP_REPORT ' . json_encode($entry, JSON_UNESCAPED_SLASHES));

http_response_code(204);
