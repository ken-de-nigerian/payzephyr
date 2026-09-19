<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Queue;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * The inbound half of a payment's timeline.
 *
 * Two things were previously invisible in the database: what a duplicate
 * webhook actually said - webhook_events keeps only a dedup key - and the fact
 * that a delivery was retried at all. Both are recorded here, under the same
 * reference the charge used, so the redirect and the webhook end up on one
 * timeline rather than two.
 */
function webhookTracingDriver(?string $reference = 'PZ_1755000000_abcdef01'): DriverInterface
{
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('extractWebhookReference')->andReturn($reference);
    $driver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $driver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $driver->shouldReceive('getName')->andReturn('paystack');

    return $driver;
}

function installWebhookDriver(DriverInterface $driver): PaymentManager
{
    $manager = app(PaymentManager::class);
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, ['paystack' => $driver]);

    return $manager;
}

/**
 * @param  array<string, mixed>|null  $payload
 */
function webhookJob(?array $payload = null): ProcessWebhook
{
    return new ProcessWebhook('paystack', $payload ?? [
        'event' => 'charge.success',
        'data' => ['reference' => 'PZ_1755000000_abcdef01', 'amount' => 5000],
    ]);
}

/**
 * @return array<int, string>
 */
function webhookTraceEvents(): array
{
    return PaymentTraceEvent::orderBy('id')->pluck('event')
        ->map(fn (TraceEvent $event): string => $event->value)->all();
}

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.features.trace' => true,
        'payments.trace.async' => false,
        'payments.logging.enabled' => false,
        'payments.webhook.verify_signature' => false,
        'payments.providers.paystack' => [
            'driver' => 'paystack',
            'secret_key' => 'test_secret_key',
            'enabled' => true,
        ],
    ]);
});

// ---------------------------------------------------------------------------
// A delivery that lands
// ---------------------------------------------------------------------------

test('a processed webhook is recorded under the reference it concerns', function () {
    installWebhookDriver(webhookTracingDriver());

    app()->call([webhookJob(), 'handle']);

    $received = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_RECEIVED->value)->sole();

    expect($received->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($received->direction)->toBe(TraceDirection::INBOUND)
        ->and($received->provider)->toBe('paystack')
        ->and($received->metadata['event_key'])->not->toBeEmpty();
});

test('the webhook body is kept, which webhook_events never did', function () {
    installWebhookDriver(webhookTracingDriver());

    app()->call([webhookJob(), 'handle']);

    $received = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_RECEIVED->value)->sole();

    expect($received->payload['event'])->toBe('charge.success')
        ->and($received->payload['data']['amount'])->toBe(5000);
});

test('the body is dropped when provider bodies are switched off', function () {
    config(['payments.trace.record_http_bodies' => false]);
    app()->forgetInstance('payments.config');

    installWebhookDriver(webhookTracingDriver());

    app()->call([webhookJob(), 'handle']);

    $received = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_RECEIVED->value)->sole();

    expect($received->payload)->toBe([])
        ->and($received->reference)->toBe('PZ_1755000000_abcdef01');
});

