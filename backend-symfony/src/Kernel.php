<?php
// TEMP DEBUG: trace who includes this file
if (\class_exists('App\\Kernel', false)) {
    \fwrite(\STDERR, "=== App\\Kernel ALREADY DEFINED when Kernel.php included ===\n");
    foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $i => $f) {
        \fwrite(\STDERR, "#$i " . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . "\n");
    }
    \fwrite(\STDERR, "=== END ===\n");
} else {
    \fwrite(\STDERR, "=== Kernel.php first include ===\n");
    foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $i => $f) {
        \fwrite(\STDERR, "#$i " . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . "\n");
    }
}

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
