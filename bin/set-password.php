<?php

declare(strict_types=1);

/**
 * Generates the bcrypt hash that gates Germa OS.
 *
 * Usage:
 *   php bin/set-password.php                 prompts for a password
 *   php bin/set-password.php "the password"  takes it as an argument
 *
 * It never writes the hash anywhere. It prints the line to paste into
 * config/config.local.php, so the secret only ever lands in the gitignored file
 * you control, not in anything this script touches.
 */

require __DIR__ . '/../bootstrap.php';

$password = $argv[1] ?? null;

if ($password === null) {
    // Read without echoing where the terminal allows it.
    if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false) {
        shell_exec('stty -echo 2>/dev/null');
    }
    fwrite(STDOUT, 'New password: ');
    $password = trim((string) fgets(STDIN));
    if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
}

if (strlen($password) < 10) {
    fwrite(STDERR, "Refusing a password under 10 characters. This is the only lock on the whole system.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

if ($hash === false) {
    fwrite(STDERR, "Hashing failed.\n");
    exit(1);
}

echo "\nPaste this into config/config.local.php, inside the returned array:\n\n";
echo "    'auth' => [\n";
echo "        'password_hash' => " . var_export($hash, true) . ",\n";
echo "    ],\n\n";
echo "Then reload the app. Any existing sessions are invalidated by the change.\n";
