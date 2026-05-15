<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/vendor',
        // Removing "unused" parameters on a public SDK is a BC break.
        // The signatures are the contract — they outlast any one impl.
        RemoveUnusedPublicMethodParameterRector::class,
        RemoveUnusedPrivateMethodParameterRector::class,
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        // Phase 3 active set — toggle one at a time, commit each.
        SetList::TYPE_DECLARATION,
    ])
    ->withImportNames(
        importNames: true,
        importDocBlockNames: true,
        importShortClasses: false,
        removeUnusedImports: true,
    );
