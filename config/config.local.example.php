<?php

declare(strict_types=1);

/**
 * Copy to config.local.php and edit. Never put a real key in THIS file
 * it is the committed template. config.local.php is the one git ignores.
 */

return [
    'db' => [
        'username' => 'root',
        'password' => '',
    ],

    'ai' => [
        'driver'  => 'claude',
        'api_key' => 'sk-ant-api03-REPLACE-ME',
    ],
];
