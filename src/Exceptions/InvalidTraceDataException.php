<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Exceptions;

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

/**
 * Thrown when a trace event carries a reference that cannot key a timeline.
 *
 * The only thing TraceEventDTO refuses outright. Everything else it is handed
 * gets normalized, because a trace event describes something that already
 * happened and is worth keeping even with a field trimmed. A bad reference is
 * different in kind: it would file this payment's history under some other
 * payment, which is worse than having no history at all.
 *
 * Nothing on the payment path is allowed to surface one. TraceRecorder catches
 * it, reports it to the payment log channel, and drops the event.
 */
final class InvalidTraceDataException extends PaymentException
{
    public static function invalidReference(string $reference): self
    {
        return new self(
            "Invalid trace reference [$reference]. A reference must be 1-".PaymentConstants::MAX_REFERENCE_LENGTH.
            ' characters of letters, digits, hyphens and underscores.'
        );
    }
}
