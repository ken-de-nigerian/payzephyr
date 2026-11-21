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

test('the requested amount and fees survive fromArray and toArray, and are null when absent', function (): void {
    $withFees = VerificationResponseDTO::fromArray([
        'reference' => 'PZ_1', 'status' => 'success', 'amount' => 106700.51, 'currency' => 'ngn',
        'requested_amount' => '105000', 'fees' => 1700.51,
    ]);
    $plain = VerificationResponseDTO::fromArray(['reference' => 'PZ_2', 'status' => 'success', 'amount' => 250, 'currency' => 'NGN']);

    expect($withFees->requestedAmount)->toBe(105000.0)
        ->and($withFees->fees)->toBe(1700.51)
        ->and($withFees->amountRequested())->toBe(105000.0)
        ->and($withFees->toArray())->toMatchArray(['amount' => 106700.51, 'requested_amount' => 105000.0, 'fees' => 1700.51])
        ->and($plain->requestedAmount)->toBeNull()
        ->and($plain->fees)->toBeNull()
        ->and($plain->amountRequested())->toBe(250.0)
        ->and($plain->toArray())->toMatchArray(['requested_amount' => null, 'fees' => null]);
});
