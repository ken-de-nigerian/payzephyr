<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Str;
use JsonException;
use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Exceptions\InvalidTraceDataException;
use KenDeNigerian\PayZephyr\Jobs\RecordTraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * Writes trace events to the database, synchronously or via the queue.
 */
final readonly class TraceRecorder implements TraceRecorderInterface
{
    public function __construct(
        private PayloadRedactor $redactor
    ) {}

    /**
     * @throws InvalidTraceDataException|JsonException
     */
    public function record(TraceEventDTO $event): ?PaymentTraceEvent
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $redacted = $event->withPayload($this->redactor->redact($event->payload));

        if ($this->shouldRecordAsync()) {
            $this->recordAsync($redacted);

            return null;
        }

        return PaymentTraceEvent::create($redacted->toArray());
    }

    public function startCorrelation(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Queues the write. record() returns null afterwards rather than a model:
     * the row does not exist yet, and pretending otherwise would give callers
     * something to depend on that is only true in synchronous mode.
     */
    private function recordAsync(TraceEventDTO $event): void
    {
        $config = $this->config();

        $job = new RecordTraceEvent($event);

        $connection = data_get($config, 'trace.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        $queue = data_get($config, 'trace.queue.name', 'default');
        dispatch($job->onQueue(is_string($queue) ? $queue : 'default'));
    }

    private function isEnabled(): bool
    {
        return (bool) (data_get($this->config(), 'trace.enabled') ?? false);
    }

    private function shouldRecordAsync(): bool
    {
        return (bool) (data_get($this->config(), 'trace.async') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = app('payments.config') ?? config('payments', []);

        return $config;
    }
}
