<?php
declare(strict_types=1);

// Copy to database.local.php and replace placeholders privately.
// Use a dedicated account with only SELECT, INSERT, UPDATE on lab2.* at runtime.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'lab2',
    'username' => 'REPLACE_WITH_DEDICATED_USER',
    'password' => 'REPLACE_WITH_PRIVATE_PASSWORD',
];
