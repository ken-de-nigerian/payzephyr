<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use JsonException;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\InvalidTraceDataException;

/**
 * One step in a payment's timeline, on its way to storage.
 *
 * Keyed by reference rather than by any trace-specific identifier: a timeline
 * is only useful if it is reachable by the same string the caller already has
 * from Payment::charge(), and PayZephyr guarantees one reference per payment
 * for the whole fallback chain.
 *
 * Almost every field here is normalized rather than validated. Trace events
 * describe things that already happened, and several of these values arrive
 * from outside PayZephyr's control - the webhook route is
 * `POST /{provider}` with no constraint on the segment, and payloads are
 * provider response bodies. Refusing to construct because a provider sent a
 * sixty-character slug would throw away the timestamp, the event type and the
 * timing, all of which are still true and still worth having.
 *
 * The one exception is the reference, which is the key the whole timeline
 * hangs off. A truncated or coerced reference would file this payment's
 * history under a different payment, so that one throws.
 */
final readonly class TraceEventDTO
{
    /**
     * Matches payments.webhook.max_payload_size. A trace payload is usually a
     * provider request or response body, so the ceiling that already governs
     * what PayZephyr will accept from a provider is the right one here too.
     */
    public const MAX_PAYLOAD_SIZE = 1048576;

    /**
     * Replaces a payload or metadata array that cannot be stored. The event
     * survives; only the body is lost, and the reason travels with it.
     */
    public const DROPPED_KEY = '_payzephyr_dropped';

    private const MAX_PROVIDER_LENGTH = 50;

    private const VALID_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private const MIN_HTTP_STATUS_CODE = 100;

    private const MAX_HTTP_STATUS_CODE = 599;

    /** @var array<string, mixed> */
    public array $payload;

    /** @var array<string, mixed> */
    public array $metadata;

    public ?string $provider;

    public ?string $correlationId;

    public ?string $httpMethod;

    public ?int $httpStatusCode;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidTraceDataException If the reference cannot key a timeline.
     */
    public function __construct(
        public string $reference,
        public TraceEvent $event,
        public TraceDirection $direction,
        array $payload = [],
        ?string $provider = null,
        ?string $correlationId = null,
        array $metadata = [],
        ?string $httpMethod = null,
        public ?string $httpUrl = null,
        ?int $httpStatusCode = null,
        public ?int $responseTimeMs = null,
    ) {
        if (preg_match('/^[a-zA-Z0-9_-]{1,'.PaymentConstants::MAX_REFERENCE_LENGTH.'}$/', $this->reference) !== 1) {
            throw InvalidTraceDataException::invalidReference($this->reference);
        }

        $this->payload = self::normalizeJsonColumn($payload);
        $this->metadata = self::normalizeJsonColumn($metadata);
        $this->provider = self::normalizeProvider($provider);
        $this->correlationId = $correlationId === '' ? null : $correlationId;
        $this->httpMethod = self::normalizeHttpMethod($httpMethod);
        $this->httpStatusCode = self::normalizeHttpStatusCode($httpStatusCode);
    }

    /**
     * Keep an array only if it can actually be stored: encodable as JSON, and
     * inside the size ceiling.
     *
     * Dropped wholesale rather than truncated, because there is no useful way
     * to cut a JSON structure at a byte offset - what comes back is neither
     * valid nor readable. Losing the body while keeping the event is the
     * honest version of truncation.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function normalizeJsonColumn(array $value): array
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [self::DROPPED_KEY => 'not encodable as JSON: '.$e->getMessage()];
        }

        $size = strlen($encoded);

        if ($size > self::MAX_PAYLOAD_SIZE) {
            return [self::DROPPED_KEY => 'exceeded the '.self::MAX_PAYLOAD_SIZE." byte ceiling at $size bytes"];
        }

        return $value;
    }

    /**
     * The provider column is 50 characters and the webhook route accepts any
     * segment, so this both trims and treats an empty slug as "unknown".
     */
    private static function normalizeProvider(?string $provider): ?string
    {
        if ($provider === null || $provider === '') {
            return null;
        }

        return mb_substr($provider, 0, self::MAX_PROVIDER_LENGTH);
    }

    /**
     * Also upper-cases, so a timeline never shows `post` and `POST` as though
     * they were different things.
     */
    private static function normalizeHttpMethod(?string $method): ?string
    {
        if ($method === null) {
            return null;
        }

        $upper = strtoupper($method);

        return in_array($upper, self::VALID_HTTP_METHODS, true) ? $upper : null;
    }

    private static function normalizeHttpStatusCode(?int $statusCode): ?int
    {
        if ($statusCode === null) {
            return null;
        }

        return $statusCode >= self::MIN_HTTP_STATUS_CODE && $statusCode <= self::MAX_HTTP_STATUS_CODE
            ? $statusCode
            : null;
    }

    /**
     * Return a copy of this event carrying scrubbed payload and metadata.
     *
     * Lets TraceRecorder swap both in without the DTO giving up readonly on
     * everything else. Both together rather than one at a time: they are the
     * two free-form columns, they are redacted by the same pass, and doing
     * them separately would build and re-validate the DTO twice.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidTraceDataException
     */
    public function withRedacted(array $payload, array $metadata): self
    {
        return new self(
            reference: $this->reference,
            event: $this->event,
            direction: $this->direction,
            payload: $payload,
            provider: $this->provider,
            correlationId: $this->correlationId,
            metadata: $metadata,
            httpMethod: $this->httpMethod,
            httpUrl: $this->httpUrl,
            httpStatusCode: $this->httpStatusCode,
            responseTimeMs: $this->responseTimeMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'event' => $this->event->value,
            'direction' => $this->direction->value,
            'payload' => $this->payload,
            'provider' => $this->provider,
            'correlation_id' => $this->correlationId,
            'metadata' => $this->metadata,
            'http_method' => $this->httpMethod,
            'http_url' => $this->httpUrl,
            'http_status_code' => $this->httpStatusCode,
            'response_time_ms' => $this->responseTimeMs,
        ];
    }
}
