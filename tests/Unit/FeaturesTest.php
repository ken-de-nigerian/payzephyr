<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Console\Features;

test('get() names the known features when asked for an unknown one', function (): void {
    expect(fn (): array => Features::get('invoicing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown PayZephyr feature [invoicing]. Known features: subscriptions, refunds, trace');
});

test('resolveDependencies() refuses a feature that is not in the registry', function (): void {
    expect(fn (): array => Features::resolveDependencies(['invoicing']))
        ->toThrow(InvalidArgumentException::class, 'Unknown PayZephyr feature [invoicing]');
});

test('resolveDependencies() lists a feature selected twice only once', function (): void {
    expect(Features::resolveDependencies(['refunds', 'refunds', 'trace']))->toBe(['refunds', 'trace']);
});

test('resolveDependencies() pulls in transitive dependencies, each exactly once, before their dependents', function (): void {
    $graph = [
        'refunds' => ['ledger'],
        'disputes' => ['refunds', 'ledger'],
        'ledger' => [],
    ];

    expect(Features::resolveDependencies(['disputes'], $graph))->toBe(['ledger', 'refunds', 'disputes'])
        ->and(Features::resolveDependencies(['refunds', 'disputes'], $graph))->toBe(['ledger', 'refunds', 'disputes']);
});

test('resolveDependencies() detects a cycle instead of recursing forever', function (array $graph, string $start): void {
    expect(fn (): array => Features::resolveDependencies([$start], $graph))
        ->toThrow(InvalidArgumentException::class, 'Circular dependency detected');
})->with([
    'two features requiring each other' => [['a' => ['b'], 'b' => ['a']], 'a'],
    'a longer loop' => [['a' => ['b'], 'b' => ['c'], 'c' => ['a']], 'a'],
    'a feature requiring itself' => [['a' => ['a']], 'a'],
]);

test('resolveDependencies() refuses a dependency on a feature that does not exist', function (): void {
    expect(fn (): array => Features::resolveDependencies(['a'], ['a' => ['ghost']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown PayZephyr feature [ghost]');
});
