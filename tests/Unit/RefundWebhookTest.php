<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundCreated;
use KenDeNigerian\PayZephyr\Events\RefundFailed;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.webhook.verify_signature' => true,
        'payments.providers' => [
            'paystack' => [
                'driver' => 'paystack',
                'secret_key' => 'test_secret',
                'enabled' => true,
            ],
            'stripe' => [
                'driver' => 'stripe',
                'secret_key' => 'sk_test',
                'enabled' => true,
            ],
        ],
    ]);
});

test('a completed refund webhook dispatches RefundCompleted with the refund and transaction reference', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $payload = [
        'event' => 'refund.processed',
        'data' => [
            'id' => 12345,
            'status' => 'processed',
            'transaction' => ['reference' => 'txn_ref_123'],
        ],
    ];

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    Event::assertDispatched(RefundCompleted::class, function (RefundCompleted $event) {
        return $event->refundReference === '12345'
            && $event->transactionReference === 'txn_ref_123'
            && $event->provider === 'paystack';
    });
    Event::assertNotDispatched(RefundFailed::class);
    Event::assertNotDispatched(RefundCreated::class);
});

test('a failed refund webhook dispatches RefundFailed with a reason', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $payload = [
        'event' => 'refund.failed',
        'data' => [
            'id' => 999,
            'status' => 'failed',
            'reason' => 'insufficient balance',
            'transaction' => ['reference' => 'txn_ref_999'],
        ],
    ];

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    Event::assertDispatched(RefundFailed::class, function (RefundFailed $event) {
        return $event->refundReference === '999'
            && $event->transactionReference === 'txn_ref_999'
            && $event->reason === 'insufficient balance';
    });
});

test('a stripe refund.created webhook dispatches RefundCreated using the payment_intent as the transaction reference', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $payload = [
        'event' => 'refund.created',
        'data' => [
            'object' => [
                'id' => 're_123',
                'payment_intent' => 'pi_123',
                'status' => 'pending',
            ],
        ],
    ];

    $job = new ProcessWebhook('stripe', $payload);
    app()->call([$job, 'handle']);

    Event::assertDispatched(RefundCreated::class, function (RefundCreated $event) {
        return $event->refundReference === 're_123'
            && $event->transactionReference === 'pi_123'
            && $event->provider === 'stripe';
    });
});

test('a refund webhook missing a refund reference is skipped without dispatching an event', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $payload = ['event' => 'refund.processed', 'data' => ['status' => 'processed']];

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    Event::assertNotDispatched(RefundCompleted::class);
    Event::assertNotDispatched(RefundCreated::class);
    Event::assertNotDispatched(RefundFailed::class);
});
