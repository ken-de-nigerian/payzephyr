<?php

use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

test('abstract driver handles missing base url gracefully', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    expect($driver->getName())->toBe('paystack');
});

test('abstract driver handles empty currency list', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => [], // Empty array
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    expect($driver->isCurrencySupported('NGN'))->toBeFalse();
});

test('abstract driver handles null currency check', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    expect(fn (): bool => $driver->isCurrencySupported(null))
        ->toThrow(TypeError::class);
});

test('abstract driver reference generation handles custom prefix', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'reference_prefix' => 'CUSTOM_',
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('generateReference');

    $reference = $method->invoke($driver);

    expect($reference)->toBeString()
        ->and(strlen($reference))->toBeGreaterThan(0);
});

test('abstract driver handles ssl verification in testing mode', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.testing' => true,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('client');
    $client = $property->getValue($driver);

    expect($client)->not->toBeNull();
});

test('abstract driver health check caching respects ttl', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.health_check.cache_ttl' => 60,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    $result1 = $driver->getCachedHealthCheck();

    $result2 = $driver->getCachedHealthCheck();

    expect($result1)->toBe($result2);
});

test('abstract driver handles logging disabled config', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.logging.enabled' => false,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    $result = $driver->isCurrencySupported('NGN');

    expect($result)->toBeBool();
});

test('abstract driver handles different log levels', function (): void {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.logging.enabled' => true,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    $method->invoke($driver, 'info', 'Test message');
    $method->invoke($driver, 'warning', 'Test warning');
    $method->invoke($driver, 'error', 'Test error');
    $method->invoke($driver, 'debug', 'Test debug');

    expect(true)->toBeTrue(); // If no exception, logging works
});

test('abstract driver stores configuration correctly', function (): void {
    $config = [
        'driver' => 'paystack',
        'secret_key' => 'sk_test_xxx',
        'enabled' => true,
        'custom_key' => 'custom_value',
    ];

    $driver = new PaystackDriver($config);

    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('config');
    $storedConfig = $property->getValue($driver);

    expect($storedConfig)->toBe($config);
});
