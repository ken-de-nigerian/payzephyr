<?php

use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Services\DriverFactory;

test('driver factory creates all default drivers', function () {
    $factory = new DriverFactory;

    $paystackConfig = ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'currencies' => ['NGN']];
    $flutterwaveConfig = ['secret_key' => 'sk_test', 'currencies' => ['NGN']];
    $monnifyConfig = ['api_key' => 'key', 'secret_key' => 'secret', 'contract_code' => 'code', 'currencies' => ['NGN']];
    $stripeConfig = ['secret_key' => 'sk_test', 'currencies' => ['USD']];
    $paypalConfig = ['client_id' => 'id', 'client_secret' => 'secret', 'mode' => 'sandbox', 'currencies' => ['USD']];

    expect($factory->create('paystack', $paystackConfig))->toBeInstanceOf(PaystackDriver::class)
        ->and($factory->create('flutterwave', $flutterwaveConfig))->toBeInstanceOf(FlutterwaveDriver::class)
        ->and($factory->create('monnify', $monnifyConfig))->toBeInstanceOf(MonnifyDriver::class)
        ->and($factory->create('stripe', $stripeConfig))->toBeInstanceOf(StripeDriver::class)
        ->and($factory->create('paypal', $paypalConfig))->toBeInstanceOf(PayPalDriver::class);
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

test('driver factory getRegisteredDrivers returns empty array initially', function () {
    $factory = new DriverFactory;

    expect($factory->getRegisteredDrivers())->toBe([]);
});

test('driver factory getRegisteredDrivers returns all registered driver names', function () {
    $factory = new DriverFactory;

    $factory->register('custom1', PaystackDriver::class);
    $factory->register('custom2', PaystackDriver::class);
    $factory->register('custom3', PaystackDriver::class);

    $drivers = $factory->getRegisteredDrivers();

    expect($drivers)->toContain('custom1', 'custom2', 'custom3')
        ->and($drivers)->toHaveCount(3);
});

test('driver factory isRegistered checks if driver is registered', function () {
    $factory = new DriverFactory;

    expect($factory->isRegistered('custom'))->toBeFalse();

    $factory->register('custom', PaystackDriver::class);

    expect($factory->isRegistered('custom'))->toBeTrue()
        ->and($factory->isRegistered('not_registered'))->toBeFalse();
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

test('driver factory uses config driver class over registered driver', function () {
    // Create a custom factory that checks config before registered
    $factory = new class extends DriverFactory
    {
        protected function resolveDriverClass(string $name): string
        {
            // Check config FIRST (before registered)
            $configDriver = config("payments.providers.$name.driver_class");
            if ($configDriver && class_exists($configDriver)) {
                return $configDriver;
            }

            // Then check registered
            if (isset($this->drivers[$name])) {
                return $this->drivers[$name];
            }

            // Then defaults
            if (isset($this->defaultDrivers[$name])) {
                return $this->defaultDrivers[$name];
            }

            return $name;
        }
    };

    $factory->register('paystack', FlutterwaveDriver::class);
    config(['payments.providers.paystack.driver_class' => PaystackDriver::class]);

    $driver = $factory->create('paystack', [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    // Config should override registered driver
    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory uses registered driver over default driver', function () {
    $factory = new DriverFactory;

    $factory->register('paystack', FlutterwaveDriver::class);

    $driver = $factory->create('paystack', [
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(FlutterwaveDriver::class);
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

test('driver factory create throws exception if class does not exist', function () {
    $factory = new DriverFactory;

    expect(fn () => $factory->create('nonexistent', []))
        ->toThrow(DriverNotFoundException::class, 'Driver class');
});

test('driver factory create throws exception if class does not implement DriverInterface', function () {
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

test('driver factory register allows chaining', function () {
    $factory = new DriverFactory;

    $result = $factory->register('custom1', PaystackDriver::class)
        ->register('custom2', PaystackDriver::class);

    expect($result)->toBe($factory)
        ->and($factory->isRegistered('custom1'))->toBeTrue()
        ->and($factory->isRegistered('custom2'))->toBeTrue();
});
