<?php

/**
 * CI bootstrap with permanent include tracking.
 *
 * Root cause: Composer's ClassLoader::loadClass uses plain `include` (not include_once).
 * Between two tests, KernelTestCase::ensureKernelShutdown() is called but doesn't
 * undefine the class. However, class_exists() still triggers the autoloader for the
 * second test, causing Composer to re-include src/Kernel.php → fatal error.
 *
 * Fix: replace Composer's `include` with `include_once` in our wrapper.
 */

require __DIR__ . '/../vendor/autoload.php';

// Replace Composer's autoloader with include_once wrapper
foreach (spl_autoload_functions() as $loader) {
    if (is_array($loader) && isset($loader[0]) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $composerLoader = $loader[0];
        spl_autoload_unregister($loader);
        spl_autoload_register(
            function (string $class) use ($composerLoader): void {
                if (\class_exists($class, false) || \interface_exists($class, false) || \trait_exists($class, false)) {
                    return; // Already defined, skip
                }
                $file = $composerLoader->findFile($class);
                if ($file !== false) {
                    include_once $file;
                }
            },
            true,
            true
        );
        break;
    }
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';

require __DIR__ . '/../config/bootstrap.php';

try {
    $dotenv = new \Symfony\Component\Dotenv\Dotenv();
    $dotenv->usePutenv();
    $dotenv->bootEnv('../' . dirname(__DIR__) . '/.env');
} catch (\Throwable $e) {
    // Expected in CI: path doesn't resolve outside Docker
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
