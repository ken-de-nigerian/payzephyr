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

test('get walks a path segment by segment and answers null for a missing one', function () {
    $payload = samplePayload();

    expect($payload->get('data', 'customer', 'email'))->toBe('a@b.test')
        ->and($payload->get('items', 1, 'sku'))->toBe('B')
        ->and($payload->get('data', 'missing'))->toBeNull()
        ->and($payload->get('data', 'reference', 'deeper'))->toBeNull()
        ->and($payload->get())->toBe($payload->all());
});

test('a key containing a dot is one segment, not a path', function () {
    // Flutterwave sends a top-level "event.type"; a dotted-path reader would
    // look for ['event']['type'] and find nothing.
    expect(samplePayload()->string('event.type'))->toBe('CARD_TRANSACTION')
        ->and(samplePayload()->string('event', 'type'))->toBeNull();
});

test('has is true only for a value that is present and not null', function () {
    $payload = samplePayload();

    expect($payload->has('data', 'reference'))->toBeTrue()
        ->and($payload->has('data', 'declined'))->toBeTrue()
        ->and($payload->has('data', 'nothing'))->toBeFalse()
        ->and($payload->has('data', 'missing'))->toBeFalse();
});

test('string accepts strings and numbers, and nothing else', function () {
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

test('int accepts integers and numeric values that are whole', function () {
    $payload = samplePayload();

    expect($payload->int('data', 'id'))->toBe(12345)
        ->and($payload->int('data', 'whole'))->toBe(100)
        ->and($payload->int('data', 'wholeFloat'))->toBe(100)
        ->and($payload->int('data', 'fraction'))->toBeNull()
        ->and($payload->int('data', 'amount'))->toBeNull()
        ->and($payload->int('data', 'reference'))->toBeNull()
        ->and($payload->int('data', 'paid'))->toBeNull();
});

test('float accepts anything numeric', function () {
    $payload = samplePayload();

    expect($payload->float('data', 'amount'))->toBe(1500.5)
        ->and($payload->float('data', 'id'))->toBe(12345.0)
        ->and($payload->float('data', 'reference'))->toBeNull()
        ->and($payload->float('data', 'paid'))->toBeNull();
});

test('bool is null for anything that is not a boolean, so absent is not read as false', function () {
    $payload = samplePayload();

    expect($payload->bool('data', 'paid'))->toBeTrue()
        ->and($payload->bool('data', 'declined'))->toBeFalse()
        ->and($payload->bool('data', 'missing'))->toBeNull()
        ->and($payload->bool('data', 'id'))->toBeNull();
});

test('array answers an empty array for anything that is not one', function () {
    $payload = samplePayload();

    expect($payload->array('data', 'customer', 'tags'))->toBe(['vip', 'new'])
        ->and($payload->array('data', 'reference'))->toBe([])
        ->and($payload->array('data', 'missing'))->toBe([]);
});

test('at reads into a branch, and into a missing one without failing', function () {
    $payload = samplePayload();

    expect($payload->at('data', 'customer')->string('email'))->toBe('a@b.test')
        ->and($payload->at('data', 'no_such_branch')->string('email'))->toBeNull()
        ->and($payload->at('data', 'reference')->all())->toBe([]);
});

test('of wraps an array and reads anything else as empty', function (mixed $value) {
    expect(Payload::of($value)->all())->toBe([]);
})->with([
    'null' => [null],
    'a string' => ['not an array'],
    'a number' => [42],
    'an object' => [fn () => new stdClass],
]);

test('of keeps an array as it is', function () {
    expect(Payload::of(['a' => 1])->int('a'))->toBe(1);
});
