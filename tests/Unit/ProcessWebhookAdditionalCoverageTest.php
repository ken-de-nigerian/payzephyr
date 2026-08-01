<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionLifecycleHooks;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\PaymentManager;

uses(RefreshDatabase::class);

/**
 * Injects a fake driver into PaymentManager's private `drivers` array - see
 * tests/Unit/SubscriptionQueryTest.php's makeQueryManager() for the origin of
 * this pattern (PaymentManager is final, so it can't be partially mocked).
 */
function injectFakeDriver(PaymentManager $manager, string $provider, object $driver): void
{
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $current = $driversProperty->getValue($manager);
    $current[$provider] = $driver;
    $driversProperty->setValue($manager, $current);
}

beforeEach(function () {
    config([
        'payments.logging.enabled' => true,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
    ]);
    Event::fake();
});

test('process webhook job treats an unresolvable provider as verified and still processes the webhook', function () {
    // Covers verifyDeferredSignature()'s DriverNotFoundException catch (returns
    // true - skip deferred verification) and resolveEventKey()'s equivalent
    // catch (falls back to the content-hash idempotency key).
    $job = new ProcessWebhook('does-not-exist', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_missing_driver'],
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->provider === 'does-not-exist';
    });
});

test('process webhook job swallows a non-driver-not-found exception raised while updating the transaction', function () {
    // Covers updateTransactionFromWebhook()'s catch (Throwable $e) block: the
    // exception is logged, not rethrown, so handle() completes normally.
    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_status_boom'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn('ref_status_boom');
    $mockDriver->shouldReceive('extractWebhookStatus')->andThrow(new RuntimeException('status extraction blew up'));

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->reference === 'ref_status_boom';
    });
});

test('process webhook job dispatches SubscriptionCreated for a subscription-create event', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'subscription.create',
        'data' => ['subscription_code' => 'SUB_CREATE_1', 'plan' => 'gold'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertDispatched(SubscriptionCreated::class, function ($event) {
        return $event->subscriptionCode === 'SUB_CREATE_1' && $event->provider === 'paystack';
    });
});

test('process webhook job dispatches SubscriptionRenewed and calls the driver lifecycle hooks', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'subscription.renewed',
        'data' => ['subscription_code' => 'SUB_RENEW_1', 'reference' => 'INV_RENEW_1'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class, SubscriptionLifecycleHooks::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);
    $mockDriver->shouldReceive('beforeSubscriptionRenewal')->once()->with('SUB_RENEW_1');
    $mockDriver->shouldReceive('afterSubscriptionRenewal')->once()->with('SUB_RENEW_1', 'INV_RENEW_1');

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertDispatched(SubscriptionRenewed::class, function ($event) {
        return $event->subscriptionCode === 'SUB_RENEW_1'
            && $event->provider === 'paystack'
            && $event->invoiceReference === 'INV_RENEW_1';
    });
});

test('process webhook job dispatches SubscriptionCancelled for a subscription-cancel event', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'subscription.cancel',
        'data' => ['subscription_code' => 'SUB_CANCEL_1'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertDispatched(SubscriptionCancelled::class, function ($event) {
        return $event->subscriptionCode === 'SUB_CANCEL_1' && $event->provider === 'paystack';
    });
});

test('process webhook job dispatches SubscriptionPaymentFailed and calls the driver failure hook', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'invoice.payment_failed',
        'data' => ['subscription_code' => 'SUB_FAIL_1', 'reason' => 'Card declined'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class, SubscriptionLifecycleHooks::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);
    $mockDriver->shouldReceive('onSubscriptionRenewalFailed')->once()->with('SUB_FAIL_1', 'Card declined');

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertDispatched(SubscriptionPaymentFailed::class, function ($event) {
        return $event->subscriptionCode === 'SUB_FAIL_1'
            && $event->provider === 'paystack'
            && $event->reason === 'Card declined';
    });
});

test('process webhook job logs a warning and dispatches nothing when subscription_code is missing', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'subscription.create',
        'data' => [],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertNotDispatched(SubscriptionCreated::class);
    Event::assertDispatched(WebhookReceived::class);
});

test('process webhook job dispatches nothing subscription-specific for an unmatched subscription event type', function () {
    $job = new ProcessWebhook('paystack', [
        'event' => 'subscription.updated',
        'data' => ['subscription_code' => 'SUB_UNMATCHED_1'],
    ]);

    $manager = app(PaymentManager::class);
    $mockDriver = Mockery::mock(DriverInterface::class);
    $mockDriver->shouldReceive('extractWebhookReference')->andReturn(null);

    injectFakeDriver($manager, 'paystack', $mockDriver);

    app()->call([$job, 'handle']);

    Event::assertNotDispatched(SubscriptionCreated::class);
    Event::assertNotDispatched(SubscriptionRenewed::class);
    Event::assertNotDispatched(SubscriptionCancelled::class);
    Event::assertNotDispatched(SubscriptionPaymentFailed::class);
    Event::assertDispatched(WebhookReceived::class);
});

test('process webhook job still dispatches SubscriptionRenewed when the provider driver cannot be resolved', function () {
    // Covers the DriverNotFoundException catch inside the "renewed" branch of
    // processSubscriptionWebhook() - the lifecycle hook call is skipped, but
    // the domain event still fires.
    $job = new ProcessWebhook('unknown-provider', [
        'event' => 'subscription.renewed',
        'data' => ['subscription_code' => 'SUB_RENEW_NO_DRIVER', 'reference' => 'INV_ND'],
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(SubscriptionRenewed::class, function ($event) {
        return $event->subscriptionCode === 'SUB_RENEW_NO_DRIVER';
    });
});

test('process webhook job still dispatches SubscriptionPaymentFailed when the provider driver cannot be resolved', function () {
    // Covers the DriverNotFoundException catch inside the "payment failed"
    // branch of processSubscriptionWebhook().
    $job = new ProcessWebhook('unknown-provider', [
        'event' => 'invoice.payment_failed',
        'data' => ['subscription_code' => 'SUB_FAIL_NO_DRIVER', 'reason' => 'timeout'],
    ]);

    app()->call([$job, 'handle']);

    Event::assertDispatched(SubscriptionPaymentFailed::class, function ($event) {
        return $event->subscriptionCode === 'SUB_FAIL_NO_DRIVER';
    });
});
