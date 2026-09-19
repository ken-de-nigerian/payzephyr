<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * The command that makes the timeline worth having.
 *
 * Everything recorded in the previous phases is only useful if somebody can
 * read it back without writing a query. Keyed by reference - the same string
 * the caller already has - so there is nothing new to look up first.
 */
function recordTraceStep(
    string $reference,
    TraceEvent $event,
    ?string $provider = null,
    ?string $correlationId = null,
    ?int $responseTimeMs = null,
    ?int $httpStatusCode = null,
    array $payload = [],
): PaymentTraceEvent {
    return PaymentTraceEvent::create([
        'reference' => $reference,
        'event' => $event->value,
        'direction' => TraceDirection::INTERNAL->value,
        'provider' => $provider,
        'correlation_id' => $correlationId,
        'response_time_ms' => $responseTimeMs,
        'http_status_code' => $httpStatusCode,
        'payload' => $payload,
        'metadata' => [],
    ]);
}

function traceCommandOutput(array $options = []): string
{
    Artisan::call('payzephyr:trace', $options);

    return Artisan::output();
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    config(['payments.features.trace' => true]);
});

// ---------------------------------------------------------------------------
// Reading a timeline back
// ---------------------------------------------------------------------------

test('a fallback-recovered payment reads as one story', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_INITIATED);
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_SKIPPED, provider: 'paystack');
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_ERROR, provider: 'monnify');
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_COMPLETED, provider: 'stripe');

    $output = traceCommandOutput(['reference' => 'PZ_1_a']);

    expect($output)->toContain('PZ_1_a')
        ->and($output)->toContain('payment.initiated')
        ->and($output)->toContain('provider.skipped (paystack)')
        ->and($output)->toContain('provider.error (monnify)')
        ->and($output)->toContain('payment.completed (stripe)')
        ->and($output)->toContain('Events:   4')
        ->and($output)->toContain('Errors:   1')
        ->and($output)->toContain('Outcome:  payment.completed');
});

test('http status and timing are shown against the step they belong to', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_RESPONSE_RECEIVED,
        provider: 'stripe', responseTimeMs: 1234, httpStatusCode: 200);

    $output = traceCommandOutput(['reference' => 'PZ_1_a']);

    expect($output)->toContain('HTTP 200')->and($output)->toContain('1234ms');
});

test('a payment with no terminal event is reported as still open', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_INITIATED);

    expect(traceCommandOutput(['reference' => 'PZ_1_a']))->toContain('still open');
});

test('the provider filter narrows the timeline', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_ERROR, provider: 'paystack');
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_COMPLETED, provider: 'stripe');

    $output = traceCommandOutput(['reference' => 'PZ_1_a', '--provider' => 'stripe']);

    expect($output)->toContain('payment.completed (stripe)')
        ->and($output)->not->toContain('paystack');
});

// ---------------------------------------------------------------------------
// Nothing found is not an error
// ---------------------------------------------------------------------------

test('an unknown reference explains itself rather than failing', function () {
    $exit = Artisan::call('payzephyr:trace', ['reference' => 'PZ_nothing_here']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No trace events recorded')
        ->and($output)->toContain('predates it or was never traced');
});

test('with tracing off the empty result says so instead of blaming the reference', function () {
    config(['payments.features.trace' => false]);
    app()->forgetInstance('payments.config');

    expect(traceCommandOutput(['reference' => 'PZ_nothing_here']))
        ->toContain('PAYZEPHYR_FEATURE_TRACE=true');
});

test('an over-narrow provider filter points at the filter', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_COMPLETED, provider: 'stripe');

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--provider' => 'paypal']))
        ->toContain('try again without --provider');
});

// ---------------------------------------------------------------------------
// --json
// ---------------------------------------------------------------------------

test('json output carries the whole timeline in a machine-readable shape', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_INITIATED);
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_COMPLETED,
        provider: 'stripe', payload: ['status' => 'pending']);

    $decoded = json_decode(traceCommandOutput(['reference' => 'PZ_1_a', '--json' => true]), true);

    expect($decoded['reference'])->toBe('PZ_1_a')
        ->and($decoded['outcome'])->toBe('payment.completed')
        ->and($decoded['succeeded'])->toBeTrue()
        ->and($decoded['failed'])->toBeFalse()
        ->and($decoded['events'])->toHaveCount(2)
        ->and($decoded['events'][1]['provider'])->toBe('stripe')
        ->and($decoded['events'][1]['payload'])->toBe(['status' => 'pending'])
        ->and($decoded['events'][0]['recorded_at'])->not->toBeNull();
});

