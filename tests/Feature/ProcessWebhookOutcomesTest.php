<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Contracts\SendsStatelessWebhooks;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundCreated;
use KenDeNigerian\PayZephyr\Events\RefundFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;

/*
 * What ProcessWebhook does with a delivery once it is queued: which events it
 * announces, what it records on the timeline, what it logs, and which fields
 * of each provider's body it reads. "acme" has no driver, so the generic
 * reading of the body is what is under test; a mocked driver stands in where
 * a reference and a status are needed.
 */

beforeEach(function (): void {
    config(['payments.features.trace' => true, 'payments.trace.async' => false]);
    app()->forgetInstance('payments.config');
});

function processWebhook(array $payload, string $provider = 'acme'): void
{
    app()->call([new ProcessWebhook($provider, $payload), 'handle']);
}

function processWebhookOnAttempt(array $payload, int $attempt, string $provider = 'acme'): void
{
    $job = new ProcessWebhook($provider, $payload);
    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($queueJob);

    app()->call($job->handle(...));
}

function traceFor(string $reference, TraceEvent $event): PaymentTraceEvent
{
    return PaymentTraceEvent::where('reference', $reference)->where('event', $event->value)->sole();
}

/**
 * A driver for "acme" that names $reference and reports $status.
 */
function acmeDriver(string $reference, string $status, ?string $channel = null): DriverInterface
{
    $driver = Mockery::mock(DriverInterface::class)->shouldIgnoreMissing();
    $driver->shouldReceive('extractWebhookReference')->andReturn($reference);
    $driver->shouldReceive('extractWebhookStatus')->andReturn($status);
    $driver->shouldReceive('extractWebhookChannel')->andReturn($channel);
    $driver->shouldReceive('getName')->andReturn('acme');

    $manager = app(PaymentManager::class);
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setValue($manager, ['acme' => $driver]);

    return $driver;
}

function acmeTransaction(string $reference, string $status = 'pending'): PaymentTransaction
{
    return PaymentTransaction::create([
        'reference' => $reference, 'provider' => 'acme', 'status' => $status,
        'amount' => 100, 'currency' => 'NGN', 'email' => 'buyer@example.test',
    ]);
}

// ---------------------------------------------------------------------------
// Delivery claims
// ---------------------------------------------------------------------------

test('a duplicate delivery is skipped and logged with its key', function (): void {
    $logs = captureLogs();
    $payload = ['event' => 'charge.success', 'data' => ['id' => 'dup-1']];

    processWebhook($payload);
    processWebhook($payload);

    $context = loggedEntry($logs, 'Duplicate webhook delivery skipped')['context'];

    expect($context['provider'])->toBe('acme')
        ->and($context['event_key'])->toBe(hash('sha256', 'acme|'.json_encode($payload)));
});

