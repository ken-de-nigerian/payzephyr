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
 */
final readonly class TraceEventDTO
{
    private const MAX_PROVIDER_LENGTH = 50;

    /**
     * Matches payments.webhook.max_payload_size. A trace payload is usually a
     * provider request or response body, so the ceiling that already governs
     * what PayZephyr will accept from a provider is the right one here too.
     */
    private const MAX_PAYLOAD_SIZE = 1048576;

    private const VALID_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private const MIN_HTTP_STATUS_CODE = 100;

    private const MAX_HTTP_STATUS_CODE = 599;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidTraceDataException|JsonException
     */
    public function __construct(
        public string $reference,
        public TraceEvent $event,
        public TraceDirection $direction,
        public array $payload = [],
        public ?string $provider = null,
        public ?string $correlationId = null,
        public array $metadata = [],
        public ?string $httpMethod = null,
        public ?string $httpUrl = null,
        public ?int $httpStatusCode = null,
        public ?int $responseTimeMs = null,
    ) {
        $this->validate();
    }

    /**
     * @throws InvalidTraceDataException|JsonException
     */
    private function validate(): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,'.PaymentConstants::MAX_REFERENCE_LENGTH.'}$/', $this->reference) !== 1) {
            throw InvalidTraceDataException::invalidReference($this->reference);
        }

        if ($this->provider !== null && strlen($this->provider) > self::MAX_PROVIDER_LENGTH) {
            throw InvalidTraceDataException::providerNameTooLong($this->provider, self::MAX_PROVIDER_LENGTH);
        }

        $payloadSize = strlen(json_encode($this->payload, JSON_THROW_ON_ERROR));
        if ($payloadSize > self::MAX_PAYLOAD_SIZE) {
            throw InvalidTraceDataException::payloadTooLarge($payloadSize, self::MAX_PAYLOAD_SIZE);
        }

        if ($this->httpMethod !== null && ! in_array(strtoupper($this->httpMethod), self::VALID_HTTP_METHODS, true)) {
            throw InvalidTraceDataException::invalidHttpMethod($this->httpMethod);
        }

        if ($this->httpStatusCode !== null
            && ($this->httpStatusCode < self::MIN_HTTP_STATUS_CODE || $this->httpStatusCode > self::MAX_HTTP_STATUS_CODE)) {
            throw InvalidTraceDataException::invalidHttpStatusCode($this->httpStatusCode);
        }
    }

    /**
     * Return a copy of this event carrying the given payload.
     *
     * Lets TraceRecorder swap in the redacted payload without the DTO giving
     * up readonly on everything else.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidTraceDataException|JsonException
     */
    public function withPayload(array $payload): self
    {
        return new self(
            reference: $this->reference,
            event: $this->event,
            direction: $this->direction,
            payload: $payload,
            provider: $this->provider,
            correlationId: $this->correlationId,
            metadata: $this->metadata,
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
