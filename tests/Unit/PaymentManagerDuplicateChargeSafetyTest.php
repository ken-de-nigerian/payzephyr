<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Events\PaymentInitiated;
use KenDeNigerian\PayZephyr\Events\PaymentVerificationSuccess;
use KenDeNigerian\PayZephyr\PaymentManager;

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.default' => 'primary',
        'payments.fallback' => 'secondary',
        'payments.health_check.enabled' => false,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

test('a cache failure after a successful charge does not trigger a second charge against the fallback provider', function () {
    // Regression: chargeWithFallback() ran cacheSessionData()/logTransaction()/
    // PaymentInitiated::dispatch() *inside* the same try{} block as
    // $driver->charge(), so an exception from any of them after a
    // successful charge was caught by the provider-loop's own
    // catch(Throwable) and silently retried against the next fallback
    // provider - charging the customer twice.
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache backend unreachable'));
    Cache::shouldReceive('forget')->andReturnTrue();

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $response = $manager->chargeWithFallback($request);

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a PaymentInitiated listener exception after a successful charge does not trigger a second charge', function () {
    Event::listen(PaymentInitiated::class, function () {
        throw new RuntimeException('listener exploded');
    });

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $response = $manager->chargeWithFallback($request);

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a PaymentVerificationSuccess listener exception after a successful verify does not trigger a second verify call against another provider', function () {
    Event::listen(PaymentVerificationSuccess::class, function () {
        throw new RuntimeException('listener exploded');
    });

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->verify('some_reference', 'primary');

    expect($response->reference)->toBe('some_reference')
        ->and($primary->verifyCalls)->toBe(1)
        ->and($secondary->verifyCalls)->toBe(0);
});
