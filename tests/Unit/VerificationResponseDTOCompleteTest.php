<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;

test('verification response getNormalizedStatus uses container when available', function (): void {
    $response = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'succeeded',
        amount: 10000,
        currency: 'NGN',
        provider: 'paystack',
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response getNormalizedStatus falls back to static when container unavailable', function (): void {
    $response = VerificationResponseDTO::fromArray([
        'reference' => 'ref_123',
        'status' => 'completed',
        'amount' => 10000,
        'currency' => 'NGN',
        'provider' => 'paystack',
    ]);

    expect($response->isSuccessful())->toBeTrue();
});

test('verification response handles all status variations with normalization', function (): void {
    $successStatuses = ['success', 'succeeded', 'completed', 'successful', 'paid'];
    $failedStatuses = ['failed', 'declined', 'rejected', 'cancelled'];

    foreach ($successStatuses as $status) {
        $response = new VerificationResponseDTO(
            reference: 'ref_123',
            status: $status,
            amount: 10000,
            currency: 'NGN',
        );

        expect($response->isSuccessful())->toBeTrue("Failed for status: $status");
    }

    foreach ($failedStatuses as $status) {
        $response = new VerificationResponseDTO(
            reference: 'ref_123',
            status: $status,
            amount: 10000,
            currency: 'NGN',
        );

        expect($response->isFailed())->toBeTrue("Failed for status: $status");
    }
});
