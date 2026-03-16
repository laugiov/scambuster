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

// === DEBUG: dump autoloaders and cache info ===
$output = "=== REGISTERED AUTOLOADERS ===\n";
foreach (spl_autoload_functions() as $i => $al) {
    if (is_array($al)) {
        $cls = is_object($al[0]) ? get_class($al[0]) : (string) $al[0];
        $output .= "#$i: {$cls}::{$al[1]}\n";
    } elseif (is_string($al)) {
        $output .= "#$i: $al\n";
    } else {
        $output .= "#$i: Closure\n";
    }
}

// Check compiled container for Kernel references
$cacheDir = __DIR__ . '/../var/cache/test/';
if (is_dir($cacheDir)) {
    $output .= "\n=== COMPILED CONTAINER KERNEL REFS ===\n";
    foreach (array_merge(glob($cacheDir . '*.php'), glob($cacheDir . 'Container*/*.php')) as $f) {
        $content = file_get_contents($f);
        if (preg_match_all('/.*src.Kernel\.php.*|.*[^_]App\\\\Kernel[^T].*|.*include.*Kernel.*/', $content, $matches)) {
            $output .= basename($f) . ":\n";
            foreach ($matches[0] as $m) {
                $m = trim($m);
                if ($m && !str_contains($m, 'HttpKernel') && !str_contains($m, 'KernelEvent')
                    && !str_contains($m, 'KernelException') && !str_contains($m, 'KernelBrowser')) {
                    $output .= "  $m\n";
                }
            }
        }
    }
}

$output .= "\n=== APP_ENV={$_SERVER['APP_ENV']} APP_DEBUG={$_SERVER['APP_DEBUG']} ===\n";
file_put_contents('/tmp/ci_debug.log', $output);

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
