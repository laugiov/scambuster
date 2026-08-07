<?php

/**
 * Kernel autoloader guard — used by tests/bootstrap.php.
 *
 * While `class App\Kernel extends BaseKernel` is being compiled, something
 * re-triggers autoloading of App\Kernel. Composer's ClassLoader includes files
 * with a plain `include` (not `include_once`), so that re-entry tries to declare
 * the class a second time → "Cannot declare class App\Kernel, because the name is
 * already in use in .../src/Kernel.php". This replaces Composer's loader with an
 * include_once wrapper that also short-circuits on already-declared symbols,
 * making class loading idempotent regardless of what re-triggers it.
 *
 * tests/bootstrap_ci.php carries its own inline copy of this same guard — kept
 * deliberately duplicated there so the battle-tested CI bootstrap stays untouched.
 *
 * Requires vendor/autoload.php to have been loaded already.
 */

foreach (spl_autoload_functions() as $loader) {
    if (is_array($loader) && isset($loader[0]) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $composerLoader = $loader[0];
        spl_autoload_unregister($loader);
        spl_autoload_register(
            function (string $class) use ($composerLoader): void {
                if (\class_exists($class, false) || \interface_exists($class, false) || \trait_exists($class, false) || \enum_exists($class, false)) {
                    return;
                }
                $file = $composerLoader->findFile($class);
                if ($file !== false) {
                    $resolved = \realpath($file);
                    if ($resolved !== false) {
                        include_once $resolved;
                    } else {
                        include_once $file;
                    }
                }
            },
            true,
            true
        );
        break;
    }
}
