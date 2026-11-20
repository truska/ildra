<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!$currentUser) {
    header('Location: /');
    exit;
}

header('Location: admin/index.php');
exit;
