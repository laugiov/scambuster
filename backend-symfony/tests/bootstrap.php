<?php
use Symfony\Component\Dotenv\Dotenv;
require __DIR__ . '/../vendor/autoload.php';

// Fix: preload Kernel so class_exists() returns TRUE before any autoloader fires.
// Prevents double-include caused by inline_class_loader (require_once) + Composer (include).
// See: local/tasks/KERNEL-ISSUE-FINAL-FINDINGS.md
require_once __DIR__ . '/../src/Kernel.php';

require __DIR__ . '/../config/bootstrap.php';


$dotenv = new Dotenv();
$dotenv->usePutenv();
$dotenv->bootEnv('../' . dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
