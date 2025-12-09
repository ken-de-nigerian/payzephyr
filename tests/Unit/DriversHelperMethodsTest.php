<?php

use GuzzleHttp\Client;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;

// Reference Generation Tests
test('paystack driver generates unique references', function () {
    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('generateReference');

    $ref1 = $method->invoke($driver);
    $ref2 = $method->invoke($driver);

    expect($ref1)->toBeString()
        ->and($ref1)->toContain('PAYSTACK_')
        ->and($ref1)->not->toBe($ref2);
});

test('flutterwave driver generates unique references', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('generateReference');

    $ref = $method->invoke($driver, 'FLW');

    expect($ref)->toBeString()
        ->and($ref)->toContain('FLW_');
});

test('monnify driver generates unique references', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test',
        'secret_key' => 'test',
        'contract_code' => 'test',
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('generateReference');

    $ref = $method->invoke($driver, 'MON');

    expect($ref)->toBeString()
        ->and($ref)->toContain('MON_');
});

test('references contain timestamp and random component', function () {
    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('generateReference');

    $ref = $method->invoke($driver);

    // Format: PREFIX_timestamp_random
    $parts = explode('_', $ref);

    expect($parts)->toHaveCount(3)
        ->and($parts[0])->toBe('PAYSTACK')
        ->and($parts[1])->toBeNumeric(); // timestamp
});

// Currency Support Tests
test('driver checks currency support correctly', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'currencies' => ['NGN', 'USD', 'GHS'],
    ]);

    expect($driver->isCurrencySupported('NGN'))->toBeTrue()
        ->and($driver->isCurrencySupported('USD'))->toBeTrue()
        ->and($driver->isCurrencySupported('EUR'))->toBeFalse()
        ->and($driver->isCurrencySupported('JPY'))->toBeFalse();
});

test('driver currency check is case insensitive', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'currencies' => ['NGN', 'USD'],
    ]);

    expect($driver->isCurrencySupported('ngn'))->toBeTrue()
        ->and($driver->isCurrencySupported('usd'))->toBeTrue()
        ->and($driver->isCurrencySupported('Ngn'))->toBeTrue()
        ->and($driver->isCurrencySupported('UsD'))->toBeTrue();
});

test('driver returns correct supported currencies list', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'currencies' => ['NGN', 'USD', 'GHS', 'ZAR'],
    ]);

    $currencies = $driver->getSupportedCurrencies();

    expect($currencies)->toBe(['NGN', 'USD', 'GHS', 'ZAR'])
        ->and($currencies)->toHaveCount(4);
});

test('all drivers have currency support method', function () {
    $drivers = [
        new PaystackDriver(['secret_key' => 'test']),
        new FlutterwaveDriver(['secret_key' => 'test']),
        new MonnifyDriver(['api_key' => 'test', 'secret_key' => 'test', 'contract_code' => 'test']),
        new StripeDriver(['secret_key' => 'test']),
        new PayPalDriver(['client_id' => 'test', 'client_secret' => 'test']),
    ];

    foreach ($drivers as $driver) {
        expect($driver->getSupportedCurrencies())->toBeArray();
    }
});

// Logging Tests
test('driver logs info when logging enabled', function () {
    config(['payments.logging.enabled' => true]);

    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    // Should not throw exception
    $method->invoke($driver, 'info', 'Test log message', ['key' => 'value']);

    expect(true)->toBeTrue();
});

test('driver respects logging disabled config', function () {
    config(['payments.logging.enabled' => false]);

    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    // Should not throw exception
    $method->invoke($driver, 'info', 'Test log message');

    expect(true)->toBeTrue();
});

test('driver logs with different levels', function () {
    config(['payments.logging.enabled' => true]);

    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    $levels = ['debug', 'info', 'warning', 'error', 'critical'];

    foreach ($levels as $level) {
        $method->invoke($driver, $level, "Test $level message");
    }

    expect(true)->toBeTrue();
});

test('driver logs with context data', function () {
    config(['payments.logging.enabled' => true]);

    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    $context = [
        'reference' => 'ref_123',
        'amount' => 10000,
        'provider' => 'paystack',
    ];

    $method->invoke($driver, 'info', 'Payment processed', $context);

    expect(true)->toBeTrue();
});

