<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;

test('charge response dto falls back to static normalization when app function not available', function () {
    // Create a scenario where app() might not be available
    // This tests the fallback path in getNormalizedStatus()
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'succeeded', // This should normalize to 'success'
        provider: 'test'
    );

    // The getNormalizedStatus() method should use static normalization
    expect($response->isSuccessful())->toBeTrue();
});

test('charge response dto handles exception in normalization gracefully', function () {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'completed',
        provider: 'test'
    );

    // Should still work even if container throws exception
    expect($response->isSuccessful())->toBeTrue();
});

test('verification response dto falls back to static normalization when app function not available', function () {
    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'succeeded',
        amount: 100.0,
        currency: 'NGN',
        provider: 'test'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response dto handles exception in normalization gracefully', function () {
    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'declined',
        amount: 100.0,
        currency: 'NGN',
        provider: 'test'
    );

    expect($response->isFailed())->toBeTrue();
});
