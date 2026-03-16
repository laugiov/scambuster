<?php

/**
 * CI bootstrap with Composer autoloader re-entrance guard + full logging.
 */

$log = function (string $msg) {
    fwrite(STDERR, "[CI] $msg\n");
};

require __DIR__ . '/../vendor/autoload.php';

// Wrap Composer's ClassLoader with re-entrance protection + logging
$loading = [];
$wrapped = false;
foreach (spl_autoload_functions() as $loader) {
    if (is_array($loader) && isset($loader[0]) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $composerLoader = $loader[0];
        spl_autoload_unregister($loader);
        spl_autoload_register(
            function (string $class) use ($composerLoader, &$loading, $log): void {
                if ($class === 'App\\Kernel') {
                    $log("AUTOLOAD App\\Kernel requested (loading=" . implode(',', array_keys($loading)) . ")");
                    $log("  class_exists(false)=" . (class_exists('App\\Kernel', false) ? 'YES' : 'NO'));
                    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                    foreach ($bt as $i => $f) {
                        $log("  #$i " . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''));
                    }
                }
                $file = $composerLoader->findFile($class);
                if ($file === false) {
                    return;
                }
                $realFile = realpath($file) ?: $file;
                if (isset($loading[$realFile])) {
                    $log("RE-ENTRANCE BLOCKED for $class ($realFile)");
                    return;
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
        $wrapped = true;
        $log("Composer ClassLoader wrapped with re-entrance guard");
        break;
    }
}
if (!$wrapped) {
    $log("WARNING: Composer ClassLoader NOT found in autoloader chain!");
}

// Remove PHPStan PharAutoloader
foreach (spl_autoload_functions() as $fn) {
    if (is_array($fn) && isset($fn[0]) && is_string($fn[0]) && str_contains($fn[0], 'PHPStan')) {
        spl_autoload_unregister($fn);
        $log("Removed PHPStan PharAutoloader");
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
    // Expected in CI
}

$log("Bootstrap complete. Autoloaders: " . count(spl_autoload_functions()));
$log("App\\Kernel defined: " . (class_exists('App\\Kernel', false) ? 'YES' : 'NO'));
