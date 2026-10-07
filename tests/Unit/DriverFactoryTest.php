<?php

use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
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

    app()->forgetInstance('payments.config');

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

test('driver factory register throws exception if class does not implement DriverInterface', function () {
    $factory = new DriverFactory;

    expect(fn () => $factory->register('bad', stdClass::class))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});

test('each bundled driver resolves by name to its exact class', function (string $name, string $class) {
    // Exact, case included: "paypal" used to resolve to PaypalDriver, which
    // only loads where file names ignore case, or once PayPalDriver was loaded.
    $resolve = (new ReflectionClass(DriverFactory::class))->getMethod('resolveDriverClass');

    expect($resolve->invoke(new DriverFactory, $name))->toBe($class)
        ->and($resolve->invoke(new DriverFactory, strtoupper($name)))->toBe($class);
})->with([
    ['flutterwave', \KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver::class],
    ['mollie', \KenDeNigerian\PayZephyr\Drivers\MollieDriver::class],
    ['monnify', \KenDeNigerian\PayZephyr\Drivers\MonnifyDriver::class],
    ['opay', \KenDeNigerian\PayZephyr\Drivers\OPayDriver::class],
    ['paddle', \KenDeNigerian\PayZephyr\Drivers\PaddleDriver::class],
    ['paypal', \KenDeNigerian\PayZephyr\Drivers\PayPalDriver::class],
    ['paystack', \KenDeNigerian\PayZephyr\Drivers\PaystackDriver::class],
    ['razorpay', \KenDeNigerian\PayZephyr\Drivers\RazorpayDriver::class],
    ['square', \KenDeNigerian\PayZephyr\Drivers\SquareDriver::class],
    ['stripe', \KenDeNigerian\PayZephyr\Drivers\StripeDriver::class],
]);

test('a name written in words resolves to the driver class those words spell', function (string $name, string $class) {
    // Not one of the bundled names, so it reaches the naming convention:
    // each word capitalised, joined, and "Driver" appended.
    $resolve = (new ReflectionClass(DriverFactory::class))->getMethod('resolveDriverClass');

    expect($resolve->invoke(new DriverFactory, $name))->toBe($class);
})->with([
    ['pay-pal', \KenDeNigerian\PayZephyr\Drivers\PayPalDriver::class],
    ['o_pay', \KenDeNigerian\PayZephyr\Drivers\OPayDriver::class],
]);

test('an abstract driver class is refused, by name or when registered, before PHP is asked to create it', function () {
    // AbstractDriver implements DriverInterface, so only instantiability
    // tells it apart from a driver; creating it used to throw a raw Error.
    $factory = new DriverFactory;

    expect(fn () => $factory->create(AbstractDriver::class, []))->toThrow(
        DriverNotFoundException::class,
        'Driver class ['.AbstractDriver::class.'] for driver ['.AbstractDriver::class.'] is abstract and cannot be created'
    )->and(fn () => $factory->register('half', AbstractDriver::class))->toThrow(
        DriverNotFoundException::class,
        'Cannot register driver [half]: class ['.AbstractDriver::class.'] is abstract and cannot be created'
    )->and($factory->getRegisteredDrivers())->toBe([]);
});
