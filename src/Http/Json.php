<?php

declare(strict_types=1);

namespace Germa\Http;

final class Json
{
    /** @param array<string,mixed> $payload */
    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @param array<string,mixed> $extra */
    public static function ok(array $extra = []): never
    {
        self::send(['ok' => true] + $extra);
    }

    public static function error(string $message, int $status = 400): never
    {
        self::send(['ok' => false, 'error' => $message], $status);
    }

    /** @return array<string,mixed> */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');

        if (is_string($raw) && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }
}
