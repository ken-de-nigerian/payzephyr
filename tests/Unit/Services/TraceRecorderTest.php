<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Facades\Trace;
use KenDeNigerian\PayZephyr\Jobs\RecordTraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Services\PayloadRedactor;
use KenDeNigerian\PayZephyr\Services\TraceRecorder;

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.trace.enabled' => true,
        'payments.trace.async' => false,
    ]);
});

function recorder(): TraceRecorder
{
    return new TraceRecorder(new PayloadRedactor);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function recordableEvent(array $overrides = []): TraceEventDTO
{
    /** @var array<string, mixed> $args */
    $args = array_merge([
        'reference' => 'PZ_1755000000_abcdef01',
        'event' => TraceEvent::PAYMENT_INITIATED,
        'direction' => TraceDirection::INTERNAL,
    ], $overrides);

    return new TraceEventDTO(...$args);
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

test('recording persists the event and returns the row', function () {
    $model = recorder()->record(recordableEvent([
        'provider' => 'stripe',
        'payload' => ['amount' => 5000],
        'httpStatusCode' => 200,
        'responseTimeMs' => 42,
    ]));

    expect($model)->toBeInstanceOf(PaymentTraceEvent::class)
        ->and($model->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($model->provider)->toBe('stripe')
        ->and($model->payload)->toBe(['amount' => 5000])
        ->and($model->http_status_code)->toBe(200)
        ->and($model->response_time_ms)->toBe(42)
        ->and(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->count())->toBe(1);
});

test('the stored event and direction come back as enums', function () {
    $model = recorder()->record(recordableEvent([
        'event' => TraceEvent::PROVIDER_REQUEST_SENT,
        'direction' => TraceDirection::OUTBOUND,
    ]));

    $fresh = PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->first();

    expect($model->event)->toBe(TraceEvent::PROVIDER_REQUEST_SENT)
        ->and($fresh->event)->toBe(TraceEvent::PROVIDER_REQUEST_SENT)
        ->and($fresh->direction)->toBe(TraceDirection::OUTBOUND);
});

test('sensitive payload fields are redacted before they reach the database', function () {
    recorder()->record(recordableEvent([
        'payload' => ['amount' => 5000, 'cvv' => '123'],
    ]));

    $stored = PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->first();

    expect($stored->payload)->toBe(['amount' => 5000, 'cvv' => PayloadRedactor::REDACTED]);
});

test('trace rows are append-only, so recording twice keeps both steps', function () {
    recorder()->record(recordableEvent(['event' => TraceEvent::PAYMENT_INITIATED]));
    recorder()->record(recordableEvent(['event' => TraceEvent::PAYMENT_COMPLETED]));

    expect(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// The master switch
// ---------------------------------------------------------------------------

test('nothing is written while tracing is disabled', function () {
    config(['payments.trace.enabled' => false]);

    $result = recorder()->record(recordableEvent());

    expect($result)->toBeNull()
        ->and(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->count())->toBe(0);
});

test('tracing is off unless it has been switched on', function () {
    config(['payments.trace' => []]);

    expect(recorder()->record(recordableEvent()))->toBeNull();
});

// ---------------------------------------------------------------------------
// Asynchronous recording
// ---------------------------------------------------------------------------

test('in async mode the write is queued rather than performed inline', function () {
    Queue::fake();
    config(['payments.trace.async' => true]);

    $result = recorder()->record(recordableEvent());

    expect($result)->toBeNull()
        ->and(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->count())->toBe(0);

    Queue::assertPushed(RecordTraceEvent::class);
});

test('the queued job writes the row when it runs', function () {
    config(['payments.trace.async' => true]);

    recorder()->record(recordableEvent(['payload' => ['amount' => 5000]]));

    expect(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->count())->toBe(1);
});

test('the payload is already redacted by the time it reaches the queue', function () {
    config(['payments.trace.async' => true]);

    recorder()->record(recordableEvent(['payload' => ['cvv' => '123']]));

    $stored = PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->first();

    expect($stored->payload)->toBe(['cvv' => PayloadRedactor::REDACTED]);
});

test('the job honours the configured queue connection and name', function () {
    Queue::fake();
    config([
        'payments.trace.async' => true,
        'payments.trace.queue.connection' => 'redis',
        'payments.trace.queue.name' => 'traces',
    ]);

    recorder()->record(recordableEvent());

    Queue::assertPushed(RecordTraceEvent::class, function (RecordTraceEvent $job): bool {
        return $job->connection === 'redis' && $job->queue === 'traces';
    });
});

test('with no queue configured the job goes to the default queue', function () {
    Queue::fake();
    config(['payments.trace.async' => true]);

    recorder()->record(recordableEvent());

    Queue::assertPushed(RecordTraceEvent::class, function (RecordTraceEvent $job): bool {
        return $job->connection === null && $job->queue === 'default';
    });
});

test('a failed recording job reports the event it could not write', function () {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged) {
        $logged[] = $message;
    });

    (new RecordTraceEvent(recordableEvent()))->failed(new RuntimeException('table is gone'));

    $entry = collect($logged)->firstWhere('message', 'Failed to record a payment trace event');

    expect($entry)->not->toBeNull()
        ->and($entry->level)->toBe('error')
        ->and($entry->context['reference'])->toBe('PZ_1755000000_abcdef01')
        ->and($entry->context['event'])->toBe('payment.initiated')
        ->and($entry->context['error'])->toBe('table is gone');
});

// ---------------------------------------------------------------------------
// Correlation
// ---------------------------------------------------------------------------

test('each correlation group gets its own identifier', function () {
    $first = recorder()->startCorrelation();
    $second = recorder()->startCorrelation();

    expect($first)->not->toBe($second)
        ->and($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('a correlation id survives the round trip to the database', function () {
    $correlationId = recorder()->startCorrelation();

    recorder()->record(recordableEvent(['correlationId' => $correlationId]));

    $stored = PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->first();

    expect($stored->correlation_id)->toBe($correlationId);
});

// ---------------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------------

test('the container resolves the recorder contract to the real recorder', function () {
    expect(app(TraceRecorderInterface::class))->toBeInstanceOf(TraceRecorder::class);
});

test('the Trace facade records through the container binding', function () {
    Trace::record(recordableEvent());

    expect(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->count())->toBe(1);
});

test('the trace table name is configurable', function () {
    expect((new PaymentTraceEvent)->getTable())->toBe('payment_trace_events');

    config(['payments.trace.table' => 'custom_trace_events']);
    app()->forgetInstance('payments.config');

    expect((new PaymentTraceEvent)->getTable())->toBe('custom_trace_events');
});

test('the trace connection is configurable so the table can live elsewhere', function () {
    expect((new PaymentTraceEvent)->getConnectionName())->toBe('testing');

    config([
        'database.connections.analytics' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'payments.trace.connection' => 'analytics',
    ]);
    app()->forgetInstance('payments.config');

    expect((new PaymentTraceEvent)->getConnectionName())->toBe('analytics')
        ->and((new PaymentTraceEvent)->getConnection()->getName())->toBe('analytics');
});
