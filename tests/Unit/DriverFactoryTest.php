<?php

use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Services\DriverFactory;

test('driver factory creates default drivers', function () {
    $factory = new DriverFactory;

    $driver = $factory->create('paystack', [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory throws exception for non-existent class', function () {
    $factory = new DriverFactory;

    expect(fn () => $factory->create('nonexistent', []))
        ->toThrow(DriverNotFoundException::class, 'Driver class');
});

test('driver factory register adds custom driver', function () {
    $factory = new DriverFactory;

    $factory->register('custom', PaystackDriver::class);

    $driver = $factory->create('custom', [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory register throws exception for non-existent class', function () {
    $factory = new DriverFactory;

    expect(fn () => $factory->register('custom', 'NonExistentClass'))
        ->toThrow(DriverNotFoundException::class, 'does not exist');
});

test('driver factory register throws exception for non-interface class', function () {
    $factory = new DriverFactory;

    expect(fn () => $factory->register('custom', stdClass::class))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});

test('driver factory getRegisteredDrivers returns registered driver names', function () {
    $factory = new DriverFactory;

    $factory->register('custom1', PaystackDriver::class);
    $factory->register('custom2', PaystackDriver::class);

    $drivers = $factory->getRegisteredDrivers();

    expect($drivers)->toContain('custom1', 'custom2');
});

test('driver factory isRegistered checks if driver is registered', function () {
    $factory = new DriverFactory;

    expect($factory->isRegistered('custom'))->toBeFalse();

    $factory->register('custom', PaystackDriver::class);

    expect($factory->isRegistered('custom'))->toBeTrue();
});

test('driver factory uses config driver class if available', function () {
    $factory = new DriverFactory;

    config(['payments.providers.custom.driver_class' => PaystackDriver::class]);

    $driver = $factory->create('custom', [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory uses fully qualified class name as fallback', function () {
    $factory = new DriverFactory;

    $driver = $factory->create(PaystackDriver::class, [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory create throws exception if class does not implement DriverInterface', function () {
    // Register a class that doesn't implement DriverInterface
    $factory = new class extends DriverFactory
    {
        protected function resolveDriverClass(string $name): string
        {
            return stdClass::class;
        }
    };

    expect(fn () => $factory->create('test', []))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});
