<?php

/**
 * CI-specific bootstrap that skips Dotenv file loading.
 * All environment variables are injected via GitHub Actions env block and phpunit.ci.xml.
 */

// Capture fatal error details
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && str_contains($error['message'] ?? '', 'Kernel')) {
        $kernelFiles = array_filter(get_included_files(), fn($f) => str_contains($f, 'Kernel'));
        file_put_contents('/tmp/kernel_fatal.log',
            "=== FATAL ERROR ===\n" .
            print_r($error, true) . "\n" .
            "=== ALL INCLUDED FILES WITH 'Kernel' ===\n" .
            implode("\n", $kernelFiles) . "\n" .
            "=== TOTAL INCLUDED FILES: " . count(get_included_files()) . " ===\n"
        );
    }
});

require __DIR__ . '/../vendor/autoload.php';

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
