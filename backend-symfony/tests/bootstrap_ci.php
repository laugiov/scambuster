<?php

/**
 * CI-specific bootstrap that skips Dotenv file loading.
 * All environment variables are injected via GitHub Actions env block and phpunit.ci.xml.
 */

require __DIR__ . '/../vendor/autoload.php';

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
