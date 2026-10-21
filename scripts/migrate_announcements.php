<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/cms.php';
$root = dirname(__DIR__);
$config = require is_file($root . '/../private/config.php') ? $root . '/../private/config.php' : $root . '/config.php';
$alerts = [];
$pdo = createPdo($config, $alerts);
if (!$pdo) { fwrite(STDERR, "Database connection unavailable.\n"); exit(1); }
ensure_announcements_table($pdo, getSiteSettings($pdo));
echo "Announcement table ready; existing Manual and Super notices preserved.\n";
