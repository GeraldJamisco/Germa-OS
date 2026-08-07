<?php

declare(strict_types=1);

/**
 * Germa OS bootstrap. Every entry point (index.php, bin/*.php) requires this
 * exactly once.
 */

if (!defined('GERMA_ROOT')) {
    define('GERMA_ROOT', __DIR__);
}

require __DIR__ . '/src/Support/Autoloader.php';

use Germa\Support\Autoloader;
use Germa\Support\Config;

Autoloader::register(GERMA_ROOT . '/src');

// Composer supplies the official Anthropic PHP SDK. The app degrades to the
// rules-based AI driver when vendor/ is absent, so this is not fatal.
if (is_file(GERMA_ROOT . '/vendor/autoload.php')) {
    require GERMA_ROOT . '/vendor/autoload.php';
}

Config::init(require GERMA_ROOT . '/config/config.php');

date_default_timezone_set((string) Config::get('app.timezone', 'Africa/Kampala'));

mb_internal_encoding('UTF-8');

if (Config::get('app.debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
