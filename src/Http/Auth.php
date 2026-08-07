<?php

declare(strict_types=1);

namespace Germa\Http;

use Germa\Support\Config;

/**
 * The gate in front of the whole application.
 *
 * Germa OS began as a single-operator tool with no login, which was correct on a
 * private machine and a serious hole the moment it faces a network. This class is
 * the fix: nothing renders and no endpoint answers until the operator has proven
 * who they are.
 *
 * It authenticates against one bcrypt hash held in config (out of the committed
 * file, in config.local.php). That is deliberately the smallest thing that works:
 * the login form, the session handling and the gate are all reusable, so when the
 * product grows a real users table the only thing that changes is where the hash
 * is looked up. The plumbing does not move.
 *
 * Two safety rules shape the awkward edges.
 *
 * Until a password is set, access is allowed from the loopback address only. That
 * keeps first-run and local development working with no password, while a public
 * server refuses everyone until a password exists, so an unconfigured deployment
 * fails closed rather than open.
 *
 * The brute-force throttle is best-effort and fails open on any I/O problem. A
 * throttle that can lock the real operator out of their own finances because a
 * temp file could not be written is worse than the attack it prevents.
 */
final class Auth
{
    private const SESSION_KEY = 'germa_auth';
    private const MAX_ATTEMPTS = 8;
    private const WINDOW       = 900;  // 15 minutes
    private const LOCKOUT      = 900;  // 15 minutes

    /** Whether a password has been configured at all. */
    public static function isConfigured(): bool
    {
        return self::hash() !== '';
    }

    /**
     * Whether the current request is allowed through.
     *
     * With no password set, only the loopback address is admitted, so a public
     * deployment is locked until someone sets one. With a password set, a valid
     * session bound to the current hash is required: rotating the password
     * invalidates every existing session, which is the point of binding to it.
     */
    public static function check(): bool
    {
        Csrf::start();

        if (!self::isConfigured()) {
            return self::isLoopback();
        }

        $marker = (string) ($_SESSION[self::SESSION_KEY] ?? '');

        return $marker !== '' && hash_equals(self::marker(), $marker);
    }

    /**
     * Verify a submitted password and, on success, establish the session.
     *
     * Constant-time comparison is handled by password_verify. The session id is
     * regenerated on success so a token fixed before login cannot be reused after
     * it. A failed attempt is recorded for the throttle; a successful one clears
     * the record for this address.
     */
    public static function attempt(string $password): bool
    {
        Csrf::start();

        if (!self::isConfigured()) {
            return false;
        }

        $ok = password_verify($password, self::hash());

        self::record($ok);

        if ($ok) {
            // A new id after authentication defeats session fixation.
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY] = self::marker();
        }

        return $ok;
    }

    public static function logout(): void
    {
        Csrf::start();

        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * The throttle verdict for the current address.
     *
     * @return array{locked:bool,retry_after:int}
     */
    public static function throttle(): array
    {
        $record = self::readThrottle()[self::client()] ?? null;

        if ($record === null || ($record['locked_until'] ?? 0) <= time()) {
            return ['locked' => false, 'retry_after' => 0];
        }

        return ['locked' => true, 'retry_after' => (int) $record['locked_until'] - time()];
    }

    // ── Request context ─────────────────────────────────────────────────────

    /**
     * Whether the connection is secure, so the session cookie can carry the
     * Secure flag. A reverse proxy is trusted only via X-Forwarded-Proto, which
     * matters once this sits behind one in production.
     */
    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded === 'https') {
            return true;
        }

        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private static function isLoopback(): bool
    {
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private static function hash(): string
    {
        return trim((string) Config::get('auth.password_hash', ''));
    }

    /**
     * A session marker derived from the password hash. Storing the hash of the
     * hash means a session cannot be used to recover the credential, and changing
     * the password changes the marker, which logs every old session out.
     */
    private static function marker(): string
    {
        return hash('sha256', 'germa-auth|' . self::hash());
    }

    /** A stable, non-identifying key for the caller, for throttle bucketing. */
    private static function client(): string
    {
        return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    private static function record(bool $success): void
    {
        $this_ = self::client();
        $now   = time();

        $records = self::readThrottle();

        if ($success) {
            unset($records[$this_]);
            self::writeThrottle($records);
            return;
        }

        $record = $records[$this_] ?? ['count' => 0, 'first' => $now, 'locked_until' => 0];

        // A fresh window if the last burst has aged out.
        if ($now - (int) $record['first'] > self::WINDOW) {
            $record = ['count' => 0, 'first' => $now, 'locked_until' => 0];
        }

        $record['count'] = (int) $record['count'] + 1;

        if ($record['count'] >= self::MAX_ATTEMPTS) {
            $record['locked_until'] = $now + self::LOCKOUT;
        }

        $records[$this_] = $record;
        self::writeThrottle($records);
    }

    /** @return array<string,array{count:int,first:int,locked_until:int}> */
    private static function readThrottle(): array
    {
        $file = self::throttleFile();

        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $records */
    private static function writeThrottle(array $records): void
    {
        $now = time();

        // Never let the file grow without bound: drop entries no longer relevant.
        foreach ($records as $key => $record) {
            $stale = $now - (int) ($record['first'] ?? 0) > self::WINDOW
                && (int) ($record['locked_until'] ?? 0) <= $now;
            if ($stale) {
                unset($records[$key]);
            }
        }

        $dir = dirname(self::throttleFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        // Fail open: a throttle that cannot write must not block a real login.
        @file_put_contents(self::throttleFile(), json_encode($records), LOCK_EX);
    }

    private static function throttleFile(): string
    {
        return GERMA_ROOT . '/storage/auth-throttle.json';
    }
}
