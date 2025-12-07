<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;

test('falls back to secondary provider when primary fails', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => 'stripe',
        'payments.health_check.enabled' => false,
    ]);

    $manager = new PaymentManager;

    expect($manager->getFallbackChain())->toBe(['paystack', 'stripe']);
});

test(/**
 * @throws ProviderException
 */ 'throws exception when all providers fail', function () {
    config([
        'payments.providers.paystack.enabled' => false,
        'payments.providers.stripe.enabled' => false,
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager->chargeWithFallback($request);
})->throws(ProviderException::class);
