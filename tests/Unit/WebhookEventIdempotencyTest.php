<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;

beforeEach(function () {
    config([
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
    ]);
    Event::fake();
});

/**
 * The key a Paystack delivery is recorded under: Paystack sends no event id,
 * so it is a hash of the body.
 */
function paystackDeliveryKey(array $payload): string
{
    return hash('sha256', 'paystack|'.json_encode($payload));
}

function dispatchPaystackWebhookJob(array $payload): void
{
    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);
}

test('a duplicate webhook delivery is skipped and does not redispatch events (ADR-0005)', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => ['id' => 999888777, 'reference' => 'ref_dedupe_1'],
    ];

    dispatchPaystackWebhookJob($payload);
    dispatchPaystackWebhookJob($payload);

    // Byte-identical deliveries share a body hash, so only one WebhookEvent
    // row should exist and only one WebhookReceived event be dispatched.
    expect(WebhookEvent::where('provider', 'paystack')->where('event_key', paystackDeliveryKey($payload))->count())->toBe(1);
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

test('two different webhook deliveries are both processed', function () {
    dispatchPaystackWebhookJob([
        'event' => 'charge.success',
        'data' => ['id' => 111, 'reference' => 'ref_a'],
    ]);
    dispatchPaystackWebhookJob([
        'event' => 'charge.success',
        'data' => ['id' => 222, 'reference' => 'ref_b'],
    ]);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
});

test('a duplicate delivery without a native event id falls back to a content hash', function () {
    // No 'id' anywhere in this payload - resolveEventKey() must fall back to
    // hashing the payload rather than crashing or always treating every
    // delivery as unique.
    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref_no_id']];

    dispatchPaystackWebhookJob($payload);
    dispatchPaystackWebhookJob($payload);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    expect(WebhookEvent::count())->toBe(1);
});

