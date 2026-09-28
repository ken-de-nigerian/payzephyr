<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use Tests\Helpers\RefundTestHelper;
use Tests\Helpers\SubscriptionTestHelper;

/**
 * Refunds and subscriptions used to leave no trace rows at all. A refund
 * moves money outward, settles asynchronously, and is what a dispute is
 * argued over, so it is recorded on the refunded payment's own timeline.
 * Subscriptions get a timeline of their own, keyed by subscription code.
 */
function tracedEvents(string $reference): array
{
    return PaymentTraceEvent::where('reference', $reference)
        ->orderBy('id')
        ->pluck('event')
        ->map(fn (TraceEvent $event): string => $event->value)
        ->all();
}

function tracedPayload(string $reference, TraceEvent $event): array
{
    return (array) PaymentTraceEvent::where('reference', $reference)->where('event', $event->value)->firstOrFail()->payload;
}

beforeEach(function () {
    config([
        'payments.features.trace' => true,
        'payments.trace.async' => false,
        'payments.refunds.prevent_duplicates' => true,
    ]);
    app()->forgetInstance('payments.config');
});

// ---------------------------------------------------------------------------
// Refunds
// ---------------------------------------------------------------------------

test('a refund is recorded on the payment it refunds, with its provider round trip', function () {
    config(['payments.refunds.validation.enabled' => false]);
    $refund = RefundTestHelper::createWithMock([RefundTestHelper::refundMock(9001, ['status' => 'pending'])]);

    $refund->with('paystack')->transaction('PAY_REF_1')->amount(50)->refund();

    expect(tracedEvents('PAY_REF_1'))->toBe([
        'refund.requested',
        'provider.request.sent',
        'provider.response.received',
        'refund.accepted',
    ])->and(tracedPayload('PAY_REF_1', TraceEvent::REFUND_ACCEPTED))->toMatchArray([
        'refund_reference' => '9001',
        'status' => 'pending',
    ]);
});

test('an instant refund also records the payment as refunded', function () {
    config(['payments.refunds.validation.enabled' => false]);
    $refund = RefundTestHelper::createWithMock([RefundTestHelper::refundMock(9002, ['status' => 'processed'])]);

    $refund->with('paystack')->transaction('PAY_REF_2')->amount(50)->refund();

    expect(tracedEvents('PAY_REF_2'))->toContain('refund.accepted')
        ->and(tracedEvents('PAY_REF_2'))->toContain('payment.refunded');
});

test('a refund the provider rejects is recorded as failed, and not as ambiguous', function () {
    config(['payments.refunds.validation.enabled' => false]);
    $refund = RefundTestHelper::createWithMock([
        new Response(200, [], (string) json_encode(['status' => false, 'message' => 'Transaction has been fully reversed'])),
    ]);

    expect(fn () => $refund->with('paystack')->transaction('PAY_REF_3')->amount(50)->refund())->toThrow(RefundException::class);

    expect(tracedEvents('PAY_REF_3'))->toContain('refund.failed')
        ->and(tracedPayload('PAY_REF_3', TraceEvent::REFUND_FAILED))->toMatchArray(['stage' => 'provider', 'ambiguous' => false]);
});

test('a refund whose response was lost is recorded as ambiguous', function () {
    // The provider may have refunded anyway. A timeline that says "failed"
    // would send someone to refund the customer a second time.
    config(['payments.refunds.validation.enabled' => false]);
    $refund = RefundTestHelper::createWithMock([
        new \GuzzleHttp\Exception\RequestException('connection reset', new Request('POST', '/refund')),
    ]);

    expect(fn () => $refund->with('paystack')->transaction('PAY_REF_4')->amount(50)->refund())->toThrow(RefundException::class);

    expect(tracedPayload('PAY_REF_4', TraceEvent::REFUND_FAILED)['ambiguous'])->toBeTrue();
});

