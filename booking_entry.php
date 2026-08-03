<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if ($pdo && $itemId > 0 && ensure_bookings_tables($pdo)) {
    $guid = ensure_booking_item_guid($pdo, $itemId);
    if ($guid) {
        header('Location: ' . $basePath . '/entry_hub.php?code=' . urlencode((string)$guid), true, 302);
        exit;
    }
}
http_response_code(404);
echo 'Entry not found.';
exit;
