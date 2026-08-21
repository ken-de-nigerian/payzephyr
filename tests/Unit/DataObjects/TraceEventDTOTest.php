<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\InvalidTraceDataException;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;

function traceEvent(mixed ...$overrides): TraceEventDTO
{
    /** @var array<string, mixed> $args */
    $args = array_merge([
        'reference' => 'PZ_1755000000_abcdef01',
        'event' => TraceEvent::PAYMENT_INITIATED,
        'direction' => TraceDirection::INTERNAL,
    ], $overrides);

    return new TraceEventDTO(...$args);
}

test('a minimal trace event keeps its identity and defaults the rest', function () {
    $dto = traceEvent();

    expect($dto->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($dto->event)->toBe(TraceEvent::PAYMENT_INITIATED)
        ->and($dto->direction)->toBe(TraceDirection::INTERNAL)
        ->and($dto->payload)->toBe([])
        ->and($dto->metadata)->toBe([])
        ->and($dto->provider)->toBeNull()
        ->and($dto->correlationId)->toBeNull()
        ->and($dto->httpMethod)->toBeNull()
        ->and($dto->httpUrl)->toBeNull()
        ->and($dto->httpStatusCode)->toBeNull()
        ->and($dto->responseTimeMs)->toBeNull();
});

test('toArray maps to the column names the trace table actually uses', function () {
    $dto = traceEvent(
        payload: ['amount' => 5000],
        provider: 'stripe',
        correlationId: 'b1d1f3e0-0000-4000-8000-000000000000',
        metadata: ['ip' => '127.0.0.1'],
        httpMethod: 'POST',
        httpUrl: 'https://api.stripe.com/v1/payment_intents',
        httpStatusCode: 200,
        responseTimeMs: 1234,
    );

    expect($dto->toArray())->toBe([
        'reference' => 'PZ_1755000000_abcdef01',
        'event' => 'payment.initiated',
        'direction' => 'internal',
        'payload' => ['amount' => 5000],
        'provider' => 'stripe',
        'correlation_id' => 'b1d1f3e0-0000-4000-8000-000000000000',
        'metadata' => ['ip' => '127.0.0.1'],
        'http_method' => 'POST',
        'http_url' => 'https://api.stripe.com/v1/payment_intents',
        'http_status_code' => 200,
        'response_time_ms' => 1234,
    ]);
});

test('withPayload swaps the payload and leaves everything else alone', function () {
    $original = traceEvent(
        payload: ['card_number' => '4111111111111111'],
        provider: 'stripe',
        correlationId: 'b1d1f3e0-0000-4000-8000-000000000000',
        metadata: ['ip' => '127.0.0.1'],
        httpMethod: 'POST',
        httpUrl: 'https://api.stripe.com/v1/payment_intents',
        httpStatusCode: 201,
        responseTimeMs: 90,
    );

    $swapped = $original->withPayload(['card_number' => '[REDACTED]']);

    expect($swapped->payload)->toBe(['card_number' => '[REDACTED]'])
        ->and($original->payload)->toBe(['card_number' => '4111111111111111'])
        ->and($swapped->reference)->toBe($original->reference)
        ->and($swapped->event)->toBe($original->event)
        ->and($swapped->direction)->toBe($original->direction)
        ->and($swapped->provider)->toBe($original->provider)
        ->and($swapped->correlationId)->toBe($original->correlationId)
        ->and($swapped->metadata)->toBe($original->metadata)
        ->and($swapped->httpMethod)->toBe($original->httpMethod)
        ->and($swapped->httpUrl)->toBe($original->httpUrl)
        ->and($swapped->httpStatusCode)->toBe($original->httpStatusCode)
        ->and($swapped->responseTimeMs)->toBe($original->responseTimeMs);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('the reference must be a reference PayZephyr would have issued', function (string $reference) {
    expect(fn () => traceEvent(reference: $reference))
        ->toThrow(InvalidTraceDataException::class, 'Invalid trace reference');
})->with([
    'empty' => [''],
    'spaces' => ['has spaces'],
    'punctuation' => ['ref!'],
    'slashes' => ['ref/123'],
    'too long' => [str_repeat('a', PaymentConstants::MAX_REFERENCE_LENGTH + 1)],
]);

test('a reference at the maximum permitted length is accepted', function () {
    $reference = str_repeat('a', PaymentConstants::MAX_REFERENCE_LENGTH);

    expect(traceEvent(reference: $reference)->reference)->toBe($reference);
});

test('a provider name longer than the column is rejected', function () {
    expect(fn () => traceEvent(provider: str_repeat('p', 51)))
        ->toThrow(InvalidTraceDataException::class, 'exceeds the maximum length of 50');
});

test('a provider name at the column limit is accepted', function () {
    expect(traceEvent(provider: str_repeat('p', 50))->provider)->toBe(str_repeat('p', 50));
});

test('a payload larger than the webhook ceiling is rejected', function () {
    expect(fn () => traceEvent(payload: ['blob' => str_repeat('x', 1048577)]))
        ->toThrow(InvalidTraceDataException::class, 'exceeds the maximum of 1048576 bytes');
});

test('an unrecognised HTTP method is rejected', function () {
    expect(fn () => traceEvent(httpMethod: 'TRACE'))
        ->toThrow(InvalidTraceDataException::class, 'Invalid HTTP method [TRACE]');
});

test('HTTP methods are accepted regardless of case', function () {
    expect(traceEvent(httpMethod: 'post')->httpMethod)->toBe('post');
});

test('an HTTP status code outside the real range is rejected', function (int $status) {
    expect(fn () => traceEvent(httpStatusCode: $status))
        ->toThrow(InvalidTraceDataException::class, 'Invalid HTTP status code');
})->with([
    'below range' => [99],
    'above range' => [600],
    'zero' => [0],
]);

test('HTTP status codes at the edges of the real range are accepted', function () {
    expect(traceEvent(httpStatusCode: 100)->httpStatusCode)->toBe(100)
        ->and(traceEvent(httpStatusCode: 599)->httpStatusCode)->toBe(599);
});

test('invalid trace data is catchable as a PaymentException like everything else in the package', function () {
    expect(fn () => traceEvent(reference: 'no good'))->toThrow(PaymentException::class);
});

test('every validation failure names the offending value', function () {
    expect(InvalidTraceDataException::invalidReference('bad ref')->getMessage())->toContain('bad ref')
        ->and(InvalidTraceDataException::payloadTooLarge(20, 10)->getMessage())->toContain('20')
        ->and(InvalidTraceDataException::providerNameTooLong('wide', 3)->getMessage())->toContain('wide')
        ->and(InvalidTraceDataException::invalidHttpMethod('NOPE')->getMessage())->toContain('NOPE')
        ->and(InvalidTraceDataException::invalidHttpStatusCode(999)->getMessage())->toContain('999');
});