test('a refund that fails validation is recorded at the validation stage', function () {
    config(['payments.refunds.validation.enabled' => true]);
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'PAY_REF_5', 'provider' => 'paystack', 'status' => 'success',
        'amount' => 10, 'currency' => 'NGN', 'email' => 'a@b.test',
    ]);
    $refund = RefundTestHelper::createWithMock([]);

    expect(fn () => $refund->with('paystack')->transaction('PAY_REF_5')->amount(500)->refund())->toThrow(RefundException::class, 'exceeds the remaining refundable balance');

    expect(tracedEvents('PAY_REF_5'))->toBe(['refund.requested', 'refund.failed'])
        ->and(tracedPayload('PAY_REF_5', TraceEvent::REFUND_FAILED)['stage'])->toBe('validation');
});

test('a second refund while the first is in flight is recorded as rejected', function () {
    config(['payments.refunds.validation.enabled' => false]);
    Illuminate\Support\Facades\Cache::add('payzephyr:refund:inflight:PAY_REF_6', true, 60);
    $refund = RefundTestHelper::createWithMock([]);

    expect(fn () => $refund->with('paystack')->transaction('PAY_REF_6')->amount(50)->refund())->toThrow(RefundException::class);

    expect(tracedEvents('PAY_REF_6'))->toBe(['refund.requested', 'refund.duplicate_rejected']);
});

test('a refund completed by webhook is recorded on the payment, once', function () {
    $payload = ['event' => 'refund.processed', 'data' => ['id' => 9007, 'status' => 'processed', 'transaction' => ['reference' => 'PAY_REF_7']]];

    app()->call([new ProcessWebhook('paystack', $payload), 'handle']);
    app()->call([new ProcessWebhook('paystack', array_merge($payload, ['event' => 'refund.updated'])), 'handle']);

    expect(array_count_values(tracedEvents('PAY_REF_7'))['payment.refunded'] ?? 0)->toBe(1);
});

test('a refund failure reported by webhook is recorded on the payment at the settlement stage', function () {
    app()->call([new ProcessWebhook('paystack', ['event' => 'refund.failed', 'data' => [
        'id' => 9008, 'status' => 'failed', 'transaction' => ['reference' => 'PAY_REF_8'], 'reason' => 'Bank rejected',
    ]]), 'handle']);

    expect(tracedPayload('PAY_REF_8', TraceEvent::REFUND_FAILED))->toMatchArray(['stage' => 'settlement', 'reason' => 'Bank rejected']);
});

// ---------------------------------------------------------------------------
// Subscriptions
// ---------------------------------------------------------------------------

test('a subscription cancellation is recorded on the subscription with its provider round trips', function () {
    config(['payments.subscriptions.validation.enabled' => false]);
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], (string) json_encode(['status' => true, 'message' => 'Subscription disabled successfully'])),
        SubscriptionTestHelper::subscriptionMock('SUB_TRACE_1', ['status' => 'cancelled']),
    ]);

    $subscription->with('paystack')->code('SUB_TRACE_1')->token('tok_email_confirm_1')->cancel();

    $events = tracedEvents('SUB_TRACE_1');
    expect($events)->toContain('provider.request.sent')
        ->and(end($events))->toBe('subscription.cancelled')
        ->and(tracedPayload('SUB_TRACE_1', TraceEvent::SUBSCRIPTION_CANCELLED)['status'])->toBe('cancelled');
});

test('a subscription re-enable is recorded, and a failed one names the operation', function () {
    config(['payments.subscriptions.validation.enabled' => false]);
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], (string) json_encode(['status' => true, 'message' => 'Subscription enabled successfully'])),
        SubscriptionTestHelper::subscriptionMock('SUB_TRACE_2', ['status' => 'active']),
        new ConnectException('timed out', new Request('POST', '/subscription/enable')),
    ]);

    $subscription->with('paystack')->code('SUB_TRACE_2')->token('tok_email_confirm_2')->enable();
    expect(tracedEvents('SUB_TRACE_2'))->toContain('subscription.enabled');

    expect(fn () => $subscription->with('paystack')->code('SUB_TRACE_2')->token('tok_email_confirm_2')->enable())->toThrow(SubscriptionException::class);
    expect(tracedPayload('SUB_TRACE_2', TraceEvent::SUBSCRIPTION_OPERATION_FAILED)['operation'])->toBe('enable');
});

