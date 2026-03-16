<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

// TEMP DEBUG: log EVERY time this file is included, with full call stack
@\file_put_contents('/app/var/kernel_loads.txt',
    "\n=== " . \date('H:i:s.u') . " include #" . (\class_exists('App\\Kernel', false) ? '2+' : '1') . " ===\n" .
    \implode("\n", \array_map(
        fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' → ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
        \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS)
    )) . "\n",
    \FILE_APPEND
);

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
