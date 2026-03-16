<?php

/**
 * CI bootstrap that pre-loads App\Kernel to prevent double-include.
 *
 * Root cause: Composer's ClassLoader uses plain `include` (not include_once),
 * and something in the CI environment causes class_exists('App\Kernel', true)
 * to trigger the autoloader even when the class is already defined.
 *
 * Fix: require_once src/Kernel.php right after vendor/autoload.php.
 * This defines App\Kernel before any test runs, so class_exists() returns
 * true immediately without calling the autoloader.
 */

// 1. Load Composer autoloader (needed for BaseKernel, MicroKernelTrait, etc.)
require __DIR__ . '/../vendor/autoload.php';

// 2. Pre-load App\Kernel IMMEDIATELY - prevents autoloader re-entrance
require_once __DIR__ . '/../src/Kernel.php';

// 3. Set env vars before config/bootstrap.php
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? '0';

// 4. Load Symfony bootstrap (Debug::enable skipped because APP_DEBUG=0)
require __DIR__ . '/../config/bootstrap.php';

// 5. Load env vars (may fail in CI due to path, that's OK)
try {
    $dotenv = new \Symfony\Component\Dotenv\Dotenv();
    $dotenv->usePutenv();
    $dotenv->bootEnv(dirname(__DIR__) . '/.env');
} catch (\Throwable $e) {
    // Expected in CI: path resolution differs from Docker
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
