<?php

/**
 * CI-specific bootstrap that skips Dotenv file loading.
 * All environment variables are injected via GitHub Actions env block and phpunit.ci.xml.
 */

// Trace App\Kernel loading to debug double-declaration issue
spl_autoload_register(function ($class) {
    if ($class === 'App\Kernel') {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $frames = [];
        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? '?';
            $line = $frame['line'] ?? '?';
            $fn = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $frames[] = "#$i $file:$line $fn";
        }
        file_put_contents('/tmp/kernel_autoload_trace.log', implode("\n", $frames) . "\n\n", FILE_APPEND);
    }
}, true, true);

require __DIR__ . '/../vendor/autoload.php';

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