test('a subscription creation is recorded under the code the provider returned', function () {
    config(['payments.subscriptions.validation.enabled' => false]);
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], (string) json_encode(['status' => true, 'data' => [
            'subscription_code' => 'SUB_TRACE_3', 'status' => 'active', 'amount' => 500000,
            'plan' => ['plan_code' => 'PLN_1'], 'customer' => ['email' => 'a@b.test'],
        ]])),
    ]);

    $subscription->with('paystack')->customer('a@b.test')->plan('PLN_1')->authorization('AUTH_1')->create();

    expect(tracedEvents('SUB_TRACE_3'))->toBe(['subscription.created']);
});

test('subscription lifecycle webhooks are recorded on the subscription', function (string $event, string $expected) {
    app()->call([new ProcessWebhook('paystack', ['event' => $event, 'data' => [
        'subscription_code' => 'SUB_TRACE_WEBHOOK', 'status' => 'active', 'reference' => 'INV_1',
    ]]), 'handle']);

    expect(tracedEvents('SUB_TRACE_WEBHOOK'))->toContain($expected);
})->with([
    ['subscription.create', 'subscription.created'],
    ['invoice.payment_succeeded', 'subscription.renewed'],
    ['subscription.disable', 'subscription.cancelled'],
    ['invoice.payment_failed', 'subscription.payment_failed'],
]);

// ---------------------------------------------------------------------------
// Synchronous signature rejections
// ---------------------------------------------------------------------------

test('a webhook rejected for its signature is recorded on the payment it names, without its body', function () {
    config(['payments.webhook.verify_signature' => true]);
    app()->forgetInstance('payments.config');
    $body = (string) json_encode(['event' => 'charge.success', 'data' => ['reference' => 'PAY_FORGED', 'amount' => 999999]]);

    $request = makeWebhookRequestFor('paystack', $body, ['x-paystack-signature' => 'not-the-signature']);

    expect($request->authorize())->toBeFalse()
        ->and(tracedEvents('PAY_FORGED'))->toBe(['webhook.validation_failed']);

    $payload = tracedPayload('PAY_FORGED', TraceEvent::WEBHOOK_VALIDATION_FAILED);
    expect($payload['stage'])->toBe('signature')
        ->and($payload)->not->toHaveKey('amount')
        ->and(json_encode($payload))->not->toContain('999999');
});

test('a rejected webhook whose body is not JSON is still rejected, with nothing to record against', function () {
    config(['payments.webhook.verify_signature' => true]);
    app()->forgetInstance('payments.config');

    $request = makeWebhookRequestFor('paystack', 'not json', ['x-paystack-signature' => 'nope']);

    expect($request->authorize())->toBeFalse()
        ->and(PaymentTraceEvent::count())->toBe(0);
});

test('a rejected webhook whose body breaks the driver\'s reference extraction is still just rejected', function () {
    // The body is whatever a forger sent. A custom driver's extraction
    // throwing on a hostile shape must not turn a clean 403 into a 500.
    config(['payments.webhook.verify_signature' => true]);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(\KenDeNigerian\PayZephyr\Contracts\DriverInterface::class);
    $driver->shouldReceive('validateWebhook')->andReturnFalse();
    $driver->shouldReceive('extractWebhookReference')->andThrow(new TypeError('Cannot access offset of type string on string'));
    injectFakeDrivers(app(\KenDeNigerian\PayZephyr\PaymentManager::class), ['acmepay' => $driver]);

    $request = makeWebhookRequestFor('acmepay', '{"data":"not-an-object"}');

    expect($request->authorize())->toBeFalse()
        ->and(PaymentTraceEvent::count())->toBe(0);
});
