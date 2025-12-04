<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;

test('charge request validates amount', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => -100,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class, 'Amount must be greater than zero');
});

test('charge request validates email', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'NGN',
        'email' => 'invalid-email',
    ]))->toThrow(InvalidArgumentException::class, 'Invalid email address');
});

test('charge request validates currency format', function () {
    expect(fn () => ChargeRequest::fromArray([
        'amount' => 100,
        'currency' => 'INVALID',
        'email' => 'test@example.com',
    ]))->toThrow(InvalidArgumentException::class);
});

test('charge request converts amount to minor units', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 100.50,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($request->getAmountInMinorUnits())->toBe(10050);
});

test('charge request creates from array', function () {
    $request = ChargeRequest::fromArray([
        'amount' => 5000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'REF_123',
        'metadata' => ['order_id' => 123],
    ]);

    expect($request->amount)->toBe(5000.0)
        ->and($request->currency)->toBe('NGN')
        ->and($request->email)->toBe('test@example.com')
        ->and($request->reference)->toBe('REF_123')
        ->and($request->metadata)->toBe(['order_id' => 123]);
});

test('charge request converts to array', function () {
    $data = [
        'amount' => 5000,
        'currency' => 'USD',
        'email' => 'test@example.com',
        'reference' => 'REF_123',
    ];

    $request = ChargeRequest::fromArray($data);
    $array = $request->toArray();

    expect($array['amount'])->toBe(5000.0)
        ->and($array['currency'])->toBe('USD')
        ->and($array['email'])->toBe('test@example.com')
        ->and($array['reference'])->toBe('REF_123');
});
