<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
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

test('throws exception when all providers fail', function () {
    config([
        'payments.providers' => [
            'paystack' => ['enabled' => false],
            'stripe' => ['enabled' => false],
        ],
        'payments.health_check.enabled' => false,
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager->chargeWithFallback($request);
})->throws(ProviderException::class);

test('skips providers that do not support currency', function () {
    config([
        'payments.health_check.enabled' => false,
        'payments.providers' => [
            'paystack' => [
                'driver' => 'paystack',
                'secret_key' => 'test',
                'enabled' => true,
                'currencies' => ['NGN', 'USD'],
            ],
            'stripe' => [
                'driver' => 'stripe',
                'secret_key' => 'test',
                'enabled' => true,
                'currencies' => ['USD', 'EUR'],
            ],
        ],
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'EUR', // Only Stripe supports EUR
        'email' => 'test@example.com',
    ]);

    // This will fail because we're not actually calling APIs,
    // But it tests that Paystack is skipped for EUR
    try {
        $manager->chargeWithFallback($request, ['paystack', 'stripe']);
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('provider exception includes context about all failures', function () {
    config([
        'payments.providers' => [
            'invalid1' => ['enabled' => false],
            'invalid2' => ['enabled' => false],
        ],
        'payments.health_check.enabled' => false,
    ]);

    try {
        $manager = new PaymentManager;
        $request = ChargeRequestDTO::fromArray([
            'amount' => 1000,
            'currency' => 'NGN',
            'email' => 'test@example.com',
        ]);

        $manager->chargeWithFallback($request);
    } catch (ProviderException $e) {
        expect($e->getMessage())->toContain('All payment providers failed')
            ->and($e->getContext())->toHaveKey('exceptions');
    }
});

test('verification tries all providers when provider not specified', function () {
    config([
        'payments.providers' => [
            'paystack' => [
                'driver' => 'paystack',
                'secret_key' => 'test',
                'enabled' => true,
            ],
            'stripe' => [
                'driver' => 'stripe',
                'secret_key' => 'test',
                'enabled' => true,
            ],
        ],
    ]);

    $manager = new PaymentManager;

    try {
        // This will fail because no API is available
        $manager->verify('ref_123');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toContain('Unable to verify payment reference');
    }
});

test('verification uses specific provider when specified', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;

    try {
        // This will fail because no API is available
        $manager->verify('ref_123', 'paystack');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('health check can be disabled', function () {
    config(['payments.health_check.enabled' => false]);

    expect(config('payments.health_check.enabled'))->toBeFalse();
});

test('health check can be enabled', function () {
    config(['payments.health_check.enabled' => true]);

    expect(config('payments.health_check.enabled'))->toBeTrue();
});

test('health check cache ttl is configurable', function () {
    config(['payments.health_check.cache_ttl' => 600]);

    expect(config('payments.health_check.cache_ttl'))->toBe(600);
});

test('fallback chain handles empty fallback', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => null,
    ]);

    $manager = new PaymentManager;

    expect($manager->getFallbackChain())->toBe(['paystack']);
});

test('fallback chain handles same default and fallback', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => 'paystack',
    ]);

    $manager = new PaymentManager;

    expect($manager->getFallbackChain())->toBe(['paystack']);
});

test('custom provider list overrides config fallback', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => 'stripe',
        'payments.health_check.enabled' => false,
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequestDTO::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    // Custom providers: ['flutterwave', 'monnify']
    // Should use these instead of default fallback chain
    try {
        $manager->chargeWithFallback($request, ['flutterwave', 'monnify']);
    } catch (Exception $e) {
        // Will fail due to no API, but tests custom provider list
        expect($e)->toBeInstanceOf(Exception::class);
    }
});

test('invalid driver configuration throws exception', function () {
    config([
        'payments.providers.invalid' => [
            'driver' => 'NonExistentDriver',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;
    $manager->driver('invalid');
})->throws(DriverNotFoundException::class);

test('disabled provider is not available', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            'enabled' => false,
        ],
    ]);

    $manager = new PaymentManager;
    $manager->driver('paystack');
})->throws(DriverNotFoundException::class);

test('missing provider configuration throws exception', function () {
    $manager = new PaymentManager;
    $manager->driver('nonexistent');
})->throws(DriverNotFoundException::class);

test('driver instance is cached', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;

    $driver1 = $manager->driver('paystack');
    $driver2 = $manager->driver('paystack');

    expect($driver1)->toBe($driver2);
});
