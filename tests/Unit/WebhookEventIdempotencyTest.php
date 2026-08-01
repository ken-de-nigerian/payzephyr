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
