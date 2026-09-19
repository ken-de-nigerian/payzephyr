<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * The recorder bound when tracing is switched off.
 *
 * Tracing is the only PayZephyr feature that writes on the hot path of every
 * charge, verification and webhook, so switching it off has to mean *nothing
 * happens* - not "a disabled check runs first". Nothing here touches the
 * database, the queue, the config or the container, which is what makes
 * PAYZEPHYR_FEATURE_TRACE=false a real kill switch: it takes effect on the
 * next request, with no migration to roll back and no deploy required.
 *
 * Call sites cannot tell the difference. record() returning null is already
 * the documented outcome for a queued write and for a failed one.
 */
final readonly class NullTraceRecorder implements TraceRecorderInterface
{
    public function record(TraceEventDTO $event): ?PaymentTraceEvent
    {
        return null;
    }

    /**
     * There is no correlation group to join, and minting a UUID nobody will
     * store would be work done for nothing. Call sites pass this straight into
     * a TraceEventDTO, where an empty string is normalized away.
     */
    public function startCorrelation(): string
    {
        return '';
    }
}
