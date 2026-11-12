<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/auth_throttle.php';
$config=require dirname(__DIR__) . '/config.php';
$alerts=[];
$pdo=createPdo($config,$alerts);
if (!$pdo) { fwrite(STDERR,"Database connection unavailable.\n");exit(1); }
ensureAuthThrottleTable($pdo);
echo "Persistent authentication throttle table ready. Existing accounts and tokens unchanged.\n";
