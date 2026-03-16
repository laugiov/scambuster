<?php

/**
 * CI-specific bootstrap: loads config/bootstrap.php (with DebugClassLoader)
 * but skips the Dotenv bootEnv call (incompatible path outside Docker).
 * All environment variables are injected via GitHub Actions env block.
 */

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '1';

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
