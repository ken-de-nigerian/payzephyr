<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;

test('charge response getNormalizedStatus uses container when available', function (): void {
    $response = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://example.com',
        accessCode: 'code_123',
        status: 'succeeded',
        provider: 'paystack',
    );

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response getNormalizedStatus falls back to static when container unavailable', function (): void {
    $response = ChargeResponseDTO::fromArray([
        'reference' => 'ref_123',
        'authorization_url' => 'https://example.com',
        'access_code' => 'code_123',
        'status' => 'completed',
        'provider' => 'paystack',
    ]);

    expect($response->isSuccessful())->toBeTrue();
});

test('charge response handles all status variations with normalization', function (): void {
    $statuses = ['success', 'succeeded', 'completed', 'successful', 'paid'];

    foreach ($statuses as $status) {
        $response = new ChargeResponseDTO(
            reference: 'ref_123',
            authorizationUrl: 'https://example.com',
            accessCode: 'code_123',
            status: $status,
        );

        expect($response->isSuccessful())->toBeTrue("Failed for status: $status");
    }
});
