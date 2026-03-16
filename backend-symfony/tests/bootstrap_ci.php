<?php

/**
 * CI-specific bootstrap: minimal setup, no Dotenv.
 * All environment variables come from GitHub Actions env block.
 */

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '0';

require __DIR__ . '/../vendor/autoload.php';

// Remove PHPStan PharAutoloader which conflicts with Symfony Kernel class loading
foreach (spl_autoload_functions() as $fn) {
    if (is_array($fn) && isset($fn[0]) && is_object($fn[0])
        && str_contains(get_class($fn[0]), 'PHPStan')) {
        spl_autoload_unregister($fn);
    }
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
