<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Traits\RecordsTraceEvents;

/**
 * The wrapper every call site goes through.
 *
 * TraceRecorder::record() promises not to throw, but that promise starts after
 * the TraceEventDTO exists - and building one can fail. These are the tests
 * for the gap between those two points, which is where a malformed webhook
 * reference would otherwise reach a payment.
 */
function tracingSubject(): object
{
    return new class
    {
        use RecordsTraceEvents;

        public function record(?string $reference, TraceEvent $event = TraceEvent::PAYMENT_INITIATED): void
        {
            $this->trace($reference, $event);
        }

        public function correlation(): string
        {
            return $this->startTraceCorrelation();
        }

        public function bodiesAllowed(): bool
        {
            return $this->traceRecordsHttpBodies();
        }
    };
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    config(['payments.features.trace' => true, 'payments.trace.async' => false]);
});

test('a step with no reference to hang off is dropped silently', function () {
    // A webhook whose body PayZephyr could not parse has nothing to key a
    // timeline with. That is a normal outcome, not a fault worth reporting.
    tracingSubject()->record(null);
    tracingSubject()->record('');

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('a reference that could never key a timeline is dropped, not thrown', function () {
    // On the webhook path this value comes out of a provider payload. If the
    // DTO's refusal escaped here, a malformed body would take down webhook
    // handling through the tracing code.
    tracingSubject()->record('has spaces and !');

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('a container that cannot produce a recorder does not take the payment with it', function () {
    app()->bind(TraceRecorderInterface::class, function () {
        throw new RuntimeException('container is broken');
    });

    tracingSubject()->record('PZ_1755000000_abcdef01');

    expect(PaymentTraceEvent::count())->toBe(0);
});

test('a correlation id falls back to none rather than failing', function () {
    app()->bind(TraceRecorderInterface::class, function () {
        throw new RuntimeException('container is broken');
    });

    expect(tracingSubject()->correlation())->toBe('');
});

test('a correlation id is minted when the recorder is available', function () {
    expect(tracingSubject()->correlation())
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('body capture is refused when the config cannot be read', function () {
    app()->bind('payments.config', function () {
        throw new RuntimeException('config is broken');
    });

    expect(tracingSubject()->bodiesAllowed())->toBeFalse();
});

test('body capture is refused whenever tracing itself is off', function () {
    // Callers use this to decide whether to read a response body at all, so
    // with tracing off it has to say no regardless of the body setting.
    config(['payments.features.trace' => false, 'payments.trace.record_http_bodies' => true]);
    app()->forgetInstance('payments.config');

    expect(tracingSubject()->bodiesAllowed())->toBeFalse();
});

test('body capture is on by default once tracing is on', function () {
    expect(tracingSubject()->bodiesAllowed())->toBeTrue();
});

test('body capture can be switched off on its own', function () {
    config(['payments.trace.record_http_bodies' => false]);
    app()->forgetInstance('payments.config');

    expect(tracingSubject()->bodiesAllowed())->toBeFalse();
});

test('a well-formed step is recorded through the container binding', function () {
    tracingSubject()->record('PZ_1755000000_abcdef01', TraceEvent::PAYMENT_COMPLETED);

    expect(PaymentTraceEvent::where('reference', 'PZ_1755000000_abcdef01')->sole()->event)
        ->toBe(TraceEvent::PAYMENT_COMPLETED);
});
