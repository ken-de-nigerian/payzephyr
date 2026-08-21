<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

/**
 * Persists one trace event off the request path.
 *
 * The payload has already been redacted by TraceRecorder before this job is
 * queued, so nothing sensitive is sitting in the queue backend waiting to be
 * written.
 */
final class RecordTraceEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LogsToPaymentChannel;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private readonly TraceEventDTO $event
    ) {}

    public function handle(): void
    {
        PaymentTraceEvent::create($this->event->toArray());
    }

    /**
     * A trace event that cannot be written is worth a log line and nothing
     * more. The payment it describes has already happened either way.
     */
    public function failed(Throwable $exception): void
    {
        $this->log('error', 'Failed to record a payment trace event', [
            'reference' => $this->event->reference,
            'event' => $this->event->event->value,
            'error' => $exception->getMessage(),
        ]);
    }
}
