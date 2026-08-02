<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

/**
 * PaystackDriver pulls in LogsSubscriptionTransactions transitively via
 * PaystackSubscriptionMethods, so it's used here to exercise the protected
 * logSubscriptionFromResponse() method directly via reflection.
 */
function makeSubscriptionLoggingDriver(): AbstractDriver
{
    return new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
}

function makeSubscriptionResponse(): SubscriptionResponseDTO
{
    return new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_REMAINING_GAPS',
        status: 'active',
        customer: 'customer@example.com',
        plan: 'PLN_123',
        amount: 5000.0,
        currency: 'NGN',
    );
}

afterEach(function () {
    app()->forgetInstance('payments.config');
});

test('logSubscriptionFromResponse returns early without touching the repository when logging is disabled', function () {
    app()->forgetInstance('payments.config');
    config(['payments.subscriptions.logging.enabled' => false]);

    $repository = Mockery::mock(SubscriptionRepositoryInterface::class);
    $repository->shouldNotReceive('updateOrCreateAtomic');

    $driver = makeSubscriptionLoggingDriver();
    $driver->setSubscriptionRepository($repository);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('logSubscriptionFromResponse');

    $method->invoke($driver, makeSubscriptionResponse(), 'PLN_123', 'customer@example.com');

    expect(true)->toBeTrue();
});

test('logSubscriptionFromResponse falls back to the top-level logging.enabled flag when subscriptions.logging is not set', function () {
    app()->forgetInstance('payments.config');
    config([
        'payments.subscriptions' => [],
        'payments.logging.enabled' => false,
    ]);

    $repository = Mockery::mock(SubscriptionRepositoryInterface::class);
    $repository->shouldNotReceive('updateOrCreateAtomic');

    $driver = makeSubscriptionLoggingDriver();
    $driver->setSubscriptionRepository($repository);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('logSubscriptionFromResponse');

    $method->invoke($driver, makeSubscriptionResponse(), 'PLN_123', 'customer@example.com');

    expect(true)->toBeTrue();
});

test('logSubscriptionFromResponse logs and swallows an exception raised by the repository', function () {
    app()->forgetInstance('payments.config');
    config(['payments.subscriptions.logging.enabled' => true]);

    $repository = Mockery::mock(SubscriptionRepositoryInterface::class);
    $repository->shouldReceive('updateOrCreateAtomic')
        ->once()
        ->andThrow(new RuntimeException('subscription table unavailable'));

    $driver = makeSubscriptionLoggingDriver();
    $driver->setSubscriptionRepository($repository);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('logSubscriptionFromResponse');

    // Should not throw - the exception is caught, logged, and swallowed.
    $method->invoke($driver, makeSubscriptionResponse(), 'PLN_123', 'customer@example.com');

    expect(true)->toBeTrue();
});
