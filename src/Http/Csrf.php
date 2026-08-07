<?php

declare(strict_types=1);

namespace Germa\Http;

/**
 * The app is single-user and local, but every endpoint here moves money or
 * changes the record of it. A one-line token check costs nothing and stops a
 * page in another tab from posting on Gerald's behalf.
 */
final class Csrf
{
    private const KEY = 'germa_csrf';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                // Set once the site is on HTTPS so the session cookie never
                // travels in clear. Left off on plain HTTP, or login breaks
                // locally before a certificate is in place.
                'cookie_secure'   => Auth::isHttps(),
            ]);
        }
    }

    public static function token(): string
    {
        self::start();

        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::KEY];
    }

    /** @param array<string,mixed> $body */
    public static function check(array $body): bool
    {
        self::start();

        $sent = (string) ($body['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $held = (string) ($_SESSION[self::KEY] ?? '');

        return $held !== '' && hash_equals($held, $sent);
    }
}
