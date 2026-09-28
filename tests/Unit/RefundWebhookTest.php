<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundCreated;
use KenDeNigerian\PayZephyr\Events\RefundFailed;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use Tests\Helpers\RazorpayDriverTestHelper;

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

test('a completed refund webhook updates the locally-persisted refund_transactions row to completed', function () {
    // Regression: processRefundWebhook() used to only dispatch an in-memory
    // event. The local row (created "pending" when refund() first ran)
    // never transitioned to a terminal state, so RefundValidator's
    // in-flight duplicate guard stayed permanently tripped for any
    // provider - like Paystack - that confirms refunds asynchronously,
    // exactly the flow docs/refunds.md recommends ("listen for the
    // webhook-driven events").
    RefundTransaction::create([
        'refund_reference' => '55555',
        'transaction_reference' => 'txn_ref_persist',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    $payload = [
        'event' => 'refund.processed',
        'data' => [
            'id' => 55555,
            'status' => 'processed',
            'transaction' => ['reference' => 'txn_ref_persist'],
        ],
    ];

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    expect(RefundTransaction::where('refund_reference', '55555')->first()->status)->toBe('completed');
});

test('a failed refund webhook updates the locally-persisted refund_transactions row to failed', function () {
    RefundTransaction::create([
        'refund_reference' => '66666',
        'transaction_reference' => 'txn_ref_fail_persist',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    $payload = [
        'event' => 'refund.failed',
        'data' => [
            'id' => 66666,
            'status' => 'failed',
            'reason' => 'insufficient balance',
            'transaction' => ['reference' => 'txn_ref_fail_persist'],
        ],
    ];

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    expect(RefundTransaction::where('refund_reference', '66666')->first()->status)->toBe('failed');
});

test('a refund webhook for a refund with no locally-persisted row does not throw', function () {
    // Best-effort: the refund may have been initiated outside PayZephyr, or
    // refund logging may have been disabled when it ran.
    $payload = [
        'event' => 'refund.processed',
        'data' => [
            'id' => 77777,
            'status' => 'processed',
            'transaction' => ['reference' => 'txn_ref_no_local_row'],
        ],
    ];

    $job = new ProcessWebhook('paystack', $payload);
    app()->call([$job, 'handle']);

    expect(RefundTransaction::where('refund_reference', '77777')->exists())->toBeFalse();
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

test('a razorpay refund.processed webhook reads the nested refund entity and reports the package reference', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    RefundTransaction::create([
        'refund_reference' => 'rfnd_Abc123',
        'transaction_reference' => 'ORDER_1001',
        'provider' => 'razorpay',
        'status' => 'pending',
        'amount' => 499.50,
        'currency' => 'INR',
    ]);

    $job = new ProcessWebhook('razorpay', RazorpayDriverTestHelper::refundWebhook('refund.processed', 'rfnd_Abc123', 'processed'));
    app()->call([$job, 'handle']);

    Event::assertDispatched(RefundCompleted::class, function (RefundCompleted $event) {
        return $event->refundReference === 'rfnd_Abc123'
            && $event->transactionReference === 'ORDER_1001'
            && $event->provider === 'razorpay';
    });

    expect(RefundTransaction::where('refund_reference', 'rfnd_Abc123')->first()->status)->toBe('completed');
});

test('a razorpay refund.failed webhook dispatches RefundFailed', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $job = new ProcessWebhook('razorpay', RazorpayDriverTestHelper::refundWebhook('refund.failed', 'rfnd_Fail123', 'failed'));
    app()->call([$job, 'handle']);

    Event::assertDispatched(RefundFailed::class, function (RefundFailed $event) {
        return $event->refundReference === 'rfnd_Fail123'
            && $event->transactionReference === 'ORDER_1001';
    });
    Event::assertNotDispatched(RefundCompleted::class);
});

test('a razorpay refund webhook without a package reference falls back to the payment id', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $job = new ProcessWebhook('razorpay', RazorpayDriverTestHelper::refundWebhook('refund.created', 'rfnd_Ext123', 'pending', []));
    app()->call([$job, 'handle']);

    Event::assertDispatched(RefundCreated::class, function (RefundCreated $event) {
        return $event->refundReference === 'rfnd_Ext123'
            && $event->transactionReference === 'pay_Abc123';
    });
});

/*
 * A refund's outcome is announced once. Razorpay reports an instant refund
 * twice - refund.created already processed, then refund.processed - and
 * listeners that credit a wallet or email a customer would do it twice.
 */

function enableRazorpayForRefundWebhooks(): void
{
    config(['payments.providers.razorpay' => [
        'driver' => 'razorpay',
        'key_id' => 'rzp_test_x',
        'key_secret' => 'secret',
        'webhook_secret' => 'whsec',
        'enabled' => true,
        'currencies' => ['INR'],
    ]]);
    app()->forgetInstance('payments.config');
    app()->forgetInstance(\KenDeNigerian\PayZephyr\PaymentManager::class);
}

test('an instant razorpay refund reported as created and as processed fires RefundCompleted once', function () {
    enableRazorpayForRefundWebhooks();
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class, \KenDeNigerian\PayZephyr\Events\WebhookReceived::class]);

    app()->call([new ProcessWebhook('razorpay', RazorpayDriverTestHelper::refundWebhook('refund.created', 'rfnd_instant', 'processed')), 'handle']);
    app()->call([new ProcessWebhook('razorpay', RazorpayDriverTestHelper::refundWebhook('refund.processed', 'rfnd_instant', 'processed')), 'handle']);

    // Two distinct deliveries, both processed...
    Event::assertDispatchedTimes(\KenDeNigerian\PayZephyr\Events\WebhookReceived::class, 2);
    // ...but one refund, completed once.
    Event::assertDispatchedTimes(RefundCompleted::class, 1);
});

test('a refund failure reported twice fires RefundFailed once', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    $failed = fn (string $event) => ['event' => $event, 'data' => ['id' => 777, 'status' => 'failed', 'transaction' => ['reference' => 'txn_777']]];

    app()->call([new ProcessWebhook('paystack', $failed('refund.failed')), 'handle']);
    app()->call([new ProcessWebhook('paystack', $failed('refund.processed')), 'handle']);

    Event::assertDispatchedTimes(RefundFailed::class, 1);
});

