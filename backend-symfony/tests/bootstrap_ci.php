<?php

/**
 * CI-specific bootstrap: minimal setup, no Dotenv, no DebugClassLoader.
 * All environment variables come from GitHub Actions env block.
 */

// Set env BEFORE autoload to prevent any automatic Dotenv loading
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '0';

require __DIR__ . '/../vendor/autoload.php';

// Debug: verify only ONE autoloader
$count = count(spl_autoload_functions());
file_put_contents('/tmp/ci_debug.log', "Autoloaders after require: $count\n");
foreach (spl_autoload_functions() as $i => $fn) {
    $name = is_array($fn) ? (is_object($fn[0]) ? get_class($fn[0]) : $fn[0]) . '::' . $fn[1] : 'other';
    file_put_contents('/tmp/ci_debug.log', "#$i: $name\n", FILE_APPEND);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
