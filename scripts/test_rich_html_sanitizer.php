<?php
declare(strict_types=1);

require __DIR__ . '/../helpers.php';

$payload = '<p onclick="alert(1)">Hello <strong>world</strong> <a href="javascript:alert(2)">bad link</a> <a href="https://example.test/path" target="_blank">good link</a><img src="data:image/svg+xml,boom" onerror="alert(3)"><script>alert(4)</script></p>';
$sanitized = sanitize_rich_html($payload);

$required = ['<p>Hello <strong>world</strong>', '<a>bad link</a>', 'href="https://example.test/path"', 'target="_blank"', 'rel="noopener noreferrer"', '<img>'];
$forbidden = ['onclick', 'onerror', 'javascript:', 'data:image', '<script', 'alert(4)'];

foreach ($required as $fragment) {
    if (!str_contains($sanitized, $fragment)) {
        fwrite(STDERR, "Expected formatting was not retained: {$fragment}\n");
        exit(1);
    }
}
foreach ($forbidden as $fragment) {
    if (stripos($sanitized, $fragment) !== false) {
        fwrite(STDERR, "Unsafe fragment survived sanitisation: {$fragment}\n");
        exit(1);
    }
}

echo "Rich HTML sanitiser tests passed.\n";
