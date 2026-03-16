<?php

/**
 * CI-specific bootstrap that skips Dotenv file loading.
 * All environment variables are injected via GitHub Actions env block and phpunit.ci.xml.
 */

// Check if App\Kernel was already loaded BEFORE bootstrap ran
$alreadyLoaded = class_exists('App\Kernel', false);
file_put_contents('/tmp/kernel_debug.log',
    'Bootstrap start: App\Kernel already loaded = ' . ($alreadyLoaded ? 'YES' : 'NO') . "\n" .
    'Included files with Kernel: ' . implode(', ', array_filter(get_included_files(), fn($f) => str_contains($f, 'Kernel.php'))) . "\n" .
    'PHP SAPI: ' . PHP_SAPI . "\n" .
    'opcache.preload: ' . (ini_get('opcache.preload') ?: 'none') . "\n" .
    'auto_prepend_file: ' . (ini_get('auto_prepend_file') ?: 'none') . "\n" .
    'Classmap has App\\Kernel: ' . (isset(\Composer\Autoload\ClassLoader::class) ? 'check below' : 'no classloader') . "\n"
);

// Dump autoload classmap for App\Kernel
$loader = require __DIR__ . '/../vendor/autoload.php';
$classMap = $loader->getClassMap();
file_put_contents('/tmp/kernel_debug.log',
    'After autoload: App\Kernel loaded = ' . (class_exists('App\Kernel', false) ? 'YES' : 'NO') . "\n" .
    'ClassMap has App\\Kernel: ' . (isset($classMap['App\\Kernel']) ? 'YES -> ' . $classMap['App\\Kernel'] : 'NO') . "\n",
    FILE_APPEND
);

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
