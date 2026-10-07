<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Support\Payload;

/**
 * Payload is what stands between a provider's JSON and PayZephyr's typed
 * code, so its contract is stated here value by value: each reader answers
 * with the type it promises, or null - never with whatever was there.
 */
function samplePayload(): Payload
{
    return new Payload([
        'event.type' => 'CARD_TRANSACTION',
        'data' => [
            'id' => 12345,
            'reference' => 'REF_1',
            'amount' => '1500.50',
            'whole' => '100',
            'wholeFloat' => 100.0,
            'fraction' => 99.5,
            'paid' => true,
            'declined' => false,
            'nothing' => null,
            'customer' => ['email' => 'a@b.test', 'tags' => ['vip', 'new']],
        ],
        'items' => [['sku' => 'A'], ['sku' => 'B']],
    ]);
}

test('get walks a path segment by segment and answers null for a missing one', function (): void {
    $payload = samplePayload();

    expect($payload->get('data', 'customer', 'email'))->toBe('a@b.test')
        ->and($payload->get('items', 1, 'sku'))->toBe('B')
        ->and($payload->get('data', 'missing'))->toBeNull()
        ->and($payload->get('data', 'reference', 'deeper'))->toBeNull()
        ->and($payload->get())->toBe($payload->all());
});

test('a key containing a dot is one segment, not a path', function (): void {
    // Flutterwave sends a top-level "event.type"; a dotted-path reader would
    // look for ['event']['type'] and find nothing.
    expect(samplePayload()->string('event.type'))->toBe('CARD_TRANSACTION')
        ->and(samplePayload()->string('event', 'type'))->toBeNull();
});

test('has is true only for a value that is present and not null', function (): void {
    $payload = samplePayload();

    expect($payload->has('data', 'reference'))->toBeTrue()
        ->and($payload->has('data', 'declined'))->toBeTrue()
        ->and($payload->has('data', 'nothing'))->toBeFalse()
        ->and($payload->has('data', 'missing'))->toBeFalse();
});

test('string accepts strings and numbers, and nothing else', function (): void {
    $payload = samplePayload();

    expect($payload->string('data', 'reference'))->toBe('REF_1')
        ->and($payload->string('data', 'id'))->toBe('12345')
        ->and($payload->string('data', 'fraction'))->toBe('99.5')
        // A boolean rendered as "1" or "" is never what was meant.
        ->and($payload->string('data', 'paid'))->toBeNull()
        ->and($payload->string('data', 'customer'))->toBeNull()
        ->and($payload->string('data', 'nothing'))->toBeNull()
        ->and($payload->string('data', 'missing'))->toBeNull();
});

test('int accepts integers and numeric values that are whole', function (): void {
    $payload = samplePayload();

    expect($payload->int('data', 'id'))->toBe(12345)
        ->and($payload->int('data', 'whole'))->toBe(100)
        ->and($payload->int('data', 'wholeFloat'))->toBe(100)
        ->and($payload->int('data', 'fraction'))->toBeNull()
        ->and($payload->int('data', 'amount'))->toBeNull()
        ->and($payload->int('data', 'reference'))->toBeNull()
        ->and($payload->int('data', 'paid'))->toBeNull();
});

test('float accepts anything numeric', function (): void {
    $payload = samplePayload();

    expect($payload->float('data', 'amount'))->toBe(1500.5)
        ->and($payload->float('data', 'id'))->toBe(12345.0)
        ->and($payload->float('data', 'reference'))->toBeNull()
        ->and($payload->float('data', 'paid'))->toBeNull();
});

test('bool is null for anything that is not a boolean, so absent is not read as false', function (): void {
    $payload = samplePayload();

    expect($payload->bool('data', 'paid'))->toBeTrue()
        ->and($payload->bool('data', 'declined'))->toBeFalse()
        ->and($payload->bool('data', 'missing'))->toBeNull()
        ->and($payload->bool('data', 'id'))->toBeNull();
});

test('array answers an empty array for anything that is not one', function (): void {
    $payload = samplePayload();

    expect($payload->array('data', 'customer', 'tags'))->toBe(['vip', 'new'])
        ->and($payload->array('data', 'reference'))->toBe([])
        ->and($payload->array('data', 'missing'))->toBe([]);
});

test('at reads into a branch, and into a missing one without failing', function (): void {
    $payload = samplePayload();

    expect($payload->at('data', 'customer')->string('email'))->toBe('a@b.test')
        ->and($payload->at('data', 'no_such_branch')->string('email'))->toBeNull()
        ->and($payload->at('data', 'reference')->all())->toBe([]);
});

test('of wraps an array and reads anything else as empty', function (mixed $value): void {
    expect(Payload::of($value)->all())->toBe([]);
})->with([
    'null' => [null],
    'a string' => ['not an array'],
    'a number' => [42],
    'an object' => [fn (): \stdClass => new stdClass],
]);

test('of keeps an array as it is', function (): void {
    expect(Payload::of(['a' => 1])->int('a'))->toBe(1);
});

test('flag reads a switch the way it arrives from an env file', function (mixed $value, bool $expected): void {
    // env() makes "true"/"false" booleans, but "0", "1", "off" and "yes" stay
    // strings. Reading those by strict type would turn "0" into "not set" and
    // quietly switch the feature back on.
    expect((new Payload(['switch' => $value]))->flag(true, 'switch'))->toBe($expected)
        ->and((new Payload(['switch' => $value]))->flag(false, 'switch'))->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'string 1' => ['1', true],
    'string 0' => ['0', false],
    'string true' => ['true', true],
    'string false' => ['false', false],
    'on' => ['on', true],
    'off' => ['off', false],
    'yes' => ['yes', true],
    'no' => ['no', false],
    'empty string' => ['', false],
    'integer 1' => [1, true],
    'integer 0' => [0, false],
    'float 0.0' => [0.0, false],
]);

test('flag answers the default for anything that is not a switch', function (mixed $value): void {
    expect((new Payload(['switch' => $value]))->flag(true, 'switch'))->toBeTrue()
        ->and((new Payload(['switch' => $value]))->flag(false, 'switch'))->toBeFalse();
})->with([
    'null' => [null],
    'an array' => [['on']],
    'a word that is not a switch' => ['sometimes'],
]);

test('flag answers the default for a missing path', function (): void {
    expect((new Payload([]))->flag(true, 'a', 'b'))->toBeTrue()
        ->and((new Payload([]))->flag(false, 'a', 'b'))->toBeFalse();
});

test('arrayOrNull tells an absent array from an empty one', function (): void {
    $payload = new Payload(['customer' => ['email' => 'a@b.test'], 'split' => [], 'note' => 'text']);

    expect($payload->arrayOrNull('customer'))->toBe(['email' => 'a@b.test'])
        ->and($payload->arrayOrNull('split'))->toBe([])
        ->and($payload->arrayOrNull('note'))->toBeNull()
        ->and($payload->arrayOrNull('missing'))->toBeNull();
});

test('onOff reads a switch, and answers null for anything that is not one', function (mixed $value, ?bool $expected): void {
    expect((new Payload(['switch' => $value]))->onOff('switch'))->toBe($expected);
})->with([
    [true, true], [false, false], [1, true], [0, false], [0.0, false],
    ['yes', true], ['off', false], ['0', false],
    ['archived', null], [['on'], null], [null, null],
]);

test('onOff answers null for a missing path', function (): void {
    expect((new Payload([]))->onOff('a', 'b'))->toBeNull();
});
