<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;

test('the trace event taxonomy covers every recorded stage of a payment', function () {
    expect(TraceEvent::cases())->toHaveCount(24)
        ->and(TraceEvent::PAYMENT_INITIATED->value)->toBe('payment.initiated')
        ->and(TraceEvent::PROVIDER_REQUEST_SENT->value)->toBe('provider.request.sent')
        ->and(TraceEvent::WEBHOOK_DUPLICATE->value)->toBe('webhook.duplicate')
        ->and(TraceEvent::RETRY_ABANDONED->value)->toBe('retry.abandoned')
        ->and(TraceEvent::VERIFICATION_FAILED->value)->toBe('verification.failed')
        ->and(TraceEvent::CUSTOM->value)->toBe('custom');
});

test('every trace event has a non-empty description', function () {
    foreach (TraceEvent::cases() as $case) {
        expect($case->description())->toBeString()->not->toBe('');
    }
});

test('terminal events are exactly the ones that end a payment flow', function () {
    $terminal = array_values(array_filter(
        TraceEvent::cases(),
        fn (TraceEvent $case): bool => $case->isTerminal()
    ));

    expect($terminal)->toBe([
        TraceEvent::PAYMENT_COMPLETED,
        TraceEvent::PAYMENT_FAILED,
        TraceEvent::PAYMENT_CANCELLED,
        TraceEvent::RETRY_ABANDONED,
    ]);
});

test('error events are exactly the ones worth surfacing as a problem', function () {
    $errors = array_values(array_filter(
        TraceEvent::cases(),
        fn (TraceEvent $case): bool => $case->isError()
    ));

    expect($errors)->toBe([
        TraceEvent::PAYMENT_FAILED,
        TraceEvent::PROVIDER_TIMEOUT,
        TraceEvent::PROVIDER_ERROR,
        TraceEvent::PROVIDER_EXCEPTION,
        TraceEvent::WEBHOOK_VALIDATION_FAILED,
        TraceEvent::WEBHOOK_PROCESSING_FAILED,
        TraceEvent::AUTH_FAILED,
        TraceEvent::VERIFICATION_FAILED,
    ]);
});

test('a successful payment is terminal without being an error', function () {
    expect(TraceEvent::PAYMENT_COMPLETED->isTerminal())->toBeTrue()
        ->and(TraceEvent::PAYMENT_COMPLETED->isError())->toBeFalse();
});

test('a failed payment is both terminal and an error', function () {
    expect(TraceEvent::PAYMENT_FAILED->isTerminal())->toBeTrue()
        ->and(TraceEvent::PAYMENT_FAILED->isError())->toBeTrue();
});

test('an ordinary provider round trip is neither terminal nor an error', function () {
    expect(TraceEvent::PROVIDER_REQUEST_SENT->isTerminal())->toBeFalse()
        ->and(TraceEvent::PROVIDER_REQUEST_SENT->isError())->toBeFalse()
        ->and(TraceEvent::PROVIDER_RESPONSE_RECEIVED->isError())->toBeFalse();
});

test('trace direction distinguishes internal steps from provider traffic', function () {
    expect(TraceDirection::cases())->toHaveCount(3)
        ->and(TraceDirection::INTERNAL->value)->toBe('internal')
        ->and(TraceDirection::OUTBOUND->value)->toBe('outbound')
        ->and(TraceDirection::INBOUND->value)->toBe('inbound');
});

test('each direction renders a distinct timeline icon', function () {
    $icons = array_map(
        fn (TraceDirection $case): string => $case->icon(),
        TraceDirection::cases()
    );

    expect($icons)->toBe(['•', '→', '←'])
        ->and(array_unique($icons))->toHaveCount(3);
});

test('every direction has a non-empty description', function () {
    foreach (TraceDirection::cases() as $case) {
        expect($case->description())->toBeString()->not->toBe('');
    }
});
