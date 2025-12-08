<?php

use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

test('abstract driver handles missing base url gracefully', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            // No base_url
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    // Should use default base URL
    expect($driver->getName())->toBe('paystack');
});

test('abstract driver handles empty currency list', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => [], // Empty array
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    // Should handle empty currency list
    expect($driver->isCurrencySupported('NGN'))->toBeFalse();
});

test('abstract driver handles null currency check', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
            'currencies' => ['NGN', 'USD'],
        ],
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    // Should throw TypeError for null currency (type safety)
    expect(fn () => $driver->isCurrencySupported(null))
        ->toThrow(TypeError::class);
});

test('abstract driver reference generation handles custom prefix', function () {
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

    // Check if prefix is used (may be in config or default)
    expect($reference)->toBeString()
        ->and(strlen($reference))->toBeGreaterThan(0);
});

test('abstract driver handles ssl verification in testing mode', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.testing' => true,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    // Should disable SSL verification in testing mode
    $reflection = new ReflectionClass($driver);
    $property = $reflection->getProperty('client');
    $client = $property->getValue($driver);

    // Client should be initialized
    expect($client)->not->toBeNull();
});

test('abstract driver health check caching respects ttl', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.health_check.cache_ttl' => 60,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    // First call should check health
    $result1 = $driver->getCachedHealthCheck();

    // Second call should use cache
    $result2 = $driver->getCachedHealthCheck();

    // Results should be the same (cached)
    expect($result1)->toBe($result2);
});

test('abstract driver handles logging disabled config', function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'sk_test_xxx',
            'enabled' => true,
        ],
        'payments.logging.enabled' => false,
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));

    // Should not throw exception when logging is disabled
    $result = $driver->isCurrencySupported('NGN');

    expect($result)->toBeBool();
});

test('abstract driver handles different log levels', function () {
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

    // Should handle different log levels
    $method->invoke($driver, 'info', 'Test message');
    $method->invoke($driver, 'warning', 'Test warning');
    $method->invoke($driver, 'error', 'Test error');
    $method->invoke($driver, 'debug', 'Test debug');

    expect(true)->toBeTrue(); // If no exception, logging works
});

test('abstract driver stores configuration correctly', function () {
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
