<?php

/**
 * CI bootstrap with Composer autoloader re-entrance guard.
 *
 * Root cause: Composer's ClassLoader::loadClass uses plain `include` (not include_once)
 * with no re-entrance protection. During compilation of src/Kernel.php, PHP resolves
 * parent class (BaseKernel) via autoload. Something in that chain re-triggers autoloading
 * of App\Kernel, causing Composer to include src/Kernel.php a second time → fatal error.
 *
 * This wrapper tracks files currently being compiled and skips re-entrance.
 * See: local/tasks/KERNEL-ISSUE-FINAL-FINDINGS.md
 */

require __DIR__ . '/../vendor/autoload.php';

// Wrap Composer's ClassLoader with re-entrance protection
$loading = [];
foreach (spl_autoload_functions() as $loader) {
    if (is_array($loader) && isset($loader[0]) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $composerLoader = $loader[0];
        spl_autoload_unregister($loader);
        spl_autoload_register(
            function (string $class) use ($composerLoader, &$loading): void {
                $file = $composerLoader->findFile($class);
                if ($file === false) {
                    return;
                }
                $realFile = realpath($file) ?: $file;
                if (isset($loading[$realFile])) {
                    return; // Re-entrance: file already being compiled
                }
                $loading[$realFile] = true;
                try {
                    include $file;
                } finally {
                    unset($loading[$realFile]);
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

$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->usePutenv();
try {
    $dotenv->bootEnv('../' . dirname(__DIR__) . '/.env');
} catch (\Throwable $e) {
    // Expected in CI: the path doesn't resolve outside Docker
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
