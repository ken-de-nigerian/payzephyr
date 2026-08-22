<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Jobs\RecordTraceEvent;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

/**
 * Writes trace events to the database, synchronously or via the queue.
 */
final readonly class TraceRecorder implements TraceRecorderInterface
{
    use LogsToPaymentChannel;

    public function __construct(
        private PayloadRedactor $redactor
    ) {}

    /**
     * Guaranteed not to throw.
     *
     * A trace event describes something that has already happened. If storing
     * that description fails - the table was never migrated, the connection is
     * down, the queue backend is unreachable - the payment it describes is
     * still exactly as true as it was, and the caller must hear about the
     * payment, not about PayZephyr's bookkeeping. The blanket catch is the
     * point of this method, not an oversight.
     *
     * Mirrors PaymentManager::completeSuccessfulCharge(), which takes the same
     * position for the same reason.
     */
    public function record(TraceEventDTO $event): ?PaymentTraceEvent
    {
        try {
            if (! $this->isEnabled()) {
                return null;
            }

            $redacted = $event->withPayload($this->redactor->redact($event->payload));

            if ($this->shouldRecordAsync()) {
                $this->recordAsync($redacted);

                return null;
            }

            return PaymentTraceEvent::create($redacted->toArray());
        } catch (Throwable $e) {
            $this->reportFailure($event, $e);

            return null;
        }
    }

    /**
     * Report a dropped trace event to the payment log channel.
     *
     * Guarded in turn: a misconfigured log channel must not convert a failed
     * trace write into a failed payment either.
     */
    private function reportFailure(TraceEventDTO $event, Throwable $e): void
    {
        try {
            $this->log('error', 'Failed to record a payment trace event', [
                'reference' => $event->reference,
                'event' => $event->event->value,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);
        } catch (Throwable) {
            // Nothing left to try. Losing a trace event is not worth an exception.
        }
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
        return (bool) (data_get($this->config(), 'features.trace') ?? false);
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
