<?php

/**
 * CI-specific bootstrap: skips Dotenv file loading (path incompatible outside Docker).
 * All environment variables come from GitHub Actions env block and phpunit.ci.xml.
 */

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '0';

require __DIR__ . '/../vendor/autoload.php';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