test('a webhook PayZephyr cannot attribute to a payment records nothing', function () {
    // There is no timeline to hang it off, and inventing a key would file it
    // under the wrong payment.
    installWebhookDriver(webhookTracingDriver(reference: null));

    app()->call([webhookJob(), 'handle']);

    expect(PaymentTraceEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// A duplicate delivery
// ---------------------------------------------------------------------------

test('a duplicate delivery is recorded along with what it said', function () {
    // The gap this closes: webhook_events stores provider + event_key and
    // nothing else, so before this you knew a duplicate had arrived but had
    // no way to see whether it agreed with the first one.
    installWebhookDriver(webhookTracingDriver());

    app()->call([webhookJob(), 'handle']);
    app()->call([webhookJob(), 'handle']);

    expect(webhookTraceEvents())->toBe(['webhook.received', 'webhook.duplicate']);

    $duplicate = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_DUPLICATE->value)->sole();
    $received = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_RECEIVED->value)->sole();

    expect($duplicate->payload)->toBe($received->payload)
        ->and($duplicate->metadata['event_key'])->toBe($received->metadata['event_key'])
        ->and($duplicate->event->isTerminal())->toBeFalse();
});

// ---------------------------------------------------------------------------
// A delivery that fails
// ---------------------------------------------------------------------------

test('a failed delivery records the failure with the attempt it was on', function () {
    installWebhookDriver(webhookTracingDriver());

    $repository = Mockery::mock(WebhookEventRepositoryInterface::class);
    $repository->shouldReceive('recordIfNew')->andThrow(new RuntimeException('idempotency store down'));
    $repository->shouldReceive('forget')->andReturnTrue();
    app()->instance(WebhookEventRepositoryInterface::class, $repository);

    expect(fn () => app()->call([webhookJob(), 'handle']))->toThrow(RuntimeException::class);

    $failed = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_PROCESSING_FAILED->value)->sole();

    expect($failed->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($failed->payload['error'])->toBe('idempotency store down')
        ->and($failed->payload['max_attempts'])->toBe(3)
        ->and($failed->direction)->toBe(TraceDirection::INBOUND);
});

test('no retry is promised when the job is not running on a queue', function () {
    // attempts() reports 0 off a queue, so an unguarded check would record a
    // retry.scheduled for a retry that is never coming.
    installWebhookDriver(webhookTracingDriver());

    $repository = Mockery::mock(WebhookEventRepositoryInterface::class);
    $repository->shouldReceive('recordIfNew')->andThrow(new RuntimeException('boom'));
    $repository->shouldReceive('forget')->andReturnTrue();
    app()->instance(WebhookEventRepositoryInterface::class, $repository);

    expect(fn () => app()->call([webhookJob(), 'handle']))->toThrow(RuntimeException::class);

    expect(PaymentTraceEvent::where('event', TraceEvent::RETRY_SCHEDULED->value)->exists())->toBeFalse();
});

test('a retry is recorded when one is genuinely coming', function () {
    installWebhookDriver(webhookTracingDriver());

    $repository = Mockery::mock(WebhookEventRepositoryInterface::class);
    $repository->shouldReceive('recordIfNew')->andThrow(new RuntimeException('boom'));
    $repository->shouldReceive('forget')->andReturnTrue();
    app()->instance(WebhookEventRepositoryInterface::class, $repository);

    $job = webhookJob();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    $scheduled = PaymentTraceEvent::where('event', TraceEvent::RETRY_SCHEDULED->value)->sole();

    expect($scheduled->payload['attempt'])->toBe(2)
        ->and($scheduled->payload['in_seconds'])->toBe(60)
        ->and($scheduled->direction)->toBe(TraceDirection::INTERNAL);
});

test('the last attempt is recorded as abandoned rather than retried', function () {
    // failed() is the only point at which "abandoned" is a fact rather than a
    // guess, which is why the catch block does not try to infer it.
    installWebhookDriver(webhookTracingDriver());

    webhookJob()->failed(new RuntimeException('gave up'));

    $abandoned = PaymentTraceEvent::where('event', TraceEvent::RETRY_ABANDONED->value)->sole();

    expect($abandoned->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($abandoned->payload['error'])->toBe('gave up')
        ->and($abandoned->payload['attempts'])->toBe(3)
        ->and($abandoned->direction)->toBe(TraceDirection::INTERNAL)
        ->and($abandoned->event->isTerminal())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Tracing never matters to the webhook
// ---------------------------------------------------------------------------

test('with tracing off a webhook is processed exactly as before and records nothing', function () {
    config(['payments.features.trace' => false]);
    app()->forgetInstance('payments.config');

    installWebhookDriver(webhookTracingDriver());

    app()->call([webhookJob(), 'handle']);

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('a webhook is still processed when the trace table was never migrated', function () {
    Illuminate\Support\Facades\Schema::drop('payment_trace_events');

    installWebhookDriver(webhookTracingDriver());

    app()->call([webhookJob(), 'handle']);

    expect(Illuminate\Support\Facades\Schema::hasTable('payment_trace_events'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The two failures the job cannot see for itself
// ---------------------------------------------------------------------------

test('a deferred signature failure is recorded against the payment it claimed', function () {
    // Only reachable for drivers that defer verification (Mollie, PayPal).
    // Every other driver is rejected with a 403 in WebhookRequest::authorize()
    // before the controller or this job ever runs, so those failures leave no
    // trace row at all - documented, not an oversight.
    config(['payments.webhook.verify_signature' => true]);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(DriverInterface::class, RequiresAsyncWebhookVerification::class);
    $driver->shouldReceive('requiresAsyncVerification')->andReturnTrue();
    $driver->shouldReceive('validateWebhook')->andReturnFalse();
    $driver->shouldReceive('extractWebhookReference')->andReturn('PZ_1755000000_abcdef01');
    $driver->shouldReceive('getName')->andReturn('paystack');
    installWebhookDriver($driver);

    app()->call([webhookJob(), 'handle']);

    $rejected = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_VALIDATION_FAILED->value)->sole();

    expect($rejected->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($rejected->provider)->toBe('paystack')
        ->and($rejected->direction)->toBe(TraceDirection::INBOUND)
        ->and($rejected->event->isError())->toBeTrue()
        ->and(PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_RECEIVED->value)->exists())->toBeFalse();
});

test('a webhook that could not be queued is recorded, because nothing else will', function () {
    // The job records everything else, but a delivery that never reached the
    // queue has no job to record it - and no retry is coming either.
    installWebhookDriver(webhookTracingDriver());

    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue backend unreachable'));

    $response = $this->postJson('/payments/webhook/paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'PZ_1755000000_abcdef01'],
    ]);

    $response->assertStatus(500);

    $failed = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_QUEUE_FAILED->value)->sole();

    expect($failed->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($failed->provider)->toBe('paystack')
        ->and($failed->payload['error'])->toContain('queue backend unreachable')
        ->and($failed->event->isError())->toBeTrue()
        ->and($failed->event->isTerminal())->toBeFalse();
});

test('a queue failure for an unknown provider is dropped rather than raised', function () {
    // referenceFor() runs inside an already-failing request. A provider that
    // cannot be resolved must not turn a 500 into an unhandled exception on
    // the way out.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue backend unreachable'));

    $response = $this->postJson('/payments/webhook/nosuchprovider', [
        'event' => 'charge.success',
        'data' => ['reference' => 'PZ_1755000000_abcdef01'],
    ]);

    $response->assertStatus(500);

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('a driver that cannot read its own payload does not break the abandoned record', function () {
    // failed() runs after the delivery has already been given up on. Anything
    // thrown while looking up the reference is worth less than a clean exit.
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('extractWebhookReference')->andThrow(new RuntimeException('payload is nonsense'));
    $driver->shouldReceive('getName')->andReturn('paystack');
    installWebhookDriver($driver);

    webhookJob()->failed(new RuntimeException('gave up'));

    expect(PaymentTraceEvent::count())->toBe(0);
});
