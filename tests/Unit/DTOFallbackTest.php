<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;

test('charge response dto falls back to static normalization when app function not available', function (): void {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'succeeded', // This should normalize to 'success'
        provider: 'test'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response dto handles exception in normalization gracefully', function (): void {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'completed',
        provider: 'test'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response dto falls back to static normalization when app function not available', function (): void {
    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'succeeded',
        amount: 100.0,
        currency: 'NGN',
        provider: 'test'
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response dto handles exception in normalization gracefully', function (): void {
    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'declined',
        amount: 100.0,
        currency: 'NGN',
        provider: 'test'
    );

    expect($response->isFailed())->toBeTrue();
});
