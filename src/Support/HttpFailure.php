<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Reads a failed HTTP request the same way under Guzzle 7 and Guzzle 8.
 *
 * The two differ in where a failure's response lives. Under Guzzle 7 any
 * RequestException may carry one. Under Guzzle 8 only its ResponseException
 * branch does (BadResponseException, ClientException, ServerException and
 * response-transfer failures); a plain RequestException has no getResponse().
 * And Guzzle 8 splits no-response failures: ConnectException is a connection
 * that was never made, while NetworkException covers failures after the
 * request may have been sent, such as a read timeout.
 */
final class HttpFailure
{
    /**
     * The response the failed request received, or null when none arrived.
     */
    public static function responseOf(Throwable $failure): ?ResponseInterface
    {
        // Asked of the interface, not RequestException: which Guzzle exceptions
        // have getResponse() differs between the two versions.
        if (! $failure instanceof GuzzleException || ! method_exists($failure, 'getResponse')) {
            return null;
        }

        $response = $failure->getResponse();

        return $response instanceof ResponseInterface ? $response : null;
    }

    /**
     * Whether the connection was never made, so the provider cannot have seen
     * the request.
     */
    public static function neverSent(Throwable $failure): bool
    {
        return $failure instanceof ConnectException;
    }

    /**
     * Whether the request failed on the network without any response: a
     * connection never made, or (Guzzle 8) one lost after sending.
     */
    public static function isNetworkFailure(Throwable $failure): bool
    {
        return $failure instanceof NetworkExceptionInterface;
    }
}
