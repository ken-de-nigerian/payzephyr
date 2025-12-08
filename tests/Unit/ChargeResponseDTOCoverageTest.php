<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

test('charge response getNormalizedStatus uses container when available', function () {
    $normalizer = new StatusNormalizer;
    app()->instance(StatusNormalizer::class, $normalizer);

    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'SUCCEEDED',
        provider: 'paystack'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response getNormalizedStatus falls back to static when container unavailable', function () {
    // Create response without container
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'COMPLETED'
    );

    // Should still work with static normalization
    expect($response->isSuccessful())->toBeTrue();
});

test('charge response getNormalizedStatus handles provider-specific normalization', function () {
    $normalizer = new StatusNormalizer;
    $normalizer->registerProviderMappings('custom', [
        'success' => ['CUSTOM_SUCCESS'],
    ]);
    app()->instance(StatusNormalizer::class, $normalizer);

    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'CUSTOM_SUCCESS',
        provider: 'custom'
    );

    expect($response->isSuccessful())->toBeTrue();
});
