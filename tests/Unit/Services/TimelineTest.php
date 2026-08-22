<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Services\Timeline;
use KenDeNigerian\PayZephyr\Services\TraceTimelineBuilder;

beforeEach(function () {
    app()->forgetInstance('payments.config');
    config(['payments.features.trace' => true]);
});

function traceRow(
    TraceEvent $event,
    ?string $provider = null,
    ?string $at = null,
    string $reference = 'PZ_1755000000_abcdef01',
    TraceDirection $direction = TraceDirection::INTERNAL,
): PaymentTraceEvent {
    $row = PaymentTraceEvent::create([
        'reference' => $reference,
        'event' => $event,
        'direction' => $direction,
        'provider' => $provider,
        'payload' => [],
    ]);

    if ($at !== null) {
        $row->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    return $row;
}

function timeline(): TraceTimelineBuilder
{
    return new TraceTimelineBuilder;
}

// ---------------------------------------------------------------------------
// Building
// ---------------------------------------------------------------------------

test('a timeline returns every event recorded for its reference, oldest first', function () {
    traceRow(TraceEvent::PAYMENT_COMPLETED, at: '2026-08-21 12:01:10');
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:01:03');
    traceRow(TraceEvent::PROVIDER_REQUEST_SENT, at: '2026-08-21 12:01:04');

    $built = timeline()->build('PZ_1755000000_abcdef01');

    expect($built->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($built->all())->toHaveCount(3)
        ->and($built->all()->pluck('event')->all())->toBe([
            TraceEvent::PAYMENT_INITIATED,
            TraceEvent::PROVIDER_REQUEST_SENT,
            TraceEvent::PAYMENT_COMPLETED,
        ]);
});

test('events recorded in the same millisecond keep their insertion order', function () {
    $first = traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:00:00.000');
    $second = traceRow(TraceEvent::PROVIDER_REQUEST_SENT, at: '2026-08-21 12:00:00.000');

    expect(timeline()->build('PZ_1755000000_abcdef01')->all()->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

test('a timeline never picks up another payment\'s events', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED);
    traceRow(TraceEvent::PAYMENT_FAILED, reference: 'PZ_1755000000_beefbeef');

    expect(timeline()->build('PZ_1755000000_abcdef01')->all())->toHaveCount(1);
});

test('a reference with nothing recorded produces an empty timeline rather than an error', function () {
    $built = timeline()->build('PZ_1755000000_nothing1');

    expect($built->isEmpty())->toBeTrue()
        ->and($built->all())->toHaveCount(0)
        ->and($built->duration())->toBeNull()
        ->and($built->terminal())->toBeNull()
        ->and($built->succeeded())->toBeFalse()
        ->and($built->failed())->toBeFalse()
        ->and($built->errors())->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Reading a timeline
// ---------------------------------------------------------------------------

test('errors surfaces only the events worth investigating', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED);
    traceRow(TraceEvent::PROVIDER_TIMEOUT, provider: 'paystack');
    traceRow(TraceEvent::PROVIDER_RESPONSE_RECEIVED, provider: 'stripe');
    traceRow(TraceEvent::PAYMENT_FAILED);

    $errors = timeline()->build('PZ_1755000000_abcdef01')->errors();

    expect($errors)->toHaveCount(2)
        ->and($errors->pluck('event')->all())->toBe([
            TraceEvent::PROVIDER_TIMEOUT,
            TraceEvent::PAYMENT_FAILED,
        ]);
});

test('forProvider narrows a fallback chain down to one provider\'s attempt', function () {
    traceRow(TraceEvent::PROVIDER_REQUEST_SENT, provider: 'paystack');
    traceRow(TraceEvent::PROVIDER_TIMEOUT, provider: 'paystack');
    traceRow(TraceEvent::PROVIDER_REQUEST_SENT, provider: 'stripe');

    $built = timeline()->build('PZ_1755000000_abcdef01');

    expect($built->forProvider('paystack'))->toHaveCount(2)
        ->and($built->forProvider('stripe'))->toHaveCount(1)
        ->and($built->forProvider('mollie'))->toHaveCount(0);
});

test('the terminal event is the first one that ended the payment', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:00:00');
    traceRow(TraceEvent::PAYMENT_COMPLETED, at: '2026-08-21 12:00:05');

    $built = timeline()->build('PZ_1755000000_abcdef01');

    expect($built->terminal()->event)->toBe(TraceEvent::PAYMENT_COMPLETED)
        ->and($built->succeeded())->toBeTrue()
        ->and($built->failed())->toBeFalse();
});

test('a failed payment reads as failed rather than merely not succeeded', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:00:00');
    traceRow(TraceEvent::PAYMENT_FAILED, at: '2026-08-21 12:00:05');

    $built = timeline()->build('PZ_1755000000_abcdef01');

    expect($built->failed())->toBeTrue()
        ->and($built->succeeded())->toBeFalse();
});

test('a payment still in flight has no terminal event and is neither succeeded nor failed', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED);
    traceRow(TraceEvent::PROVIDER_REQUEST_SENT, provider: 'stripe');

    $built = timeline()->build('PZ_1755000000_abcdef01');

    expect($built->terminal())->toBeNull()
        ->and($built->succeeded())->toBeFalse()
        ->and($built->failed())->toBeFalse();
});

