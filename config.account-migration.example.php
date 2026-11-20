<?php
// Copy to ../private/account-migration.php on the destination; fill in its actual
// realpath(web root), database and existing operator ID. Keep the deployed private file out of Git.
return [
    'enabled'=>false,             // Enable HTTP migration admin/sending only after verification.
    'allow_imports'=>false,       // Separate, temporary CLI import permission.
    'web_root'=>'/absolute/real/path/to/web',
    'database'=>'destination_database',
    'host'=>'enduranceridingireland.com',
    'public_origin'=>'https://enduranceridingireland.com',
    'operator_user_id'=>0,        // Existing active Developer/Superadmin for CLI imports.
    'delivery_mode'=>'redirect',  // Use redirect for rehearsal; direct only after DNS/TLS checks.
];