test('different refunds each announce their own outcome', function () {
    Event::fake([RefundCompleted::class, RefundCreated::class, RefundFailed::class]);

    foreach ([101, 102] as $id) {
        app()->call([new ProcessWebhook('paystack', ['event' => 'refund.processed', 'data' => ['id' => $id, 'status' => 'processed']]), 'handle']);
    }

    Event::assertDispatchedTimes(RefundCompleted::class, 2);
});

test('a RefundCompleted listener that fails leaves the outcome unclaimed, so the retry announces it', function () {
    // The outcome claim is written before the listener runs. If the listener
    // throws and the claim stayed, the queue's retry would find it and never
    // announce the refund at all.
    Event::fakeExcept([RefundCompleted::class]);

    $shouldThrow = true;
    $announced = 0;
    Event::listen(RefundCompleted::class, function () use (&$shouldThrow, &$announced) {
        if ($shouldThrow) {
            throw new RuntimeException('wallet service down');
        }
        $announced++;
    });

    $payload = ['event' => 'refund.processed', 'data' => ['id' => 555, 'status' => 'processed']];

    expect(fn () => app()->call([new ProcessWebhook('paystack', $payload), 'handle']))
        ->toThrow(RuntimeException::class, 'wallet service down');
    expect(\KenDeNigerian\PayZephyr\Models\WebhookEvent::where('event_key', 'refund.completed:555')->exists())->toBeFalse();

    $shouldThrow = false;
    app()->call([new ProcessWebhook('paystack', $payload), 'handle']);

    expect($announced)->toBe(1);
});
