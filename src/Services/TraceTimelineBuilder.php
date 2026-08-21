<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/**
 * Reconstructs a payment's timeline from its recorded trace events.
 */
final readonly class TraceTimelineBuilder
{
    public function build(string $reference): Timeline
    {
        return new Timeline(
            $reference,
            PaymentTraceEvent::timelineFor($reference)->get()
        );
    }
}
