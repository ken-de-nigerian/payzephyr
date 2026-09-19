<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use Throwable;

/**
 * One-line, never-throwing access to the trace recorder.
 *
 * TraceRecorder::record() already guarantees it will not throw, but that
 * guarantee starts too late to be useful on its own: building the
 * TraceEventDTO happens at the call site, and the DTO rejects a reference it
 * cannot key a timeline with. On the webhook path that reference is extracted
 * from a provider payload, so without this wrapper a malformed body could take
 * down webhook handling through the tracing code - the one thing tracing must
 * never do.
 *
 * Every call site in PayZephyr goes through here. Nothing resolves
 * TraceRecorderInterface directly.
 */
trait RecordsTraceEvents
{
    /**
     * Record one step of a payment's timeline. Never throws.
     *
     * A missing reference is dropped without comment rather than reported: not
     * every step has one to hang off - a webhook whose body PayZephyr could
     * not parse, for instance - and that is a normal outcome, not a fault
     * worth a log line every time it happens.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    protected function trace(
        ?string $reference,
        TraceEvent $event,
        TraceDirection $direction = TraceDirection::INTERNAL,
        array $payload = [],
        ?string $provider = null,
        ?string $correlationId = null,
        array $metadata = [],
        ?string $httpMethod = null,
        ?string $httpUrl = null,
        ?int $httpStatusCode = null,
        ?int $responseTimeMs = null,
    ): void {
        if ($reference === null || $reference === '') {
            return;
        }

        try {
            app(TraceRecorderInterface::class)->record(new TraceEventDTO(
                reference: $reference,
                event: $event,
                direction: $direction,
                payload: $payload,
                provider: $provider,
                correlationId: $correlationId,
                metadata: $metadata,
                httpMethod: $httpMethod,
                httpUrl: $httpUrl,
                httpStatusCode: $httpStatusCode,
                responseTimeMs: $responseTimeMs,
            ));
        } catch (Throwable) {
            // Anything reaching here is a malformed reference or a container
            // that cannot resolve the recorder. Both are worth strictly less
            // than the payment in progress.
        }
    }

    /**
     * Mint an identifier grouping the events of a single provider attempt.
     *
     * A reference spans the whole payment; a correlation id spans one provider
     * round-trip within it. That pairing is what makes a fallback chain
     * readable - one reference, one correlation group per provider tried.
     *
     * Returns an empty string when tracing is off or the recorder cannot be
     * resolved, which TraceEventDTO normalizes to no correlation at all.
     */
    protected function startTraceCorrelation(): string
    {
        try {
            return app(TraceRecorderInterface::class)->startCorrelation();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Whether provider request and response bodies may be stored.
     *
     * False whenever tracing is off, not just when body capture is: callers
     * use this to decide whether to read a body at all, and with tracing
     * disabled there is nothing that would ever store one.
     */
    protected function traceRecordsHttpBodies(): bool
    {
        try {
            $config = app('payments.config') ?? config('payments', []);

            if (! (data_get($config, 'features.trace') ?? false)) {
                return false;
            }

            return (bool) (data_get($config, 'trace.record_http_bodies') ?? true);
        } catch (Throwable) {
            return false;
        }
    }
}
