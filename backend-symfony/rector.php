<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/src/DataFixtures',
        __DIR__ . '/src/Kernel.php',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withRules([
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
