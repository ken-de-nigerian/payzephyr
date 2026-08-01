<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionLifecycleHooks;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Subscription;

/**
 * This file closes specific coverage gaps in src/Subscription.php that are
 * NOT exercised by SubscriptionCompleteTest.php or SubscriptionEdgeCasesTest.php:
 *
 *  - authorization(), callbackUrl() and idempotency() fluent setters are
 *    never called anywhere else (only Payment::idempotency() is tested).
 *  - SubscriptionLifecycleHooks hooks (before/afterSubscriptionCreate,
 *    before/afterSubscriptionCancel) are never exercised because PaystackDriver
 *    doesn't implement that optional interface.
 *  - The "[Provider] does not support subscriptions" guard is only tested for
 *    createPlan(); fetch(), cancel(), enable(), list(), updatePlan(),
 *    fetchPlan() and listPlans() each have their own copy of that guard that
 *    was never independently exercised.
 */
interface AdditionalCoverageSubscriptionDriver extends DriverInterface, SupportsSubscriptionsInterface {}

interface AdditionalCoverageHooksSubscriptionDriver extends DriverInterface, SubscriptionLifecycleHooks, SupportsSubscriptionsInterface {}

function makeAdditionalCoverageManager(string $provider, DriverInterface $driver): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, [$provider => $driver]);

    config(['payments.default' => $provider]);

    return $manager;
}

beforeEach(function () {
    // Most of these tests bypass validation on purpose: we're targeting the
    // Subscription class's own fluent setters / guard clauses / hook
    // dispatch, not SubscriptionValidator (which is covered elsewhere).
    config(['payments.subscriptions.validation.enabled' => false]);
    app()->forgetInstance('payments.config');
});

// ==================== Fluent setter coverage ====================

test('subscription authorization() forwards the authorization code to the request', function () {
    $driver = Mockery::mock(AdditionalCoverageSubscriptionDriver::class);
    $driver->shouldReceive('createSubscription')
        ->once()
        ->andReturnUsing(function (SubscriptionRequestDTO $request) {
            expect($request->authorization)->toBe('AUTH_1234567890');

            return new SubscriptionResponseDTO(
                subscriptionCode: 'SUB_123',
                status: 'active',
                customer: 'test@example.com',
                plan: 'PLN_123',
                amount: 5000.0,
                currency: 'NGN',
            );
        });

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->authorization('AUTH_1234567890')
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class)
        ->and($result->subscriptionCode)->toBe('SUB_123');
});

