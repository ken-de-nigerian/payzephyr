<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Closure\RemoveUnusedClosureVariableUseRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayMapRector;

/*
 * Enforced in CI with `vendor/bin/rector process --dry-run`: a change Rector
 * would make fails the build, in src and in tests.
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
 *
 * Four more are skipped in tests only, where they break what a test does:
 *
 * - RemoveAlwaysTrueIfConditionRector and RemoveUnusedClosureVariableUseRector
 *   misread a variable a closure captures by reference: a listener told to
 *   throw only while $shouldThrow is true had its condition and its captures
 *   removed, and threw on every call.
 * - FunctionFirstClassCallableRector and ArrowFunctionDelegatingCallToFirstClassCallableRector
 *   turn `fn () => f()` into `f(...)`, which
 *   passes the caller's arguments on to f(); a fake answering every SDK call
 *   with sdkSubscription() was handed the subscription id as its overrides.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    // tests/ more than doubles the files; a busy CI runner needs longer than the 120s default per worker.
    ->withParallel(timeoutSeconds: 600)
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
        RemoveAlwaysTrueIfConditionRector::class => [__DIR__.'/tests'],
        RemoveUnusedClosureVariableUseRector::class => [__DIR__.'/tests'],
        FunctionFirstClassCallableRector::class => [__DIR__.'/tests'],
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [__DIR__.'/tests'],
    ]);
