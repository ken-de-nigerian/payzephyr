<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundCreated;
use KenDeNigerian\PayZephyr\Events\RefundFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;

/*
 * Refund and subscription webhooks in each provider's own shape.
 *
 * The job read the event name from `event`, `eventType` and `event_type`, and
 * the subscription from Paystack's field names. Stripe and Square name the
 * event in `type`, and every other provider nests the subscription somewhere
 * else - so their refund and subscription webhooks were processed as plain
 * deliveries and no RefundCompleted or SubscriptionCancelled ever fired.
 */

beforeEach(function (): void {
    // Signatures are verified before this job runs; PayPal's deferred check
    // would otherwise call PayPal.
    config(['payments.webhook.verify_signature' => false]);
    app()->forgetInstance('payments.config');

    Event::fake([
        RefundCompleted::class, RefundCreated::class, RefundFailed::class,
        SubscriptionCancelled::class, SubscriptionCreated::class,
        SubscriptionPaymentFailed::class, SubscriptionRenewed::class,
    ]);
});

function processWebhookShape(string $provider, array $payload): void
{
    app()->call([new ProcessWebhook($provider, $payload), 'handle']);
}

// ---------------------------------------------------------------------------
// Refunds
// ---------------------------------------------------------------------------

test('a stripe refund event completes the refund it carries', function (string $type): void {
    processWebhookShape('stripe', ['id' => 'evt_1', 'type' => $type, 'data' => ['object' => [
        'id' => 're_1', 'object' => 'refund', 'payment_intent' => 'pi_1', 'status' => 'succeeded', 'amount' => 500,
    ]]]);

    Event::assertDispatched(RefundCompleted::class, fn ($e): bool => $e->refundReference === 're_1' && $e->transactionReference === 'pi_1');
})->with(['refund.updated', 'charge.refund.updated', 'refund.created']);

test('a stripe refund that failed reports why', function (): void {
    processWebhookShape('stripe', ['id' => 'evt_2', 'type' => 'refund.failed', 'data' => ['object' => [
        'id' => 're_2', 'object' => 'refund', 'payment_intent' => 'pi_2', 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card',
    ]]]);

    Event::assertDispatched(RefundFailed::class, fn ($e): bool => $e->refundReference === 're_2' && $e->reason === 'expired_or_canceled_card');
    Event::assertNotDispatched(RefundCompleted::class);
});

test('a cancelled refund is recorded as cancelled and announced as not happening', function (): void {
    RefundTransaction::create([
        'refund_reference' => 're_3', 'transaction_reference' => 'pi_3', 'provider' => 'stripe',
        'status' => 'pending', 'amount' => 5, 'currency' => 'USD',
    ]);

    processWebhookShape('stripe', ['id' => 'evt_3', 'type' => 'refund.updated', 'data' => ['object' => [
        'id' => 're_3', 'object' => 'refund', 'payment_intent' => 'pi_3', 'status' => 'canceled',
    ]]]);

    expect(RefundTransaction::where('refund_reference', 're_3')->value('status'))->toBe('cancelled');
    Event::assertDispatched(RefundFailed::class, fn ($e): bool => $e->reason === 'Refund cancelled');
});

test('stripe charge.refunded is not mistaken for a refund: its object is the charge', function (): void {
    processWebhookShape('stripe', ['id' => 'evt_4', 'type' => 'charge.refunded', 'data' => ['object' => [
        'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_4', 'refunded' => true,
    ]]]);

    Event::assertNotDispatched(RefundCompleted::class);
    Event::assertNotDispatched(RefundCreated::class);
});

test('a square refund that was rejected fails it', function (): void {
    processWebhookShape('square', ['type' => 'refund.updated', 'event_id' => 'e1', 'data' => [
        'type' => 'refund', 'id' => 'sqr_1', 'object' => ['refund' => ['id' => 'sqr_1', 'status' => 'REJECTED', 'payment_id' => 'sqp_1']],
    ]]);

    Event::assertDispatched(RefundFailed::class, fn ($e): bool => $e->refundReference === 'sqr_1' && $e->transactionReference === 'sqp_1');
});

