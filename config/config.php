<?php

declare(strict_types=1);

/**
 * Germa OS base configuration.
 *
 * Do not put secrets here. Copy config.local.example.php to config.local.php
 * and override there config.local.php is ignored by git.
 */

$config = [
    'app' => [
        'name'      => 'Germa OS',
        'base_path' => '/Germa OS',      // URL prefix under htdocs
        'timezone'  => 'Africa/Kampala',
        'locale'    => 'en_UG',
        'debug'     => false,   // MUST stay false on any server: true leaks file paths to clients
    ],

    'auth' => [
        // A single bcrypt hash gates the whole app. Generate one with
        //   php bin/set-password.php
        // and paste the result into config/config.local.php (never here, this
        // file is committed). Until a hash is set, access is allowed from
        // 127.0.0.1 only, so a public server refuses everyone until you set it.
        'password_hash' => '',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'germa_os',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'money' => [
        // Everything is stored in minor units against one of these currencies.
        'base'      => 'UGX',
        'secondary' => 'USD',
        'decimals'  => ['UGX' => 0, 'USD' => 2],
        'symbols'   => ['UGX' => 'UGX', 'USD' => '$'],
    ],

    'ai' => [
        // 'rules'  deterministic, no network, no key required (default)
        // 'claude' calls the Anthropic Messages API
        'driver'      => 'rules',
        // Set the real key in config/config.local.php this file is committed.
        'api_key'     => '',
        // 'model'       => 'claude-opus-5', // claude's model i have changed to the lowest not to burn the tokens i have 
        'model'       => 'claude-sonnet-5',
        'effort'      => 'medium',   // low | medium | high | xhigh | max
        // Caps thinking + response together adaptive thinking is on by
        // default on Opus 5, so leave headroom or briefs truncate mid-answer.
        'max_tokens'  => 8000,
        'timeout'     => 60,
        // Re-run a refused request on Anthropic's recommended fallback model.
        'fallbacks'   => true,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        foreach ($overrides as $section => $values) {
            $config[$section] = is_array($values) && isset($config[$section]) && is_array($config[$section])
                ? array_replace($config[$section], $values)
                : $values;
        }
    }
}

return $config;
