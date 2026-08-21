<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Services\NullTraceRecorder;
use KenDeNigerian\PayZephyr\Services\PayloadRedactor;
use KenDeNigerian\PayZephyr\Services\TraceRecorder;

/**
 * The kill switch, and the promise that tracing cannot break a payment.
 *
 * Tracing is the only PayZephyr feature that writes on the hot path of every
 * charge, verification and webhook. Two things therefore have to be true
 * before any call site exists: switching it off must mean nothing runs at all,
 * and switching it on must never be able to fail the payment being described.
 */
function nullTraceEvent(): TraceEventDTO
{
    return new TraceEventDTO(
        reference: 'PZ_1755000000_abcdef01',
        event: TraceEvent::PAYMENT_INITIATED,
        direction: TraceDirection::INTERNAL,
    );
}

function freshRecorderBinding(): TraceRecorderInterface
{
    app()->forgetInstance('payments.config');
    app()->forgetInstance(TraceRecorderInterface::class);

    return app(TraceRecorderInterface::class);
}

// ---------------------------------------------------------------------------
// The switch
// ---------------------------------------------------------------------------

test('with tracing off the container hands out the do-nothing recorder', function () {
    config(['payments.trace.enabled' => false]);

    expect(freshRecorderBinding())->toBeInstanceOf(NullTraceRecorder::class);
});

test('with tracing on the container hands out the real recorder', function () {
    config(['payments.trace.enabled' => true]);

    expect(freshRecorderBinding())->toBeInstanceOf(TraceRecorder::class);
});

test('tracing is off unless it has been switched on', function () {
    config(['payments.trace' => []]);

    expect(freshRecorderBinding())->toBeInstanceOf(NullTraceRecorder::class);
});

test('the disabled recorder issues no queries at all', function () {
    // The point of a separate implementation rather than an early return: with
    // tracing off there is no config lookup and no database round trip, so the
    // feature costs nothing on a hot path it is not participating in.
    config(['payments.trace.enabled' => false]);
    $recorder = freshRecorderBinding();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $recorder->record(nullTraceEvent());
    $recorder->startCorrelation();

    expect($queries)->toBeEmpty();
});

test('the disabled recorder returns nothing to depend on', function () {
    expect((new NullTraceRecorder)->record(nullTraceEvent()))->toBeNull()
        ->and((new NullTraceRecorder)->startCorrelation())->toBe('');
});

test('an empty correlation id from the disabled recorder never reaches the database', function () {
    $dto = new TraceEventDTO(
        reference: 'PZ_1755000000_abcdef01',
        event: TraceEvent::PAYMENT_INITIATED,
        direction: TraceDirection::INTERNAL,
        correlationId: (new NullTraceRecorder)->startCorrelation(),
    );

    expect($dto->correlationId)->toBeNull()
        ->and($dto->toArray()['correlation_id'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Recording can fail. Payments cannot fail because of it.
// ---------------------------------------------------------------------------

test('recording against a trace table that was never migrated does not throw', function () {
    // The exit criterion for this phase, and the realistic failure: the
    // feature flag is on but nobody ran `payzephyr:install --features=trace`.
    config(['payments.trace.enabled' => true]);
    app()->forgetInstance('payments.config');

    Schema::drop('payment_trace_events');

    $result = (new TraceRecorder(new PayloadRedactor))->record(nullTraceEvent());

    expect($result)->toBeNull()
        ->and(Schema::hasTable('payment_trace_events'))->toBeFalse();
});

test('a dropped trace event is reported to the payment log channel', function () {
    config(['payments.trace.enabled' => true]);
    app()->forgetInstance('payments.config');

    Schema::drop('payment_trace_events');

    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged) {
        $logged[] = $message;
    });

    (new TraceRecorder(new PayloadRedactor))->record(nullTraceEvent());

    $entry = collect($logged)->firstWhere('message', 'Failed to record a payment trace event');

    expect($entry)->not->toBeNull()
        ->and($entry->level)->toBe('error')
        ->and($entry->context['reference'])->toBe('PZ_1755000000_abcdef01')
        ->and($entry->context['event'])->toBe('payment.initiated')
        ->and($entry->context['error_class'])->toBe(Illuminate\Database\QueryException::class);
});

test('a logger that is itself broken on top of a broken write still does not throw', function () {
    // Both guards failing at once is exactly when a payment is most at risk of
    // being reported as failed when it actually succeeded. LogsToPaymentChannel
    // already falls back to the default channel on a bad channel name, so this
    // breaks the logger outright rather than merely misconfiguring it.
    config(['payments.trace.enabled' => true]);
    app()->forgetInstance('payments.config');

    Schema::drop('payment_trace_events');

    Log::shouldReceive('channel')->andThrow(new RuntimeException('logging is down'));

    expect((new TraceRecorder(new PayloadRedactor))->record(nullTraceEvent()))->toBeNull();
});

test('a queue backend that cannot be reached does not take the payment with it', function () {
    config([
        'payments.trace.enabled' => true,
        'payments.trace.async' => true,
        'payments.trace.queue.connection' => 'nonexistent-connection',
    ]);
    app()->forgetInstance('payments.config');

    expect((new TraceRecorder(new PayloadRedactor))->record(nullTraceEvent()))->toBeNull()
        ->and(PaymentTraceEvent::count())->toBe(0);
});
