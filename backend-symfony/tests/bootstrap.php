<?php
use Symfony\Component\Dotenv\Dotenv;

// Guard: prevent DebugClassLoader from re-including Kernel.php when
// the inline_class_loader has already loaded it via include_once.
// See: local/tasks/KERNEL-ISSUE-FINAL-FINDINGS.md
spl_autoload_register(function (string $class): void {
    if ($class === 'App\\Kernel' && \class_exists($class, false)) {
        return;
    }
}, false, true);

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/../config/bootstrap.php';


$dotenv = new Dotenv();
$dotenv->usePutenv();
$dotenv->bootEnv('../' . dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
