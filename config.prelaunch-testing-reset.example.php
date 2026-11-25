<?php
declare(strict_types=1);

// Copy ONLY to ../private/prelaunch-testing-reset.php on the intended destination.
// Literal pins must match that installation; never calculate them dynamically here.
// Remove the opt-in and create ../private/prelaunch-testing-reset.disabled before launch.
return [
    'enabled' => false,
    'web_root' => '/REPLACE/WITH/EXACT/PHYSICAL/WEB/ROOT',
    'database' => 'REPLACE_WITH_EXACT_DATABASE_NAME',
    'host' => 'ildra.witecanvas.com',
];
