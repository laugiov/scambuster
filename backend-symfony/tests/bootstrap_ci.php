<?php

/**
 * CI bootstrap with Composer autoloader guard.
 *
 * Problem: During compilation of class App\Kernel extends BaseKernel,
 * something re-triggers autoloading of App\Kernel. Composer's ClassLoader
 * uses plain `include` (not include_once) which causes double-include.
 *
 * Fix: Replace Composer's autoloader with a wrapper that uses include_once.
 * The realpath() call ensures consistent path resolution.
 */

require __DIR__ . '/../vendor/autoload.php';

// Replace Composer's autoloader with include_once wrapper
foreach (spl_autoload_functions() as $loader) {
    if (is_array($loader) && isset($loader[0]) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $composerLoader = $loader[0];
        spl_autoload_unregister($loader);
        spl_autoload_register(
            function (string $class) use ($composerLoader): void {
                if (\class_exists($class, false) || \interface_exists($class, false) || \trait_exists($class, false) || \enum_exists($class, false)) {
                    return;
                }
                $file = $composerLoader->findFile($class);
                if ($file !== false) {
                    $resolved = \realpath($file);
                    if ($resolved !== false) {
                        include_once $resolved;
                    } else {
                        include_once $file;
                    }
                }
            },
            true,
            true
        );
        break;
    }
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '0';

require __DIR__ . '/../config/bootstrap.php';

try {
    $dotenv = new \Symfony\Component\Dotenv\Dotenv();
    $dotenv->usePutenv();
    $dotenv->bootEnv(dirname(__DIR__) . '/.env');
} catch (\Throwable $e) {
    // Expected in CI
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
