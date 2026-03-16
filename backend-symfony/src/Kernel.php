<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

// TEMP DEBUG: trace who includes this file a second time
if (\class_exists('App\\Kernel', false)) {
    \fwrite(\STDERR, "=== SECOND INCLUDE of Kernel.php ===\n");
    foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $i => $f) {
        \fwrite(\STDERR, "#$i " . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . "\n");
    }
    \fwrite(\STDERR, "=== END ===\n");
    return;
}

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
