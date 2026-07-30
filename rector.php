<?php

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use RectorLaravel\Set\LaravelSetList;

/*
 * Conservative, behavior-preserving rule sets only — these are safe to
 * auto-apply on commit (see .githooks/pre-commit). Aggressive sets (PHP
 * version upgrades, type-declaration inference, Laravel upgrade paths) are
 * deliberately omitted; add them as one-off manual runs when desired.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    // strict_types is a project-wide decision — opt in deliberately, don't let
    // Rector inject it file-by-file on commit. Pint stays authoritative on style.
    ->withSkip([
        SafeDeclareStrictTypesRector::class,
    ]);
