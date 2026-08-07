<?php

declare(strict_types=1);

namespace Germa\Support;

/**
 * Mutable operating parameters that live in the database rather than in config,
 * because the spec says these numbers change and must never be treated as fixed
 * constants (survival requirement, exchange rate, daily contact target...).
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key, (string) $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = strtolower(self::get($key, $default ? '1' : '0'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function money(string $key, ?string $currency = null): Money
    {
        return Money::of(self::int($key), $currency ?? Money::base());
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            ['k' => $key, 'v' => $value]
        );
        self::$cache[$key] = $value;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];
        foreach (Database::all('SELECT `key`, `value` FROM settings') as $row) {
            self::$cache[(string) $row['key']] = (string) $row['value'];
        }
    }
}
