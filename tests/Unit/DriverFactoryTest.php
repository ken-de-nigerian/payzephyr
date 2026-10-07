<?php

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Services\DriverFactory;

test('driver factory creates default drivers', function (): void {
    $factory = new DriverFactory;

    $driver = $factory->create('paystack', [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory throws exception for non-existent class', function (): void {
    $factory = new DriverFactory;

    expect(fn (): DriverInterface => $factory->create('nonexistent', []))
        ->toThrow(DriverNotFoundException::class, 'Driver class');
});

test('driver factory register adds custom driver', function (): void {
    $factory = new DriverFactory;

    $factory->register('custom', PaystackDriver::class);

    $driver = $factory->create('custom', [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory register throws exception for non-existent class', function (): void {
    $factory = new DriverFactory;

    expect(fn (): DriverFactory => $factory->register('custom', 'NonExistentClass'))
        ->toThrow(DriverNotFoundException::class, 'does not exist');
});

test('driver factory register throws exception for non-interface class', function (): void {
    $factory = new DriverFactory;

    expect(fn (): DriverFactory => $factory->register('custom', stdClass::class))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});

test('driver factory getRegisteredDrivers returns registered driver names', function (): void {
    $factory = new DriverFactory;

    $factory->register('custom1', PaystackDriver::class);
    $factory->register('custom2', PaystackDriver::class);

    $drivers = $factory->getRegisteredDrivers();

    expect($drivers)->toContain('custom1', 'custom2');
});

test('driver factory isRegistered checks if driver is registered', function (): void {
    $factory = new DriverFactory;

    expect($factory->isRegistered('custom'))->toBeFalse();

    $factory->register('custom', PaystackDriver::class);

    expect($factory->isRegistered('custom'))->toBeTrue();
});

test('driver factory uses config driver class if available', function (): void {
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

test('driver factory uses fully qualified class name as fallback', function (): void {
    $factory = new DriverFactory;

    $driver = $factory->create(PaystackDriver::class, [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    expect($driver)->toBeInstanceOf(PaystackDriver::class);
});

test('driver factory register throws exception if class does not implement DriverInterface', function (): void {
    $factory = new DriverFactory;

    expect(fn (): DriverFactory => $factory->register('bad', stdClass::class))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});

test('each bundled driver resolves by name to its exact class', function (string $name, string $class): void {
    // Exact, case included: "paypal" used to resolve to PaypalDriver, which
    // only loads where file names ignore case, or once PayPalDriver was loaded.
    $resolve = (new ReflectionClass(DriverFactory::class))->getMethod('resolveDriverClass');

    expect($resolve->invoke(new DriverFactory, $name))->toBe($class)
        ->and($resolve->invoke(new DriverFactory, strtoupper($name)))->toBe($class);
})->with([
    ['flutterwave', FlutterwaveDriver::class],
    ['mollie', MollieDriver::class],
    ['monnify', MonnifyDriver::class],
    ['opay', OPayDriver::class],
    ['paddle', PaddleDriver::class],
    ['paypal', PayPalDriver::class],
    ['paystack', PaystackDriver::class],
    ['razorpay', RazorpayDriver::class],
    ['square', SquareDriver::class],
    ['stripe', StripeDriver::class],
]);

test('a name the bundled drivers do not use is not turned into a class name', function (string $name): void {
    // These used to be spelled into PayPalDriver, OPayDriver and AbstractDriver.
    $factory = new DriverFactory;
    $resolve = (new ReflectionClass(DriverFactory::class))->getMethod('resolveDriverClass');

    expect($resolve->invoke($factory, $name))->toBe($name)
        ->and(fn (): DriverInterface => $factory->create($name, []))->toThrow(DriverNotFoundException::class, "Driver class [$name] not found for driver [$name]");
})->with(['pay-pal', 'o_pay', 'abstract']);

test('an abstract driver class is refused, by name or when registered, before PHP is asked to create it', function (): void {
    // AbstractDriver implements DriverInterface, so only instantiability
    // tells it apart from a driver; creating it used to throw a raw Error.
    $factory = new DriverFactory;

    expect(fn (): DriverInterface => $factory->create(AbstractDriver::class, []))->toThrow(
        DriverNotFoundException::class,
        'Driver class ['.AbstractDriver::class.'] for driver ['.AbstractDriver::class.'] is abstract and cannot be created'
    )->and(fn (): DriverFactory => $factory->register('half', AbstractDriver::class))->toThrow(
        DriverNotFoundException::class,
        'Cannot register driver [half]: class ['.AbstractDriver::class.'] is abstract and cannot be created'
    )->and($factory->getRegisteredDrivers())->toBe([]);
});
