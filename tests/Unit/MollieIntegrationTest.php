<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Facades\Payment;

test('mollie integration - full payment flow', function () {
    // Skip if no real API key
    if (! env('MOLLIE_API_KEY') || ! str_starts_with(env('MOLLIE_API_KEY'), 'test_')) {
        $this->markTestSkipped('Mollie test API key not configured');
    }

    // Initialize payment
    $response = Payment::amount(10.00)
        ->currency('EUR')
        ->email('test@example.com')
        ->description('Test Payment')
        ->callback('https://example.com/callback')
        ->with('mollie')
        ->charge();

    expect($response->reference)->toBeString()
        ->and($response->authorizationUrl)->toStartWith('https://www.mollie.com')
        ->and($response->status)->toBe('pending')
        ->and($response->provider)->toBe('mollie');
});