test('a square refund still pending is announced as created', function (): void {
    processWebhookShape('square', ['type' => 'refund.created', 'event_id' => 'e2', 'data' => [
        'type' => 'refund', 'id' => 'sqr_2', 'object' => ['refund' => ['id' => 'sqr_2', 'status' => 'PENDING', 'payment_id' => 'sqp_2']],
    ]]);

    Event::assertDispatched(RefundCreated::class, fn ($e): bool => $e->refundReference === 'sqr_2');
});

test('a paddle refund adjustment settles the refund it is', function (string $status, string $event): void {
    processWebhookShape('paddle', ['event_id' => 'evt_adj_'.$status, 'event_type' => 'adjustment.updated', 'data' => [
        'id' => 'adj_1', 'action' => 'refund', 'status' => $status, 'transaction_id' => 'txn_9',
    ]]);

    Event::assertDispatched($event, fn ($e): bool => $e->refundReference === 'adj_1' && $e->transactionReference === 'txn_9');
})->with([
    'approved' => ['approved', RefundCompleted::class],
    'rejected' => ['rejected', RefundFailed::class],
    'reversed' => ['reversed', RefundFailed::class],
    'awaiting approval' => ['pending_approval', RefundCreated::class],
]);

test('a paddle adjustment that is not a refund is not treated as one', function (): void {
    processWebhookShape('paddle', ['event_id' => 'evt_adj_credit', 'event_type' => 'adjustment.created', 'data' => [
        'id' => 'adj_2', 'action' => 'credit', 'status' => 'approved', 'transaction_id' => 'txn_8',
    ]]);

    Event::assertNotDispatched(RefundCompleted::class);
    Event::assertNotDispatched(RefundCreated::class);
});

// ---------------------------------------------------------------------------
// Subscriptions
// ---------------------------------------------------------------------------

test('stripe subscription events name the subscription they are about', function (string $type, string $event): void {
    processWebhookShape('stripe', ['id' => 'evt_s', 'type' => $type, 'data' => ['object' => [
        'id' => 'sub_1', 'object' => 'subscription', 'status' => 'active',
    ]]]);

    Event::assertDispatched($event, fn ($e): bool => $e->subscriptionCode === 'sub_1');
})->with([
    'created' => ['customer.subscription.created', SubscriptionCreated::class],
    'deleted' => ['customer.subscription.deleted', SubscriptionCancelled::class],
]);

