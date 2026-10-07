<?php

use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

test('verification response getNormalizedStatus uses container when available', function (): void {
    $normalizer = new StatusNormalizer;
    app()->instance(StatusNormalizer::class, $normalizer);

    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'SUCCEEDED',
        amount: 1000.0,
        currency: 'NGN',
        provider: 'paystack'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response getNormalizedStatus falls back to static when container unavailable', function (): void {
    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'COMPLETED',
        amount: 1000.0,
        currency: 'NGN'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response getNormalizedStatus handles provider-specific normalization', function (): void {
    $normalizer = new StatusNormalizer;
    $normalizer->registerProviderMappings('custom', [
        'failed' => ['CUSTOM_FAILED'],
    ]);
    app()->instance(StatusNormalizer::class, $normalizer);

    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'CUSTOM_FAILED',
        amount: 1000.0,
        currency: 'NGN',
        provider: 'custom'
    );

    expect($response->isFailed())->toBeTrue();
});
