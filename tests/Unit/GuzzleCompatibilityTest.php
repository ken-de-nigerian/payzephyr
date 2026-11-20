<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Support\HttpFailure;

/*
 * PayZephyr runs on Guzzle 7 and Guzzle 8. The tests in this file pin how a
 * failed request is read under each, above all the safety rule: a request that
 * may have reached the provider is never retried against another one.
 */

describe('HttpFailure', function (): void {
    test('reads the response a failure carries, and none from one without', function (): void {
        $request = new Request('POST', '/charge');
        $response = new Response(503);

        expect(HttpFailure::responseOf(failureWithResponse('Server error', $request, $response)))->toBe($response)
            ->and(HttpFailure::responseOf(new RequestException('Stream closed', $request)))->toBeNull()
            ->and(HttpFailure::responseOf(new ConnectException('Connection refused', $request)))->toBeNull()
            ->and(HttpFailure::responseOf(new RuntimeException('not HTTP')))->toBeNull();
    });

    test('tells a connection never made from other failures', function (): void {
        $request = new Request('POST', '/charge');

        expect(HttpFailure::neverSent(new ConnectException('Connection refused', $request)))->toBeTrue()
            ->and(HttpFailure::neverSent(new RequestException('Stream closed', $request)))->toBeFalse()
            ->and(HttpFailure::isNetworkFailure(new ConnectException('Connection refused', $request)))->toBeTrue()
            ->and(HttpFailure::isNetworkFailure(new RequestException('Stream closed', $request)))->toBeFalse()
            ->and(HttpFailure::isNetworkFailure(new RuntimeException('not HTTP')))->toBeFalse();
    });
});

describe('Guzzle 8 network failures', function (): void {
    test('a connection never made is not ambiguous', function (): void {
        $request = new Request('POST', '/charge');
        $timedOut = ChargeException::withContext('x', [], new ConnectTimeoutException('Connection timed out', $request));
        $lost = ChargeException::withContext('x', [], new NetworkException('Connection reset by peer', $request));

        expect($timedOut->isAmbiguousProviderOutcome())->toBeFalse()
            ->and($lost->isAmbiguousProviderOutcome())->toBeTrue();
    });

    test('a failure after the response arrived carries it', function (): void {
        $request = new Request('GET', '/bank');
        $response = new Response(200);

        expect(HttpFailure::responseOf(new ResponseTransferException('Body cut short', $request, $response)))->toBe($response)
            ->and(HttpFailure::isNetworkFailure(new NetworkTimeoutException('Operation timed out', $request)))->toBeTrue()
            ->and(HttpFailure::neverSent(new NetworkTimeoutException('Operation timed out', $request)))->toBeFalse();
    });
})->skip(! guzzleHasNetworkExceptions(), 'Guzzle 8 only');
