<?php

use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    config([
        'payments.providers.paystack' => ['driver' => 'paystack', 'secret_key' => 'sk_test', 'enabled' => true],
        'payments.providers.flutterwave' => ['driver' => 'flutterwave', 'secret_key' => 'flw_sec', 'enabled' => true],
        'payments.providers.stripe' => ['driver' => 'stripe', 'secret_key' => 'sk_test', 'enabled' => true],
        'payments.providers.monnify' => ['driver' => 'monnify', 'api_key' => 'mk_test', 'secret_key' => 'sk_test', 'contract_code' => '123', 'enabled' => true],
        'payments.providers.paypal' => ['driver' => 'paypal', 'client_id' => 'id', 'client_secret' => 'secret', 'enabled' => true],
    ]);
});

test('manager resolves all supported driver types', function () {
    $manager = app(PaymentManager::class);

    expect($manager->driver('paystack'))->toBeInstanceOf(PaystackDriver::class)
        ->and($manager->driver('flutterwave'))->toBeInstanceOf(FlutterwaveDriver::class)
        ->and($manager->driver('stripe'))->toBeInstanceOf(StripeDriver::class)
        ->and($manager->driver('monnify'))->toBeInstanceOf(MonnifyDriver::class)
        ->and($manager->driver('paypal'))->toBeInstanceOf(PayPalDriver::class);
});

test('manager passes configuration to drivers correctly', function () {
    $manager = app(PaymentManager::class);

    // Test that a specific driver received the config
    // This often hits the `buildProvider` or similar internal methods in the Manager
    $stripe = $manager->driver('stripe');

    // Use reflection to check if config was passed if there's no getter
    $reflection = new ReflectionClass($stripe);
    $property = $reflection->getProperty('config');
    $config = $property->getValue($stripe);

    expect($config['secret_key'])->toBe('sk_test');
});