test('duration measures first recorded event to last', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:01:03.123');
    traceRow(TraceEvent::PAYMENT_COMPLETED, at: '2026-08-21 12:01:10.567');

    expect(timeline()->build('PZ_1755000000_abcdef01')->duration())->toBe(7444);
});

test('duration is zero, not negative, for a single recorded event', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:01:03.123');

    expect(timeline()->build('PZ_1755000000_abcdef01')->duration())->toBe(0);
});

test('duration is null when timestamps are missing', function () {
    $row = new PaymentTraceEvent([
        'reference' => 'PZ_1755000000_abcdef01',
        'event' => TraceEvent::PAYMENT_INITIATED,
        'direction' => TraceDirection::INTERNAL,
    ]);

    $built = new Timeline('PZ_1755000000_abcdef01', collect([$row]));

    expect($built->duration())->toBeNull();
});

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

test('a single event renders with its time, direction icon and provider', function () {
    $row = traceRow(
        TraceEvent::PROVIDER_REQUEST_SENT,
        provider: 'stripe',
        at: '2026-08-21 12:01:04.456',
        direction: TraceDirection::OUTBOUND,
    );

    expect($row->formatForTimeline())->toBe('12:01:04.456 → provider.request.sent (stripe)')
        ->and($row->getDirectionIcon())->toBe('→')
        ->and($row->isError())->toBeFalse()
        ->and($row->isTerminal())->toBeFalse();
});

test('an event with no provider and no timestamp still renders', function () {
    $row = new PaymentTraceEvent([
        'reference' => 'PZ_1755000000_abcdef01',
        'event' => TraceEvent::PAYMENT_INITIATED,
        'direction' => TraceDirection::INTERNAL,
    ]);

    expect($row->formatForTimeline())->toBe('--:--:--.--- • payment.initiated');
});

test('a terminal error event reports itself as both', function () {
    $row = traceRow(TraceEvent::PAYMENT_FAILED);

    expect($row->isError())->toBeTrue()
        ->and($row->isTerminal())->toBeTrue();
});

test('toText renders the whole timeline with a summary', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:01:03.000');
    traceRow(TraceEvent::PROVIDER_TIMEOUT, provider: 'paystack', at: '2026-08-21 12:01:05.000');
    traceRow(TraceEvent::PAYMENT_COMPLETED, at: '2026-08-21 12:01:08.000');

    $text = timeline()->build('PZ_1755000000_abcdef01')->toText();

    expect($text)->toContain('Payment timeline: PZ_1755000000_abcdef01')
        ->and($text)->toContain('12:01:03.000 • payment.initiated')
        ->and($text)->toContain('12:01:05.000 • provider.timeout (paystack)')
        ->and($text)->toContain('- Total events: 3')
        ->and($text)->toContain('- Errors: 1')
        ->and($text)->toContain('- Duration: 5000ms')
        ->and($text)->toContain('- Status: payment.completed');
});

test('toText says so plainly when nothing was recorded', function () {
    expect(timeline()->build('PZ_1755000000_nothing1')->toText())
        ->toBe('No trace events recorded for reference: PZ_1755000000_nothing1');
});

test('toText reports an unfinished payment as incomplete rather than failed', function () {
    traceRow(TraceEvent::PAYMENT_INITIATED, at: '2026-08-21 12:01:03.000');

    expect(timeline()->build('PZ_1755000000_abcdef01')->toText())
        ->toContain('- Status: incomplete (no terminal event)');
});
