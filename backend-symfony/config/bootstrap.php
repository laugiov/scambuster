<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;

// Autoload de Composer
require __DIR__ . '/../vendor/autoload.php';

// If APP_ENV is not already defined, load .env, .env.local, .env.{env} from /app/.env
if (!isset($_SERVER['APP_ENV'])) {
    // __DIR__ = /app/config  → dirname(__DIR__) = /app
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
    Debug::enable();
}
