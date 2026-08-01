<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Constants;

/**
 * Payment-related constants to replace magic numbers.
 */
final class PaymentConstants
{
    /**
     * Maximum length for reference strings (e.g., transaction references, idempotency keys).
     */
    public const MAX_REFERENCE_LENGTH = 255;

    /**
     * Maximum length for metadata keys.
     */
    public const MAX_KEY_LENGTH = 255;

    /**
     * Default webhook timestamp tolerance in seconds (5 minutes).
     */
    public const WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Default health check cache TTL in seconds (5 minutes).
     */
    public const HEALTH_CHECK_CACHE_TTL_SECONDS = 300;

    /**
     * Maximum string length before token pattern checking in log sanitization.
     */
    public const MAX_STRING_LENGTH_FOR_TOKEN_CHECK = 20;

    /**
     * Maximum recursion depth for metadata sanitization.
     */
    public const METADATA_MAX_DEPTH = 10;

    /**
     * Maximum string length for metadata values.
     */
    public const METADATA_MAX_STRING_LENGTH = 10000;

    /**
     * Maximum array size for metadata arrays.
     */
    public const METADATA_MAX_ARRAY_SIZE = 100;

    /**
     * Maximum recursion depth for log context sanitization.
     *
     * Kept distinct from METADATA_MAX_DEPTH: log context (error contexts, raw
     * provider responses) and persisted transaction metadata are different
     * shapes with independently-tunable limits.
     */
    public const LOG_SANITIZATION_MAX_DEPTH = 10;
}
