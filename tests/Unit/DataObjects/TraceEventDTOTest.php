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
// The reference is the one thing that must be right
// ---------------------------------------------------------------------------

test('the reference must be a reference PayZephyr would have issued', function (string $reference) {
    // The only refusal in the DTO. A coerced reference would file this
    // payment's history under a different payment, which is worse than
    // having no history at all.
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

test('a bad reference is catchable as a PaymentException like everything else in the package', function () {
    expect(fn () => traceEvent(reference: 'no good'))->toThrow(PaymentException::class);
});

test('the exception names the offending reference', function () {
    expect(InvalidTraceDataException::invalidReference('bad ref')->getMessage())->toContain('bad ref');
});

// ---------------------------------------------------------------------------
// Everything else is normalized rather than refused
// ---------------------------------------------------------------------------

test('a provider name longer than the column is trimmed to fit', function () {
    // POST /payments/webhook/{provider} has no constraint on the segment, so
    // this value is attacker-controlled. Refusing here would let a long URL
    // take down webhook handling through the tracing code.
    expect(traceEvent(provider: str_repeat('p', 200))->provider)->toBe(str_repeat('p', 50));
});

test('a provider name at the column limit is kept whole', function () {
    expect(traceEvent(provider: str_repeat('p', 50))->provider)->toBe(str_repeat('p', 50));
});

test('an empty provider is stored as no provider at all', function () {
    expect(traceEvent(provider: '')->provider)->toBeNull();
});

test('a payload past the ceiling is dropped, and the event survives', function () {
    $dto = traceEvent(payload: ['blob' => str_repeat('x', TraceEventDTO::MAX_PAYLOAD_SIZE + 1)]);

    expect($dto->payload)->toHaveKey(TraceEventDTO::DROPPED_KEY)
        ->and($dto->payload[TraceEventDTO::DROPPED_KEY])->toContain('exceeded the 1048576 byte ceiling')
        ->and($dto->reference)->toBe('PZ_1755000000_abcdef01')
        ->and($dto->event)->toBe(TraceEvent::PAYMENT_INITIATED);
});

test('metadata past the ceiling is dropped the same way', function () {
    $dto = traceEvent(metadata: ['headers' => str_repeat('x', TraceEventDTO::MAX_PAYLOAD_SIZE + 1)]);

    expect($dto->metadata)->toHaveKey(TraceEventDTO::DROPPED_KEY)
        ->and($dto->payload)->toBe([]);
});

test('a payload that is not encodable as JSON is dropped rather than thrown', function () {
    // Provider bodies are not guaranteed to be valid UTF-8, and json_encode
    // throwing here would surface as a failed payment.
    $dto = traceEvent(payload: ['body' => "\xB1\x31"]);

    expect($dto->payload)->toHaveKey(TraceEventDTO::DROPPED_KEY)
        ->and($dto->payload[TraceEventDTO::DROPPED_KEY])->toContain('not encodable as JSON');
});

test('a payload at exactly the ceiling is kept', function () {
    $filler = str_repeat('x', TraceEventDTO::MAX_PAYLOAD_SIZE - strlen('{"blob":""}'));

    expect(traceEvent(payload: ['blob' => $filler])->payload)->toBe(['blob' => $filler]);
});

test('an unrecognised HTTP method is dropped', function () {
    expect(traceEvent(httpMethod: 'TRACE')->httpMethod)->toBeNull();
});

test('HTTP methods are upper-cased so a timeline shows one spelling', function () {
    expect(traceEvent(httpMethod: 'post')->httpMethod)->toBe('POST');
});

test('an HTTP status code outside the real range is dropped', function (int $status) {
    expect(traceEvent(httpStatusCode: $status)->httpStatusCode)->toBeNull();
})->with([
    'below range' => [99],
    'above range' => [600],
    'zero' => [0],
]);

test('HTTP status codes at the edges of the real range are kept', function () {
    expect(traceEvent(httpStatusCode: 100)->httpStatusCode)->toBe(100)
        ->and(traceEvent(httpStatusCode: 599)->httpStatusCode)->toBe(599);
});

test('an empty correlation id is stored as no correlation', function () {
    // NullTraceRecorder::startCorrelation() returns an empty string, and a
    // call site that passes it straight through must not write one.
    expect(traceEvent(correlationId: '')->correlationId)->toBeNull();
});

test('normalization survives withPayload', function () {
    $dto = traceEvent(provider: str_repeat('p', 200), httpMethod: 'get')->withPayload(['a' => 1]);

    expect($dto->provider)->toBe(str_repeat('p', 50))
        ->and($dto->httpMethod)->toBe('GET')
        ->and($dto->payload)->toBe(['a' => 1]);
});
