<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Constants;

/**
 * HTTP status code constants to replace magic numbers.
 */
final class HttpStatusCodes
{
    public const OK = 200;

    public const ACCEPTED = 202;

    public const BAD_REQUEST = 400;

    public const UNAUTHORIZED = 401;

    public const FORBIDDEN = 403;

    public const NOT_FOUND = 404;

    public const REQUEST_TIMEOUT = 408;

    public const TOO_MANY_REQUESTS = 429;

    public const INTERNAL_SERVER_ERROR = 500;

    public const BAD_GATEWAY = 502;

    public const SERVICE_UNAVAILABLE = 503;

    /**
     * Check if status code indicates a client error (4xx).
     */
    public static function isClientError(int $statusCode): bool
    {
        return $statusCode >= 400 && $statusCode < 500;
    }

    /**
     * Check if status code indicates a server error (5xx).
     */
    public static function isServerError(int $statusCode): bool
    {
        return $statusCode >= 500;
    }

    /**
     * Check if status code indicates success (2xx).
     */
    public static function isSuccess(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }
}
