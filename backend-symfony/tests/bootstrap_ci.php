<?php

/**
 * CI bootstrap with maximum debugging for Kernel class loading issue.
 */

$log = function (string $msg) {
    fwrite(STDERR, "[BOOTSTRAP] $msg\n");
};

$checkKernel = function () use ($log) {
    $exists = class_exists('App\\Kernel', false);
    $autoloaders = array_map(function ($fn) {
        if (is_array($fn)) return (is_object($fn[0]) ? get_class($fn[0]) : $fn[0]) . '::' . $fn[1];
        return 'closure';
    }, spl_autoload_functions() ?: []);
    $log('App\\Kernel defined: ' . ($exists ? 'YES' : 'NO') . ' | Autoloaders: ' . implode(', ', $autoloaders));
};

$log('=== BOOTSTRAP START ===');
$checkKernel();

$log('Step 1: require vendor/autoload.php');
require __DIR__ . '/../vendor/autoload.php';
$checkKernel();

// Disable PHPStan PharAutoloader to test if it causes the double-include
$log('Step 1b: disabling PHPStan PharAutoloader');
define('__PHPSTAN_RUNNING__', true);
foreach (spl_autoload_functions() as $fn) {
    if (is_array($fn) && isset($fn[0]) && is_string($fn[0]) && str_contains($fn[0], 'PHPStan')) {
        spl_autoload_unregister($fn);
        $log('Unregistered: ' . $fn[0] . '::' . $fn[1]);
    }
}
$checkKernel();

$log('Step 2: set env vars');
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';

$log('Step 3: require config/bootstrap.php');
require __DIR__ . '/../config/bootstrap.php';
$checkKernel();

$log('Step 4: Dotenv bootEnv');
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->usePutenv();
try {
    $dotenv->bootEnv('../' . dirname(__DIR__) . '/.env');
} catch (\Throwable $e) {
    $log('bootEnv failed (expected in CI): ' . $e->getMessage());
}
$checkKernel();

$log('=== BOOTSTRAP COMPLETE ===');
$log('Total included files: ' . count(get_included_files()));
$kernelFiles = array_filter(get_included_files(), fn($f) => str_contains($f, 'Kernel'));
if ($kernelFiles) {
    $log('Kernel files included: ' . implode(', ', $kernelFiles));
}
