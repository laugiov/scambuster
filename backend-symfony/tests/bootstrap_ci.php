<?php

/**
 * CI-specific bootstrap that skips Dotenv file loading.
 * All environment variables are injected via GitHub Actions env block and phpunit.ci.xml.
 */

file_put_contents('/tmp/kernel_debug.log',
    'Bootstrap start' . "\n" .
    'App\Kernel already loaded: ' . (class_exists('App\Kernel', false) ? 'YES' : 'NO') . "\n" .
    'Included Kernel files: ' . implode(', ', array_filter(get_included_files(), fn($f) => str_contains($f, 'Kernel'))) . "\n"
);

$loader = require __DIR__ . '/../vendor/autoload.php';

file_put_contents('/tmp/kernel_debug.log',
    'After autoload require' . "\n" .
    'App\Kernel loaded: ' . (class_exists('App\Kernel', false) ? 'YES' : 'NO') . "\n" .
    'ClassMap has App\\Kernel: ' . (array_key_exists('App\\Kernel', $loader->getClassMap()) ? 'YES' : 'NO') . "\n",
    FILE_APPEND
);

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
