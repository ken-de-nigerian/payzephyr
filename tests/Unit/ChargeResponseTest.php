<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;

test('charge response checks successful status', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'success'
    );

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->isPending())->toBeFalse();
});

test('charge response checks succeeded status variation', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'succeeded'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response checks completed status variation', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'completed'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response checks pending status', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'pending'
    );

    expect($response->isPending())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse();
});

test('charge response handles case insensitive status', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'SUCCESS'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response converts to array', function () {
    $response = ChargeResponseDTO::fromArray([
        'reference' => 'ref_123',
        'authorization_url' => 'https://example.com',
        'access_code' => 'code_123',
        'status' => 'success',
        'metadata' => ['key' => 'value'],
        'provider' => 'paystack',
    ]);

    $array = $response->toArray();

    expect($array['reference'])->toBe('ref_123')
        ->and($array['authorization_url'])->toBe('https://example.com')
        ->and($array['access_code'])->toBe('code_123')
        ->and($array['status'])->toBe('success')
        ->and($array['metadata'])->toBe(['key' => 'value'])
        ->and($array['provider'])->toBe('paystack');
});

test('charge response creates from array with defaults', function () {
    $response = ChargeResponseDTO::fromArray([]);

    expect($response->reference)->toBe('')
        ->and($response->authorizationUrl)->toBe('')
        ->and($response->accessCode)->toBe('')
        ->and($response->status)->toBe('pending')
        ->and($response->metadata)->toBe([])
        ->and($response->provider)->toBeNull();
});

test('charge response includes metadata', function () {
    $metadata = [
        'order_id' => 12345,
        'customer_id' => 'cust_123',
        'custom_field' => 'value',
    ];

    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'pending',
        metadata: $metadata
    );

    expect($response->metadata)->toBe($metadata);
});

test('charge response includes provider name', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'pending',
        provider: 'paystack'
    );

    expect($response->provider)->toBe('paystack');
});

test('charge response is immutable', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'pending'
    );

    expect($response)->toBeInstanceOf(ChargeResponseDTO::class)
        ->and($response->reference)->toBe('ref_123');
});

test('charge response handles empty metadata', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'pending',
        metadata: []
    );

    expect($response->metadata)->toBe([]);
});