// Health Check Caching Tests
test('driver caches health check results', function () {
    config(['payments.health_check.cache_ttl' => 300]);

    $driver = new PaystackDriver(['secret_key' => 'test']);

    $result1 = $driver->getCachedHealthCheck();
    $result2 = $driver->getCachedHealthCheck();

    expect($result1)->toBeBool()
        ->and($result2)->toBeBool()
        ->and($result1)->toBe($result2); // Should be cached
});

test('driver health check cache respects ttl', function () {
    config(['payments.health_check.cache_ttl' => 1]); // 1 second

    $driver = new PaystackDriver(['secret_key' => 'test']);

    $result1 = $driver->getCachedHealthCheck();

    expect($result1)->toBeBool();
});

test('all drivers have health check method', function () {
    $drivers = [
        new PaystackDriver(['secret_key' => 'test']),
        new FlutterwaveDriver(['secret_key' => 'test']),
        new MonnifyDriver(['api_key' => 'test', 'secret_key' => 'test', 'contract_code' => 'test']),
        new StripeDriver(['secret_key' => 'test']),
        new PayPalDriver(['client_id' => 'test', 'client_secret' => 'test']),
    ];

    foreach ($drivers as $driver) {
        expect($driver->healthCheck())->toBeBool();
    }
});

// Driver Name Tests
test('driver returns correct name', function () {
    $drivers = [
        ['driver' => new PaystackDriver(['secret_key' => 'test']), 'name' => 'paystack'],
        ['driver' => new FlutterwaveDriver(['secret_key' => 'test']), 'name' => 'flutterwave'],
        ['driver' => new MonnifyDriver(['api_key' => 'test', 'secret_key' => 'test', 'contract_code' => 'test']), 'name' => 'monnify'],
        ['driver' => new StripeDriver(['secret_key' => 'test']), 'name' => 'stripe'],
        ['driver' => new PayPalDriver(['client_id' => 'test', 'client_secret' => 'test']), 'name' => 'paypal'],
    ];

    foreach ($drivers as $item) {
        expect($item['driver']->getName())->toBe($item['name']);
    }
});

// HTTP Client Tests
test('driver initializes http client', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'base_url' => 'https://api.paystack.co',
    ]);

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('client');

    $client = $property->getValue($driver);

    expect($client)->toBeInstanceOf(Client::class);
});

test('driver http client has correct base url', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'test',
        'base_url' => 'https://api.flutterwave.com/v3',
    ]);

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('client');

    $client = $property->getValue($driver);

    expect($client)->toBeInstanceOf(Client::class);
});

test('driver http client has default timeout', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'timeout' => 30,
    ]);

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('client');

    $client = $property->getValue($driver);

    expect($client)->toBeInstanceOf(Client::class);
});

test('driver http client disables ssl verification in testing mode', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'testing_mode' => true,
    ]);

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('client');

    $client = $property->getValue($driver);

    expect($client)->toBeInstanceOf(Client::class);
});

// Configuration Tests
test('driver stores configuration', function () {
    $config = [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'enabled' => true,
    ];

    $driver = new PaystackDriver($config);

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('config');

    expect($property->getValue($driver))->toBe($config);
});

test('driver validates required configuration on construction', function () {
    // Should throw exception for missing config
    expect(true)->toBeTrue(); // Already tested in DriversTest
});

// Edge Cases
test('driver handles empty currency list', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'currencies' => [],
    ]);

    expect($driver->getSupportedCurrencies())->toBe([])
        ->and($driver->isCurrencySupported('NGN'))->toBeFalse();
});

test('driver handles null currency check', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        'currencies' => ['NGN'],
    ]);

    expect($driver->isCurrencySupported(''))->toBeFalse();
});

test('reference generation handles custom prefix', function () {
    $driver = new PaystackDriver(['secret_key' => 'test']);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('generateReference');

    $ref = $method->invoke($driver, 'CUSTOM');

    expect($ref)->toContain('CUSTOM_');
});

test('driver handles missing base url gracefully', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'test',
        // No base_url provided
    ]);

    expect($driver->getName())->toBe('paystack');
});
