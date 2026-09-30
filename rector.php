<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayMapRector;

/*
 * Enforced in CI with `vendor/bin/rector process --dry-run`: a change Rector
 * would make fails the build. Scoped to src, the code the package ships.
 *
 * The PHP set follows composer.json's floor (8.2), so nothing here rewrites
 * code into syntax a supported PHP version cannot run. See ADR-0003.
 *
 * The skipped rules change behaviour, not style:
 *
 * - ReadOnlyClassRector would make the event classes readonly - classes an
 *   application extends and the queue serializes.
 * - LocallyCalledStaticMethodToNonStaticRector would turn public static
 *   methods into instance methods.
 * - ThrowWithPreviousExceptionRector chains the caught exception into one
 *   thrown in its place. Some throws leave it out on purpose: an ambiguous
 *   provider outcome is detected by walking the previous chain, and a lookup
 *   that failed *before* anything was sent must not look like one.
 * - AddArrayFunctionClosureParamTypeRector and AddClosureParamTypeForArrayMapRector
 *   type a closure's parameter from the docblock of the array it walks. Most
 *   of those arrays are provider payloads, where the docblock describes what
 *   is expected and not what can arrive; a native type there turns an
 *   unexpected value into a TypeError.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        ReadOnlyClassRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        ThrowWithPreviousExceptionRector::class,
        AddArrayFunctionClosureParamTypeRector::class,
        AddClosureParamTypeForArrayMapRector::class,
    ]);
