<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;

test('payment manager returns enabled providers', function () {
    config([
        'payments.providers' => [
            'paystack' => ['enabled' => true, 'secret_key' => 'test'],
            'stripe' => ['enabled' => false, 'secret_key' => 'test'],
            'flutterwave' => ['enabled' => true, 'secret_key' => 'test'],
        ],
    ]);

    $manager = new PaymentManager;
    $providers = $manager->getEnabledProviders();

    expect($providers)->toHaveKey('paystack')
        ->and($providers)->toHaveKey('flutterwave')
        ->and($providers)->not->toHaveKey('stripe');
});

test('payment manager throws exception for disabled driver', function () {
    config([
        'payments.providers.invalid' => ['enabled' => false],
    ]);

    $manager = new PaymentManager;
    $manager->driver('invalid');
})->throws(DriverNotFoundException::class);

test('payment manager throws exception for non-existent driver', function () {
    $manager = new PaymentManager;
    $manager->driver('nonexistent');
})->throws(DriverNotFoundException::class);

test('payment manager gets default driver', function () {
    config([
        'payments.default' => 'paystack',
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;

    expect($manager->getDefaultDriver())->toBe('paystack');
});

test('payment manager gets fallback chain', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => 'stripe',
    ]);

    $manager = new PaymentManager;

    expect($manager->getFallbackChain())
        ->toBe(['paystack', 'stripe'])
        ->and($manager->getFallbackChain())->toHaveCount(2);
});

test('payment manager fallback chain removes duplicates', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => 'paystack', // Same as default
    ]);

    $manager = new PaymentManager;

    expect($manager->getFallbackChain())
        ->toBe(['paystack'])
        ->and($manager->getFallbackChain())->toHaveCount(1);
});

test('payment manager fallback chain works without fallback', function () {
    config([
        'payments.default' => 'paystack',
        'payments.fallback' => null,
    ]);

    $manager = new PaymentManager;

    expect($manager->getFallbackChain())
        ->toBe(['paystack'])
        ->and($manager->getFallbackChain())->toHaveCount(1);
});

test('payment manager caches driver instances', function () {
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

    expect($driver1)->toBe($driver2); // Same instance
});

test('payment manager resolves driver classes correctly', function () {
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

    $paystackDriver = $manager->driver('paystack');
    $stripeDriver = $manager->driver('stripe');

    expect($paystackDriver->getName())->toBe('paystack')
        ->and($stripeDriver->getName())->toBe('stripe');
});

test('payment manager uses default driver when none specified', function () {
    config([
        'payments.default' => 'stripe',
        'payments.providers.stripe' => [
            'driver' => 'stripe',
            'secret_key' => 'test',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;
    $driver = $manager->driver(); // No provider specified

    expect($driver->getName())->toBe('stripe');
});

test('payment manager throws when all providers fail charge', function () {
    config([
        'payments.providers' => [
            'paystack' => ['enabled' => false],
            'stripe' => ['enabled' => false],
        ],
        'payments.health_check.enabled' => false,
    ]);

    $manager = new PaymentManager;
    $request = ChargeRequest::fromArray([
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager->chargeWithFallback($request);
})->throws(ProviderException::class);

test('payment manager gets enabled providers count', function () {
    config([
        'payments.providers' => [
            'paystack' => ['enabled' => true],
            'stripe' => ['enabled' => true],
            'flutterwave' => ['enabled' => false],
        ],
    ]);

    $manager = new PaymentManager;
    $enabled = $manager->getEnabledProviders();

    expect($enabled)->toHaveCount(2);
});

test('payment manager handles empty providers config', function () {
    config(['payments.providers' => []]);

    $manager = new PaymentManager;

    expect($manager->getEnabledProviders())->toBe([]);
});

test('payment manager handles missing enabled flag as true', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test',
            // No 'enabled' flag
        ],
    ]);

    $manager = new PaymentManager;
    $providers = $manager->getEnabledProviders();

    expect($providers)->toHaveKey('paystack');
});

test('payment manager throws when driver class does not exist', function () {
    config([
        'payments.providers.custom' => [
            'driver' => 'NonExistentDriver',
            'enabled' => true,
        ],
    ]);

    $manager = new PaymentManager;
    $manager->driver('custom');
})->throws(DriverNotFoundException::class);