test('a stripe invoice event finds its subscription, in either api shape', function (array $invoice): void {
    processWebhookShape('stripe', ['id' => 'evt_i', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1', 'object' => 'invoice'] + $invoice]]);

    Event::assertDispatched(SubscriptionRenewed::class, fn ($e): bool => $e->subscriptionCode === 'sub_9' && $e->invoiceReference === 'in_1');
})->with([
    'subscription field' => [['subscription' => 'sub_9']],
    'parent subscription details (2025 api)' => [['parent' => ['subscription_details' => ['subscription' => 'sub_9']]]],
]);

test('a stripe invoice whose payment failed reports a failed renewal', function (): void {
    processWebhookShape('stripe', ['id' => 'evt_f', 'type' => 'invoice.payment_failed', 'data' => ['object' => [
        'id' => 'in_2', 'object' => 'invoice', 'subscription' => 'sub_2',
    ]]]);

    Event::assertDispatched(SubscriptionPaymentFailed::class, fn ($e): bool => $e->subscriptionCode === 'sub_2');
});

test('paypal subscription events name the subscription in resource', function (string $eventType, string $event): void {
    processWebhookShape('paypal', ['id' => 'WH-1', 'event_type' => $eventType, 'resource' => ['id' => 'I-SUB1', 'status' => 'CANCELLED']]);

    Event::assertDispatched($event, fn ($e): bool => $e->subscriptionCode === 'I-SUB1');
})->with([
    'created' => ['BILLING.SUBSCRIPTION.CREATED', SubscriptionCreated::class],
    'cancelled' => ['BILLING.SUBSCRIPTION.CANCELLED', SubscriptionCancelled::class],
    'payment failed' => ['BILLING.SUBSCRIPTION.PAYMENT.FAILED', SubscriptionPaymentFailed::class],
]);

test('a paypal sale for a billing agreement is a renewal; a plain sale is not', function (): void {
    processWebhookShape('paypal', ['id' => 'WH-2', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => [
        'id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB2', 'state' => 'completed',
    ]]);
    processWebhookShape('paypal', ['id' => 'WH-3', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => [
        'id' => 'SALE-2', 'state' => 'completed',
    ]]);

    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
    Event::assertDispatched(SubscriptionRenewed::class, fn ($e): bool => $e->subscriptionCode === 'I-SUB2' && $e->invoiceReference === 'SALE-1');
});

test('square reports a cancellation as an update to cancelled', function (string $status, bool $cancelled): void {
    processWebhookShape('square', ['type' => 'subscription.updated', 'event_id' => 'e-'.$status, 'data' => [
        'type' => 'subscription', 'id' => 'sqs_1', 'object' => ['subscription' => ['id' => 'sqs_1', 'status' => $status]],
    ]]);

    $cancelled
        ? Event::assertDispatched(SubscriptionCancelled::class, fn ($e): bool => $e->subscriptionCode === 'sqs_1')
        : Event::assertNotDispatched(SubscriptionCancelled::class);
})->with([
    'canceled' => ['CANCELED', true],
    'deactivated' => ['DEACTIVATED', true],
    'still active' => ['ACTIVE', false],
]);

test('square invoice events find the subscription they bill', function (string $type, string $event): void {
    processWebhookShape('square', ['type' => $type, 'event_id' => 'e-'.$type, 'data' => [
        'type' => 'invoice', 'id' => 'inv_1', 'object' => ['invoice' => ['id' => 'inv_1', 'subscription_id' => 'sqs_2']],
    ]]);

    Event::assertDispatched($event, fn ($e): bool => $e->subscriptionCode === 'sqs_2');
})->with([
    'paid' => ['invoice.payment_made', SubscriptionRenewed::class],
    'charge failed' => ['invoice.scheduled_charge_failed', SubscriptionPaymentFailed::class],
]);

test('paddle subscription events and subscription transactions', function (string $eventType, array $data, string $event, string $code): void {
    processWebhookShape('paddle', ['event_id' => 'evt_'.$eventType, 'event_type' => $eventType, 'data' => $data]);

    Event::assertDispatched($event, fn ($e): bool => $e->subscriptionCode === $code);
})->with([
    'cancelled' => ['subscription.canceled', ['id' => 'sub_p1', 'status' => 'canceled'], SubscriptionCancelled::class, 'sub_p1'],
    'past due' => ['subscription.past_due', ['id' => 'sub_p2', 'status' => 'past_due'], SubscriptionPaymentFailed::class, 'sub_p2'],
    'renewal transaction' => ['transaction.completed', ['id' => 'txn_1', 'subscription_id' => 'sub_p3', 'status' => 'completed'], SubscriptionRenewed::class, 'sub_p3'],
]);

test('a paddle transaction outside a subscription is not a subscription event', function (): void {
    processWebhookShape('paddle', ['event_id' => 'evt_plain', 'event_type' => 'transaction.completed', 'data' => [
        'id' => 'txn_2', 'status' => 'completed',
    ]]);

    Event::assertNotDispatched(SubscriptionRenewed::class);
});

test('a flutterwave subscription event names the subscription by its id', function (): void {
    processWebhookShape('flutterwave', ['event' => 'subscription.cancelled', 'data' => ['id' => 4321, 'status' => 'cancelled']]);

    Event::assertDispatched(SubscriptionCancelled::class, fn ($e): bool => $e->subscriptionCode === '4321');
});

test('a paystack invoice event finds the subscription nested in it', function (): void {
    processWebhookShape('paystack', ['event' => 'invoice.payment_failed', 'data' => [
        'invoice_code' => 'INV_1', 'subscription' => ['subscription_code' => 'SUB_pf', 'status' => 'attention'],
    ]]);

    Event::assertDispatched(SubscriptionPaymentFailed::class, fn ($e): bool => $e->subscriptionCode === 'SUB_pf');
});