test('a delivery for a provider with no driver is still claimed, so its duplicate is skipped', function (): void {
    Event::fake([WebhookReceived::class]);
    $payload = ['event' => 'charge.success', 'data' => ['id' => 'nodriver-1']];

    processWebhook($payload);
    processWebhook($payload);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

test('a retry that finds its own marker reclaims it, and says so', function (): void {
    $logs = captureLogs();
    $payload = ['event' => 'charge.success', 'data' => ['id' => 'reclaim-1']];
    $key = hash('sha256', 'acme|'.json_encode($payload));
    app(WebhookEventRepositoryInterface::class)->recordIfNew('acme', $key);

    processWebhookOnAttempt($payload, 3);

    expect(loggedEntry($logs, 'Reclaiming an idempotency marker')['context'])
        ->toBe(['provider' => 'acme', 'event_key' => $key, 'attempt' => 3]);
});

test('a marker that cannot be released after a failure is logged, and the failure still raised', function (): void {
    $logs = captureLogs();
    $events = Mockery::mock(WebhookEventRepositoryInterface::class);
    $events->shouldReceive('recordIfNew')->andReturnTrue();
    $events->shouldReceive('forget')->andThrow(new RuntimeException('database gone'));
    app()->instance(WebhookEventRepositoryInterface::class, $events);
    Event::listen(WebhookReceived::class, fn () => throw new LogicException('listener failed'));
    $payload = ['event' => 'charge.success', 'data' => ['id' => 'release-1']];

    expect(fn () => processWebhook($payload))->toThrow(LogicException::class, 'listener failed');

    expect(loggedEntry($logs, 'Failed to clear the webhook idempotency marker')['context'])->toBe([
        'provider' => 'acme',
        'event_key' => hash('sha256', 'acme|'.json_encode($payload)),
        'error' => 'database gone',
    ]);
});

test('an abandoned delivery is recorded with the class of what stopped it', function (): void {
    acmeDriver('PZ_ABANDONED', 'success');

    (new ProcessWebhook('acme', ['event' => 'charge.success']))->failed(new DomainException('gave up'));

    expect(traceFor('PZ_ABANDONED', TraceEvent::RETRY_ABANDONED)->payload)
        ->toMatchArray(['error' => 'gave up', 'error_class' => DomainException::class]);
});

// ---------------------------------------------------------------------------
// The payment a webhook names
// ---------------------------------------------------------------------------

test('a webhook with no recognisable status leaves the payment alone, and says so', function (): void {
    acmeDriver('PZ_UNKNOWN', 'unknown');
    acmeTransaction('PZ_UNKNOWN');
    $logs = captureLogs();

    processWebhook(['event' => 'charge.success', 'n' => 1]);

    expect(PaymentTransaction::first()->status)->toBe('pending')
        ->and(loggedEntry($logs, 'no recognisable payment status')['context'])->toBe(['provider' => 'acme', 'reference' => 'PZ_UNKNOWN']);
});

test('a status PayZephyr has no enum case for is stored as reported, and not marked paid', function (): void {
    acmeDriver('PZ_ON_HOLD', 'on_hold');
    acmeTransaction('PZ_ON_HOLD');

    processWebhook(['event' => 'charge.updated', 'n' => 2]);

    $transaction = PaymentTransaction::first();
    expect($transaction->status)->toBe('on_hold')
        ->and($transaction->paid_at)->toBeNull();
});

test('an updated payment is logged', function (): void {
    acmeDriver('PZ_UPDATED', 'success', 'card');
    acmeTransaction('PZ_UPDATED');
    $logs = captureLogs();

    processWebhook(['event' => 'charge.success', 'n' => 3]);

    expect(loggedEntry($logs, 'Transaction updated from webhook')['context'])
        ->toBe(['reference' => 'PZ_UPDATED', 'status' => 'success', 'provider' => 'acme']);
});

test('a payment already successful is not updated, or logged as if it were', function (): void {
    acmeDriver('PZ_ALREADY', 'failed');
    acmeTransaction('PZ_ALREADY', 'success');
    $logs = captureLogs();

    processWebhook(['event' => 'charge.failed', 'n' => 4]);

    expect(PaymentTransaction::first()->status)->toBe('success')
        ->and(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Transaction updated from webhook')))->toBe([]);
});

test('a payment that cannot be updated is logged, and the webhook still processed', function (): void {
    acmeDriver('PZ_BROKEN', 'success');
    $transactions = Mockery::mock(TransactionRepositoryInterface::class);
    $transactions->shouldReceive('updateIfNotSuccessful')->andThrow(new RuntimeException('write failed'));
    app()->instance(TransactionRepositoryInterface::class, $transactions);
    Event::fake([WebhookReceived::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'charge.success', 'n' => 5]);

    Event::assertDispatched(WebhookReceived::class);
    expect(loggedEntry($logs, 'Failed to update transaction from webhook')['context'])
        ->toBe(['error' => 'write failed', 'reference' => 'PZ_BROKEN', 'provider' => 'acme']);
});

// ---------------------------------------------------------------------------
// Subscriptions
// ---------------------------------------------------------------------------

test('a subscription named by subscriptionCode is recognised', function (): void {
    Event::fake([SubscriptionCreated::class]);

    processWebhook(['event' => 'subscription.create', 'data' => ['subscriptionCode' => 'SUB_CAMEL']]);

    Event::assertDispatched(SubscriptionCreated::class, fn (SubscriptionCreated $e): bool => $e->subscriptionCode === 'SUB_CAMEL');
});

test('a subscription event with no subscription is dropped, and says why', function (): void {
    Event::fake([SubscriptionCreated::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'subscription.create', 'data' => ['plan' => 'PLN_1']]);

    Event::assertNotDispatched(SubscriptionCreated::class);
    expect(loggedEntry($logs, 'Subscription webhook missing subscription_code')['context'])
        ->toBe(['provider' => 'acme', 'event' => 'subscription.create']);
});

test('each subscription event is recorded with the event that reported it, and logged', function (): void {
    Event::fake([SubscriptionCreated::class, SubscriptionCancelled::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_T1']]);
    processWebhook(['event' => 'subscription.disable', 'data' => ['subscription_code' => 'SUB_T2']]);

    expect(traceFor('SUB_T1', TraceEvent::SUBSCRIPTION_CREATED)->payload)->toEqual(['event' => 'subscription.create'])
        ->and(traceFor('SUB_T2', TraceEvent::SUBSCRIPTION_CANCELLED)->payload)->toEqual(['event' => 'subscription.disable']);

    $processed = array_values(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Subscription webhook event processed')));
    expect(array_column($processed, 'context'))->toBe([
        ['provider' => 'acme', 'event' => 'subscription.create', 'subscription_code' => 'SUB_T1'],
        ['provider' => 'acme', 'event' => 'subscription.disable', 'subscription_code' => 'SUB_T2'],
    ]);
});

test('a renewal reads its invoice reference from whichever field the provider uses', function (array $data, string $expected): void {
    Event::fake([SubscriptionRenewed::class]);

    processWebhook(['event' => 'subscription.renewed', 'data' => ['subscription_code' => 'SUB_R'] + $data]);

    Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e): bool => $e->invoiceReference === $expected);
    expect(traceFor('SUB_R', TraceEvent::SUBSCRIPTION_RENEWED)->payload)
        ->toEqual(['event' => 'subscription.renewed', 'invoice_reference' => $expected]);
})->with([
    'reference' => [['reference' => 'INV_A'], 'INV_A'],
    'invoice_reference' => [['invoice_reference' => 'INV_B'], 'INV_B'],
    'invoiceReference' => [['invoiceReference' => 'INV_C'], 'INV_C'],
    'none' => [[], ''],
]);

test('a failed subscription payment carries the provider\'s message as its reason', function (): void {
    Event::fake([SubscriptionPaymentFailed::class]);

    processWebhook(['event' => 'invoice.payment_failed', 'data' => ['subscription_code' => 'SUB_F', 'message' => 'Card declined']]);

    Event::assertDispatched(SubscriptionPaymentFailed::class, fn (SubscriptionPaymentFailed $e): bool => $e->reason === 'Card declined');
    expect(traceFor('SUB_F', TraceEvent::SUBSCRIPTION_PAYMENT_FAILED)->payload)
        ->toEqual(['event' => 'invoice.payment_failed', 'reason' => 'Card declined']);
});

test('a subscription update cancels it whatever the case of its status, and not without one', function (?string $status, bool $cancelled): void {
    Event::fake([SubscriptionCancelled::class]);
    $subscription = array_filter(['id' => 'SUB_SQ', 'status' => $status]);

    processWebhook(['type' => 'subscription.updated', 'data' => ['type' => 'subscription', 'object' => ['subscription' => $subscription]]]);

    $cancelled
        ? Event::assertDispatched(SubscriptionCancelled::class)
        : Event::assertNotDispatched(SubscriptionCancelled::class);
})->with([
    'CANCELED' => ['CANCELED', true],
    'canceled' => ['canceled', true],
    'deactivated' => ['deactivated', true],
    'ACTIVE' => ['ACTIVE', false],
    'no status' => [null, false],
]);

// ---------------------------------------------------------------------------
// Refunds
// ---------------------------------------------------------------------------

test('a refund event carrying the charge is left to the refund\'s own event, and says so', function (): void {
    Event::fake([RefundCreated::class, RefundCompleted::class]);
    $logs = captureLogs();

    processWebhook(['type' => 'charge.refunded', 'data' => ['object' => ['object' => 'charge', 'id' => 'ch_1']]]);

    Event::assertNotDispatched(RefundCompleted::class);
    expect(loggedEntry($logs, 'carries the charge, not a refund')['context'])->toBe(['provider' => 'acme', 'event' => 'charge.refunded']);
});

test('a refund event with no refund is dropped, and says why', function (): void {
    Event::fake([RefundCreated::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'refund.pending', 'data' => ['amount' => 100]]);

    Event::assertNotDispatched(RefundCreated::class);
    expect(loggedEntry($logs, 'Refund webhook missing refund reference')['context'])->toBe(['provider' => 'acme', 'event' => 'refund.pending']);
});

test('a refund is found wherever the provider nests it', function (array $payload): void {
    Event::fake([RefundCreated::class]);

    processWebhook($payload);

    Event::assertDispatched(RefundCreated::class, fn (RefundCreated $e): bool => $e->refundReference === 'RF_NESTED'
        && $e->transactionReference === 'PZ_NESTED'
        && $e->data === $payload[array_key_last($payload)]);
})->with([
    'data' => [['event' => 'refund.pending', 'data' => ['id' => 'RF_NESTED', 'transaction_reference' => 'PZ_NESTED']]],
    'resource' => [['event_type' => 'refund.pending', 'resource' => ['id' => 'RF_NESTED', 'transaction_reference' => 'PZ_NESTED']]],
    'eventData' => [['eventType' => 'refund.pending', 'eventData' => ['refund_reference' => 'RF_NESTED', 'transaction_reference' => 'PZ_NESTED']]],
]);

test('a refund pending without a payment it names is announced with an empty reference', function (): void {
    Event::fake([RefundCreated::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'refund.pending', 'data' => ['id' => 'RF_ALONE']]);

    Event::assertDispatched(RefundCreated::class, fn (RefundCreated $e): bool => $e->transactionReference === '' && $e->data === ['id' => 'RF_ALONE']);
    expect(loggedEntry($logs, 'Refund webhook event processed')['context'])
        ->toBe(['provider' => 'acme', 'event' => 'refund.pending', 'refund_reference' => 'RF_ALONE']);
});

test('a refund reported failed by its status fails, whatever word the provider uses', function (string $status, string $reason): void {
    Event::fake([RefundFailed::class]);

    processWebhook(['event' => 'refund.updated', 'data' => ['id' => "RF_$status", 'status' => strtoupper($status)]]);

    Event::assertDispatched(RefundFailed::class, fn (RefundFailed $e): bool => $e->reason === $reason && $e->transactionReference === '');
})->with([
    ['failed', 'Refund failed'],
    ['declined', 'Refund failed'],
    ['error', 'Refund failed'],
    ['rejected', 'Refund failed'],
    ['cancelled', 'Refund cancelled'],
    ['canceled', 'Refund cancelled'],
]);

test('a refund status the body gives only beside the refund is read', function (): void {
    Event::fake([RefundFailed::class]);

    processWebhook(['event' => 'refund.updated', 'data' => ['object' => ['id' => 'RF_DETAIL'], 'status' => 'failed']]);

    Event::assertDispatched(RefundFailed::class, fn (RefundFailed $e): bool => $e->refundReference === 'RF_DETAIL');
});

test('a failed refund carries the provider\'s reason from whichever field it uses', function (array $fields, string $reason): void {
    Event::fake([RefundFailed::class]);

    processWebhook(['event' => 'refund.failed', 'data' => ['id' => 'RF_REASON', 'transaction_reference' => 'PZ_REASON'] + $fields]);

    Event::assertDispatched(RefundFailed::class, fn (RefundFailed $e): bool => $e->reason === $reason);
    expect(traceFor('PZ_REASON', TraceEvent::REFUND_FAILED)->payload)
        ->toEqual(['stage' => 'settlement', 'refund_reference' => 'RF_REASON', 'reason' => $reason]);
})->with([
    'reason' => [['reason' => 'Insufficient balance'], 'Insufficient balance'],
    'reason on the refund, over the one beside it' => [['object' => ['id' => 'RF_REASON', 'transaction_reference' => 'PZ_REASON', 'reason' => 'Own reason'], 'reason' => 'Outer reason'], 'Own reason'],
    'failure_reason' => [['failure_reason' => 'expired_or_canceled_card'], 'expired_or_canceled_card'],
    'message' => [['message' => 'Bank rejected'], 'Bank rejected'],
]);

test('a failed refund reads the reason the body gives beside the refund', function (): void {
    Event::fake([RefundFailed::class]);

    processWebhook(['event' => 'refund.failed', 'data' => ['refund_reference' => 'RF_OUTER', 'reason' => 'Outer reason', 'object' => ['id' => 'RF_OUTER']]]);

    Event::assertDispatched(RefundFailed::class, fn (RefundFailed $e): bool => $e->reason === 'Outer reason');
});

test('a refund is completed by its event name, or by any status meaning done', function (string $event, ?string $status): void {
    Event::fake([RefundCompleted::class]);

    processWebhook(['event' => $event, 'data' => array_filter(['id' => "RF_{$event}_$status", 'status' => $status])]);

    Event::assertDispatched(RefundCompleted::class, fn (RefundCompleted $e): bool => $e->transactionReference === '');
})->with([
    'event processed' => ['refund.processed', 'pending'],
    'event refunded' => ['payment.refunded', 'pending'],
    'event completed' => ['refund.completed', 'pending'],
    'status completed' => ['refund.updated', 'completed'],
    'status succeeded' => ['refund.updated', 'succeeded'],
    'status success' => ['refund.updated', 'success'],
    'status processed' => ['refund.updated', 'processed'],
    'status refunded' => ['refund.updated', 'refunded'],
    'status approved' => ['refund.updated', 'approved'],
]);

test('a completed refund is recorded on the payment with the refund that settled it', function (): void {
    Event::fake([RefundCompleted::class]);

    processWebhook(['event' => 'refund.processed', 'data' => ['id' => 'RF_DONE', 'transaction_reference' => 'PZ_DONE']]);

    expect(traceFor('PZ_DONE', TraceEvent::PAYMENT_REFUNDED)->payload)->toEqual(['refund_reference' => 'RF_DONE']);
});

test('a refund outcome already announced is not announced again, and says so', function (): void {
    Event::fake([RefundCompleted::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'refund.processed', 'data' => ['id' => 'RF_TWICE'], 'n' => 1]);
    processWebhook(['event' => 'refund.completed', 'data' => ['id' => 'RF_TWICE'], 'n' => 2]);

    Event::assertDispatchedTimes(RefundCompleted::class, 1);
    expect(loggedEntry($logs, 'Refund outcome already reported')['context'])
        ->toBe(['provider' => 'acme', 'refund_reference' => 'RF_TWICE', 'outcome' => 'completed']);
});

test('a refund outcome is written to the refund log when logging is not configured either way', function (): void {
    config(['payments.logging' => [], 'payments.refunds.logging' => []]);
    app()->forgetInstance('payments.config');
    Event::fake([RefundCompleted::class]);
    RefundTransaction::create([
        'refund_reference' => 'RF_LOGGED', 'transaction_reference' => 'PZ_LOGGED', 'provider' => 'acme',
        'status' => 'pending', 'amount' => 10, 'currency' => 'NGN',
    ]);

    processWebhook(['event' => 'refund.processed', 'data' => ['id' => 'RF_LOGGED']]);

    expect(RefundTransaction::first()->status)->toBe('completed');
});

test('a refund outcome that cannot be written to the refund log is logged, and still announced', function (): void {
    Event::fake([RefundCompleted::class]);
    $refunds = Mockery::mock(RefundRepositoryInterface::class);
    $refunds->shouldReceive('updateStatusIfExists')->andThrow(new RuntimeException('log table locked'));
    app()->instance(RefundRepositoryInterface::class, $refunds);
    $logs = captureLogs();

    processWebhook(['event' => 'refund.processed', 'data' => ['id' => 'RF_UNWRITTEN']]);

    Event::assertDispatched(RefundCompleted::class);
    expect(loggedEntry($logs, 'Failed to update refund status from webhook')['context'])
        ->toBe(['error' => 'log table locked', 'refund_reference' => 'RF_UNWRITTEN', 'status' => 'completed']);
});

// ---------------------------------------------------------------------------
// The job's own outcome
// ---------------------------------------------------------------------------

/**
 * A driver for "acme" that verifies in the job, and refuses.
 */
function acmeDriverRefusingSignatures(): DriverInterface
{
    $driver = Mockery::mock(DriverInterface::class, RequiresAsyncWebhookVerification::class)->shouldIgnoreMissing();
    $driver->shouldReceive('requiresAsyncVerification')->andReturnTrue();
    $driver->shouldReceive('validateWebhook')->with(Mockery::type('array'), Mockery::type('string'))->andReturnFalse();

    $manager = app(PaymentManager::class);
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setValue($manager, ['acme' => $driver]);

    return $driver;
}

test('a delivery whose deferred signature check fails is discarded, and says so', function (): void {
    acmeDriverRefusingSignatures();
    Event::fake([WebhookReceived::class]);
    $logs = captureLogs();

    processWebhook(['event' => 'charge.success', 'n' => 'unsigned']);

    Event::assertNotDispatched(WebhookReceived::class);
    expect(loggedEntry($logs, 'Deferred webhook signature verification failed')['context'])->toBe(['provider' => 'acme']);
});

test('the deferred signature check runs when verification is not configured either way', function (): void {
    config(['payments.webhook' => ['max_retries' => 3]]);
    app()->forgetInstance('payments.config');
    acmeDriverRefusingSignatures();
    Event::fake([WebhookReceived::class]);

    processWebhook(['event' => 'charge.success', 'n' => 'unconfigured']);

    Event::assertNotDispatched(WebhookReceived::class);
});

test('a body that cannot be re-encoded is checked as empty rather than failing the job', function (): void {
    acmeDriverRefusingSignatures();
    $logs = captureLogs();

    processWebhook(['event' => 'charge.success', 'note' => "\xB1\x31"]);

    expect(loggedEntry($logs, 'Deferred webhook signature verification failed')['level'])->toBe('warning');
});

test('a payment is updated from a webhook when logging is not configured either way', function (): void {
    config(['payments.logging' => []]);
    app()->forgetInstance('payments.config');
    acmeDriver('PZ_DEFAULT_LOGGING', 'success');
    acmeTransaction('PZ_DEFAULT_LOGGING');

    processWebhook(['event' => 'charge.success', 'n' => 'default-logging']);

    expect(PaymentTransaction::first()->status)->toBe('success');
});

test('a processed webhook is logged with its reference and event, or "unknown" without one', function (): void {
    acmeDriver('PZ_PROCESSED', 'unknown');
    $logs = captureLogs();

    processWebhook(['event' => 'Charge.Success', 'n' => 1]);
    processWebhook(['n' => 2]);

    $processed = array_values(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Webhook processed for acme')));
    expect(array_column($processed, 'context'))->toBe([
        ['reference' => 'PZ_PROCESSED', 'event' => 'charge.success'],
        ['reference' => 'PZ_PROCESSED', 'event' => 'unknown'],
    ]);
});

test('a webhook that fails is logged and recorded with what went wrong and on which attempt', function (): void {
    acmeDriver('PZ_FAILING', 'unknown');
    Event::listen(WebhookReceived::class, fn () => throw new LogicException('listener failed'));
    $logs = captureLogs();

    expect(fn () => processWebhookOnAttempt(['event' => 'charge.success', 'n' => 'failing'], 2))->toThrow(LogicException::class);

    $context = loggedEntry($logs, 'Webhook processing failed')['context'];
    expect($context['provider'])->toBe('acme')
        ->and($context['error'])->toBe('listener failed')
        ->and($context['trace'])->toBeString()->not->toBeEmpty()
        ->and(traceFor('PZ_FAILING', TraceEvent::WEBHOOK_PROCESSING_FAILED)->payload)->toEqual([
            'error' => 'listener failed', 'error_class' => LogicException::class, 'attempt' => 2, 'max_attempts' => 3,
        ]);
});

test('a retry is recorded as scheduled only when one is coming', function (int $attempt, bool $scheduled): void {
    acmeDriver("PZ_RETRY_$attempt", 'unknown');
    Event::listen(WebhookReceived::class, fn () => throw new LogicException('listener failed'));

    expect(fn () => processWebhookOnAttempt(['event' => 'charge.success', 'n' => "retry-$attempt"], $attempt))->toThrow(LogicException::class);

    expect(PaymentTraceEvent::where('reference', "PZ_RETRY_$attempt")->where('event', TraceEvent::RETRY_SCHEDULED->value)->exists())->toBe($scheduled);
})->with([
    'attempt 2 of 3' => [2, true],
    'attempt 3 of 3' => [3, false],
]);

test('a stateless delivery that fails releases no marker, because it never claimed one', function (): void {
    $driver = Mockery::mock(DriverInterface::class, SendsStatelessWebhooks::class)->shouldIgnoreMissing();
    $driver->shouldReceive('isStatelessWebhook')->andReturnTrue();
    $manager = app(PaymentManager::class);
    (new ReflectionClass($manager))->getProperty('drivers')->setValue($manager, ['acme' => $driver]);
    $events = Mockery::mock(WebhookEventRepositoryInterface::class);
    $events->shouldNotReceive('recordIfNew');
    $events->shouldNotReceive('forget');
    app()->instance(WebhookEventRepositoryInterface::class, $events);
    Event::listen(WebhookReceived::class, fn () => throw new LogicException('listener failed'));
    $logs = captureLogs();

    expect(fn () => processWebhook(['event' => 'charge.success', 'n' => 'stateless']))->toThrow(LogicException::class, 'listener failed');

    // The release runs in its own try, so a call that should not happen
    // shows up as its logged failure.
    expect(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Failed to clear')))->toBe([]);
});

test('a delivery is tried three times, a minute apart, when retries are not configured', function (): void {
    config(['payments.webhook' => ['verify_signature' => true]]);
    app()->forgetInstance('payments.config');

    $job = new ProcessWebhook('acme', []);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60);
});

test('a payment event is not read as a subscription event', function (array $payload): void {
    Event::fake([SubscriptionCreated::class, SubscriptionRenewed::class]);
    $logs = captureLogs();

    processWebhook($payload);

    expect(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Subscription webhook')))->toBe([]);
})->with([
    'charge' => [['event' => 'charge.success', 'data' => ['reference' => 'PZ_1']]],
    'sale without an agreement' => [['event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => ['id' => 'SALE_1']]],
]);

test('a delivery is tried as often, and as far apart, as configured', function (): void {
    config(['payments.webhook.max_retries' => 5, 'payments.webhook.retry_backoff' => 30]);
    app()->forgetInstance('payments.config');

    $job = new ProcessWebhook('acme', []);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe(30);
});
