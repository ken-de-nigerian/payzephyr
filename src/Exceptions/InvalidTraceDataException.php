<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Exceptions;

use KenDeNigerian\PayZephyr\Constants\PaymentConstants;

/**
 * Thrown when a trace event carries data that cannot be stored as-is.
 *
 * Every one of these is a programming error on the recording side, not a
 * payment failure. Nothing on the payment path is allowed to surface one -
 * TraceRecorder catches them, reports them, and drops the event.
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

    public static function payloadTooLarge(int $size, int $maxSize): self
    {
        return new self("Trace payload size ($size bytes) exceeds the maximum of $maxSize bytes");
    }

    public static function providerNameTooLong(string $provider, int $maxLength): self
    {
        return new self("Provider name [$provider] exceeds the maximum length of $maxLength characters");
    }

    public static function invalidHttpMethod(string $method): self
    {
        return new self("Invalid HTTP method [$method]. Must be one of: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS");
    }

    public static function invalidHttpStatusCode(int $statusCode): self
    {
        return new self("Invalid HTTP status code [$statusCode]. Must be between 100 and 599");
    }
}
