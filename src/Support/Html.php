<?php

declare(strict_types=1);

namespace Germa\Support;

final class Html
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Percentage string clamped to 0–100, safe to drop straight into a width: rule. */
    public static function pct(int|float $value): string
    {
        return max(0, min(100, (int) round($value))) . '%';
    }

    /** Bar widths get a floor so a non-zero value is never invisible. */
    public static function barWidth(int|float $value, int $floor = 2): string
    {
        $clamped = max(0, min(100, (float) $value));
        return ($clamped > 0 && $clamped < $floor ? $floor : (int) round($clamped)) . '%';
    }

    public static function url(string $path = ''): string
    {
        $base = rtrim((string) Config::get('app.base_path', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        $file    = GERMA_ROOT . '/public/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return self::url('public/' . ltrim($path, '/')) . '?v=' . $version;
    }
}
