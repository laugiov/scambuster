<?php

/**
 * CI-specific bootstrap with deep Kernel loading trace.
 */

// Register tracer BEFORE anything else - catch ALL autoload calls
spl_autoload_register(function (string $class) {
    if ($class === 'App\\Kernel') {
        $bt = array_map(function ($f) {
            return ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' .
                   ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
        }, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20));
        file_put_contents('/tmp/kernel_trace.log',
            "=== AUTOLOAD App\\Kernel ===\n" . implode("\n", $bt) . "\n\n", FILE_APPEND);
    }
}, true, true);

// Also intercept include/require via a shutdown handler
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && str_contains($error['message'] ?? '', 'Kernel')) {
        $kernelFiles = array_filter(get_included_files(), fn($f) => str_contains($f, 'Kernel'));
        file_put_contents('/tmp/kernel_trace.log',
            "\n=== FATAL ERROR ===\n" . print_r($error, true) .
            "\n=== INCLUDED KERNEL FILES ===\n" . implode("\n", $kernelFiles) .
            "\n=== TOTAL FILES: " . count(get_included_files()) . " ===\n", FILE_APPEND);
    }
});

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '0';

require __DIR__ . '/../vendor/autoload.php';

// Log state after autoload
file_put_contents('/tmp/kernel_trace.log',
    "=== AFTER AUTOLOAD ===\n" .
    "App\\Kernel loaded: " . (class_exists('App\\Kernel', false) ? 'YES' : 'NO') . "\n" .
    "Autoloaders: " . count(spl_autoload_functions()) . "\n", FILE_APPEND);

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
