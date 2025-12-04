<?php

use KenDeNigerian\PayZephyr\DataObjects\VerificationResponse;

test('verification response checks success status', function () {
    $response = VerificationResponse::fromArray([
        'reference' => 'ref_123',
        'status' => 'success',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->isFailed())->toBeFalse()
        ->and($response->isPending())->toBeFalse();
});

test('verification response checks failed status', function () {
    $response = VerificationResponse::fromArray([
        'reference' => 'ref_123',
        'status' => 'failed',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isFailed())->toBeTrue();
});

test('verification response checks pending status', function () {
    $response = VerificationResponse::fromArray([
        'reference' => 'ref_123',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    expect($response->isPending())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse();
});

test('verification response converts to array', function () {
    $response = VerificationResponse::fromArray([
        'reference' => 'ref_123',
        'status' => 'success',
        'amount' => 1000,
        'currency' => 'NGN',
        'paid_at' => '2024-01-01 12:00:00',
        'metadata' => ['order_id' => 123],
        'provider' => 'paystack',
        'channel' => 'card',
        'card_type' => 'visa',
        'bank' => 'Test Bank',
        'customer' => ['email' => 'test@example.com'],
    ]);

    $array = $response->toArray();

    expect($array['reference'])->toBe('ref_123')
        ->and($array['amount'])->toBe(1000.0)
        ->and($array['currency'])->toBe('NGN')
        ->and($array['status'])->toBe('success')
        ->and($array['provider'])->toBe('paystack');
});

test('verification response handles all successful status variations', function () {
    expect(VerificationResponse::fromArray([
        'reference' => 'ref',
        'status' => 'success',
        'amount' => 100,
        'currency' => 'NGN',
    ])->isSuccessful())->toBeTrue()
        ->and(VerificationResponse::fromArray([
            'reference' => 'ref',
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'NGN',
        ])->isSuccessful())->toBeTrue()
        ->and(VerificationResponse::fromArray([
            'reference' => 'ref',
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'NGN',
        ])->isSuccessful())->toBeTrue()
        ->and(VerificationResponse::fromArray([
            'reference' => 'ref',
            'status' => 'successful',
            'amount' => 100,
            'currency' => 'NGN',
        ])->isSuccessful())->toBeTrue();

});

test('verification response handles all failed status variations', function () {
    expect(VerificationResponse::fromArray([
        'reference' => 'ref',
        'status' => 'failed',
        'amount' => 100,
        'currency' => 'NGN',
    ])->isFailed())->toBeTrue()
        ->and(VerificationResponse::fromArray([
            'reference' => 'ref',
            'status' => 'cancelled',
            'amount' => 100,
            'currency' => 'NGN',
        ])->isFailed())->toBeTrue()
        ->and(VerificationResponse::fromArray([
            'reference' => 'ref',
            'status' => 'declined',
            'amount' => 100,
            'currency' => 'NGN',
        ])->isFailed())->toBeTrue();

});

test('verification response handles case insensitive status', function () {
    expect(VerificationResponse::fromArray([
        'reference' => 'ref',
        'status' => 'SUCCESS',
        'amount' => 100,
        'currency' => 'NGN',
    ])->isSuccessful())->toBeTrue()
        ->and(VerificationResponse::fromArray([
            'reference' => 'ref',
            'status' => 'FAILED',
            'amount' => 100,
            'currency' => 'NGN',
        ])->isFailed())->toBeTrue();

});

test('verification response includes payment details', function () {
    $response = new VerificationResponse(
        reference: 'ref_123',
        status: 'success',
        amount: 5000.0,
        currency: 'NGN',
        paidAt: '2024-01-01 12:00:00',
        metadata: ['order_id' => 123],
        provider: 'paystack',
        channel: 'card',
        cardType: 'visa',
        bank: 'Access Bank',
        customer: ['email' => 'customer@example.com', 'name' => 'John Doe']
    );

    expect($response->reference)->toBe('ref_123')
        ->and($response->amount)->toBe(5000.0)
        ->and($response->currency)->toBe('NGN')
        ->and($response->channel)->toBe('card')
        ->and($response->cardType)->toBe('visa')
        ->and($response->bank)->toBe('Access Bank')
        ->and($response->customer)->toBe(['email' => 'customer@example.com', 'name' => 'John Doe']);
});

test('verification response handles optional fields as null', function () {
    $response = new VerificationResponse(
        reference: 'ref_123',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN'
    );

    expect($response->paidAt)->toBeNull()
        ->and($response->metadata)->toBe([])
        ->and($response->provider)->toBeNull()
        ->and($response->channel)->toBeNull()
        ->and($response->cardType)->toBeNull()
        ->and($response->bank)->toBeNull()
        ->and($response->customer)->toBeNull();
});

test('verification response from array with defaults', function () {
    $response = VerificationResponse::fromArray([
        'reference' => 'ref_123',
    ]);

    expect($response->reference)->toBe('ref_123')
        ->and($response->status)->toBe('unknown')
        ->and($response->amount)->toBe(0.0)
        ->and($response->currency)->toBe('');
});

test('verification response normalizes currency to uppercase', function () {
    $response = VerificationResponse::fromArray([
        'reference' => 'ref_123',
        'status' => 'success',
        'amount' => 1000,
        'currency' => 'ngn',
    ]);

    expect($response->currency)->toBe('NGN');
});

test('verification response is immutable', function () {
    $response = new VerificationResponse(
        reference: 'ref_123',
        status: 'success',
        amount: 1000.0,
        currency: 'NGN'
    );

    expect($response)->toBeInstanceOf(VerificationResponse::class)
        ->and($response->reference)->toBe('ref_123');
});
