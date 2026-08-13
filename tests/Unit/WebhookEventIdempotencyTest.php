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

    // Paystack's extractWebhookEventId() reads data.id - both deliveries
    // share the same id, so only one WebhookEvent row should exist and only
    // one WebhookReceived event should have been dispatched.
    expect(WebhookEvent::where('provider', 'paystack')->where('event_key', '999888777')->count())->toBe(1);
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
    expect(WebhookEvent::where('provider', 'paystack')->where('event_key', '555')->exists())->toBeFalse();

    // Simulates the queue's own retry of the same delivery: this time
    // nothing throws, so it must actually reprocess, not be silently
    // skipped as a duplicate.
    $shouldThrow = false;

    dispatchPaystackWebhookJob($payload);

    expect($processedAgain)->toBeTrue()
        ->and(WebhookEvent::where('provider', 'paystack')->where('event_key', '555')->count())->toBe(1);
});

test('a genuinely duplicate delivery is still skipped after a successful first attempt', function () {
    // The forget()-on-failure fix must not weaken the normal dedup path:
    // once a delivery is fully processed without error, a second identical
    // delivery is still a duplicate and must be skipped.
    $payload = ['event' => 'charge.success', 'data' => ['id' => 777, 'reference' => 'ref_stays_deduped']];

    dispatchPaystackWebhookJob($payload);
    dispatchPaystackWebhookJob($payload);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    expect(WebhookEvent::where('provider', 'paystack')->where('event_key', '777')->count())->toBe(1);
});