test('a delivery whose processing fails after being recorded can still be retried', function () {
    // Regression: recordIfNew() marks a delivery "seen" before processing
    // runs. If something after that throws (a WebhookReceived listener, a
    // transient failure), the event used to stay marked "seen" forever -
    // meaning Laravel's own queue retry (this job's own $tries/$backoff)
    // would see recordIfNew() return false and silently skip every retry,
    // even though the delivery never actually completed.
    // beforeEach() fakes every event; this test needs a *real* listener to
    // actually throw, so re-fake everything except WebhookReceived.
    Event::fakeExcept([WebhookReceived::class]);

    // Illuminate\Support\Testing\Fakes\EventFake::forget() is a documented
    // no-op (it never reaches the real dispatcher), so a listener registered
    // via Event::listen() while faking can't be un-registered later in the
    // same test. A mutable flag captured by reference lets one listener
    // simulate "fails on first delivery, succeeds on retry" instead.
    $shouldThrow = true;
    $processedAgain = false;
    Event::listen(WebhookReceived::class, function () use (&$shouldThrow, &$processedAgain) {
        if ($shouldThrow) {
            throw new RuntimeException('listener exploded');
        }
        $processedAgain = true;
    });

    $payload = ['event' => 'charge.success', 'data' => ['id' => 555, 'reference' => 'ref_retry']];

    $caught = null;
    try {
        dispatchPaystackWebhookJob($payload);
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toBe('listener exploded');

    // The failed attempt's marker must have been cleared - not left behind
    // as a permanent "already processed" record.
    expect(WebhookEvent::where('provider', 'paystack')->where('event_key', paystackDeliveryKey($payload))->exists())->toBeFalse();

    // Simulates the queue's own retry of the same delivery: this time
    // nothing throws, so it must actually reprocess, not be silently
    // skipped as a duplicate.
    $shouldThrow = false;

    dispatchPaystackWebhookJob($payload);

    expect($processedAgain)->toBeTrue()
        ->and(WebhookEvent::where('provider', 'paystack')->where('event_key', paystackDeliveryKey($payload))->count())->toBe(1);
});

test('a genuinely duplicate delivery is still skipped after a successful first attempt', function () {
    // The forget()-on-failure fix must not weaken the normal dedup path:
    // once a delivery is fully processed without error, a second identical
    // delivery is still a duplicate and must be skipped.
    $payload = ['event' => 'charge.success', 'data' => ['id' => 777, 'reference' => 'ref_stays_deduped']];

    dispatchPaystackWebhookJob($payload);
    dispatchPaystackWebhookJob($payload);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    expect(WebhookEvent::where('provider', 'paystack')->where('event_key', paystackDeliveryKey($payload))->count())->toBe(1);
});

/*
 * Distinct events about one object. The key used to be the object's id
 * (Paystack/Flutterwave data.id, Monnify eventData.transactionReference,
 * OPay payload.transactionId, Mollie's payment id), so the second event was
 * recorded as a duplicate of the first and silently dropped.
 */

function dispatchWebhookJob(string $provider, array $payload): void
{
    app()->forgetInstance('payments.config');
    app()->forgetInstance(\KenDeNigerian\PayZephyr\PaymentManager::class);
    app()->call([new ProcessWebhook($provider, $payload), 'handle']);
}

test('a paystack subscription cancellation is not dropped as a duplicate of its creation', function () {
    dispatchWebhookJob('paystack', ['event' => 'subscription.create', 'data' => ['id' => 4242, 'subscription_code' => 'SUB_1', 'status' => 'active']]);
    dispatchWebhookJob('paystack', ['event' => 'subscription.disable', 'data' => ['id' => 4242, 'subscription_code' => 'SUB_1', 'status' => 'complete']]);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
    expect(WebhookEvent::where('provider', 'paystack')->count())->toBe(2);
});

test('flutterwave events about one transaction are each processed', function () {
    dispatchWebhookJob('flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 777, 'tx_ref' => 'FLW_1', 'status' => 'pending']]);
    dispatchWebhookJob('flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 777, 'tx_ref' => 'FLW_1', 'status' => 'successful']]);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
});

test('a monnify refund outcome is not dropped as a duplicate of the payment it refunds', function () {
    dispatchWebhookJob('monnify', ['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['transactionReference' => 'MNFY_1', 'paymentReference' => 'REF_1', 'paymentStatus' => 'PAID']]);
    dispatchWebhookJob('monnify', ['eventType' => 'SUCCESSFUL_REFUND', 'eventData' => ['transactionReference' => 'MNFY_1', 'refundReference' => 'RFND_1', 'refundStatus' => 'COMPLETED']]);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
});

test('opay status changes for one transaction are each processed', function () {
    dispatchWebhookJob('opay', ['type' => 'transaction-status', 'payload' => ['transactionId' => 'OP_1', 'reference' => 'REF_OP', 'status' => 'PENDING']]);
    dispatchWebhookJob('opay', ['type' => 'transaction-status', 'payload' => ['transactionId' => 'OP_1', 'reference' => 'REF_OP', 'status' => 'SUCCESS']]);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
});

test('a byte-identical retry of a keyless provider is still deduplicated', function () {
    // The fix must not trade dropped events for double processing: a retry
    // is the same body, so it still hashes to the same key.
    $payload = ['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['transactionReference' => 'MNFY_2', 'paymentStatus' => 'PAID']];

    dispatchWebhookJob('monnify', $payload);
    dispatchWebhookJob('monnify', $payload);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

test('every classic mollie ping is processed, because its body cannot tell one status change from the next', function () {
    // Paid, then refunded: Mollie sends {"id": "tr_..."} both times. Each is
    // "go and look"; deduplicating would hide the refund from every listener.
    config(['payments.providers.mollie.webhook_secret' => 'whsec_test']);

    dispatchWebhookJob('mollie', ['id' => 'tr_status_changes']);
    dispatchWebhookJob('mollie', ['id' => 'tr_status_changes']);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
    expect(WebhookEvent::where('provider', 'mollie')->count())->toBe(0);
});

test('a typed mollie event carries its own id and is deduplicated normally', function () {
    config(['payments.providers.mollie.webhook_secret' => 'whsec_test']);
    $ping = ['id' => 'event_GvJ8WHrp5isUdRub9CJyH', 'type' => 'hook.ping'];

    dispatchWebhookJob('mollie', $ping);
    dispatchWebhookJob('mollie', $ping);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    expect(WebhookEvent::where('provider', 'mollie')->where('event_key', 'event_GvJ8WHrp5isUdRub9CJyH')->count())->toBe(1);
});

test('a stateless delivery that fails leaves no marker behind', function () {
    config(['payments.providers.mollie.webhook_secret' => 'whsec_test']);
    Event::fakeExcept([WebhookReceived::class]);
    Event::listen(WebhookReceived::class, fn () => throw new RuntimeException('listener exploded'));

    expect(fn () => dispatchWebhookJob('mollie', ['id' => 'tr_fails']))->toThrow(RuntimeException::class, 'listener exploded');
    expect(WebhookEvent::count())->toBe(0);
});
