<?php

declare(strict_types=1);

namespace Germa\Support;

/**
 * Minimal PSR-4 autoloader for the Germa\ namespace. No Composer required
 * the app has to run on a stock XAMPP install with nothing to install first.
 */
final class Autoloader
{
    public static function register(string $srcDir): void
    {
        $srcDir = rtrim(str_replace('\\', '/', $srcDir), '/');

        spl_autoload_register(static function (string $class) use ($srcDir): void {
            if (!str_starts_with($class, 'Germa\\')) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen('Germa\\')));
            $file     = $srcDir . '/' . $relative . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
