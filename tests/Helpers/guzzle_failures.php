<?php

declare(strict_types=1);

use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A failed request that did get a response, under whichever Guzzle is
 * installed: Guzzle 7 keeps the response on RequestException itself, while
 * Guzzle 8 only puts one on its ResponseException branch.
 */
function failureWithResponse(string $message, RequestInterface $request, ResponseInterface $response, ?Throwable $previous = null): RequestException
{
    if (class_exists(ResponseException::class)) {
        return new ResponseException($message, $request, $response, $previous);
    }

    return new RequestException($message, $request, $response, $previous);
}

/**
 * Whether the installed Guzzle is 8 or later (its split network exceptions).
 */
function guzzleHasNetworkExceptions(): bool
{
    return class_exists(NetworkException::class);
}

/**
 * A bare transfer failure: Guzzle 8's TransferException needs the request,
 * Guzzle 7's takes only a message.
 */
function transferFailure(string $message): TransferException
{
    if (guzzleHasNetworkExceptions()) {
        return new TransferException($message, new Request('GET', '/'));
    }

    return new TransferException($message);
}
