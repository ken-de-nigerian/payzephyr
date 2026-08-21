<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * Records one step of a payment's timeline.
 *
 * Deliberately two methods. The vendored package carried convenience wrappers
 * for five of its twenty-six events, which is an arbitrary subset that every
 * implementation - including the do-nothing one - then has to carry. Call
 * sites pass an explicit TraceEventDTO instead; any ergonomics worth adding
 * can be added once the real call sites exist to justify their shape.
 */
interface TraceRecorderInterface
{
    /**
     * Record a trace event.
     *
     * Returns the persisted row, or null when tracing is disabled, when the
     * event is queued for asynchronous recording, or when recording failed.
     * Callers must never depend on getting a model back, and must never let a
     * failure here affect the payment they are describing.
     */
    public function record(TraceEventDTO $event): ?PaymentTraceEvent;

    /**
     * Mint an identifier that groups the events belonging to one attempt.
     *
     * A reference spans the whole payment; a correlation id spans a single
     * provider round-trip within it. That is what makes a fallback chain
     * readable: one reference, one correlation group per provider tried.
     */
    public function startCorrelation(): string;
}
