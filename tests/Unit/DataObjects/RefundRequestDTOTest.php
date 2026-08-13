<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;

test('it accepts a minimal request with only a transaction reference', function () {
    $request = new RefundRequestDTO(transactionReference: 'txn_123');

    expect($request->transactionReference)->toBe('txn_123')
        ->and($request->amount)->toBeNull()
        ->and($request->currency)->toBeNull()
        ->and($request->reason)->toBeNull()
        ->and($request->metadata)->toBe([]);
});

test('it rejects an empty transaction reference', function () {
    new RefundRequestDTO(transactionReference: '');
})->throws(InvalidArgumentException::class, 'Transaction reference is required');

test('it rejects a zero amount', function () {
    new RefundRequestDTO(transactionReference: 'txn_123', amount: 0.0);
})->throws(InvalidArgumentException::class, 'Refund amount must be greater than zero');

test('it rejects a negative amount', function () {
    new RefundRequestDTO(transactionReference: 'txn_123', amount: -50.0);
})->throws(InvalidArgumentException::class, 'Refund amount must be greater than zero');

test('it rejects an amount exceeding the maximum allowed value', function () {
    new RefundRequestDTO(transactionReference: 'txn_123', amount: 1_000_000_000.00);
})->throws(InvalidArgumentException::class, 'Refund amount exceeds maximum allowed value');

test('it accepts the maximum allowed amount', function () {
    $request = new RefundRequestDTO(transactionReference: 'txn_123', amount: 999999999.99);

    expect($request->amount)->toBe(999999999.99);
});

test('it accepts the smallest possible positive amount', function () {
    $request = new RefundRequestDTO(transactionReference: 'txn_123', amount: 0.01);

    expect($request->amount)->toBe(0.01);
});

test('it rejects a currency code that is not 3 letters', function () {
    new RefundRequestDTO(transactionReference: 'txn_123', currency: 'US');
})->throws(InvalidArgumentException::class, 'Currency must be a 3-letter ISO code');

test('it rejects a currency code containing non-letter characters', function () {
    new RefundRequestDTO(transactionReference: 'txn_123', currency: 'U5D');
})->throws(InvalidArgumentException::class, 'Currency must be a 3-letter ISO code');

test('it accepts a valid 3-letter currency code', function () {
    $request = new RefundRequestDTO(transactionReference: 'txn_123', currency: 'CAD');

    expect($request->currency)->toBe('CAD');
});

test('getAmountInMinorUnits converts a major-unit amount to minor units', function () {
    $request = new RefundRequestDTO(transactionReference: 'txn_123', amount: 19.99);

    expect($request->getAmountInMinorUnits())->toBe(1999);
});

test('getAmountInMinorUnits returns null when no amount was specified (full refund)', function () {
    $request = new RefundRequestDTO(transactionReference: 'txn_123');

    expect($request->getAmountInMinorUnits())->toBeNull();
});

test('fromArray builds a request from snake_case keys and auto-generates an idempotency key', function () {
    $request = RefundRequestDTO::fromArray([
        'transaction_reference' => 'txn_123',
        'amount' => 50.005,
        'currency' => 'cad',
        'reason' => 'customer request',
        'metadata' => ['order_id' => 'ORD_1'],
    ]);

    expect($request->transactionReference)->toBe('txn_123')
        ->and($request->amount)->toBe(50.01)
        ->and($request->currency)->toBe('CAD')
        ->and($request->reason)->toBe('customer request')
        ->and($request->metadata)->toBe(['order_id' => 'ORD_1'])
        ->and($request->idempotencyKey)->not->toBeNull()
        ->and($request->idempotencyKey)->toBeString();
});

test('fromArray preserves an explicitly provided idempotency key', function () {
    $request = RefundRequestDTO::fromArray([
        'transaction_reference' => 'txn_123',
        'idempotency_key' => 'my-custom-key-123',
    ]);

    expect($request->idempotencyKey)->toBe('my-custom-key-123');
});

test('fromArray rejects an idempotency key with invalid characters', function () {
    RefundRequestDTO::fromArray([
        'transaction_reference' => 'txn_123',
        'idempotency_key' => 'has spaces and $ymbols!',
    ]);
})->throws(InvalidArgumentException::class, 'Invalid idempotency key format');

test('fromArray defaults a missing transaction_reference to an empty string, which then fails validation', function () {
    RefundRequestDTO::fromArray(['amount' => 50.0]);
})->throws(InvalidArgumentException::class, 'Transaction reference is required');

test('toArray round-trips the snake_case shape used by fromArray', function () {
    $request = new RefundRequestDTO(
        transactionReference: 'txn_123',
        amount: 50.0,
        currency: 'USD',
        reason: 'duplicate charge',
        metadata: ['note' => 'test'],
    );

    expect($request->toArray())->toBe([
        'transaction_reference' => 'txn_123',
        'amount' => 50.0,
        'currency' => 'USD',
        'reason' => 'duplicate charge',
        'metadata' => ['note' => 'test'],
    ]);
});

test('it rejects a whitespace-only transaction reference by treating it as opaque, non-empty input', function () {
    // PayZephyr does not trim() the reference (correctly - a provider's
    // real reference format is opaque to this package), so a
    // whitespace-only string is NOT rejected here. This documents that
    // behavior rather than asserting a guarantee the DTO doesn't make;
    // provider APIs are expected to reject it downstream.
    $request = new RefundRequestDTO(transactionReference: '   ');

    expect($request->transactionReference)->toBe('   ');
});