test('subscription callbackUrl() forwards the callback url to the request', function () {
    $driver = Mockery::mock(AdditionalCoverageSubscriptionDriver::class);
    $driver->shouldReceive('createSubscription')
        ->once()
        ->andReturnUsing(function (SubscriptionRequestDTO $request) {
            expect($request->callbackUrl)->toBe('https://example.com/callback');

            return new SubscriptionResponseDTO(
                subscriptionCode: 'SUB_123',
                status: 'active',
                customer: 'test@example.com',
                plan: 'PLN_123',
                amount: 5000.0,
                currency: 'NGN',
            );
        });

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->callbackUrl('https://example.com/callback')
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

test('subscription idempotency() forwards an explicit key to the request', function () {
    $driver = Mockery::mock(AdditionalCoverageSubscriptionDriver::class);
    $driver->shouldReceive('createSubscription')
        ->once()
        ->andReturnUsing(function (SubscriptionRequestDTO $request) {
            expect($request->idempotencyKey)->toBe('my-explicit-key');

            return new SubscriptionResponseDTO(
                subscriptionCode: 'SUB_123',
                status: 'active',
                customer: 'test@example.com',
                plan: 'PLN_123',
                amount: 5000.0,
                currency: 'NGN',
            );
        });

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->idempotency('my-explicit-key')
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

test('subscription idempotency() generates a uuid when no key is given', function () {
    $driver = Mockery::mock(AdditionalCoverageSubscriptionDriver::class);
    $driver->shouldReceive('createSubscription')
        ->once()
        ->andReturnUsing(function (SubscriptionRequestDTO $request) {
            expect($request->idempotencyKey)
                ->toBeString()
                ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');

            return new SubscriptionResponseDTO(
                subscriptionCode: 'SUB_123',
                status: 'active',
                customer: 'test@example.com',
                plan: 'PLN_123',
                amount: 5000.0,
                currency: 'NGN',
            );
        });

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->idempotency()
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

// ==================== Lifecycle hooks coverage ====================

test('subscription create() invokes beforeSubscriptionCreate and afterSubscriptionCreate hooks', function () {
    $driver = Mockery::mock(AdditionalCoverageHooksSubscriptionDriver::class);

    $driver->shouldReceive('beforeSubscriptionCreate')
        ->once()
        ->andReturnUsing(fn (SubscriptionRequestDTO $request) => $request);

    $response = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_123',
        status: 'active',
        customer: 'test@example.com',
        plan: 'PLN_123',
        amount: 5000.0,
        currency: 'NGN',
    );

    $driver->shouldReceive('createSubscription')->once()->andReturn($response);
    $driver->shouldReceive('afterSubscriptionCreate')->once()->with($response);

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();

    expect($result)->toBe($response);
});

test('subscription create() sends the request returned by beforeSubscriptionCreate, not the original', function () {
    $driver = Mockery::mock(AdditionalCoverageHooksSubscriptionDriver::class);

    $driver->shouldReceive('beforeSubscriptionCreate')
        ->once()
        ->andReturnUsing(fn (SubscriptionRequestDTO $request) => new SubscriptionRequestDTO(
            customer: $request->customer,
            plan: $request->plan,
            metadata: ['modified_by_hook' => true],
        ));

    $driver->shouldReceive('createSubscription')
        ->once()
        ->andReturnUsing(function (SubscriptionRequestDTO $request) {
            expect($request->metadata)->toBe(['modified_by_hook' => true]);

            return new SubscriptionResponseDTO(
                subscriptionCode: 'SUB_123',
                status: 'active',
                customer: 'test@example.com',
                plan: 'PLN_123',
                amount: 5000.0,
                currency: 'NGN',
            );
        });

    $driver->shouldReceive('afterSubscriptionCreate')->once();

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

test('subscription cancel() invokes beforeSubscriptionCancel and afterSubscriptionCancel hooks', function () {
    $driver = Mockery::mock(AdditionalCoverageHooksSubscriptionDriver::class);

    $driver->shouldReceive('beforeSubscriptionCancel')->once()->with('SUB_123');

    $response = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_123',
        status: 'cancelled',
        customer: 'test@example.com',
        plan: 'PLN_123',
        amount: 5000.0,
        currency: 'NGN',
    );

    $driver->shouldReceive('cancelSubscription')->once()->andReturn($response);
    $driver->shouldReceive('afterSubscriptionCancel')->once()->with($response);

    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $result = $subscription->code('SUB_123')->cancel('token_12345');

    expect($result)->toBe($response);
});

// ==================== "does not support subscriptions" guard coverage ====================
// createPlan() already has coverage for this guard elsewhere; the remaining
// six subscription-management methods each guard independently.

test('subscription fetch() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->code('SUB_123')->fetch();
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');

test('subscription cancel() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->code('SUB_123')->cancel('token_12345');
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');

test('subscription enable() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->code('SUB_123')->enable('token_12345');
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');

test('subscription list() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->list();
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');

test('subscription updatePlan() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->plan('PLN_123')->planUpdates(['name' => 'New Name'])->updatePlan();
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');

test('subscription fetchPlan() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->plan('PLN_123')->fetchPlan();
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');

test('subscription listPlans() throws when the provider does not support subscriptions', function () {
    $driver = Mockery::mock(DriverInterface::class);
    $manager = makeAdditionalCoverageManager('paystack', $driver);
    $subscription = new Subscription($manager);

    $subscription->listPlans();
})->throws(PaymentException::class, 'Provider [paystack] does not support subscriptions');
