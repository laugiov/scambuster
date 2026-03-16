<?php
use Symfony\Component\Dotenv\Dotenv;
require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/../config/bootstrap.php';


$dotenv = new Dotenv();
$dotenv->usePutenv();
$dotenv->bootEnv('../' . dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