test('json output for an unknown reference is still valid json', function () {
    $decoded = json_decode(traceCommandOutput(['reference' => 'PZ_nothing', '--json' => true]), true);

    expect($decoded)->toBe(['reference' => 'PZ_nothing', 'events' => []]);
});

// ---------------------------------------------------------------------------
// --detailed: one analyser, one vocabulary
// ---------------------------------------------------------------------------

test('an ambiguous charge is the loudest thing the analyser reports', function () {
    recordTraceStep('PZ_1_a', TraceEvent::CHARGE_AMBIGUOUS, provider: 'paystack');

    $output = traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]);

    expect($output)->toContain('[critical]')
        ->and($output)->toContain('may have taken the money');
});

test('a clean timeline says so rather than inventing findings', function () {
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_INITIATED);
    recordTraceStep('PZ_1_a', TraceEvent::PAYMENT_COMPLETED, provider: 'stripe');

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]))
        ->toContain('Nothing worth flagging');
});

test('findings are not shown unless asked for', function () {
    recordTraceStep('PZ_1_a', TraceEvent::CHARGE_AMBIGUOUS);

    expect(traceCommandOutput(['reference' => 'PZ_1_a']))->not->toContain('critical');
});

test('a request with no reply in its correlation group is reported as orphaned', function () {
    // The point of correlation ids: one group is one provider round trip, so a
    // group holding a request and no reply is a call that went out and vanished.
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_REQUEST_SENT,
        provider: 'stripe', correlationId: 'f0000000-0000-4000-8000-000000000001');

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]))
        ->toContain('sent and no response was ever recorded');
});

test('a request that was answered is not reported as orphaned', function () {
    $group = 'f0000000-0000-4000-8000-000000000002';
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_REQUEST_SENT, provider: 'stripe', correlationId: $group);
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_RESPONSE_RECEIVED, provider: 'stripe', correlationId: $group);

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]))
        ->not->toContain('orphaned');
});

test('a slow provider is reported against the configured threshold', function () {
    config(['payments.trace.slow_response_ms' => 500]);
    app()->forgetInstance('payments.config');

    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_RESPONSE_RECEIVED, provider: 'stripe', responseTimeMs: 900);

    $output = traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]);

    expect($output)->toContain('900ms to respond')->and($output)->toContain('500ms threshold');
});

test('a response inside the threshold is not called slow', function () {
    config(['payments.trace.slow_response_ms' => 5000]);
    app()->forgetInstance('payments.config');

    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_RESPONSE_RECEIVED, provider: 'stripe', responseTimeMs: 120);

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]))
        ->not->toContain('to respond');
});

test('repeated problems are counted rather than listed one by one', function () {
    recordTraceStep('PZ_1_a', TraceEvent::WEBHOOK_DUPLICATE, provider: 'stripe');
    recordTraceStep('PZ_1_a', TraceEvent::WEBHOOK_DUPLICATE, provider: 'stripe');
    recordTraceStep('PZ_1_a', TraceEvent::WEBHOOK_DUPLICATE, provider: 'stripe');

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]))
        ->toContain('(3 occurrences)');
});

test('json output includes the same findings the detailed view shows', function () {
    recordTraceStep('PZ_1_a', TraceEvent::VERIFICATION_NOT_PERSISTED);

    $decoded = json_decode(traceCommandOutput(['reference' => 'PZ_1_a', '--json' => true]), true);

    expect($decoded['findings'])->toHaveCount(1)
        ->and($decoded['findings'][0]['severity'])->toBe('critical')
        ->and($decoded['findings'][0]['type'])->toBe('not_persisted');
});

test('a correlation group holding no request at all is not mistaken for an orphan', function () {
    // Correlation ids group a provider round trip, but not every group starts
    // with a request - a group that never contains one has nothing to orphan.
    recordTraceStep('PZ_1_a', TraceEvent::PROVIDER_RESPONSE_RECEIVED,
        provider: 'stripe', correlationId: 'f0000000-0000-4000-8000-000000000003');

    expect(traceCommandOutput(['reference' => 'PZ_1_a', '--detailed' => true]))
        ->not->toContain('orphaned');
});
